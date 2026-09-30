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

    // ---- tool binding ----------------------------------------------------

    /**
     * Offer a set of tools to the model.
     *
     * The base implementation throws. That is not laziness — it is how "this
     * provider cannot bind tools" is expressed.
     *
     * In the TypeScript original `bindTools` is an *optional* method, so a model
     * without it is detected with `typeof this.bindTools !== "function"`. PHP
     * has no optional methods, so a model that has not overridden this would
     * otherwise be indistinguishable from one that has — and
     * {@see self::withStructuredOutput()} would silently build a chain that
     * cannot work. Throwing here, and probing with
     * {@see self::supportsToolBinding()}, preserves the distinction.
     *
     * A provider overrides this and returns a *new* instance carrying the
     * rendered tools. It must not mutate `$this`: a bind that mutated its
     * receiver would leak one call's tools into the next call's, which is the
     * bug {@see \LangChain\Runnables\RunnableBinding} exists to prevent.
     *
     * `$tools` is provider-shaped. Each client overrides this to accept
     * `StructuredTool`s and render them, but a caller may also pass the
     * provider's own wire format directly, which is what makes it possible to
     * bind a tool this SDK does not know about.
     *
     * @param list<mixed>             $tools  `StructuredTool` instances and/or
     *                                           provider-shaped tool arrays.
     * @param array<string, mixed>    $kwargs Extra call options to bind alongside.
     */
    public function bindTools(array $tools, array $kwargs = []): static
    {
        throw new \RuntimeException('Not implemented.');
    }

    /**
     * Whether this subclass overrode {@see self::bindTools()}.
     *
     * Same reflection probe as {@see self::supportsStreaming()}, for the same
     * reason: the TypeScript original distinguishes "no `bindTools`" from "a
     * `bindTools` that throws", and only an override marks the difference.
     */
    public function supportsToolBinding(): bool
    {
        return (new \ReflectionMethod($this, 'bindTools'))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Ask the model for a value matching a schema, and get that value back.
     *
     * Port of `BaseChatModel.withStructuredOutput` from
     * `@langchain/core/language_models/chat_models`.
     *
     * This is the base implementation and it supports exactly one strategy:
     * **function calling**. The schema is offered to the model as a tool whose
     * parameters are the schema, and the answer is the *arguments* of the call
     * it makes. A model that understands the schema emits a well-formed call; a
     * model that does not generally does not call the tool at all, which is why
     * the parser reports "no tool call found" rather than returning a default.
     *
     * `jsonMode` is rejected rather than silently approximated: asking a model
     * for bare JSON and asking it for a tool call are different requests, and
     * quietly substituting one for the other produces output that looks right
     * and is not the thing that was asked for. Providers that support JSON mode
     * implement it themselves.
     *
     * A model that declines to call the tool produces `null`, not an exception.
     * That is upstream's behaviour — see {@see StructuredOutput} — and it is a
     * distinct fact from "an empty object", so branch on it rather than
     * expecting a throw.
     *
     * `$functionName` has to agree on both sides — the tool offered to the
     * model and the key the parser looks for. It is taken from an explicit
     * `$config['name']`, else from a `name` key on a plain schema, else
     * `extract`.
     *
     * @param array<string, mixed> $schema    JSON Schema describing the output.
     * @param array{name?: string, description?: string, method?: string,
     *              includeRaw?: bool, strict?: bool} $config
     *
     * @return Runnable<mixed, mixed> the model, or
     *         `{raw: BaseMessage, parsed: array|null}` when `includeRaw` is set
     */
    public function withStructuredOutput(array $schema, array $config = []): \LangChain\Runnables\Runnable
    {
        if (!$this->supportsToolBinding()) {
            throw new \RuntimeException(
                'Chat model must implement ".bindTools()" to use withStructuredOutput.'
            );
        }

        if (($config['strict'] ?? false) === true) {
            throw new \RuntimeException('"strict" mode is not supported for this model by default.');
        }

        if (($config['method'] ?? 'functionCalling') === 'jsonMode') {
            throw new \RuntimeException(
                'Base withStructuredOutput implementation only supports "functionCalling" as a method.'
            );
        }

        $functionName = (string) ($config['name'] ?? ($schema['name'] ?? 'extract'));
        $description = (string) ($config['description'] ?? $this->schemaDescription($schema) ?? 'A function available to call.');

        // `name` is the parser's lookup key, not part of the schema the model
        // validates against, so it is stripped before the schema is sent.
        unset($schema['name']);

        $llm = $this->bindTools([[
            'type' => 'function',
            'function' => [
                'name' => $functionName,
                'description' => $description,
                'parameters' => $schema,
            ],
        ]]);

        $parser = StructuredOutput::createFunctionCallingParser($functionName);

        return StructuredOutput::assembleStructuredOutputPipeline(
            $llm,
            $parser,
            (bool) ($config['includeRaw'] ?? false),
            ($config['includeRaw'] ?? false) ? 'StructuredOutputRunnable' : 'StructuredOutput',
        );
    }

    /**
     * A schema's own `description`, if it declares one.
     *
     * Mirrors upstream's `getSchemaDescription`. A schema with no description
     * falls back to the generic prompt in {@see self::withStructuredOutput()},
     * which is what upstream does too — an empty string would read as "this
     * function intentionally does nothing".
     */
    private function schemaDescription(array $schema): ?string
    {
        $description = $schema['description'] ?? null;

        return is_string($description) && $description !== '' ? $description : null;
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

            // The run manager for THIS prompt, not run manager 0. Upstream
            // passes `runManagers?.[i]` into `_generate`, and the end/error
            // hooks below used the same index — so passing 0 here meant the
            // tokens and the trace callbacks for prompt 3 were attributed to
            // prompt 1, while the run's own end event went to the right one.
            $thisRunManager = $runManagers[$index] ?? $runManager;

            try {
                $result = $this->dispatchGenerate($messages, $options, $thisRunManager, $config);
            } catch (\Throwable $e) {
                // Without this the trace shows a run that started and never
                // ended: no error event, no token counts, and a span that hangs
                // in any UI reading it. Upstream catches per-prompt
                // (`allSettled`) and calls `handleLLMError` before rethrowing.
                $thisRunManager?->handleLLMError($e);

                throw $e;
            }

            foreach ($result->generations as $generation) {
                // Upstream stamps a missing id from `runManagers.at(0)` here
                // specifically — the id is a property of the whole batch's run,
                // not of the individual prompt.
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
            $thisRunManager?->handleLLMEnd(new LLMResult([$result->generations], $result->llmOutput));
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
        $ended = false;

        // `finally` rather than a trailing call, because this is a generator: a
        // consumer that stops early — `break` out of a foreach, a `?->` chain
        // that gives up, an exception in the caller's own loop — abandons the
        // generator and the code after the loop never runs. Without the
        // `finally` the trace shows a run that started and never ended: no
        // completion, no error, no token counts, and a span that hangs forever
        // in whatever UI is reading it.
        try {
            foreach ($this->streamResponseChunks($messages, $options, $runManager) as $chunk) {
                $this->stampMessageId($chunk, $runManager);
                $chunk->message->response_metadata = array_merge(
                    $chunk->generationInfo,
                    $chunk->message->response_metadata,
                );
                $aggregated = $aggregated === null ? $chunk : $aggregated->concat($chunk);

                // A chunk that carries only metadata is folded in but not
                // surfaced. Upstream captures a trailing usage event in a local
                // and never yields it (`completions.ts:450-454`); yielding it
                // put a phantom EMPTY message at the end of every streamed call
                // — invisible to a consumer that concatenates text, and a
                // spurious turn to one that counts or renders each chunk.
                if ($this->isMetadataOnly($chunk)) {
                    continue;
                }

                yield [self::CHANNEL_DEFAULT, $chunk->message];
            }

            $ended = true;

            if ($aggregated === null) {
                $runManager?->handleLLMEnd(new LLMResult([[]], []));

                return;
            }

            $runManager?->handleLLMEnd(new LLMResult(
                [[new ChatGeneration($aggregated->message, $aggregated->text, $aggregated->generationInfo)]],
                $this->llmOutputFromUsage($aggregated->message),
            ));
        } catch (\Throwable $e) {
            // Mark the run as accounted for BEFORE rethrowing. A `finally` runs
            // on the way out of a `catch` too, so without this the abandoned
            // branch below fires a second `handleLLMError` for the same failure
            // — two error events for one error, which a collector that keeps
            // only the last renders as a single event and so hides entirely.
            $ended = true;
            $runManager?->handleLLMError($e);

            throw $e;
        } finally {
            // Abandoned mid-stream. This is neither success nor failure: the
            // consumer stopped, which is a legitimate thing to do with a stream.
            // Recording it as an ERROR put "Stream abandoned by the consumer"
            // — stack trace attached — into every error dashboard for what was
            // usually a deliberate early exit. Ending the run with whatever was
            // accumulated is both honest and quiet, and a genuine mid-stream
            // failure never reaches here (the `catch` above already reported it
            // and marked the run accounted-for).
            if (!$ended && $runManager !== null) {
                $runManager->handleLLMEnd(
                    new LLMResult(
                        $aggregated === null
                            ? [[]]
                            : [[new ChatGeneration($aggregated->message, $aggregated->text, $aggregated->generationInfo)]],
                        $aggregated === null ? [] : $this->llmOutputFromUsage($aggregated->message),
                    ),
                    // Marked, not silent. Ending it plainly says "the consumer
                    // finished and everything was well", which is a different
                    // fact from "the consumer stopped"; reporting it as an error
                    // instead puts a deliberate early exit into error
                    // dashboards. The flag is how both are told apart.
                    ['abandoned' => true],
                );
            }
        }
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
     * Fold per-prompt `llmOutput` into the one the returned `LLMResult` carries.
     *
     * Each entry is a single prompt's output; this produces the batch summary.
     * The base returns an empty array, which means a batch's token usage is
     * discarded — a caller invoking several prompts at once gets no totals at
     * all. A provider that wants a different shape (a provider-specific
     * aggregate, a string join) overrides this.
     *
     * @param list<array<string, mixed>> $llmOutputs One entry per prompt.
     *
     * @return array<string, mixed>
     */
    protected function combineLLMOutput(array $llmOutputs): array
    {
        $combined = [];

        foreach ($llmOutputs as $output) {
            $combined = $this->sumOutputs($combined, $output);
        }

        return $combined;
    }

    /**
     * Add `$addend` into `$base`, recursing through nested bags.
     *
     * The recursion is not optional. `llmOutput` is shaped
     * `['tokenUsage' => ['promptTokens' => …, …]]`, so a top-level-only sum
     * would take the FIRST prompt's numbers and call them a batch total — a
     * wrong number that looks right, which is the failure mode this whole
     * exercise keeps finding.
     *
     * Non-numeric leaves are first-write-wins: there is no meaningful sum for a
     * string, and a later prompt's model name replacing an earlier one would
     * make the batch's identity depend on iteration order.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $addend
     *
     * @return array<string, mixed>
     */
    private function sumOutputs(array $base, array $addend): array
    {
        foreach ($addend as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $base[$key] = ($base[$key] ?? 0) + $value;

                continue;
            }

            if (is_array($value)) {
                $existing = is_array($base[$key] ?? null) ? $base[$key] : [];
                $base[$key] = $this->sumOutputs($existing, $value);

                continue;
            }

            if (!array_key_exists($key, $base)) {
                $base[$key] = $value;
            }
        }

        return $base;
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
     * Whether a chunk carries nothing but metadata.
     *
     * Only such a chunk belongs in the accumulated message and not in the
     * caller's stream: a provider's trailing token count, chiefly. Upstream
     * captures that event in a local and never yields it
     * (`completions.ts:450-454`).
     *
     * `additional_kwargs` counts as content, not metadata. A refusal, a
     * reasoning delta and a citation all arrive there with empty content, and
     * treating them as metadata made a refusal-only response surface **zero
     * chunks** — the only thing the model said, invisible to the caller.
     */
    private function isMetadataOnly(ChatGenerationChunk $chunk): bool
    {
        $message = $chunk->message;

        if ($message->content !== '' && $message->content !== []) {
            return false;
        }

        if ($message->additional_kwargs !== []) {
            return false;
        }

        if ($message instanceof AIMessage && $message->toolCalls !== []) {
            return false;
        }

        return $message->toolCallChunks === [];
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
