<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenRouter;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Chat\OpenRouter\Converters\Messages;
use LangChain\LanguageModels\Chat\OpenRouter\Converters\Tools;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\Errors;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\OpenRouterAuthError;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\OpenRouterError;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\Stream;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\StreamEvents;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\StructuredOutput as OpenRouterStructuredOutput;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\LanguageModels\StructuredOutput;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Runnables\Runnable;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\AsyncCaller;
use LangChain\Utils\Env;
use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Http\SseParser;
use LangChain\Utils\Js;

/**
 * OpenRouter chat model integration.
 *
 * Port of `ChatOpenRouter` from `@langchain/openrouter` (`chat_models/index.ts`).
 *
 * Talks to the OpenRouter REST API directly (no SDK) and supports tool calling,
 * structured output and streaming. Any model available on OpenRouter can be used
 * by passing its identifier (e.g. `"anthropic/claude-4-sonnet"`) as the model.
 *
 * This is a model in its own right, not a `ChatOpenAI` subclass: it owns its
 * request body (OpenRouter-only knobs such as `top_k`, `models`, `route`,
 * `plugins`, `session_id`), its error taxonomy ({@see OpenRouterError}), its
 * attribution headers, and its reasoning handling. Wire translation is shared
 * with the OpenAI Chat Completions converters through {@see Messages}.
 *
 * ## Differences from the TypeScript original
 *
 *  - No `AbortSignal`: PHP has no equivalent, so nothing is wired to `options.signal`.
 *  - Retries go through {@see AsyncCaller}, as upstream does, with `maxRetries`
 *    defaulting to 6. A `sleeper` field is accepted so a test need not wait out
 *    the backoff; it is not in upstream. A streaming connect failure carries no
 *    response headers, so a `Retry-After` on a streaming 429 is not seen.
 *  - A usage-only streaming chunk (no `choices`) is folded in as a metadata-only
 *    chunk when `streamUsage` is on. Upstream skips every chunk without a delta,
 *    which would drop the trailing usage report.
 *  - `withStructuredOutput` takes JSON Schema arrays only (this port has no Zod /
 *    Standard Schema path) and does not validate the parsed value; the
 *    `ls_structured_output_format` tracing hint is not attached.
 *  - `apiKey` is never written into `kwargs()` (the serialized trace payload).
 *  - Tools are converted when bound as well as when sent, so a `StructuredTool`
 *    never reaches the serialized trace.
 */
class ChatOpenRouter extends BaseChatModel
{
    public const DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1';

    /** Model identifier, e.g. `"anthropic/claude-4-sonnet"`. */
    public string $model = '';

    /** OpenRouter API key. Falls back to the `OPENROUTER_API_KEY` env var. */
    public string $apiKey = '';

    /** Base URL for the API. */
    public string $baseURL = self::DEFAULT_BASE_URL;

    public ?float $temperature = null;

    public ?int $maxTokens = null;

    public ?float $topP = null;

    public ?int $topK = null;

    public ?float $frequencyPenalty = null;

    public ?float $presencePenalty = null;

    public ?float $repetitionPenalty = null;

    public ?float $minP = null;

    public ?float $topA = null;

    public ?int $seed = null;

    /** @var list<string>|null */
    public ?array $stop = null;

    /** @var array<string, int|float>|null */
    public ?array $logitBias = null;

    public ?int $topLogprobs = null;

    public ?string $user = null;

    /** @var list<string>|null */
    public ?array $transforms = null;

    /** @var list<string>|null */
    public ?array $models = null;

    /** Routing strategy (currently only `"fallback"`). */
    public ?string $route = null;

    /** @var array<string, mixed>|null */
    public ?array $provider = null;

    /** @var list<array<string, mixed>>|null */
    public ?array $plugins = null;

    /** Groups related requests together on OpenRouter's side. */
    public ?string $sessionId = null;

    /** @var array<string, mixed>|null */
    public ?array $trace = null;

    /** Application URL for attribution; maps to the `HTTP-Referer` header. */
    public string $siteUrl = 'https://docs.langchain.com';

    /** Application title for attribution; maps to the `X-Title` header. */
    public string $siteName = 'LangChain';

    /**
     * Marketplace categories; maps to the `X-OpenRouter-Categories` header.
     *
     * @var list<string>|null
     */
    public ?array $appCategories = null;

    /**
     * Extra params merged into the API request body.
     *
     * @var array<string, mixed>|null
     */
    public ?array $modelKwargs = null;

    /** Whether to include token usage in streaming chunks. */
    public bool $streamUsage = true;

    public int $maxRetries = 6;

    public ?float $timeout = null;

    public ?HttpClient $httpClient = null;

    /** @var (\Closure(int|float): void)|null Receives each retry backoff in milliseconds. */
    public ?\Closure $sleeper = null;

    /**
     * Fields that may be set at construction and are recorded for serialization.
     * `apiKey` is deliberately absent.
     */
    private const SERIALIZED_FIELDS = [
        'model', 'baseURL', 'temperature', 'maxTokens', 'topP', 'topK', 'frequencyPenalty', 'presencePenalty',
        'repetitionPenalty', 'minP', 'topA', 'seed', 'stop', 'logitBias', 'topLogprobs', 'user', 'transforms',
        'models', 'route', 'provider', 'plugins', 'sessionId', 'trace', 'siteUrl', 'siteName', 'appCategories',
        'modelKwargs', 'streamUsage',
    ];

    /**
     * @param string|array<string, mixed> $modelOrFields A model name, or the full field map.
     * @param array<string, mixed>        $fieldsArg     Extra fields when the first argument is a model name.
     */
    public function __construct(string|array $modelOrFields = [], array $fieldsArg = [])
    {
        $fields = is_string($modelOrFields)
            ? [...$fieldsArg, 'model' => $modelOrFields]
            : $modelOrFields;

        parent::__construct($fields);

        $apiKey = $fields['apiKey'] ?? Env::getEnvironmentVariable('OPENROUTER_API_KEY');
        if ($apiKey === null || $apiKey === '') {
            throw new OpenRouterAuthError(
                'OpenRouter API key is required. Get one at https://openrouter.ai/keys and set it via the `apiKey` parameter or the OPENROUTER_API_KEY environment variable.'
            );
        }
        $this->apiKey = (string) $apiKey;

        if (!isset($fields['model']) || $fields['model'] === '') {
            throw new \InvalidArgumentException('ChatOpenRouter requires a `model` parameter, e.g. "openai/gpt-4o-mini".');
        }
        $this->model = (string) $fields['model'];
        $this->baseURL = (string) ($fields['baseURL'] ?? self::DEFAULT_BASE_URL);
        $this->temperature = isset($fields['temperature']) ? (float) $fields['temperature'] : null;
        $this->maxTokens = isset($fields['maxTokens']) ? (int) $fields['maxTokens'] : null;
        $this->topP = isset($fields['topP']) ? (float) $fields['topP'] : null;
        $this->topK = isset($fields['topK']) ? (int) $fields['topK'] : null;
        $this->frequencyPenalty = isset($fields['frequencyPenalty']) ? (float) $fields['frequencyPenalty'] : null;
        $this->presencePenalty = isset($fields['presencePenalty']) ? (float) $fields['presencePenalty'] : null;
        $this->repetitionPenalty = isset($fields['repetitionPenalty']) ? (float) $fields['repetitionPenalty'] : null;
        $this->minP = isset($fields['minP']) ? (float) $fields['minP'] : null;
        $this->topA = isset($fields['topA']) ? (float) $fields['topA'] : null;
        $this->seed = isset($fields['seed']) ? (int) $fields['seed'] : null;
        $this->stop = isset($fields['stop']) ? array_values((array) $fields['stop']) : null;
        $this->logitBias = isset($fields['logitBias']) ? (array) $fields['logitBias'] : null;
        $this->topLogprobs = isset($fields['topLogprobs']) ? (int) $fields['topLogprobs'] : null;
        $this->user = isset($fields['user']) ? (string) $fields['user'] : null;
        $this->transforms = isset($fields['transforms']) ? array_values((array) $fields['transforms']) : null;
        $this->models = isset($fields['models']) ? array_values((array) $fields['models']) : null;
        $this->route = isset($fields['route']) ? (string) $fields['route'] : null;
        $this->provider = isset($fields['provider']) ? (array) $fields['provider'] : null;
        $this->plugins = isset($fields['plugins']) ? array_values((array) $fields['plugins']) : null;
        $this->sessionId = $fields['sessionId'] ?? Env::getEnvironmentVariable('OPENROUTER_SESSION_ID');
        $this->trace = isset($fields['trace']) ? (array) $fields['trace'] : null;
        $this->siteUrl = (string) ($fields['siteUrl'] ?? 'https://docs.langchain.com');
        $this->siteName = (string) ($fields['siteName'] ?? 'LangChain');
        $this->appCategories = isset($fields['appCategories']) ? array_values((array) $fields['appCategories']) : null;
        $this->modelKwargs = isset($fields['modelKwargs']) ? (array) $fields['modelKwargs'] : null;
        $this->streamUsage = (bool) ($fields['streamUsage'] ?? true);
        $this->maxRetries = (int) ($fields['maxRetries'] ?? $this->maxRetries);
        $this->timeout = isset($fields['timeout']) ? (float) $fields['timeout'] : null;
        $this->httpClient = $fields['httpClient'] ?? null;
        $this->sleeper = isset($fields['sleeper']) ? \Closure::fromCallable($fields['sleeper']) : null;

        // Caller-supplied values only, so a resolved default never masks a
        // later binding of the same key.
        $this->kwargs += array_filter(
            array_intersect_key($fields, array_flip(self::SERIALIZED_FIELDS)),
            static fn (mixed $v): bool => $v !== null,
        );
    }

    public function llmType(): string
    {
        return 'openrouter';
    }

    /** @return list<string> */
    public static function lcNamespace(): array
    {
        return ['langchain', 'chat_models', 'openrouter'];
    }

    public static function lcName(): string
    {
        return 'ChatOpenRouter';
    }

    /**
     * Static capability profile (context size, tool support, etc.) for the current model.
     *
     * @return array<string, int|bool>
     */
    public function profile(): array
    {
        return Profiles::for($this->model);
    }

    /**
     * Builds auth + content-type headers, plus optional attribution headers.
     *
     * @return array<string, string>
     */
    protected function buildHeaders(): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];
        if ($this->siteUrl !== '') {
            $headers['HTTP-Referer'] = $this->siteUrl;
        }
        if ($this->siteName !== '') {
            $headers['X-Title'] = $this->siteName;
        }
        if ($this->appCategories !== null && $this->appCategories !== []) {
            $headers['X-OpenRouter-Categories'] = implode(',', $this->appCategories);
        }

        return $headers;
    }

    /** The full chat-completions endpoint URL. */
    protected function buildUrl(): string
    {
        return $this->baseURL . '/chat/completions';
    }

    /**
     * The first of `$keys` present and non-null, looking at this call's options
     * and then at what `bindTools()` bound.
     *
     * @param array<string, mixed> $options
     */
    private function layered(array $options, string ...$keys): mixed
    {
        foreach ([$options, $this->kwargs] as $layer) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $layer) && $layer[$key] !== null) {
                    return $layer[$key];
                }
            }
        }

        return null;
    }

    /**
     * Merges constructor-level defaults with per-call overrides into the API
     * request body (everything except `messages`, which is added later).
     *
     * Layered options -> bound kwargs -> constructor state. Unset (null) values
     * are omitted, as `JSON.stringify` omits `undefined`.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function invocationParams(array $options = []): array
    {
        $tools = $this->layered($options, 'tools');
        $convertedTools = is_array($tools) && $tools !== []
            ? Tools::convertToolsToOpenRouter($tools, ['strict' => $this->layered($options, 'strict')])
            : null;

        $sessionId = $this->layered($options, 'sessionId') ?? $this->sessionId;
        $trace = $this->layered($options, 'trace') ?? $this->trace;
        $prediction = $this->layered($options, 'prediction');

        $params = [
            'model' => $this->model,
            'temperature' => $this->layered($options, 'temperature') ?? $this->temperature,
            'max_tokens' => $this->layered($options, 'maxTokens', 'max_tokens') ?? $this->maxTokens,
            'top_p' => $this->layered($options, 'topP', 'top_p') ?? $this->topP,
            'top_k' => $this->layered($options, 'topK', 'top_k') ?? $this->topK,
            'frequency_penalty' => $this->layered($options, 'frequencyPenalty', 'frequency_penalty') ?? $this->frequencyPenalty,
            'presence_penalty' => $this->layered($options, 'presencePenalty', 'presence_penalty') ?? $this->presencePenalty,
            'repetition_penalty' => $this->layered($options, 'repetitionPenalty', 'repetition_penalty') ?? $this->repetitionPenalty,
            'min_p' => $this->layered($options, 'minP', 'min_p') ?? $this->minP,
            'top_a' => $this->layered($options, 'topA', 'top_a') ?? $this->topA,
            'seed' => $this->layered($options, 'seed') ?? $this->seed,
            'stop' => $this->layered($options, 'stop') ?? $this->stop,
            'logit_bias' => $this->layered($options, 'logitBias', 'logit_bias') ?? $this->logitBias,
            'top_logprobs' => $this->layered($options, 'topLogprobs', 'top_logprobs') ?? $this->topLogprobs,
            'user' => $this->layered($options, 'user') ?? $this->user,
            'tools' => $convertedTools,
            'tool_choice' => Tools::formatToolChoice($this->layered($options, 'tool_choice', 'toolChoice')),
            'response_format' => $this->layered($options, 'response_format', 'responseFormat'),
            'prediction' => $prediction,
            'transforms' => $this->layered($options, 'transforms') ?? $this->transforms,
            'models' => $this->layered($options, 'models') ?? $this->models,
            'route' => $this->layered($options, 'route') ?? $this->route,
            'provider' => $this->layered($options, 'provider') ?? $this->provider,
            'plugins' => $this->layered($options, 'plugins') ?? $this->plugins,
            'session_id' => is_string($sessionId) && $sessionId !== '' ? $sessionId : null,
            'trace' => $trace,
        ];

        return array_merge(
            array_filter($params, static fn (mixed $v): bool => $v !== null),
            $this->modelKwargs ?? [],
        );
    }

    /**
     * Metadata for LangSmith tracing (provider, model name, temperature, etc.).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function getLsParams(array $options = []): array
    {
        return $this->lsParams($options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function lsParams(array $options): array
    {
        $params = $this->invocationParams($options);

        return [
            'ls_provider' => 'openrouter',
            'ls_model_name' => $this->model,
            'ls_model_type' => 'chat',
            'ls_temperature' => $params['temperature'] ?? null,
            'ls_max_tokens' => $params['max_tokens'] ?? null,
            'ls_stop' => $options['stop'] ?? null,
        ];
    }

    /**
     * Non-streaming generation. Sends a single request and returns the complete
     * response with the generated message and token usage.
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $body = Js::encode([
            ...$this->invocationParams($options),
            'messages' => Messages::convertMessagesToOpenRouterParams($messages, $this->model),
            'stream' => false,
        ]);

        $response = $this->withTransportErrors(fn (): HttpResponse => $this->caller()->callWithOptions(
            [],
            function () use ($body): HttpResponse {
                $next = $this->http()->post($this->buildUrl(), $this->buildHeaders(), $body, [], $this->timeout);
                if (!$next->isOk()) {
                    throw OpenRouterError::fromResponse($next);
                }

                return $next;
            },
        ));

        $data = $response->json();
        $choice = $data['choices'][0] ?? null;

        if (!is_array($choice)) {
            throw new OpenRouterError('No choices returned in response.');
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : null;
        $message = Messages::convertOpenRouterResponseToBaseMessage($choice, $data);
        $usageMetadata = Messages::convertUsageMetadata($usage);
        if ($usageMetadata !== null) {
            $message->response_metadata['usage_metadata'] = $usageMetadata;
        }

        $text = is_string($message->content) ? $message->content : '';

        $runManager?->handleLLMNewToken($text);

        return new ChatResult(
            [new ChatGeneration($message, $text, ['finish_reason' => $choice['finish_reason'] ?? null])],
            $usage !== null ? ['tokenUsage' => $usage] : [],
        );
    }

    /**
     * Streaming generation. Opens an SSE connection and yields one
     * `ChatGenerationChunk` per delta received from the API. The pipeline is:
     * raw bytes -> SSE events -> JSON-parsed deltas.
     *
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
        $defaultRole = null;

        foreach ($this->openChunks($messages, $options, true) as $data) {
            if ($data === null) {
                continue;
            }

            $choice = $data['choices'][0] ?? null;
            $delta = is_array($choice) ? ($choice['delta'] ?? null) : null;

            if (!is_array($delta)) {
                // A usage-only chunk has no choices. It is still the token
                // count, so it is folded in rather than dropped.
                if ($this->streamUsage && is_array($data['usage'] ?? null) && $data['usage'] !== []) {
                    yield new ChatGenerationChunk(
                        new AIMessageChunk([
                            'content' => '',
                            'id' => $data['id'] ?? null,
                            'response_metadata' => [
                                'model_provider' => 'openrouter',
                                'usage_metadata' => Messages::convertUsageMetadata($data['usage']),
                            ],
                        ]),
                        '',
                    );
                }

                continue;
            }

            $chunk = Messages::convertOpenRouterDeltaToBaseMessageChunk($delta, $data, $defaultRole);
            $defaultRole = $delta['role'] ?? $defaultRole;

            if (is_array($data['usage'] ?? null) && $data['usage'] !== []) {
                if ($this->streamUsage) {
                    $chunk->response_metadata['usage_metadata'] = Messages::convertUsageMetadata($data['usage']);
                } else {
                    unset($chunk->response_metadata['usage'], $chunk->response_metadata['usage_metadata']);
                }
            }

            $text = is_string($chunk->content) ? $chunk->content : '';
            $finishReason = $choice['finish_reason'] ?? null;

            $generationChunk = new ChatGenerationChunk(
                $chunk,
                $text,
                $finishReason ? ['finish_reason' => $finishReason] : [],
            );

            yield $generationChunk;

            $runManager?->handleLLMNewToken($text, null, ['chunk' => $generationChunk]);
        }
    }

    /**
     * Stream the response as protocol-style events.
     *
     * Port of `_streamChatModelEvents`; see {@see StreamEvents} for the event
     * vocabulary. Like upstream this makes a single attempt (no retries).
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamChatModelEvents(array $messages, array $options = []): \Generator
    {
        $chunks = (function () use ($messages, $options): \Generator {
            foreach ($this->openChunks($messages, $options, false) as $chunk) {
                if ($chunk !== null) {
                    yield $chunk;
                }
            }
        })();

        yield from StreamEvents::convertOpenRouterStream($chunks, [
            'streamUsage' => $this->streamUsage,
        ]);
    }

    /**
     * Returns a new model with the given tools bound into every call.
     *
     * Equivalent to upstream's `.withConfig({ ...kwargs, tools })`: the model is
     * not mutated, and `kwargs` (e.g. `tool_choice`, `strict`) are bound
     * alongside the tools.
     *
     * @param list<mixed>          $tools
     * @param array<string, mixed> $kwargs
     */
    public function bindTools(array $tools, array $kwargs = []): static
    {
        $next = clone $this;

        foreach ($kwargs as $key => $value) {
            if ($key !== 'tools') {
                $next->kwargs[$key] = $value;
            }
        }
        $next->kwargs['tools'] = Tools::convertToolsToOpenRouter(
            array_values($tools),
            ['strict' => $kwargs['strict'] ?? null],
        );

        return $next;
    }

    /**
     * Returns a Runnable that forces the model to produce output conforming to
     * `$schema` (a JSON Schema array).
     *
     * The extraction strategy (JSON Schema response format, function calling, or
     * JSON mode) is chosen from the model's profile: see
     * {@see OpenRouterStructuredOutput::resolveOpenRouterStructuredOutputMethod()}.
     * Override it with `$config['method']`.
     *
     * With `includeRaw` the result is `{raw, parsed}`, and `parsed` is null if the
     * parser throws.
     *
     * @param array<string, mixed> $schema
     * @param array{name?: string, description?: string, method?: string, includeRaw?: bool, strict?: bool} $config
     */
    public function withStructuredOutput(array $schema, array $config = []): Runnable
    {
        $includeRaw = (bool) ($config['includeRaw'] ?? false);
        $strict = $config['strict'] ?? null;

        $method = OpenRouterStructuredOutput::resolveOpenRouterStructuredOutputMethod([
            'model' => $this->model,
            'method' => $config['method'] ?? null,
            'profile' => $this->profile(),
            'models' => $this->models,
            'route' => $this->route,
        ]);

        $description = $config['description'] ?? (is_string($schema['description'] ?? null) && $schema['description'] !== '' ? $schema['description'] : null);

        if ($method === 'jsonSchema') {
            $llm = $this->bind(['response_format' => [
                'type' => 'json_schema',
                'json_schema' => array_filter([
                    'name' => $config['name'] ?? 'extract',
                    'description' => $description,
                    'schema' => $schema,
                    'strict' => $strict,
                ], static fn (mixed $v): bool => $v !== null),
            ]]);
            $parser = StructuredOutput::createContentParser();
        } elseif ($method === 'jsonMode') {
            $llm = $this->bind(['response_format' => ['type' => 'json_object']]);
            $parser = StructuredOutput::createContentParser();
        } else {
            $functionName = (string) ($config['name'] ?? 'extract');
            if (is_string($schema['name'] ?? null)) {
                $functionName = $schema['name'];
                unset($schema['name']);
            }

            $llm = $this->bindTools(
                [[
                    'type' => 'function',
                    'function' => [
                        'name' => $functionName,
                        'description' => $description ?? '',
                        'parameters' => $schema,
                    ],
                ]],
                [
                    'tool_choice' => ['type' => 'function', 'function' => ['name' => $functionName]],
                    ...($strict !== null ? ['strict' => $strict] : []),
                ],
            );
            $parser = StructuredOutput::createFunctionCallingParser($functionName);
        }

        return StructuredOutput::assembleStructuredOutputPipeline(
            $llm,
            $parser,
            $includeRaw,
            'ChatOpenRouterStructuredOutput',
        );
    }

    /**
     * POST the chat request and decode the SSE reply into chunk objects.
     *
     * With `$retry`, establishing the stream goes through {@see AsyncCaller}
     * and is retried until the first byte arrives; once bytes flow an error
     * propagates, because reconnecting would re-emit tokens already delivered.
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, array<string, mixed>|null>
     */
    private function openChunks(array $messages, array $options, bool $retry): \Generator
    {
        $body = Js::encode([
            ...$this->invocationParams($options),
            'messages' => Messages::convertMessagesToOpenRouterParams($messages, $this->model),
            'stream' => true,
        ]);

        $open = function () use ($body): \Generator {
            $raw = $this->http()->postStream($this->buildUrl(), $this->buildHeaders(), $body, [], $this->timeout);
            // postStream() is a generator function: the request is not sent,
            // and a non-2xx is not raised, until it is first advanced.
            try {
                $raw->current();
            } catch (HttpException $e) {
                throw $this->toOpenRouterError($e);
            }

            return $raw;
        };

        $raw = $this->withTransportErrors(
            fn (): \Generator => $retry ? $this->caller()->callWithOptions([], $open) : $open(),
        );

        $parser = new SseParser();
        $payloads = (function () use ($raw, $parser): \Generator {
            try {
                foreach ($raw as $bytes) {
                    yield from $parser->feed($bytes);
                }
                yield from $parser->flush();
            } catch (HttpException $e) {
                throw $this->toOpenRouterError($e);
            }
        })();

        yield from Stream::jsonParse($payloads);
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function withTransportErrors(callable $call): mixed
    {
        try {
            return $call();
        } catch (HttpException $e) {
            throw $this->toOpenRouterError($e);
        }
    }

    private function toOpenRouterError(HttpException $e): OpenRouterError
    {
        if ($e->status === 0) {
            return new OpenRouterError($e->getMessage(), null, null, null, null, $e);
        }

        return Errors::fromHttpException($e);
    }

    private function caller(): AsyncCaller
    {
        return new AsyncCaller(null, $this->maxRetries, null, $this->sleeper);
    }

    private function http(): HttpClient
    {
        return $this->httpClient ??= new GuzzleHttpClient();
    }
}
