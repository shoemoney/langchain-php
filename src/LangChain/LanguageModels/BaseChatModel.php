<?php

declare(strict_types=1);

namespace LangChain\LanguageModels;

use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\LanguageModels\Outputs\Generation;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\PromptValue;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Tracers\Serialized;

/**
 * The base for chat models.
 *
 * Port of `BaseChatModel` from `@langchain/core/language_models/chat_models`.
 *
 * A chat model takes messages and returns messages. Everything else — binding
 * tools, forcing a structured shape, streaming, caching — is expressed on top of
 * one primitive a subclass implements:
 *
 *  - `_generate()`, or
 *  - `_streamResponseChunks()` when the provider can stream.
 *
 * ## Why streaming is not optional
 *
 * `_streamResponseChunks()` returns a `\Generator` of {@see ChatGenerationChunk}.
 * A provider that cannot stream simply does not override it, and the base class
 * falls back to `_generate()`. A provider that *can* overrides it and gets
 * token-by-token callbacks, `stream()`, and implicit streaming for free.
 *
 * The chunks are folded with {@see ChatGenerationChunk::concat()}, not with
 * string concatenation — that is what keeps a streamed tool call's arguments
 * intact across deltas.
 *
 * @template-extends BaseLanguageModel<BaseMessage>
 */
abstract class BaseChatModel extends BaseLanguageModel
{
    /**
     * The version of the `AIMessage` content format.
     *
     * `'v0'` stores the provider's own shape and parses lazily;
     * `'v1'` normalises content into standard blocks before returning, so a
     * transcript is comparable across providers.
     */
    public ?string $outputVersion = null;

    /**
     * The trace namespace: `['langchain', 'chat_models']`.
     *
     * Deliberately without the concrete model kind. `lcNamespace()` is static
     * and PHP has no `$this` in a static context, so the kind cannot be read
     * here — and a namespace that tried would break for any subclass whose
     * `llmType()` depends on constructor state.
     *
     * A subclass that wants its kind in the serialized id overrides
     * {@see self::lcId()} directly; {@see FakeListChatModel} does.
     *
     * @return list<string>
     */
    public static function lcNamespace(): array
    {
        return ['langchain', 'chat_models'];
    }

    public function modelType(): string
    {
        return 'chat';
    }

    /**
     * Produce one response for one message list.
     *
     * @param list<BaseMessage>      $messages
     * @param array<string, mixed>   $options
     */
    abstract protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult;

    /**
     * Stream a response as chunks arrive.
     *
     * Overriding this is what opts a model into streaming. A subclass that
     * does not override it must call `parent::streamResponseChunks()`, which
     * throws — that is how the base class detects "this model cannot stream"
     * and routes to `_generate()` instead, rather than silently producing a
     * single chunk and hiding the difference.
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     * @return \Generator<int, ChatGenerationChunk>
     */
    protected function streamResponseChunks(
        array $messages,
        array $options = [],
        ?CallbackManagerForLLMRun $runManager = null,
    ): \Generator {
        throw new \RuntimeException('Not implemented.');
    }

    /**
     * Whether this subclass overrode {@see self::streamResponseChunks()}.
     *
     * The TypeScript original compares the method against the prototype's and
     * takes the eager path when they match. This is the same test, expressed as
     * a reflection lookup so it works for a method defined several levels down
     * an inheritance chain.
     */
    public function supportsStreaming(): bool
    {
        return (new \ReflectionMethod($this, 'streamResponseChunks'))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Run the model over message lists.
     *
     * @param list<list<BaseMessage>> $messages
     * @param array<string, mixed>     $options
     * @param list<object>|null        $callbacks
     */
    public function generatePrompt(array $promptValues, array $options = [], ?array $callbacks = null): LLMResult
    {
        $promptMessages = [];
        foreach ($promptValues as $promptValue) {
            $promptMessages[] = $promptValue instanceof PromptValue
                ? $promptValue->toMessages()
                : self::convertInputToPromptValue($promptValue)->toMessages();
        }

        $config = new RunnableConfig(callbacks: $callbacks ?? []);

        return $this->generateMessages($promptMessages, $config, $options);
    }

    /**
     * @param list<list<BaseMessage>> $messageLists
     * @param array<string, mixed>     $options
     */
    public function generateMessages(array $messageLists, ?RunnableConfig $config = null, array $options = []): LLMResult
    {
        $invocationParams = $this->invocationParams($options);
        $callbackManager = $this->configureCallbacks(
            $config,
            $this->inheritableMetadata($config?->metadata ?? [], $options),
            $this->filterInvocationParamsForTracing($invocationParams),
        );

        $runManagers = $callbackManager?->handleChatModelStart(
            new Serialized(static::lcId(), $this->kwargs()),
            array_map(static fn (array $m): array => self::coerceMessages($m), $messageLists),
            $config?->runId[0] ?? null,
            ['options' => $options, 'invocation_params' => $invocationParams, 'batch_size' => 1],
            [],
            [],
            $config?->runName,
        );

        $runManager = $runManagers[0] ?? null;
        $generations = [];
        $llmOutputs = [];

        $runIds = array_map(static fn (object $m): string => $m->runId, $runManagers ?? []);

        foreach ($messageLists as $index => $messageList) {
            $messages = self::coerceMessages($messageList);

            $result = $this->dispatchGenerate($messages, $options, $runManager, $config);
            foreach ($result->generations as $generation) {
                $this->stampMessageId($generation, $runManager);
                $generation->message->response_metadata = array_merge(
                    $generation->generationInfo,
                    $generation->message->response_metadata,
                );
            }
            $generations[] = $result->generations;
            $llmOutputs[] = $result->llmOutput;

            // Each prompt ends its own run with its OWN llmOutput. The combined
            // output is only what the returned LLMResult carries: token counts
            // are per-prompt facts, and a run told about the batch total instead
            // of its own usage reports a number it cannot have spent.
            $runManagers[$index]?->handleLLMEnd(new LLMResult([$result->generations], $result->llmOutput));
        }

        return new LLMResult($generations, $this->combineLLMOutput($llmOutputs), $runIds);
    }

    /**
     * Stream the response for one input.
     *
     * A model that cannot stream is not an error: `stream()` yields the single
     * `invoke()` result, exactly as the non-streaming `Runnable` default does.
     * That fallback is why `stream()` is safe to call on any model.
     *
     * @return \Generator<int, array{0: string, 1: BaseMessage}>
     */
    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        if (!$this->supportsStreaming() || $this->disableStreaming) {
            yield [self::CHANNEL_DEFAULT, $this->invoke($input, $config)];

            return;
        }

        $promptValue = self::convertInputToPromptValue($input);
        $messages = $promptValue->toMessages();
        $options = $config?->options ?? [];

        $invocationParams = $this->invocationParams($options);
        $callbackManager = $this->configureCallbacks(
            $config,
            $this->inheritableMetadata($config?->metadata ?? [], $options),
            $this->filterInvocationParamsForTracing($invocationParams),
        );

        $runManagers = $callbackManager?->handleChatModelStart(
            new Serialized(static::lcId(), $this->kwargs()),
            [$messages],
            $config?->runId[0] ?? null,
            ['options' => $options, 'invocation_params' => $invocationParams, 'batch_size' => 1],
            [],
            [],
            $config?->runName,
        );
        $runManager = $runManagers[0] ?? null;

        $aggregated = null;
        try {
            foreach ($this->streamResponseChunks($messages, $options, $runManager) as $chunk) {
                $this->stampMessageId($chunk, $runManager);
                $chunk->message->response_metadata = array_merge(
                    $chunk->generationInfo,
                    $chunk->message->response_metadata,
                );
                $aggregated = $aggregated === null ? $chunk : $aggregated->concat($chunk);
                yield [self::CHANNEL_DEFAULT, $chunk->message];
            }
        } catch (\Throwable $e) {
            $runManager?->handleLLMError($e);

            throw $e;
        }

        if ($aggregated === null) {
            $runManager?->handleLLMEnd(new LLMResult([[]], []));

            return;
        }

        $runManager?->handleLLMEnd(new LLMResult(
            [[new ChatGeneration($aggregated->message, $aggregated->text, $aggregated->generationInfo)]],
            $this->llmOutputFromUsage($aggregated->message),
        ));
    }

    /**
     * Choose between the streaming and eager paths for a non-streaming caller.
     *
     * The check is deliberately about the *handler*, not the caller: a caller
     * that asked for `invoke()` still gets token callbacks if an attached
     * handler wants them. Serving the handler costs nothing extra here because
     * the stream has to be drained either way.
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    private function dispatchGenerate(
        array $messages,
        array $options,
        ?CallbackManagerForLLMRun $runManager,
        ?RunnableConfig $config,
    ): ChatResult {
        $prefersStreaming = $this->handlerPrefersStreaming($runManager);

        if ($this->supportsStreaming() && !$this->disableStreaming && $prefersStreaming) {
            return $this->aggregateStream($messages, $options, $runManager);
        }

        return $this->generate($messages, $options, $runManager);
    }

    /**
     * Drain the stream and fold it into one {@see ChatResult}.
     *
     * The empty-response error here is the one that catches a provider that
     * returned nothing at all — a silent empty is indistinguishable from success
     * to every caller downstream, so it must not be allowed to look like one.
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    private function aggregateStream(
        array $messages,
        array $options,
        ?CallbackManagerForLLMRun $runManager,
    ): ChatResult {
        $aggregated = null;
        foreach ($this->streamResponseChunks($messages, $options, $runManager) as $chunk) {
            $aggregated = $aggregated === null ? $chunk : $aggregated->concat($chunk);
        }

        if ($aggregated === null) {
            throw new \RuntimeException('Received empty response from chat model call.');
        }

        return new ChatResult(
            [new ChatGeneration($aggregated->message, $aggregated->text, $aggregated->generationInfo)],
            $this->llmOutputFromUsage($aggregated->message),
        );
    }

    /**
     * Whether any attached handler asked for streamed chunks.
     *
     * @param array<string, mixed> $llmOutputs
     * @return array<string, mixed>
     */
    protected function combineLLMOutput(array $llmOutputs): array
    {
        return [];
    }

    /**
     * `llmOutput` derived from the accumulated message's usage counters.
     *
     * Built from the *folded* message, not the last chunk. Providers stream usage
     * as deltas, so the final chunk usually carries only its own increment; a
     * run reporting that as the total under-reports every prompt by the amount
     * the earlier chunks contributed.
     *
     * @return array<string, mixed>
     */
    protected function llmOutputFromUsage(BaseMessage $message): array
    {
        $usage = $message->response_metadata["usage_metadata"] ?? null;
        if (!is_array($usage) || $usage === []) {
            return [];
        }

        return ['tokenUsage' => [
            'promptTokens' => $usage['input_tokens'] ?? null,
            'completionTokens' => $usage['output_tokens'] ?? null,
            'totalTokens' => $usage['total_tokens'] ?? null,
        ]];
    }

    /**
     * Give a generated message a run-derived id when the provider gave none.
     *
     * A message with no id cannot be referenced by a later turn — a tool message
     * replies to a `tool_call_id`, and an assistant message that cannot be
     * pointed at breaks that loop. Deriving the id from the run keeps it stable
     * and traceable rather than random.
     */
    private function stampMessageId(Generation $generation, ?CallbackManagerForLLMRun $runManager): void
    {
        $message = $generation instanceof ChatGeneration ? $generation->message : null;
        if ($message === null || $message->id !== null || $runManager === null) {
            return;
        }

        $message->id = 'run-' . $runManager->runId;
    }

    /**
     * Whether a handler on this run prefers streamed chunks.
     */
    protected function handlerPrefersStreaming(?CallbackManagerForLLMRun $runManager): bool
    {
        if ($runManager === null) {
            return false;
        }

        foreach ($runManager->handlers as $handler) {
            if ($handler->preferStreaming) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chat models return a message, not a bare string.
     */
    protected function narrowResult(\LangChain\LanguageModels\Outputs\LLMResult $result): mixed
    {
        $generation = $result->firstGeneration();
        if ($generation === null) {
            throw new \RuntimeException('Model invocation produced no generations.');
        }
        if (!$generation instanceof ChatGeneration) {
            throw new \RuntimeException('Chat model produced a non-chat generation.');
        }

        return $generation->message;
    }
}
