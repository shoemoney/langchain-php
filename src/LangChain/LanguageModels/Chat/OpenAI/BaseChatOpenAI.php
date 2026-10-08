<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI;

use LangChain\LanguageModels\Chat\NormalisesProviderOptions;
use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesTools;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Tools;
use LangChain\Tools\StructuredTool;
use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Js;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\SseParser;

/**
 * State, option handling and transport shared by the OpenAI chat clients.
 *
 * Port of `BaseChatOpenAI` from `@langchain/openai` (`chat_models/base.ts`).
 * {@see ChatOpenAICompletions} and {@see ChatOpenAIResponses} each own one wire
 * protocol; {@see ChatOpenAI} is the facade that picks between them. What lives
 * here is everything that does not depend on the protocol: constructor state,
 * the option-spelling table, `bindTools()`, authentication, and the
 * retrying transport.
 *
 * ## Retries, and where the boundary sits
 *
 * {@see self::$maxRetries} covers **request establishment only**:
 *
 *  - a non-streaming call is retried on a transport error or a 429/5xx, up to
 *    `maxRetries` extra attempts. A 4xx is not retried: it will fail
 *    identically every time, and retrying only delays the error.
 *  - a stream is retried the same way **until the first byte is yielded**. A
 *    transient 429 while opening a stream is the most common streaming failure
 *    and the one case that is unambiguously safe to retry, because nothing has
 *    been delivered.
 *  - once a byte has been yielded the stream is committed and the error
 *    propagates. Reconnecting there would re-emit tokens the caller has already
 *    been handed, which is worse than failing.
 *
 * ## API key
 *
 * Read from the constructor, else `$fields['apiKey']`, else the
 * `OPENAI_API_KEY` environment variable. The key is never written into
 * `kwargs()`, because those are what the tracer serialises.
 */
abstract class BaseChatOpenAI extends BaseChatModel
{
    use NormalisesProviderOptions {
        // Subclasses read the layered options too; the trait declares it private.
        pickOption as protected;
    }

    /**
     * Every wire spelling of every call option, mapped to its canonical name.
     *
     * One table, used by the constructor, by bound kwargs and by per-call
     * options. It existed once as a per-layer hand-rolled list, and the three
     * layers drifted: `invocationParams()` accepted `max_tokens` while the
     * constructor read only `maxTokens`, so `new ChatOpenAI(['max_tokens' => 99])`
     * was accepted, ignored, and reported as nothing anywhere.
     *
     * The canonical name is the PHP-facing one; the wire names are what the
     * TypeScript SDK and the HTTP API use, and a caller may reasonably use
     * either.
     */
    private const KEY_ALIASES = [
        'max_tokens' => 'maxTokens',
        'top_p' => 'topP',
        // Mapped so that BOTH spellings reach `rejectUnsupported()`. Leaving it
        // out would mean the camelCase form is refused while the wire form is
        // silently dropped — two spellings of the same mistake, one loud and
        // one quiet. Chat Completions has no `top_k`; Anthropic does.
        'top_k' => 'topK',
        'frequency_penalty' => 'frequencyPenalty',
        'presence_penalty' => 'presencePenalty',
        'stop' => 'stopSequences',
        'stop_sequences' => 'stopSequences',
        'parallel_tool_calls' => 'parallelToolCalls',
        'response_format' => 'responseFormat',
        'tool_choice' => 'toolChoice',
        'service_tier' => 'serviceTier',
        'zdr_enabled' => 'zdrEnabled',
        'model_kwargs' => 'modelKwargs',
        'reasoning_effort' => 'reasoningEffort',
        'prompt_cache_key' => 'promptCacheKey',
        'prompt_cache_retention' => 'promptCacheRetention',
        'prompt_cache_options' => 'promptCacheOptions',
        'use_responses_api' => 'useResponsesApi',
    ];

    private const CLASS_NAME = 'ChatOpenAI';

    public string $model = 'gpt-4o-mini';

    public ?string $apiKey = null;

    public ?string $organization = null;

    public ?string $baseUrl = null;

    public ?float $temperature = null;

    public ?float $topP = null;

    public ?float $frequencyPenalty = null;

    public ?float $presencePenalty = null;

    /** @var list<string>|null */
    public ?array $stopSequences = null;

    public ?int $maxTokens = null;

    public bool $streamUsage = true;

    /**
     * Whether tools are declared `strict`.
     *
     * Null means "whatever the call says", which is the upstream default. It is
     * a separate field from the model's own capability because a strict-capable
     * model can still be called non-strictly.
     */
    public ?bool $supportsStrictToolCalling = null;

    public ?float $timeout = null;

    /**
     * Retries for a failed request.
     *
     * Only transport errors and 429/5xx are retried. A 400 or 401 is the
     * caller's fault and will fail identically every time, so retrying it just
     * delays the error by `maxRetries` round trips.
     *
     * Applies to *establishing* a request, not to a stream once it is flowing.
     * A failure part-way through a stream cannot be retried without re-emitting
     * the tokens already delivered, so a mid-stream error propagates. Upstream
     * retries stream *creation*; this matches.
     */
    public int $maxRetries = 2;

    public ?HttpClient $httpClient = null;

    /**
     * Reasoning configuration (`effort`, `summary`) for reasoning models.
     *
     * @var array<string, mixed>|null
     */
    public ?array $reasoning = null;

    /**
     * Zero Data Retention: send `store: false` and replay reasoning only when encrypted.
     */
    public bool $zdrEnabled = false;

    public ?string $serviceTier = null;

    /**
     * Stream even when the caller asked for a plain `invoke()`.
     */
    public bool $streaming = false;

    /**
     * Extra request parameters merged into every Responses request body.
     *
     * @var array<string, mixed>
     */
    public array $modelKwargs = [];

    public ?string $promptCacheKey = null;

    public ?string $promptCacheRetention = null;

    /** @var array<string, mixed>|null */
    public ?array $promptCacheOptions = null;

    /**
     * Where a facade routes the retry sleep, so a subclass overriding
     * {@see self::backoff()} on the facade also governs its delegates.
     */
    protected ?\Closure $backoffHandler = null;

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);

        // Every layer below reads this one canonicalised bag, so a wire-spelled
        // option behaves the same whether it arrives in the constructor, in
        // bound kwargs, or per call.
        $fields = self::canonicalise($fields);

        // A model name is required. The TypeScript original gets this from its
        // type system, which PHP has no equivalent of, so without an explicit
        // check an empty name is sent and the provider answers 400 with a
        // message about the request rather than about the constructor.
        if (trim((string) ($fields['model'] ?? $this->model)) === '') {
            throw new \InvalidArgumentException(sprintf(
                '%s requires a non-empty model name.',
                self::CLASS_NAME,
            ));
        }

        // A parameter this client cannot send is refused, never dropped.
        // Silently ignoring it is the worst outcome available: the caller's
        // sampling appears configured and is not, with nothing to report it.
        $this->rejectUnsupported($fields);

        $this->model = (string) ($fields['model'] ?? $this->model);
        $this->apiKey = isset($fields['apiKey']) ? (string) $fields['apiKey'] : $this->apiKey;
        $this->organization = isset($fields['organization']) ? (string) $fields['organization'] : $this->organization;
        $this->baseUrl = isset($fields['baseUrl']) ? (string) $fields['baseUrl'] : $this->baseUrl;
        $this->temperature = isset($fields['temperature']) ? (float) $fields['temperature'] : $this->temperature;
        $this->topP = isset($fields['topP']) ? (float) $fields['topP'] : $this->topP;
        $this->frequencyPenalty = isset($fields['frequencyPenalty']) ? (float) $fields['frequencyPenalty'] : $this->frequencyPenalty;
        $this->presencePenalty = isset($fields['presencePenalty']) ? (float) $fields['presencePenalty'] : $this->presencePenalty;
        // Every spelling that means the same parameter, folded to one key.
        // The wire name is `stop`, the JS field is `stopSequences`, and the
        // Anthropic wire name is `stop_sequences` — a caller may reasonably
        // use any of them, and accepting only one meant the other two were
        // recorded in `kwargs` and then never read.
        $stop = $fields['stop'] ?? $fields['stopSequences'] ?? $fields['stop_sequences'] ?? null;
        $this->stopSequences = $stop === null ? $this->stopSequences : array_values((array) $stop);
        $this->maxTokens = isset($fields['maxTokens']) ? (int) $fields['maxTokens'] : $this->maxTokens;
        $this->streamUsage = (bool) ($fields['streamUsage'] ?? $this->streamUsage);
        // No model list: `strict` is opt-in only. Guessing per model name would
        // be a silent wrong answer the first time a model is renamed, and the
        // failure mode is a rejected request, not a degraded one.
        $this->supportsStrictToolCalling = isset($fields['supportsStrictToolCalling'])
            ? (bool) $fields['supportsStrictToolCalling']
            : null;
        $this->timeout = isset($fields['timeout']) ? (float) $fields['timeout'] : $this->timeout;
        $this->maxRetries = (int) ($fields['maxRetries'] ?? $this->maxRetries);
        $this->httpClient = $fields['httpClient'] ?? $this->httpClient;
        $this->reasoning = isset($fields['reasoning']) && is_array($fields['reasoning']) ? $fields['reasoning'] : $this->reasoning;
        $this->zdrEnabled = (bool) ($fields['zdrEnabled'] ?? $this->zdrEnabled);
        $this->serviceTier = isset($fields['serviceTier']) ? (string) $fields['serviceTier'] : $this->serviceTier;
        $this->streaming = (bool) ($fields['streaming'] ?? $this->streaming);
        $this->modelKwargs = is_array($fields['modelKwargs'] ?? null) ? $fields['modelKwargs'] : $this->modelKwargs;
        $this->promptCacheKey = isset($fields['promptCacheKey']) ? (string) $fields['promptCacheKey'] : $this->promptCacheKey;
        $this->promptCacheRetention = isset($fields['promptCacheRetention']) ? (string) $fields['promptCacheRetention'] : $this->promptCacheRetention;
        $this->promptCacheOptions = isset($fields['promptCacheOptions']) && is_array($fields['promptCacheOptions'])
            ? $fields['promptCacheOptions']
            : $this->promptCacheOptions;

        if ($this->apiKey === null) {
            $env = getenv('OPENAI_API_KEY');
            $this->apiKey = is_string($env) && $env !== '' ? $env : null;
        }

        // `kwargs` records what the CALLER passed, not the resolved property
        // values. Two reasons, and the second is the important one:
        //
        //  - a null default is noise in every serialized run record; and
        //  - a resolved default *masks a binding*. `bindTools($t,
        //    ['max_tokens' => 50])` was silently ignored because the default
        //    1024 was already sitting in `maxTokens`, so the wire spelling
        //    `max_tokens` never got a chance to be seen.
        //
        // The credential and the transport are excluded: `kwargs` is what the
        // tracer serialises, and neither belongs in a trace.
        $this->kwargs = array_intersect_key($fields, array_flip([
            'model', 'temperature', 'topP', 'frequencyPenalty', 'presencePenalty',
            'stop', 'stopSequences', 'maxTokens', 'user', 'seed', 'responseFormat', 'tools',
            'toolChoice', 'parallelToolCalls', 'organization', 'streamUsage',
            'maxRetries', 'timeout', 'reasoning', 'zdrEnabled', 'serviceTier', 'streaming', 'modelKwargs',
            'promptCacheKey', 'promptCacheRetention', 'promptCacheOptions', 'useResponsesApi',
        ]));
        $this->kwargs = array_filter($this->kwargs, static fn (mixed $v): bool => $v !== null);
    }

    public function llmType(): string
    {
        return 'openai-chat';
    }

    /**
     * @return list<string>
     */
    public static function lcNamespace(): array
    {
        return ['langchain', 'chat_models', 'openai'];
    }

    public function getName(): string
    {
        return 'ChatOpenAI';
    }

    /**
     * Copy another client's configuration and bound options onto this one.
     *
     * How the {@see ChatOpenAI} facade hands its state to the protocol client it
     * routes to.
     */
    protected function adoptStateFrom(self $other): void
    {
        foreach ([
            'model', 'apiKey', 'organization', 'baseUrl', 'temperature', 'topP', 'frequencyPenalty',
            'presencePenalty', 'stopSequences', 'maxTokens', 'streamUsage', 'supportsStrictToolCalling',
            'timeout', 'maxRetries', 'httpClient', 'reasoning', 'zdrEnabled', 'serviceTier', 'streaming',
            'modelKwargs', 'promptCacheKey', 'promptCacheRetention', 'promptCacheOptions', 'kwargs',
        ] as $property) {
            $this->{$property} = $other->{$property};
        }
    }

    /**
     * The endpoint used when no `baseUrl` is configured.
     */
    abstract protected function defaultUrl(): string;

    private const MCP_CREDENTIALS_REDACTED = '**REDACTED**';

    /**
     * `invocationParams()` with MCP tool credentials replaced, safe to put in a trace.
     *
     * Port of `_getInvocationParamsForTracing`. The request itself is unchanged.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function invocationParamsForTracing(array $options = []): array
    {
        $params = $this->invocationParams($options);
        if (is_array($params['tools'] ?? null)) {
            $params['tools'] = self::redactMcpTools($params['tools']);
        }

        return $params;
    }

    /**
     * Call options with MCP tool credentials replaced.
     *
     * Port of `_getCallOptionsForTracing`.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function callOptionsForTracing(array $options): array
    {
        if (is_array($options['tools'] ?? null)) {
            $options['tools'] = self::redactMcpTools($options['tools']);
        }

        return $options;
    }

    /**
     * @param array<int|string, mixed> $tools
     *
     * @return array<int|string, mixed>
     */
    private static function redactMcpTools(array $tools): array
    {
        return array_map(static function (mixed $tool): mixed {
            if (!is_array($tool) || ($tool['type'] ?? null) !== 'mcp') {
                return $tool;
            }
            foreach (['headers', 'authorization'] as $secret) {
                if (array_key_exists($secret, $tool)) {
                    $tool[$secret] = self::MCP_CREDENTIALS_REDACTED;
                }
            }

            return $tool;
        }, $tools);
    }

    /**
     * Parameters accepted by other clients that this one cannot send.
     *
     * @var list<string>
     */
    private const UNSUPPORTED = ['topK'];

    /**
     * @param array<string, mixed> $bag Any layer's raw options, in any spelling.
     */
    protected function rejectUnsupported(array $bag): void
    {
        // Canonicalise first: the wire spelling and the camelCase one are the
        // same mistake, and checking each layer's raw bag against canonical
        // names is what makes both of them loud.
        $bag = self::canonicalise($bag);

        foreach (self::UNSUPPORTED as $key) {
            if (array_key_exists($key, $bag)) {
                throw new \InvalidArgumentException(sprintf(
                    'Chat Completions has no %s parameter. It is available on some other '
                    . 'providers; passing it here would be silently ignored.',
                    $key,
                ));
            }
        }
    }

    /**
     * One tool, in the form it is stored when bound.
     *
     * Built-in tools and custom tools pass through as they are. A tool that
     * carries its own provider definition (`extras.providerToolDefinition`:
     * local shell, computer use, ...) is stored as that definition. Everything
     * else is rendered as a function tool, keeping `extras.defer_loading`.
     */
    private function bindableTool(mixed $tool, ?bool $strict): mixed
    {
        if (ResponsesTools::isBuiltInTool($tool) || ResponsesTools::isCustomTool($tool)) {
            return $tool;
        }

        if (ResponsesTools::hasProviderToolDefinition($tool)) {
            return ResponsesTools::providerToolDefinition($tool);
        }

        $converted = Tools::convert($tool, $strict);
        if ($tool instanceof StructuredTool && ($tool->extras['defer_loading'] ?? null) === true) {
            $converted['defer_loading'] = true;
        }

        return $converted;
    }

    /**
     * Offer tools to the model.
     *
     * Returns a **new** instance. The bound tools live in that instance's
     * `kwargs`, so a bind cannot leak into the next call — the same reason
     * `RunnableBinding` copies rather than mutates.
     *
     * `$kwargs` rides along, so call options can be bound at the same time as
     * the tools. That is the only way to set, say, a temperature *and* tools in
     * one expression, since the model itself is not mutated.
     *
     * @param list<mixed>          $tools
     * @param array<string, mixed> $kwargs
     */
    public function bindTools(array $tools, array $kwargs = []): static
    {
        $next = clone $this;

        $strict = $kwargs['strict'] ?? $this->supportsStrictToolCalling;
        $next->kwargs['tools'] = array_map(
            fn (mixed $tool): mixed => $this->bindableTool($tool, $strict === null ? null : (bool) $strict),
            array_values($tools),
        );

        // Remember the decision on the bound instance, so a later `bindTools()`
        // on it inherits it. Previously `supportsStrictToolCalling` stayed null
        // here, so chaining a second bind silently dropped the strictness the
        // first one established.
        $next->supportsStrictToolCalling = $strict === null ? null : (bool) $strict;

        foreach ($kwargs as $key => $value) {
            // `strict` is excluded, like `tools`. Its decision is already stored
            // in `$next->supportsStrictToolCalling` (set just above, from the
            // very same `$kwargs['strict']`), and nothing in `invocationParams()`
            // ever reads a `kwargs['strict']` key. Carrying a second copy of a
            // flag that changes nothing is how a caller comes to believe
            // re-binding `strict` per call does something.
            //
            // `ChatAnthropic` is the opposite case and does need its copy: it
            // reads `$this->kwargs['strict']` on a LATER bind so a chained bind
            // inherits the decision. There it is load-bearing; here it is not.
            if ($key === 'tools' || $key === 'strict') {
                continue;
            }
            $next->kwargs[$key] = $value;
        }

        // Refused at bind time rather than at the first request: a bind that
        // stores a setting the client cannot send reports success and then
        // does nothing with it.
        $this->rejectUnsupported($kwargs);

        return $next;
    }

    /**
     * POST with retries, raising the provider's own error on failure.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function post(array $params): array
    {
        // `JSON_THROW_ON_ERROR` rather than a bare cast. `json_encode` returns
        // `false` on failure and `(string) false` is `''`, so a request the port
        // could not represent was sent as an EMPTY BODY and came back as an
        // opaque 400 from the provider — local data loss disguised as a remote
        // API error. The cast is exactly what hid it.
        $body = Js::encode($params);

        $attempt = 0;
        while (true) {
            try {
                $response = $this->http()->post(
                    $this->url(),
                    $this->headers(),
                    $body,
                    $this->query(),
                    $this->timeout,
                );
            } catch (OpenAIException $e) {
                // Already one of ours — a missing API key, a rejected
                // parameter, a refusal. Re-wrapping it would replace an
                // actionable message ("set OPENAI_API_KEY") with a generic
                // one, which is a downgrade, not a normalisation.
                throw $e;
            } catch (\LangChain\Utils\Http\HttpException $e) {
                // Only a transport-level failure or a server-side status is
                // worth another round trip. A 4xx is the provider telling us the
                // request itself is wrong, and it will be wrong identically next
                // time.
                //
                // This catch retried EVERY `HttpException`, while
                // `postStream()` in this same class already filtered on
                // `status === 0 || 429 || >= 500`. Proved by counting requests
                // through a transport that raises a 400: EAGER made 4 requests
                // at `maxRetries=3`, STREAM made 1. So the same failure was four
                // times the latency and rate-limit cost on one path and not the
                // other — and the class docblock promised a 4xx is not retried.
                $retryable = $e->status === 0 || $e->status === 429 || $e->status >= 500;

                if (!$retryable || $attempt++ >= $this->maxRetries) {
                    // Converted like every other failure here. A raw
                    // `HttpException` escaping this method while a provider
                    // error, a rate limit and a 400 all raise
                    // `OpenAIException` means a caller catching that one type
                    // silently misses the case that matters most — the network
                    // being down, with no status and no provider context.
                    //
                    // The original is attached rather than discarded: "status 0"
                    // on its own does not say the connection was refused, and a
                    // network failure is exactly when the cause is wanted.
                    throw OpenAIException::fromResponse($e->body, $e->status, $this->url(), previous: $e);
                }
                $this->backoff($attempt);
                continue;
            } catch (\Throwable $e) {
                // `HttpClient` is public, so a third-party transport may raise
                // anything. Letting a bare exception escape means a caller
                // catching `OpenAIException` — the only type documented here —
                // silently misses it. Deliberately NOT retried: only a status
                // says whether the failure was transient.
                throw new OpenAIException(
                    'The HTTP transport raised ' . $e::class . ': ' . $e->getMessage(),
                    0,
                    '',
                    previous: $e,
                );
            }

            if ($response->isOk()) {
                return $response->json();
            }

            // 429 and 5xx are worth another attempt; 4xx is not.
            $retryable = $response->status === 429 || $response->status >= 500;
            if (!($retryable && $attempt++ < $this->maxRetries)) {
                throw OpenAIException::fromResponse($response->body, $response->status, $this->url());
            }

            $this->backoff($attempt);
        }
    }

    /**
     * POST and decode the event stream, retrying until the first byte arrives.
     *
     * Establishment is retried on the same terms as a non-streaming call:
     * transport errors and 429/5xx, up to `maxRetries`. A 429 on stream setup is
     * the single most common streaming failure and the one case that is
     * unambiguously safe to retry, because nothing has been delivered yet.
     *
     * Once a single byte has been yielded the stream is committed and the error
     * propagates. Reconnecting there would re-emit tokens the caller has already
     * seen, which is worse than failing.
     *
     * @param array<string, mixed> $params
     *
     * @return \Generator<int, array<string, mixed>>
     */
    protected function postStream(array $params): \Generator
    {
        // `JSON_THROW_ON_ERROR` rather than a bare cast. `json_encode` returns
        // `false` on failure and `(string) false` is `''`, so a request the port
        // could not represent was sent as an EMPTY BODY and came back as an
        // opaque 400 from the provider — local data loss disguised as a remote
        // API error. The cast is exactly what hid it.
        $body = Js::encode($params);
        $attempt = 0;

        while (true) {
            $parser = new SseParser();
            $delivered = false;

            try {
                $raw = $this->http()->postStream($this->url(), $this->headers(), $body, $this->query(), $this->timeout);

                // The drain has to live inside the `try`, not just the call that
                // creates the generator. `postStream()` is a generator function:
                // calling it runs none of its body, so wrapping the call alone
                // left every connect failure and every non-2xx status to escape
                // as a bare `HttpException`.
                foreach ($raw as $bytes) {
                    // A zero-length read is not delivery. Some transports hand
                    // one over before the body starts; treating that as a
                    // committed stream would refuse a retry that is entirely
                    // safe.
                    if ($bytes !== '') {
                        $delivered = true;
                    }

                    yield from $this->decode($parser->feed($bytes));
                }

                yield from $this->decode($parser->flush());

                return;
            } catch (OpenAIException $e) {
                throw $e;
            } catch (\LangChain\Utils\Http\HttpException $e) {
                $retryable = !$delivered && ($e->status === 0 || $e->status === 429 || $e->status >= 500);

                if (!($retryable && $attempt++ < $this->maxRetries)) {
                    // `previous` attached for the same reason as on the eager
                    // path: "status 0" does not say the connection was
                    // *refused*, and a transport failure is exactly when the
                    // cause is wanted.
                    throw OpenAIException::fromResponse($e->body, $e->status, $this->url(), previous: $e);
                }

                $this->backoff($attempt);
            } catch (\Throwable $e) {
                // `HttpClient` is a public interface, so a third-party transport
                // may raise anything. Letting a bare `LogicException` from one
                // escape means a caller catching `OpenAIException` — the only
                // type these clients document — silently misses it, and a
                // transport fault never gets the retry it would otherwise have
                // had. Retrying is deliberately NOT attempted here: only a
                // status tells us whether the failure was transient.
                throw new OpenAIException(
                    'The HTTP transport raised ' . $e::class . ': ' . $e->getMessage(),
                    0,
                    '',
                    previous: $e,
                );
            }
        }
    }

    /**
     * @param \Generator<int, string> $payloads
     *
     * @return \Generator<int, array<string, mixed>>
     */
    protected function decode(\Generator $payloads): \Generator
    {
        foreach ($payloads as $payload) {
            try {
                $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new OpenAIException(
                    'Received a malformed event from the OpenAI stream: ' . $e->getMessage() . '. Payload: ' . $payload,
                    0,
                    $payload,
                );
            }

            if (!is_array($decoded)) {
                continue;
            }

            // A provider can push an error down the stream instead of closing
            // it, as an `error` object with no `choices`. Nothing downstream
            // looks for it — the consumer sees no choices and no usage and
            // skips the event — so the stream simply ends and the caller is
            // handed a truncated answer with no idea anything went wrong.
            if (isset($decoded['error']) && is_array($decoded['error'])) {
                throw OpenAIException::fromResponse(
                    (string) json_encode($decoded),
                    0,
                    $this->url(),
                );
            }

            yield $decoded;
        }
    }

    /**
     * Exponential backoff between retries.
     *
     * Jitter is deliberately absent: this client is synchronous and
     * single-threaded, so the thundering herd it would need to defend against
     * cannot form here.
     */
    /**
     * Wait before retrying attempt `$attempt`.
     *
     * A method rather than an inline `usleep` so a test can override it and
     * assert on the retry *count* without paying the wall-clock cost. Two tests
     * at one second each is a suite that is visibly slower for no information.
     */
    protected function backoff(int $attempt): void
    {
        if ($this->backoffHandler !== null) {
            ($this->backoffHandler)($attempt);

            return;
        }

        usleep((int) (min(2 ** ($attempt - 1), 8) * 1_000_000));
    }

    protected function http(): HttpClient
    {
        return $this->httpClient ??= new GuzzleHttpClient();
    }

    protected function url(): string
    {
        return $this->baseUrl !== null ? $this->baseUrl : $this->defaultUrl();
    }

    /**
     * Query-string parameters sent on every request (Azure's `api-version`).
     *
     * @return array<string, mixed>
     */
    protected function query(): array
    {
        return [];
    }

    /**
     * The capability profile for this model (`[]` when unknown).
     *
     * Port of `get profile()` on `BaseChatOpenAI`: `PROFILES[this.model] ?? {}`.
     *
     * @return array<string, int|bool>
     */
    public function profile(): array
    {
        return Profiles::for($this->model);
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        if ($this->apiKey === null) {
            throw new OpenAIException(
                'No OpenAI API key. Pass one to the constructor or set the OPENAI_API_KEY environment variable.',
                0,
                '',
            );
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];

        if ($this->organization !== null) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        return $headers;
    }
}
