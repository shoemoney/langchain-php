<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI;

use LangChain\LanguageModels\Chat\OpenAI\Converters\Misc;
use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesInput;
use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesOutput;
use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesTools;
use LangChain\LanguageModels\Chat\OpenAI\Utils\ResponsesStreamEvents;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\BaseMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\Js;

/**
 * A chat model backed by OpenAI's Responses API.
 *
 * Port of `ChatOpenAIResponses` (`chat_models/responses.ts`) from
 * `@langchain/openai`. The request is built by {@see self::invocationParams()},
 * the conversation by {@see ResponsesInput}, and the reply by
 * {@see ResponsesOutput}; everything else (authentication, retries, tool binding)
 * is inherited from {@see BaseChatOpenAI}.
 *
 * ## Call options only this protocol understands
 *
 * `text`, `truncation`, `include`, `previous_response_id` and `verbosity`, plus
 * the `reasoning`, `promptCacheKey`, `promptCacheRetention` and
 * `promptCacheOptions` that {@see BaseChatOpenAI} carries. Their presence is what
 * makes the {@see ChatOpenAI} facade choose this class.
 */
class ChatOpenAIResponses extends BaseChatOpenAI
{
    public const DEFAULT_API_URL = 'https://api.openai.com/v1/responses';

    protected function defaultUrl(): string
    {
        return self::DEFAULT_API_URL;
    }

    protected function url(): string
    {
        $url = parent::url();

        // A URL configured for Chat Completions still has to reach this endpoint.
        return str_ends_with($url, '/chat/completions') ? substr($url, 0, -strlen('/chat/completions')) . '/responses' : $url;
    }

    /**
     * The request body, minus `input`.
     *
     * Precedence, most specific first: this call's `$options`, what
     * `bindTools()` / `bind()` attached (`kwargs`), the constructor state.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function invocationParams(array $options = [], array $extra = []): array
    {
        $this->rejectUnsupported($options);
        $this->rejectUnsupported($this->kwargs);

        $options = self::canonicalise($options);
        $bound = self::canonicalise($this->kwargs);

        $pick = fn (string ...$keys): mixed => $this->pickOption($options, ...$keys) ?? $this->pickOption($bound, ...$keys);

        $stream = ($extra['streaming'] ?? null) !== null ? (bool) $extra['streaming'] : $this->streaming;
        $model = (string) ($pick('model') ?? $this->model);

        $strict = $options['strict'] ?? $this->supportsStrictToolCalling;

        $tools = $pick('tools');
        $reducedTools = is_array($tools) && $tools !== []
            ? ResponsesTools::reduceTools(array_values($tools), $stream, $strict === null ? null : (bool) $strict)
            : null;

        $toolChoice = $pick('toolChoice');
        if ($toolChoice !== null) {
            $toolChoice = ResponsesTools::isBuiltInToolChoice($toolChoice)
                ? $toolChoice
                : ResponsesTools::formatToolChoice($toolChoice);
        }

        $maxTokens = $pick('maxTokens') ?? $this->maxTokens;

        $params = [
            'model' => $model,
            'temperature' => $pick('temperature') ?? $this->temperature,
            'top_p' => $pick('topP') ?? $this->topP,
            'user' => $pick('user'),
            'service_tier' => $pick('serviceTier') ?? $this->serviceTier,
            // if include_usage is set or streamUsage then stream must be set to true.
            'stream' => $stream ? true : null,
            'previous_response_id' => $pick('previousResponseId', 'previous_response_id'),
            'truncation' => $pick('truncation'),
            'include' => $pick('include'),
            'tools' => $reducedTools,
            'tool_choice' => $toolChoice,
            'text' => $this->textParam($pick),
            'parallel_tool_calls' => $pick('parallelToolCalls'),
            'max_output_tokens' => $maxTokens === -1 ? null : $maxTokens,
        ];

        if ($this->zdrEnabled) {
            $params['store'] = false;
        }

        $params = array_merge($params, $this->modelKwargs);

        $params['prompt_cache_key'] = $pick('promptCacheKey') ?? $this->modelKwargs['prompt_cache_key'] ?? $this->promptCacheKey;
        $retention = $pick('promptCacheRetention') ?? $this->modelKwargs['prompt_cache_retention'] ?? $this->promptCacheRetention;
        $params['prompt_cache_retention'] = $retention === 'in-memory' ? 'in_memory' : $retention;
        $params['prompt_cache_options'] = $pick('promptCacheOptions') ?? $this->modelKwargs['prompt_cache_options'] ?? $this->promptCacheOptions;

        $reasoning = $this->reasoningParams($model, $options, $bound);
        if ($reasoning !== null) {
            $params['reasoning'] = $reasoning;
        }

        return array_filter($params, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * The `text` request member: an explicit `text` option wins, else the
     * response format (and verbosity) is translated into one.
     *
     * An empty member is omitted; upstream would send `{}`, which means the same.
     *
     * @param callable(string ...): mixed $pick
     *
     * @return array<string, mixed>|null
     */
    private function textParam(callable $pick): ?array
    {
        $explicit = $pick('text');
        if (is_array($explicit)) {
            return $explicit;
        }

        $verbosity = $pick('verbosity');
        $format = $pick('responseFormat');

        if (is_array($format) && ($format['type'] ?? null) === 'json_schema') {
            $jsonSchema = is_array($format['json_schema'] ?? null) ? $format['json_schema'] : [];
            if (($jsonSchema['schema'] ?? null) === null) {
                return null;
            }

            return array_filter([
                'format' => array_filter([
                    'type' => 'json_schema',
                    'schema' => $jsonSchema['schema'],
                    'description' => $jsonSchema['description'] ?? null,
                    'name' => $jsonSchema['name'] ?? null,
                    'strict' => $jsonSchema['strict'] ?? null,
                ], static fn (mixed $v): bool => $v !== null),
                'verbosity' => $verbosity,
            ], static fn (mixed $v): bool => $v !== null);
        }

        $text = array_filter(['format' => $format, 'verbosity' => $verbosity], static fn (mixed $v): bool => $v !== null);

        return $text === [] ? null : $text;
    }

    /**
     * Reasoning parameters, newest source winning: constructor, then bound
     * values, then this call. `reasoningEffort` only fills a missing `effort`.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $bound
     *
     * @return array<string, mixed>|null
     */
    private function reasoningParams(string $model, array $options, array $bound): ?array
    {
        if (!Misc::isReasoningModel($model)) {
            return null;
        }

        $reasoning = null;
        foreach ([$this->reasoning, $bound['reasoning'] ?? null, $options['reasoning'] ?? null] as $layer) {
            if (is_array($layer)) {
                $reasoning = [...($reasoning ?? []), ...$layer];
            }
        }

        $effort = $options['reasoningEffort'] ?? $bound['reasoningEffort'] ?? null;
        if ($effort !== null && ($reasoning['effort'] ?? null) === null) {
            $reasoning = [...($reasoning ?? []), 'effort' => $effort];
        }

        return $reasoning;
    }

    /**
     * @param list<BaseMessage> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function inputItems(array $messages): array
    {
        return ResponsesInput::convertMessagesToResponsesInput($messages, $this->zdrEnabled, $this->model);
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

        $payload = $this->post(['input' => $this->inputItems($messages), ...$invocationParams, 'stream' => false]);

        $message = ResponsesOutput::convertResponsesMessageToAIMessage($payload);

        return new ChatResult(
            [new ChatGeneration($message, ResponsesOutput::textOf($message->content))],
            array_filter(['id' => $payload['id'] ?? null], static fn (mixed $v): bool => $v !== null)
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
        $params = ['input' => $this->inputItems($messages), ...$this->invocationParams($options, ['streaming' => true]), 'stream' => true];

        foreach ($this->postStream($params) as $event) {
            $chunk = ResponsesOutput::convertResponsesDeltaToChatGenerationChunk($event);
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
        $params = ['input' => $this->inputItems($messages), ...$this->invocationParams($options, ['streaming' => true]), 'stream' => true];

        yield from ResponsesStreamEvents::convertOpenAIResponsesStream(
            $this->postStream($params),
            ['streamUsage' => $this->streamUsage, 'provider' => $this->streamEventProvider()],
        );
    }

    /**
     * Provider id used in native stream protocol passthrough events.
     */
    protected function streamEventProvider(): string
    {
        return 'openai';
    }

    protected function decode(\Generator $payloads): \Generator
    {
        foreach (parent::decode($payloads) as $decoded) {
            // The Responses stream reports a failure as an `error` EVENT, not an `error` member.
            if (($decoded['type'] ?? null) === 'error') {
                throw OpenAIException::fromResponse(
                    Js::encode(['error' => array_filter([
                        'message' => $decoded['message'] ?? null,
                        'code' => $decoded['code'] ?? null,
                        'param' => $decoded['param'] ?? null,
                    ], static fn (mixed $v): bool => $v !== null)]),
                    0,
                    $this->url(),
                );
            }

            yield $decoded;
        }
    }
}
