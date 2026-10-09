<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\DeepSeek;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Completions;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Runnables\Runnable;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\Env;

/**
 * DeepSeek chat model.
 *
 * Port of `ChatDeepSeek` from `@langchain/deepseek`.
 *
 * DeepSeek's API is OpenAI-compatible with two additions this class handles:
 *
 *  - **Reasoning.** The chain of thought arrives as `reasoning_content` on the
 *    message (or on each streamed delta) and is kept in
 *    `additional_kwargs.reasoning_content`; {@see self::contentBlocks()} renders
 *    it as a `reasoning` block. Some hosts instead inline it as `<think>…</think>`
 *    in the text: while streaming, that span is moved out of the content and into
 *    `reasoning_content`, including when a tag is split across chunks.
 *  - **Structured output** defaults to function calling, because DeepSeek has no
 *    JSON-schema response format.
 *
 * Every message is stamped `response_metadata.model_provider = "deepseek"`.
 * `baseUrl` (or `configuration['baseURL']`) is the API root and
 * `/chat/completions` is appended; the key is `apiKey` or `DEEPSEEK_API_KEY`.
 */
class ChatDeepSeek extends ChatOpenAICompletions
{
    public const DEEPSEEK_BASE_URL = 'https://api.deepseek.com';

    public const DEFAULT_DEEPSEEK_CHAT_MODEL = 'deepseek-chat';

    private const THINK_OPEN = '<think>';

    private const THINK_CLOSE = '</think>';

    /**
     * @param string|array<string, mixed> $modelOrFields A model name, or the full field bag.
     * @param array<string, mixed>        $fields        Used when the first argument is a model name.
     */
    public function __construct(string|array $modelOrFields = [], array $fields = [])
    {
        $fields = is_string($modelOrFields) ? $fields + ['model' => $modelOrFields] : $modelOrFields;

        $apiKey = $fields['apiKey'] ?? null;
        if (!is_string($apiKey) || $apiKey === '') {
            $apiKey = Env::getEnvironmentVariable('DEEPSEEK_API_KEY');
        }
        if (!is_string($apiKey) || $apiKey === '') {
            throw new \InvalidArgumentException(
                'Deepseek API key not found. Please set the DEEPSEEK_API_KEY environment variable '
                . 'or pass the key into "apiKey" field.'
            );
        }

        $configuration = is_array($fields['configuration'] ?? null) ? $fields['configuration'] : [];
        $root = (string) ($configuration['baseURL'] ?? $configuration['baseUrl'] ?? $fields['baseUrl'] ?? self::DEEPSEEK_BASE_URL);
        unset($fields['configuration']);

        $model = $fields['model'] ?? null;

        parent::__construct(array_merge($fields, [
            'model' => is_string($model) && $model !== '' ? $model : self::DEFAULT_DEEPSEEK_CHAT_MODEL,
            'apiKey' => $apiKey,
            'baseUrl' => self::completionsUrl($root),
        ]));
    }

    public function llmType(): string
    {
        return 'deepseek';
    }

    public function getName(): string
    {
        return 'ChatDeepSeek';
    }

    /**
     * @return list<string>
     */
    public static function lcNamespace(): array
    {
        return ['langchain', 'chat_models', 'deepseek'];
    }

    /**
     * @return array<string, string>
     */
    public static function lcSecrets(): array
    {
        return ['apiKey' => 'DEEPSEEK_API_KEY'];
    }

    /**
     * Capabilities and limits of the configured model (`[]` when unknown).
     *
     * @return array<string, int|bool>
     */
    public function profile(): array
    {
        return Profiles::for($this->model);
    }

    /**
     * The message as v1 standard content blocks, reasoning first.
     *
     * @return list<array<string, mixed>>
     */
    public static function contentBlocks(BaseMessage $message): array
    {
        return DeepSeekContentBlocks::of($message);
    }

    /**
     * Function calling unless the caller picks a method: DeepSeek has no JSON-schema mode.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $config
     */
    public function withStructuredOutput(array $schema, array $config = []): Runnable
    {
        $config['method'] ??= 'functionCalling';

        return parent::withStructuredOutput($schema, $config);
    }

    /**
     * The API root with the Chat Completions path appended exactly once.
     */
    public static function completionsUrl(string $baseUrl): string
    {
        $root = rtrim($baseUrl, '/');

        return str_ends_with($root, '/chat/completions') ? $root : $root . '/chat/completions';
    }

    /**
     * Port of `_convertCompletionsMessageToBaseMessage`: the base conversion, plus
     * `reasoning_content` and the provider stamp.
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $params = $this->invocationParams($options);
        $params['messages'] = Completions::convertMessages($messages);

        $payload = $this->post($params);

        $choices = $payload['choices'] ?? [];
        if (!is_array($choices) || $choices === []) {
            throw new OpenAIException('DeepSeek returned no choices for this request.', 0, (string) json_encode($payload));
        }

        $generations = [];
        foreach ($choices as $choice) {
            if (!is_array($choice)) {
                continue;
            }

            $message = Completions::choiceToMessage($choice, $payload);
            $message->response_metadata['model_provider'] = 'deepseek';

            $reasoning = $choice['message']['reasoning_content'] ?? null;
            if (is_string($reasoning) && $reasoning !== '') {
                $message->additional_kwargs['reasoning_content'] = $reasoning;
            }

            $generations[] = new ChatGeneration(
                $message,
                Completions::stringifyContent($message->content),
                array_filter(['finish_reason' => $choice['finish_reason'] ?? null], static fn (mixed $v): bool => $v !== null),
            );
        }

        if ($generations === []) {
            throw new OpenAIException('DeepSeek returned no usable choices for this request.', 0, (string) json_encode($payload));
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
        yield from $this->splitThinkTags($this->completionChunks($messages, $options, $runManager));
    }

    /**
     * Port of `super._streamResponseChunks` as DeepSeek sees it: each delta's
     * `reasoning_content` is kept, and every chunk carries the provider stamp.
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, ChatGenerationChunk>
     */
    private function completionChunks(array $messages, array $options, ?CallbackManagerForLLMRun $runManager): \Generator
    {
        $params = $this->invocationParams($options, ['streaming' => true]);
        $params['messages'] = Completions::convertMessages($messages);

        foreach ($this->postStream($params) as $payload) {
            $choices = $payload['choices'] ?? null;

            // A usage-only chunk has no choices; it still carries the token count.
            if (!is_array($choices) || $choices === []) {
                if (isset($payload['usage']) && is_array($payload['usage'])) {
                    yield new ChatGenerationChunk(
                        new AIMessageChunk([
                            'content' => '',
                            'id' => $payload['id'] ?? null,
                            'response_metadata' => ['model_provider' => 'deepseek'] + Completions::responseMetadata($payload),
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
                $chunk = Completions::deltaToChunk($delta, $payload, (int) $index);
                $chunk->response_metadata['model_provider'] = 'deepseek';

                $reasoning = $delta['reasoning_content'] ?? null;
                if (is_string($reasoning) && $reasoning !== '') {
                    $chunk->additional_kwargs['reasoning_content'] = $reasoning;
                }

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
     * Move inline `<think>…</think>` spans out of the content and into `reasoning_content`.
     *
     * The buffer holds only what could still turn out to be the start of a tag, so
     * a tag split across chunks (`<th` + `ink>`) is recognised without stalling the
     * text around it. A `<think>` inside a think span is reasoning text, an orphan
     * `</think>` is content, and an unclosed span is flushed as reasoning when the
     * stream ends. A chunk that already carries native `reasoning_content`, or has
     * no text, passes through untouched.
     *
     * @param \Generator<int, ChatGenerationChunk> $stream
     *
     * @return \Generator<int, ChatGenerationChunk>
     */
    private function splitThinkTags(\Generator $stream): \Generator
    {
        $buffer = '';
        $thinking = false;

        foreach ($stream as $chunk) {
            $native = $chunk->message->additional_kwargs['reasoning_content'] ?? null;
            if (is_string($native) && $native !== '') {
                yield $chunk;

                continue;
            }

            if ($chunk->text === '') {
                yield $chunk;

                continue;
            }

            $buffer .= $chunk->text;

            if (!$thinking && str_contains($buffer, self::THINK_OPEN)) {
                $thinking = true;
                $at = (int) strpos($buffer, self::THINK_OPEN);
                $before = substr($buffer, 0, $at);
                $buffer = substr($buffer, $at + strlen(self::THINK_OPEN));

                if ($before !== '') {
                    yield self::derive($chunk, $before);
                }
            }

            if ($thinking && str_contains($buffer, self::THINK_CLOSE)) {
                $thinking = false;
                $end = (int) strpos($buffer, self::THINK_CLOSE);
                $thought = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + strlen(self::THINK_CLOSE));

                yield self::derive($chunk, '', $thought);

                if ($buffer !== '') {
                    yield self::derive($chunk, $buffer);
                    $buffer = '';
                }
            } elseif ($thinking) {
                $held = self::partialTagLength($buffer, self::THINK_CLOSE);
                $safe = substr($buffer, 0, strlen($buffer) - $held);
                if ($safe !== '') {
                    yield self::derive($chunk, '', $safe);
                }
                $buffer = substr($buffer, strlen($buffer) - $held);
            } else {
                $held = self::partialTagLength($buffer, self::THINK_OPEN);
                $safe = substr($buffer, 0, strlen($buffer) - $held);
                if ($safe !== '') {
                    yield self::derive($chunk, $safe);
                }
                $buffer = substr($buffer, strlen($buffer) - $held);
            }
        }

        if ($buffer !== '') {
            yield $thinking ? self::derive(null, '', $buffer) : self::derive(null, $buffer);
        }
    }

    /**
     * How many trailing bytes of `$buffer` are a proper prefix of `$tag` (longest first).
     */
    private static function partialTagLength(string $buffer, string $tag): int
    {
        for ($length = strlen($tag) - 1; $length >= 1; --$length) {
            if (str_ends_with($buffer, substr($tag, 0, $length))) {
                return $length;
            }
        }

        return 0;
    }

    /**
     * A chunk carrying `$content` (or, when given, `$reasoning`) in place of `$source`'s text.
     *
     * Everything else is copied from `$source`; with no source, as when the stream
     * ends with text still buffered, there is nothing to copy.
     */
    private static function derive(?ChatGenerationChunk $source, string $content = '', ?string $reasoning = null): ChatGenerationChunk
    {
        $fields = ['content' => $content];

        if ($source !== null) {
            $kwargs = $source->message->additional_kwargs;
            $fields += [
                'additional_kwargs' => $reasoning === null ? $kwargs : [...$kwargs, 'reasoning_content' => $reasoning],
                'response_metadata' => $source->message->response_metadata,
                'tool_call_chunks' => $source->message->toolCallChunks,
                'id' => $source->message->id,
            ];
        } elseif ($reasoning !== null) {
            $fields['additional_kwargs'] = ['reasoning_content' => $reasoning];
        }

        return new ChatGenerationChunk(
            new AIMessageChunk($fields),
            $content,
            $source?->generationInfo ?? [],
        );
    }
}
