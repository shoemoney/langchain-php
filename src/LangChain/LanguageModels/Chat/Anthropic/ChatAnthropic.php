<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Chat\Anthropic\Utils\MessageInputs;
use LangChain\LanguageModels\Chat\Anthropic\Utils\MessageOutputs;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Js;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\SseParser;

/**
 * A chat model backed by Anthropic's Messages API.
 *
 * Port of `ChatAnthropic` from `@langchain/anthropic`.
 *
 * The translation layer is in {@see MessageInputs} and {@see MessageOutputs};
 * this class owns the request shape, the auth headers, and the event stream.
 * Those are the three things that are genuinely Anthropic's own and are not
 * shared with any other provider. *
 * ## Retries, and where the boundary sits
 *
 * {@see self::$maxRetries} covers **request establishment only**:
 *
 *  - a non-streaming call is retried on a transport error or a 429/5xx, up to
 *    `maxRetries` extra attempts. A 4xx is not retried — it will fail
 *    identically every time, and retrying only delays the error.
 *  - a stream is retried the same way **until the first byte is yielded**. A
 *    transient 429 while opening a stream is the most common streaming failure
 *    and the one case that is unambiguously safe to retry, because nothing has
 *    been delivered.
 *  - once a byte has been yielded the stream is committed and the error
 *    propagates. Reconnecting there would re-emit tokens the caller has already
 *    been handed, which is worse than failing.
 *
 * Mid-stream retry is deliberately not offered. A provider that supports
 * resuming from a cursor would need that cursor in the response, and
 * pretending otherwise would produce duplicate output.

 *
 * ## Auth is a header, not a scheme
 *
 * Anthropic authenticates with `x-api-key`, not `Authorization: Bearer`, and
 * requires an explicit `anthropic-version`. A client that sends a bearer token
 * gets a 401 with a message that does not obviously say "wrong header", which
 * is why the version header is pinned here rather than left to the caller.
 */
class ChatAnthropic extends BaseChatModel
{
    public const DEFAULT_API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * The Messages API version this client speaks.
     *
     * Pinned, not configurable-by-default: the wire format of tool use and of
     * the event stream both changed across versions, and a silently negotiated
     * version would make the same code behave differently on two days.
     */
    public const API_VERSION = '2023-06-01';

    private const CLASS_NAME = 'ChatAnthropic';

    /**
     * Every wire spelling of every call option, mapped to its canonical name.
     *
     * @see ChatOpenAI::KEY_ALIASES() for why this is one shared table.
     */
    private const KEY_ALIASES = [
        'max_tokens' => 'maxTokens',
        'top_p' => 'topP',
        'top_k' => 'topK',
        'stop' => 'stopSequences',
        'stop_sequences' => 'stopSequences',
        'tool_choice' => 'toolChoice',
    ];

    public string $model = 'claude-sonnet-4-5';

    public ?string $apiKey = null;

    public ?string $baseUrl = null;

    public ?float $temperature = null;

    public ?float $topP = null;

    public ?float $topK = null;

    public int $maxTokens = 1024;

    /** @var list<string>|null */
    public ?array $stopSequences = null;

    /**
     * Extra headers to send, as a name => value map.
     *
     * Not a list of single-entry arrays, which is what this used to declare.
     * `headers()` unions it with `['x-api-key' => ..., ...]`, and a union of a
     * list with a map is just the list — so a caller who followed the declared
     * type and passed `[['X-Foo' => 'bar']]` got an array-valued "header" that
     * Guzzle rejects at request time, far from the constructor that took it.
     *
     * @var array<string, string|string[]>
     */
    public array $defaultHeaders = [];

    public bool $streamUsage = true;

    public ?float $timeout = null;

    public int $maxRetries = 2;

    public ?HttpClient $httpClient = null;

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);

        // One canonicalised bag feeds every layer below.
        $fields = self::canonicalise($fields);

        // A model name is required. The TypeScript original gets this from its
        // type system, which PHP has no equivalent of, so without an explicit
        // check an empty name is sent and the provider answers 400 about the
        // request rather than about the constructor.
        if (trim((string) ($fields['model'] ?? $this->model)) === '') {
            throw new \InvalidArgumentException('ChatAnthropic requires a non-empty model name.');
        }

        $this->model = (string) ($fields['model'] ?? $this->model);
        $this->apiKey = isset($fields['apiKey']) ? (string) $fields['apiKey'] : $this->apiKey;
        $this->baseUrl = isset($fields['baseUrl']) ? (string) $fields['baseUrl'] : $this->baseUrl;
        $this->temperature = isset($fields['temperature']) ? (float) $fields['temperature'] : $this->temperature;
        $this->topP = isset($fields['topP']) ? (float) $fields['topP'] : $this->topP;
        $this->topK = isset($fields['topK']) ? (float) $fields['topK'] : $this->topK;
        $this->maxTokens = (int) ($fields['maxTokens'] ?? $this->maxTokens);
        $this->stopSequences = isset($fields['stopSequences']) ? array_values((array) $fields['stopSequences']) : $this->stopSequences;
        $this->defaultHeaders = (array) ($fields['defaultHeaders'] ?? []);
        $this->streamUsage = (bool) ($fields['streamUsage'] ?? $this->streamUsage);
        $this->timeout = isset($fields['timeout']) ? (float) $fields['timeout'] : $this->timeout;
        $this->maxRetries = (int) ($fields['maxRetries'] ?? $this->maxRetries);
        $this->httpClient = $fields['httpClient'] ?? $this->httpClient;

        if ($this->apiKey === null) {
            $env = getenv('ANTHROPIC_API_KEY');
            $this->apiKey = is_string($env) && $env !== '' ? $env : null;
        }

        // Caller-supplied values only — see the note in ChatOpenAI. Recording
        // resolved defaults here would mask a later binding of the same key.
        $this->kwargs = array_intersect_key($fields, array_flip([
            'model', 'temperature', 'topP', 'topK', 'maxTokens', 'stopSequences',
            'tools', 'toolChoice', 'streamUsage', 'maxRetries', 'timeout',
        ]));
        $this->kwargs = array_filter($this->kwargs, static fn (mixed $v): bool => $v !== null);
    }

    public function llmType(): string
    {
        return 'anthropic-chat';
    }

    /** @return list<string> */
    public static function lcNamespace(): array
    {
        return ['langchain', 'chat_models', 'anthropic'];
    }

    public function getName(): string
    {
        return 'ChatAnthropic';
    }

    /**
     * The request body.
     *
     * `max_tokens` is required by the API and has no useful default upstream —
     * it is the one parameter a caller must think about. It defaults to 1024
     * here rather than being left unset, because an unset value produces a 400
     * that names the parameter but not the fix.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function invocationParams(array $options = [], array $extra = []): array
    {
        // Same key-spelling normalisation as ChatOpenAI, and the same reason:
        // applying it to per-call options only made a bound `max_tokens` vanish.
        $options = $this->normaliseKeys($options);
        $bound = $this->normaliseKeys($this->kwargs);

        $params = [
            'model' => $this->pick($options, 'model') ?? $bound['model'] ?? $this->model,
            'max_tokens' => $this->pick($options, 'maxTokens', 'max_tokens') ?? $bound['maxTokens'] ?? $this->maxTokens,
            'temperature' => $this->pick($options, 'temperature') ?? $bound['temperature'] ?? $this->temperature,
            'top_p' => $this->pick($options, 'topP', 'top_p') ?? $bound['topP'] ?? $this->topP,
            'top_k' => $this->pick($options, 'topK', 'top_k') ?? $bound['topK'] ?? $this->topK,
            'stop_sequences' => $this->pick($options, 'stopSequences', 'stop_sequences')
                ?? $bound['stopSequences']
                ?? $this->stopSequences,
            // Converted on BOTH arms. `bindTools()` converts on the way in, but
            // per-call tools and constructor tools arrive raw — and the request
            // body proved it: Anthropic was sent the serialised PHP object
            // `{"lc":1,"type":"constructor",...,"kwargs":[]}` with no `name`,
            // `description` or `input_schema` at all. `convertTool()` passes an
            // already-shaped array through unchanged, so this is safe for both.
            'tools' => self::convertTools($this->pick($options, 'tools'))
                ?? self::convertTools($bound['tools'] ?? null),
            // Formatted through the same path as a per-call choice, so the
            // "must name an offered tool" guard below cannot be stepped around
            // by binding the choice instead of passing it as an option.
            'tool_choice' => $this->toolChoiceOf($options) ?? $this->toolChoiceOf($bound),
        ];

        // A tool choice naming a tool that was not offered is a mistake worth
        // catching here. The API rejects it, but its error does not say which
        // of the offered tools was meant.
        $choice = $params['tool_choice'] ?? null;
        if (is_array($choice) && ($choice['type'] ?? null) === 'tool') {
            $available = is_array($params['tools'] ?? null) ? $params['tools'] : [];
            $names = array_map(static fn (array $t): string => (string) ($t['name'] ?? ''), $available);

            if (!in_array((string) ($choice['name'] ?? ''), $names, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Anthropic tool_choice references "%s", but that tool is not available.',
                    (string) ($choice['name'] ?? ''),
                ));
            }
        }

        if (($extra['streaming'] ?? false) === true) {
            $params['stream'] = true;
        }

        // An empty `tools` list is not the same as no `tools` key: providers
        // reject `tools: []` outright. `bindTools([], $kwargs)` — binding call
        // options without offering anything — is a legitimate way to use this,
        // so the empty list is dropped rather than sent.
        if (($params['tools'] ?? null) === []) {
            unset($params['tools']);
        }

        return array_filter($params, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function toolChoiceOf(array $options): mixed
    {
        $choice = $options['toolChoice'] ?? $options['tool_choice'] ?? null;

        return $choice === null ? null : MessageInputs::formatToolChoice($choice);
    }

    /**
     * Collapse the wire spelling of each known key onto its canonical name.
     *
     * @param array<string, mixed> $bag
     *
     * @return array<string, mixed>
     */
    private function normaliseKeys(array $bag): array
    {
        return self::canonicalise($bag);
    }

    /**
     * Fold every wire spelling onto its canonical name.
     *
     * One table shared by the constructor, bound kwargs and per-call options.
     * As three separate hand-written lists they drifted, and the constructor
     * reading only camelCase meant `new ChatAnthropic(['max_tokens' => 99])`
     * was accepted and then ignored.
     *
     * @param array<string, mixed> $bag
     *
     * @return array<string, mixed>
     */
    public static function canonicalise(array $bag): array
    {
        foreach (self::KEY_ALIASES as $wire => $camel) {
            if (array_key_exists($wire, $bag) && !array_key_exists($camel, $bag)) {
                $bag[$camel] = $bag[$wire];
            }
        }

        return $bag;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function pick(array $options, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null) {
                return $options[$key];
            }
        }

        return null;
    }

    /**
     * Convert caller-supplied tools, whatever shape they arrive in.
     *
     * One implementation, used by `bindTools()` and by `invocationParams()`, so
     * the two cannot drift. A tool that is already a shaped array passes
     * through `MessageInputs::convertTool()` unchanged, which is what makes it
     * safe for the bound path to call this too.
     *
     * @param list<mixed>|null $tools
     *
     * @return list<array<string, mixed>>|null
     */
    private static function convertTools(?array $tools, ?bool $strict = null): ?array
    {
        if ($tools === null || $tools === []) {
            return null;
        }

        $out = [];
        foreach (array_values($tools) as $tool) {
            $out[] = MessageInputs::convertTool($tool, $strict);
        }

        return $out;
    }

    /**
     * Offer tools to the model.
     *
     * Returns a new instance rather than mutating `$this`; see
     * {@see \LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI::bindTools()} for
     * why that is load-bearing.
     *
     * @param list<mixed>          $tools
     * @param array<string, mixed> $kwargs
     */
    public function bindTools(array $tools, array $kwargs = []): static
    {
        $next = clone $this;

        $strict = $kwargs['strict'] ?? $this->kwargs['strict'] ?? null;
        $next->kwargs['tools'] = self::convertTools($tools, $strict === null ? null : (bool) $strict);

        // Remember the decision so a later bind on this instance inherits it,
        // matching the OpenAI client. Upstream's `bindTools` passes `strict`
        // straight through `withConfig`, so it persists the same way.
        if ($strict !== null) {
            $next->kwargs['strict'] = $strict;
        }

        foreach ($kwargs as $key => $value) {
            if ($key === 'tools' || $key === 'strict') {
                continue;
            }
            $next->kwargs[$key] = $value;
        }

        return $next;
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        // Explicit assignment, mirroring ChatOpenAI.
        //
        // `invocationParams() + convert()` reads like a merge but is a union,
        // and the LEFT side wins — so a model parameter ever named `messages` or
        // `system` would silently discard the conversation. Assigning the two
        // conversation keys outright makes the conversation unconditional, and
        // there is no ordering to get wrong.
        $params = $this->invocationParams($options);
        $converted = MessageInputs::convert($messages);
        if (isset($converted['system'])) {
            $params['system'] = $converted['system'];
        }
        $params['messages'] = $converted['messages'];

        $payload = $this->post($params);
        $message = MessageOutputs::responseToMessage($payload);

        $generationInfo = array_filter(
            ['stop_reason' => $payload['stop_reason'] ?? null],
            static fn (mixed $v): bool => $v !== null,
        );

        $text = is_string($message->content) ? $message->content : '';

        return new ChatResult(
            [new ChatGeneration($message, $text, $generationInfo)],
            $this->llmOutputFromUsage($message),
        );
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, ChatGenerationChunk>
     */
    protected function streamResponseChunks(
        array $messages,
        array $options = [],
        ?CallbackManagerForLLMRun $runManager = null,
    ): \Generator {
        $params = $this->invocationParams($options, ['streaming' => true]);
        $converted = MessageInputs::convert($messages);
        if (isset($converted['system'])) {
            $params['system'] = $converted['system'];
        }
        $params['messages'] = $converted['messages'];

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
                $raw = $this->http()->postStream($this->url(), $this->headers(), $body, [], $this->timeout);

                // The drain lives inside the `try`: `postStream()` is a generator
                // function, so calling it runs none of its body and a `try`
                // around the call alone never sees a connect failure or a
                // non-2xx status.
                foreach ($raw as $bytes) {
                    if ($bytes !== '') {
                        $delivered = true;
                    }

                    yield from $this->consume($this->decode($parser->feed($bytes)), $runManager);
                }

                yield from $this->consume($this->decode($parser->flush()), $runManager);

                return;
            } catch (AnthropicException $e) {
                throw $e;
            } catch (HttpException $e) {
                // Retry establishment only. Once a byte is out the stream is
                // committed — reconnecting would repeat tokens the caller has
                // already been handed.
                $retryable = !$delivered && ($e->status === 0 || $e->status === 429 || $e->status >= 500);

                if (!($retryable && $attempt++ < $this->maxRetries)) {
                    // `previous` attached for the same reason as on the eager
                    // path: "status 0" does not say the connection was
                    // *refused*, and a transport failure is exactly when the
                    // cause is wanted.
                    throw AnthropicException::fromResponse($e->body, $e->status, $this->url(), previous: $e);
                }

                $this->backoff($attempt);
            } catch (\Throwable $e) {
                // `HttpClient` is a public interface, so a third-party transport
                // may raise anything. Letting a bare exception from one escape
                // means a caller catching `AnthropicException` — the only type
                // these clients document — silently misses it. Retrying is
                // deliberately NOT attempted: only a status says whether the
                // failure was transient.
                throw new AnthropicException(
                    'The HTTP transport raised ' . $e::class . ': ' . $e->getMessage(),
                    0,
                    '',
                    previous: $e,
                );
            }
        }
    }

    /**
     * Decode `data:` payloads into events.
     *
     * The SSE parser yields the raw payload text; the event *type* lives inside
     * that JSON rather than in the `event:` field, so decoding here is enough
     * to drive the state machine without tracking two parallel framings.
     *
     * A payload that is not JSON is fatal rather than skipped: a stream that has
     * silently dropped an event is a stream that has silently dropped part of
     * the answer, and the only evidence is the malformed payload itself.
     *
     * @param \Generator<int, string> $payloads
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function decode(\Generator $payloads): \Generator
    {
        foreach ($payloads as $payload) {
            try {
                $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new AnthropicException(
                    'Received a malformed event from the Anthropic stream: ' . $e->getMessage() . '. Payload: ' . $payload,
                    0,
                    $payload,
                );
            }

            if (!is_array($decoded)) {
                continue;
            }

            // Anthropic signals a mid-stream failure the same way — an
            // `error` object rather than a close. It must not be mistaken for a
            // lifecycle event, or the stream ends silently on a truncated answer.
            if (isset($decoded['error']) && is_array($decoded['error'])) {
                throw AnthropicException::fromResponse(
                    (string) json_encode($decoded),
                    0,
                    $this->url(),
                );
            }

            yield $decoded;
        }
    }

    /**
     * @param \Generator<int, array<string, mixed>> $events
     *
     * @return \Generator<int, ChatGenerationChunk>
     */
    private function consume(\Generator $events, ?CallbackManagerForLLMRun $runManager): \Generator
    {
        foreach ($events as $event) {
            // `message_start` and `message_delta` carry usage, which is the
            // authoritative count — Anthropic sends the input tokens there and
            // the output tokens in a separate later event, so the two have to be
            // summed as they arrive.
            //
            // Gated on `streamUsage`, matching OpenAI's client and upstream.
            // The flag used to be declared, defaulted and recorded in `kwargs`
            // (so it was visible in every trace) while nothing read it: setting
            // it to false changed nothing at all, and the caller had no way to
            // tell.
            $usage = $this->streamUsage ? MessageOutputs::usageFromEvent($event) : [];
            if ($usage !== []) {
                yield new ChatGenerationChunk(
                    new \LangChain\Messages\AIMessageChunk([
                        'content' => '',
                        'response_metadata' => ['usage_metadata' => $usage],
                    ]),
                    '',
                );
            }

            $chunk = MessageOutputs::eventToChunk($event);
            if ($chunk === null) {
                continue;
            }

            $generationInfo = array_filter(
                ['stop_reason' => $event['delta']['stop_reason'] ?? null],
                static fn (mixed $v): bool => $v !== null,
            );

            $text = is_string($chunk->content) ? $chunk->content : '';
            $generation = new ChatGenerationChunk($chunk, $text, $generationInfo);

            yield $generation;

            if ($text !== '') {
                $runManager?->handleLLMNewToken($text, ['chunk' => $generation]);
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function post(array $params): array
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
                $response = $this->http()->post($this->url(), $this->headers(), $body, [], $this->timeout);
            } catch (AnthropicException $e) {
                // Already one of ours — a missing API key, a rejected tool
                // choice, a refusal. Re-wrapping it would replace an actionable
                // message with a generic one, which is a downgrade.
                throw $e;
            } catch (HttpException $e) {
                // Only a transport-level failure or a server-side status is
                // worth another round trip; a 4xx will be wrong identically
                // next time. See the same fix in ChatOpenAI::post().
                $retryable = $e->status === 0 || $e->status === 429 || $e->status >= 500;

                if (!$retryable || $attempt++ >= $this->maxRetries) {
                    // Converted like every other failure here, with the original
                    // attached. A raw `HttpException` escaping while a provider
                    // error, a rate limit and a 400 all raise
                    // `AnthropicException` means a caller catching that one type
                    // misses exactly the case that matters most: the network
                    // being down. "status 0" alone does not say the connection
                    // was *refused*.
                    throw AnthropicException::fromResponse($e->body, $e->status, $this->url(), previous: $e);
                }
                $this->backoff($attempt);
                continue;
            } catch (\Throwable $e) {
                // `HttpClient` is public, so a third-party transport may raise
                // anything; see the note in ChatOpenAI.
                throw new AnthropicException(
                    'The HTTP transport raised ' . $e::class . ': ' . $e->getMessage(),
                    0,
                    '',
                    previous: $e,
                );
            }

            if ($response->isOk()) {
                return $response->json();
            }

            $retryable = $response->status === 429 || $response->status >= 500;
            if (!($retryable && $attempt++ < $this->maxRetries)) {
                throw AnthropicException::fromResponse($response->body, $response->status, $this->url());
            }

            $this->backoff($attempt);
        }
    }

    /**
     * Wait before retrying attempt `$attempt`.
     *
     * A method rather than an inline `usleep` so a test can override it and
     * assert on the retry *count* without paying the wall-clock cost. Two tests
     * at one second each is a suite that is visibly slower for no information.
     */
    protected function backoff(int $attempt): void
    {
        usleep((int) (min(2 ** ($attempt - 1), 8) * 1_000_000));
    }

    private function http(): HttpClient
    {
        return $this->httpClient ??= new GuzzleHttpClient();
    }

    private function url(): string
    {
        return $this->baseUrl !== null ? $this->baseUrl : self::DEFAULT_API_URL;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        if ($this->apiKey === null) {
            throw new AnthropicException(
                'No Anthropic API key. Pass one to the constructor or set the ANTHROPIC_API_KEY environment variable.',
                0,
                '',
            );
        }

        // The PINNED map goes on the LEFT so it wins.
        //
        // PHP's `+` keeps the left-hand side on a key collision, so
        // `$this->defaultHeaders + [pinned]` let a caller silently override the
        // pinned values. Measured: constructing with
        // `defaultHeaders: ['anthropic-version' => '1999-01-01']` sent
        // `1999-01-01` while the pinned const is `2023-06-01` — directly
        // contradicting the API_VERSION docblock, which says the version is
        // "Pinned, not configurable-by-default ... a silently negotiated
        // version would make the same code behave differently on two days".
        // That is precisely the harm it names: the tool-use wire format and the
        // event stream both changed across versions, so a caller who sets the
        // header by accident gets different parsing on a different day, with no
        // error.
        //
        // Caller extras still merge — only the pinned KEYS are protected.
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ] + $this->defaultHeaders;
    }
}
