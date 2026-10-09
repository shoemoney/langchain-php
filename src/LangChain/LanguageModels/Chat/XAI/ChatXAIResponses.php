<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\XAI;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAIResponses;
use LangChain\LanguageModels\Chat\XAI\Converters\Responses;
use LangChain\LanguageModels\Chat\XAI\Utils\ResponsesStreamEvents;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\BaseMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\Env;

/**
 * xAI Responses API chat model integration.
 *
 * Port of `ChatXAIResponses` (`chat_models/responses.ts`) from `@langchain/xai`.
 * Upstream builds this on `BaseChatModel` with its own `fetch` calls; here it
 * reuses {@see ChatOpenAIResponses} for the retrying transport, SSE decoding and
 * error mapping (the same shape {@see \LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAIResponses}
 * uses) and replaces what differs: the endpoint, the key, the request body
 * ({@see self::invocationParams()}) and the message/response converters, which
 * are xAI's own ({@see Responses}) rather than OpenAI's.
 *
 * xAI's built-in tools (`web_search`, `x_search`, `code_interpreter`,
 * `file_search`, and the builders in `XAI\Tools`) are sent exactly as given.
 *
 * ```php
 * $llm = new ChatXAIResponses(['model' => 'grok-3', 'tools' => [WebSearch::create()]]);
 * ```
 *
 * @phpstan-import-type XAIResponsesTool from Converters\XAIResponsesTypes
 */
class ChatXAIResponses extends ChatOpenAIResponses
{
    public const DEFAULT_BASE_URL = 'https://api.x.ai/v1';

    public const DEFAULT_API_URL = self::DEFAULT_BASE_URL . '/responses';

    public const DEFAULT_MODEL = 'grok-3';

    public ?int $maxOutputTokens = null;

    public ?bool $store = null;

    public ?string $user = null;

    /** The API root (upstream `baseURL`); `/responses` hangs off it. */
    public string $baseURL = self::DEFAULT_BASE_URL;

    /** @var array<string, mixed>|null */
    public ?array $searchParameters = null;

    /**
     * Tools offered on every call unless a call (or `bindTools()`) supplies its own.
     *
     * @var list<array<string, mixed>>|null
     */
    public ?array $tools = null;

    /**
     * `new ChatXAIResponses('grok-3', [...])` or `new ChatXAIResponses([...])`.
     *
     * @param string|array<string, mixed> $modelOrFields
     * @param array<string, mixed>        $fields        Only read when the first argument is a model name.
     */
    public function __construct(string|array $modelOrFields = [], array $fields = [])
    {
        $fields = is_string($modelOrFields) ? array_merge($fields, ['model' => $modelOrFields]) : $modelOrFields;

        $apiKey = $fields['apiKey'] ?? null;
        if (!is_string($apiKey) || $apiKey === '') {
            $apiKey = Env::getEnvironmentVariable('XAI_API_KEY');
        }
        if (!is_string($apiKey) || $apiKey === '') {
            throw new \InvalidArgumentException(
                'xAI API key not found. Please set the XAI_API_KEY environment variable or provide the key in the "apiKey" field.',
            );
        }

        $baseUrl = $fields['baseURL'] ?? $fields['baseUrl'] ?? self::DEFAULT_BASE_URL;
        $model = $fields['model'] ?? null;
        $maxOutputTokens = $fields['maxOutputTokens'] ?? $fields['max_output_tokens'] ?? null;
        $searchParameters = $fields['searchParameters'] ?? $fields['search_parameters'] ?? null;
        $tools = $fields['tools'] ?? null;
        $store = $fields['store'] ?? null;
        $user = $fields['user'] ?? null;
        unset(
            $fields['baseURL'], $fields['max_output_tokens'], $fields['maxOutputTokens'],
            $fields['searchParameters'], $fields['search_parameters'], $fields['tools'],
            $fields['store'], $fields['user'],
        );

        parent::__construct(array_merge($fields, [
            'model' => is_string($model) && $model !== '' ? $model : self::DEFAULT_MODEL,
            'apiKey' => $apiKey,
        ]));

        $this->baseURL = (string) $baseUrl;
        $this->baseUrl = $this->baseURL;
        $this->maxOutputTokens = $maxOutputTokens === null ? null : (int) $maxOutputTokens;
        $this->store = $store === null ? null : (bool) $store;
        $this->user = $user === null ? null : (string) $user;
        $this->searchParameters = is_array($searchParameters) ? $searchParameters : null;
        $this->tools = is_array($tools) ? array_values($tools) : null;

        // What the caller configured, minus the credential. `kwargs` is what the tracer serialises.
        foreach (['maxOutputTokens' => $this->maxOutputTokens, 'store' => $this->store, 'user' => $this->user] as $key => $value) {
            if ($value !== null) {
                $this->kwargs[$key] = $value;
            }
        }
        unset($this->kwargs['baseUrl']);
    }

    public function llmType(): string
    {
        return 'xai-responses';
    }

    public function getName(): string
    {
        return 'ChatXAIResponses';
    }

    /**
     * @return list<string>
     */
    public static function lcNamespace(): array
    {
        return ['langchain', 'chat_models', 'xai'];
    }

    /**
     * @return array<string, string>
     */
    public static function lcSecrets(): array
    {
        return ['apiKey' => 'XAI_API_KEY'];
    }

    /**
     * @return array<string, string>
     */
    public static function lcAliases(): array
    {
        return ['apiKey' => 'xai_api_key'];
    }

    /**
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
        return array_filter([
            'ls_provider' => 'xai',
            'ls_model_name' => $this->model,
            'ls_model_type' => 'chat',
            'ls_temperature' => $this->temperature,
            'ls_max_tokens' => $this->maxOutputTokens,
            'ls_stop' => $options['stop'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    protected function defaultUrl(): string
    {
        return self::DEFAULT_API_URL;
    }

    /**
     * Always `{baseURL}/responses`, as upstream builds it.
     */
    protected function url(): string
    {
        $base = rtrim($this->baseURL, '/');

        return str_ends_with($base, '/responses') ? $base : $base . '/responses';
    }

    protected function streamEventProvider(): string
    {
        return 'xai';
    }

    /**
     * The request body, minus `input`.
     *
     * Precedence, most specific first: this call's `$options`, what
     * `bindTools()` / `bind()` attached (`kwargs`), the constructor state. Option
     * names are the wire spellings upstream uses; their camelCase forms work too.
     * Unlike upstream, sampling parameters can also be bound or passed per call,
     * so `bind(['temperature' => 0])` is not a silent no-op.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function invocationParams(array $options = [], array $extra = []): array
    {
        $options = self::canonicalise($options);
        $bound = self::canonicalise($this->kwargs);
        $pick = fn (string ...$keys): mixed => $this->pickOption($options, ...$keys) ?? $this->pickOption($bound, ...$keys);

        $stream = ($extra['streaming'] ?? null) !== null ? (bool) $extra['streaming'] : (bool) ($pick('streaming') ?? $this->streaming);
        $reasoning = $pick('reasoning') ?? $this->reasoning;

        return array_filter([
            'model' => (string) ($pick('model') ?? $this->model),
            'temperature' => $pick('temperature') ?? $this->temperature,
            'top_p' => $pick('topP') ?? $this->topP,
            'max_output_tokens' => $pick('maxOutputTokens') ?? $this->maxOutputTokens,
            'store' => $pick('store') ?? $this->store,
            'user' => $pick('user') ?? $this->user,
            'stream' => $stream,
            'previous_response_id' => $pick('previous_response_id', 'previousResponseId'),
            'include' => $pick('include'),
            'text' => $pick('text'),
            'search_parameters' => $pick('search_parameters', 'searchParameters') ?? $this->searchParameters,
            'reasoning' => $reasoning,
            'tools' => $pick('tools') ?? $this->tools,
            'tool_choice' => $pick('toolChoice'),
            'parallel_tool_calls' => $pick('parallelToolCalls'),
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $invocationParams = $this->invocationParams($options);

        if (($invocationParams['stream'] ?? false) === true) {
            $final = null;
            foreach ($this->streamResponseChunks($messages, $options, $runManager) as $chunk) {
                $chunk->message->response_metadata = [...$chunk->generationInfo, ...$chunk->message->response_metadata];
                $final = $final === null ? $chunk : $final->concat($chunk);
            }

            if ($final === null) {
                return new ChatResult([], []);
            }

            $message = $final->message->toMessage();

            return new ChatResult(
                [new ChatGeneration($message, $final->text, $final->generationInfo)],
                $this->llmOutputFromUsage($message),
            );
        }

        $response = $this->post([
            'input' => Responses::convertMessagesToResponsesInput($messages),
            ...$invocationParams,
            'stream' => false,
        ]);

        $message = Responses::convertResponseToAIMessage($response);

        return new ChatResult(
            [new ChatGeneration($message, Responses::extractTextFromOutput($response['output'] ?? []))],
            array_filter(['id' => $response['id'] ?? null], static fn (mixed $v): bool => $v !== null)
                + $this->llmOutputFromUsage($message),
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
        $params = [
            'input' => Responses::convertMessagesToResponsesInput($messages),
            ...$this->invocationParams($options, ['streaming' => true]),
            'stream' => true,
        ];

        foreach ($this->postStream($params) as $event) {
            $chunk = Responses::convertStreamEventToChunk($event);
            if ($chunk === null) {
                continue;
            }

            yield $chunk;

            $runManager?->handleLLMNewToken($chunk->text, null, ['chunk' => $chunk]);
        }
    }

    /**
     * The same request as a plain stream, but the raw events come back as typed
     * `ChatModelStreamEvent` arrays (see {@see ResponsesStreamEvents}).
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamChatModelEvents(array $messages, array $options = []): \Generator
    {
        $params = [
            'input' => Responses::convertMessagesToResponsesInput($messages),
            ...$this->invocationParams($options, ['streaming' => true]),
            'stream' => true,
        ];

        yield from ResponsesStreamEvents::convertXAIResponsesStream(
            $this->postStream($params),
            ['streamUsage' => (bool) ($options['streamUsage'] ?? true)],
        );
    }
}
