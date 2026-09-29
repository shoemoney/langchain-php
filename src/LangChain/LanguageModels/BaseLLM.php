<?php

declare(strict_types=1);

namespace LangChain\LanguageModels;

use LangChain\LanguageModels\Outputs\GenerationChunk;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\PromptValue;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Tracers\Serialized;

/**
 * The base for completion models — prompt in, text out.
 *
 * Port of `BaseLLM` from `@langchain/core/language_models/llms`.
 *
 * The split from {@see BaseChatModel} is real, not cosmetic. A completion model
 * never sees structured messages, so there is no tool-calling delta to merge,
 * no content blocks, and no message id to stamp. Its chunks fold with a plain
 * string concat — which is exactly what makes it cheap, and exactly why it
 * cannot be substituted for a chat model.
 *
 * @template-extends BaseLanguageModel<string>
 */
abstract class BaseLLM extends BaseLanguageModel
{
    /** @return list<string> */
    public static function lcNamespace(): array
    {
        return ['langchain', 'llms'];
    }

    public function modelType(): string
    {
        return 'llm';
    }

    /**
     * Run the model over raw prompt strings.
     *
     * @param list<string>         $prompts
     * @param array<string, mixed> $options
     */
    abstract protected function generatePrompts(array $prompts, array $options = [], ?CallbackManagerForLLMRun $runManager = null): LLMResult;

    /**
     * Stream a completion as chunks arrive.
     *
     * @param array<string, mixed> $options
     * @return \Generator<int, GenerationChunk>
     */
    protected function streamResponseChunks(
        string $prompt,
        array $options = [],
        ?CallbackManagerForLLMRun $runManager = null,
    ): \Generator {
        throw new \RuntimeException('Not implemented.');
    }

    /**
     * Whether this subclass overrode {@see self::streamResponseChunks()}.
     */
    public function supportsStreaming(): bool
    {
        return (new \ReflectionMethod($this, 'streamResponseChunks'))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * @param list<PromptValue>     $promptValues
     * @param array<string, mixed>  $options
     * @param list<object>|null     $callbacks
     */
    public function generatePrompt(array $promptValues, array $options = [], ?array $callbacks = null): LLMResult
    {
        $prompts = [];
        foreach ($promptValues as $promptValue) {
            $prompts[] = $promptValue instanceof PromptValue
                ? $promptValue->toStringValue()
                : self::convertInputToPromptValue($promptValue)->toStringValue();
        }

        $config = new RunnableConfig(callbacks: $callbacks ?? []);

        return $this->generateStrings($prompts, $config, $options);
    }

    /**
     * @param list<string>         $prompts
     * @param array<string, mixed> $options
     */
    public function generateStrings(array $prompts, ?RunnableConfig $config = null, array $options = []): LLMResult
    {
        $invocationParams = $this->invocationParams($options);
        $callbackManager = $this->configureCallbacks(
            $config,
            $config?->metadata ?? [],
            $this->filterInvocationParamsForTracing($invocationParams),
        );

        $runManagers = $callbackManager?->handleLLMStart(
            new Serialized(static::lcId(), $this->kwargs()),
            $prompts,
            $config?->runId[0] ?? null,
            ['options' => $options, 'invocation_params' => $invocationParams, 'batch_size' => count($prompts)],
            [],
            [],
            $config?->runName,
        );
        $runManager = $runManagers[0] ?? null;

        // See BaseChatModel::dispatchGenerate for why the handler — not the
        // caller — decides whether streaming is used.
        if ($this->supportsStreaming() && !$this->disableStreaming && $this->handlerPrefersStreaming($runManager) && count($prompts) === 1) {
            return $this->aggregateStream($prompts[0], $options, $runManager, $runManagers);
        }

        $output = $this->generatePrompts($prompts, $options, $runManager);
        $output->runIds = array_map(static fn (object $m): string => $m->runId, $runManagers ?? []);

        foreach ($this->flattenLLMResult($output) as $index => $result) {
            ($runManagers[$index] ?? null)?->handleLLMEnd($result);
        }

        return $output;
    }

    /**
     * Stream a completion, yielding text fragments.
     *
     * @return \Generator<int, array{0: string, 1: string}>
     */
    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        if (!$this->supportsStreaming() || $this->disableStreaming) {
            yield [self::CHANNEL_DEFAULT, (string) $this->invoke($input, $config)];

            return;
        }

        $prompt = self::convertInputToPromptValue($input)->toStringValue();
        $options = $config?->options ?? [];

        $invocationParams = $this->invocationParams($options);
        $callbackManager = $this->configureCallbacks(
            $config,
            $config?->metadata ?? [],
            $this->filterInvocationParamsForTracing($invocationParams),
        );

        $runManagers = $callbackManager?->handleLLMStart(
            new Serialized(static::lcId(), $this->kwargs()),
            [$prompt],
            $config?->runId[0] ?? null,
            ['options' => $options, 'invocation_params' => $invocationParams, 'batch_size' => 1],
            [],
            [],
            $config?->runName,
        );
        $runManager = $runManagers[0] ?? null;

        $aggregated = new GenerationChunk('');
        try {
            foreach ($this->streamResponseChunks($prompt, $options, $runManager) as $chunk) {
                $aggregated = $aggregated->concat($chunk);
                yield [self::CHANNEL_DEFAULT, $chunk->text];
            }
        } catch (\Throwable $e) {
            $runManager?->handleLLMError($e);

            throw $e;
        }

        $runManager?->handleLLMEnd(new LLMResult([[$aggregated]], []));
    }

    /**
     * Drain the stream and fold it into one {@see LLMResult}.
     *
     * @param array<string, mixed> $options
     * @param list<object>|null    $runManagers
     */
    private function aggregateStream(
        string $prompt,
        array $options,
        ?CallbackManagerForLLMRun $runManager,
        ?array $runManagers,
    ): LLMResult {
        $aggregated = null;
        foreach ($this->streamResponseChunks($prompt, $options, $runManager) as $chunk) {
            $aggregated = $aggregated === null ? $chunk : $aggregated->concat($chunk);
        }

        if ($aggregated === null) {
            throw new \RuntimeException('Received empty response from LLM call.');
        }

        $output = new LLMResult([[$aggregated]], []);
        $output->runIds = array_map(static fn (object $m): string => $m->runId, $runManagers ?? []);
        $runManager?->handleLLMEnd($output);

        return $output;
    }

    /**
     * Split one batched result into one result per prompt.
     *
     * Only the first carries `llmOutput` — the token counts and finish reasons
     * there are totals for the whole batch, and repeating them on every prompt
     * invites a consumer to sum them and double-count.
     *
     * @return list<LLMResult>
     */
    protected function flattenLLMResult(LLMResult $llmResult): array
    {
        $results = [];
        foreach ($llmResult->generations as $i => $genList) {
            if ($i === 0) {
                $results[] = new LLMResult([$genList], $llmResult->llmOutput);
            } else {
                $llmOutput = $llmResult->llmOutput === [] ? [] : array_merge($llmResult->llmOutput, ['tokenUsage' => []]);
                $results[] = new LLMResult([$genList], $llmOutput);
            }
        }

        return $results;
    }

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
}
