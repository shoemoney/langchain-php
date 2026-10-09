<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\XAI;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions;
use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesTools;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Completions;
use LangChain\LanguageModels\Chat\XAI\Tools\LiveSearch as LiveSearchTool;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\Env;

/**
 * xAI chat model integration (Chat Completions API).
 *
 * Port of `ChatXAI` (`chat_models/completions.ts`) from `@langchain/xai`. The
 * xAI API is compatible with the OpenAI API with some limitations, so this is
 * {@see ChatOpenAICompletions} plus four adjustments:
 *
 *  1. the endpoint, key and model defaults (`https://api.x.ai/v1`,
 *     `XAI_API_KEY`, `grok-3-fast`);
 *  2. Live Search: `searchParameters` (constructor or call) and the built-in
 *     `live_search` tool become a `search_parameters` request field
 *     ({@see self::invocationParams()}), and the tool itself is stripped from
 *     `tools` before sending ({@see self::completionWithRetry()});
 *  3. request clean-up for parameters xAI rejects ({@see self::completionWithRetry()});
 *  4. streamed usage is only kept on the final chunk, and `reasoning_content`
 *     is surfaced in `additional_kwargs`.
 *
 * The xAI Responses API variant (`ChatXAIResponses`) is a separate class
 * upstream and is not part of this port.
 *
 * ```php
 * $llm = new ChatXAI(['model' => 'grok-3-fast', 'searchParameters' => ['mode' => 'auto', 'max_search_results' => 5]]);
 * $llm = (new ChatXAI())->bindTools([['type' => 'live_search_deprecated_20251215', 'name' => 'live_search']]);
 * ```
 */
class ChatXAI extends ChatOpenAICompletions
{
    public const DEFAULT_BASE_URL = 'https://api.x.ai/v1';

    public const DEFAULT_API_URL = self::DEFAULT_BASE_URL . '/chat/completions';

    public const DEFAULT_MODEL = 'grok-3-fast';

    /**
     * Set of all supported xAI built-in server-side tool types, so support for
     * future built-in tools needs no change to the detection logic.
     */
    private const BUILT_IN_TOOL_TYPES = [LiveSearchTool::TOOL_TYPE];

    /**
     * Default search parameters for the Live Search API.
     *
     * @var array<string, mixed>|null
     */
    public ?array $searchParameters = null;

    /**
     * `new ChatXAI('grok-3', [...])` or `new ChatXAI([...])`.
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
                'xAI API key not found. Please set the XAI_API_KEY environment variable or provide the key into "apiKey" field.',
            );
        }

        $searchParameters = $fields['searchParameters'] ?? $fields['search_parameters'] ?? null;
        $baseUrl = $fields['baseURL'] ?? $fields['baseUrl'] ?? self::DEFAULT_BASE_URL;
        $model = $fields['model'] ?? null;
        unset($fields['searchParameters'], $fields['search_parameters'], $fields['baseURL']);

        parent::__construct(array_merge($fields, [
            'model' => is_string($model) && $model !== '' ? $model : self::DEFAULT_MODEL,
            'apiKey' => $apiKey,
            'baseUrl' => $baseUrl,
        ]));

        $this->searchParameters = is_array($searchParameters) ? $searchParameters : null;
    }

    public function llmType(): string
    {
        return 'xai';
    }

    /**
     * @return list<string>
     */
    public static function lcNamespace(): array
    {
        return ['langchain', 'chat_models', 'xai'];
    }

    public function getName(): string
    {
        return 'ChatXAI';
    }

    protected function defaultUrl(): string
    {
        return self::DEFAULT_API_URL;
    }

    /**
     * `baseUrl` is the API root (as upstream's `baseURL`); the endpoint hangs off it.
     */
    protected function url(): string
    {
        $base = rtrim($this->baseUrl ?? self::DEFAULT_BASE_URL, '/');

        return str_ends_with($base, '/chat/completions') ? $base : $base . '/chat/completions';
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function lsParams(array $options): array
    {
        return ['ls_provider' => 'xai'] + parent::lsParams($options);
    }

    /**
     * Checks whether a tool is an xAI built-in tool (like live_search), which
     * the xAI API executes server-side.
     */
    public static function isXAIBuiltInTool(mixed $tool): bool
    {
        return is_array($tool)
            && isset($tool['type'])
            && is_string($tool['type'])
            && in_array($tool['type'], self::BUILT_IN_TOOL_TYPES, true);
    }

    /**
     * The effective search parameters, merging the instance defaults with call options.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    protected function getEffectiveSearchParameters(array $options = []): ?array
    {
        return LiveSearch::mergeSearchParams($this->searchParameters, $this->callSearchParameters($options));
    }

    /**
     * Whether any built-in tool (like live_search) is in the tools list.
     *
     * @param list<mixed>|null $tools
     */
    protected function hasBuiltInTools(?array $tools = null): bool
    {
        foreach ($tools ?? [] as $tool) {
            if (self::isXAIBuiltInTool($tool)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Formats tools to the xAI/OpenAI format, preserving provider-specific definitions.
     *
     * @param list<mixed> $tools
     *
     * @return list<array<string, mixed>>|null
     */
    public function formatStructuredToolToXAI(array $tools): ?array
    {
        if ($tools === []) {
            return null;
        }

        return array_map(static function (mixed $tool): array {
            // 1. A provider definition (from the xaiLiveSearch factory) wins.
            if (ResponsesTools::hasProviderToolDefinition($tool)) {
                return ResponsesTools::providerToolDefinition($tool);
            }
            // 2. Built-in tools (legacy `['type' => 'live_search...']`).
            if (self::isXAIBuiltInTool($tool)) {
                return $tool;
            }

            // 3. Standard tools to the OpenAI format.
            return \LangChain\LanguageModels\Chat\OpenAI\Utils\Tools::convert($tool);
        }, array_values($tools));
    }

    /**
     * @param list<mixed>          $tools
     * @param array<string, mixed> $kwargs
     */
    public function bindTools(array $tools, array $kwargs = []): static
    {
        return parent::bindTools($this->formatStructuredToolToXAI($tools) ?? [], $kwargs);
    }

    /**
     * The request body, plus `search_parameters` when Live Search is in use.
     *
     * Search parameters merge, lowest to highest precedence: the live_search
     * tool's own configuration, the constructor's `searchParameters`, the call's
     * `searchParameters`. The tool stays in `tools` here and is removed when the
     * request is sent.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function invocationParams(array $options = [], array $extra = []): array
    {
        $params = parent::invocationParams($options, $extra);

        $liveSearchTool = null;
        $tools = $options['tools'] ?? $this->kwargs['tools'] ?? null;
        foreach (is_array($tools) ? $tools : [] as $tool) {
            if (self::isXAIBuiltInTool($tool)) {
                $liveSearchTool = $tool;
                break;
            }
        }

        $merged = LiveSearch::mergeSearchParams(
            $this->searchParameters,
            $this->callSearchParameters($options),
            $liveSearchTool,
        );

        if ($merged !== null) {
            $params['search_parameters'] = LiveSearch::buildSearchParametersPayload($merged);
        }

        return $params;
    }

    /**
     * Call-level search parameters: this call's options, else what was bound.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    private function callSearchParameters(array $options): ?array
    {
        foreach ([$options, $this->kwargs] as $bag) {
            $given = $bag['searchParameters'] ?? $bag['search_parameters'] ?? null;
            if (is_array($given)) {
                return $given;
            }
        }

        return null;
    }

    /**
     * Calls the xAI API with retry logic in case of failures.
     *
     * Port of `completionWithRetry`. A request with `stream => true` returns a
     * generator of decoded stream events; any other returns the decoded
     * response. Either way the request is first cleaned for xAI:
     *
     *  - `frequency_penalty`, `presence_penalty`, `logit_bias` and `functions` are removed;
     *  - a message without content gets `""`;
     *  - the built-in live_search tool is removed from `tools` (it is controlled
     *    through `search_parameters`), and `tools` is dropped when nothing is left.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>|\Generator<int, array<string, mixed>>
     */
    public function completionWithRetry(array $request): array|\Generator
    {
        unset($request['frequency_penalty'], $request['presence_penalty'], $request['logit_bias'], $request['functions']);

        if (is_array($request['messages'] ?? null)) {
            $request['messages'] = array_map(static function (mixed $message): mixed {
                if (is_array($message) && (!array_key_exists('content', $message) || $message['content'] === null || $message['content'] === '')) {
                    $message['content'] = '';
                }

                return $message;
            }, $request['messages']);
        }

        if (isset($request['tools']) && is_array($request['tools'])) {
            $filtered = LiveSearch::filterXAIBuiltInTools([
                'tools' => $request['tools'],
                'excludedTypes' => [LiveSearchTool::TOOL_TYPE],
            ]);
            if ($filtered === null) {
                unset($request['tools']);
            } else {
                $request['tools'] = $filtered;
            }
        }

        return ($request['stream'] ?? false) === true ? $this->postStream($request) : $this->post($request);
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $params = $this->invocationParams($options);
        $params['messages'] = Completions::convertMessages($messages);

        $payload = $this->completionWithRetry($params);
        if ($payload instanceof \Generator) {
            throw new OpenAIException('xAI returned a stream for a non-streaming request.', 0, '');
        }

        $choices = $payload['choices'] ?? [];
        if (!is_array($choices) || $choices === []) {
            throw new OpenAIException('xAI returned no choices for this request.', 0, json_encode($payload) ?: '');
        }

        $generations = [];
        foreach ($choices as $choice) {
            if (!is_array($choice)) {
                continue;
            }

            $message = Completions::choiceToMessage($choice, $payload);
            $reasoning = is_array($choice['message'] ?? null) ? ($choice['message']['reasoning_content'] ?? null) : null;
            if ($reasoning !== null) {
                $message->additional_kwargs['reasoning_content'] = $reasoning;
            }

            $generationInfo = array_filter(
                ['finish_reason' => $choice['finish_reason'] ?? null],
                static fn (mixed $v): bool => $v !== null,
            );

            $generations[] = new ChatGeneration($message, Completions::stringifyContent($message->content), $generationInfo);
        }

        if ($generations === []) {
            throw new OpenAIException('xAI returned no usable choices for this request.', 0, json_encode($payload) ?: '');
        }

        return new ChatResult($generations, $this->llmOutputFromUsage($generations[0]->message));
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
        $params['messages'] = Completions::convertMessages($messages);
        $params['stream'] = true;

        foreach ($this->completionWithRetry($params) as $payload) {
            $choices = $payload['choices'] ?? null;

            // A usage-only chunk has no choices; it carries the authoritative
            // token count, so it is folded in rather than dropped.
            if (!is_array($choices) || $choices === []) {
                if (isset($payload['usage']) && is_array($payload['usage'])) {
                    yield new ChatGenerationChunk(
                        new AIMessageChunk([
                            'content' => '',
                            'id' => $payload['id'] ?? null,
                            'response_metadata' => Completions::responseMetadata($payload),
                        ]),
                        '',
                    );
                }

                continue;
            }

            foreach ($choices as $index => $choice) {
                if (!is_array($choice)) {
                    continue;
                }

                $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
                $chunk = $this->convertCompletionsDeltaToBaseMessageChunk($delta, $payload, (int) $index);

                $generationInfo = array_filter(
                    ['finish_reason' => $choice['finish_reason'] ?? null],
                    static fn (mixed $v): bool => $v !== null,
                );

                $text = is_string($delta['content'] ?? null) ? $delta['content'] : '';

                $generationChunk = new ChatGenerationChunk($chunk, $text, $generationInfo);

                yield $generationChunk;

                if ($text !== '') {
                    $runManager?->handleLLMNewToken($text, null, ['chunk' => $generationChunk]);
                }
            }
        }
    }

    /**
     * One streamed delta as a foldable chunk.
     *
     * Port of the xAI override of `_convertCompletionsDeltaToBaseMessageChunk`.
     * xAI repeats cumulative usage on every chunk, so it is dropped from all but
     * the final one (the one with a `finish_reason`), which keeps concatenated
     * chunks from double-counting. `reasoning_content` is carried in
     * `additional_kwargs`, where the OpenAI base converter puts it upstream.
     *
     * @param array<string, mixed> $delta
     * @param array<string, mixed> $rawResponse
     */
    protected function convertCompletionsDeltaToBaseMessageChunk(array $delta, array $rawResponse, int $index = 0): AIMessageChunk
    {
        $chunk = Completions::deltaToChunk($delta, $rawResponse, $index);

        if (array_key_exists('reasoning_content', $delta)) {
            $chunk->additional_kwargs['reasoning_content'] = $delta['reasoning_content'];
        }

        $first = is_array($rawResponse['choices'] ?? null) ? ($rawResponse['choices'][0] ?? null) : null;
        if (!(is_array($first) && !empty($first['finish_reason']))) {
            unset($chunk->response_metadata['usage'], $chunk->response_metadata['usage_metadata']);
        }

        return $chunk;
    }

    /**
     * The capability profile of the model (`[]` when unknown).
     *
     * @return array<string, int|bool>
     */
    public function profile(): array
    {
        return Profiles::for($this->model);
    }
}
