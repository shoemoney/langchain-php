<?php

declare(strict_types=1);

namespace LangChain\LanguageModels;

use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\PromptValue;
use LangChain\Tracers\CallbackManager;

/**
 * The base for completion and chat models.
 *
 * Port of `BaseLanguageModel` from `@langchain/core/language_models/base`.
 *
 * What this layer adds over {@see BaseLangChain} is the split between *how a
 * model is invoked* and *how it answers*. `generate()` owns the invocation:
 * callbacks, caching, the streamed-vs-eager decision, aggregation. A subclass
 * implements only the answer — `_generate()`, or `_streamResponseChunks()` if
 * it can stream.
 *
 * That split is the reason a streaming model can serve an `invoke()` caller
 * with no loss: when a handler wants tokens, `generate()` routes through the
 * stream and aggregates the chunks itself rather than making the caller aware
 * that two code paths exist.
 *
 * @template TOutput  What `invoke()` returns — a string, or a message chunk.
 * @extends BaseLangChain<mixed, TOutput>
 */
abstract class BaseLanguageModel extends BaseLangChain
{
    /** The result of the most recent invocation, for callers that batch. */
    public ?LLMResult $lastResult = null;

    /**
     * Never route through the streaming path, whatever any handler asks for.
     *
     * Some providers' streaming implementations are lossy or outright broken for
     * certain inputs, and this is the escape hatch. It also suppresses implicit
     * streaming for `invoke()` callers whose handlers happen to want tokens.
     */
    public bool $disableStreaming = false;

    /**
     * The identifying parameters of this model, for caching and serialization.
     *
     * Everything that would make two calls to this instance produce different
     * results for the same input — model name, temperature, base URL — and
     * nothing that would not. Two model instances with equal identifying params
     * are interchangeable and must share a cache entry.
     *
     * @return array<string, mixed>
     */
    public function identifyingParams(): array
    {
        return [];
    }

    /**
     * A stable cache key for one call.
     *
     * Built from the identifying params plus the call options, sorted so key
     * order in the caller's array cannot change the key. The `_type` and
     * `_model` entries are what stop two *different* model classes with identical
     * params from colliding.
     *
     * @param array<string, mixed> $callOptions
     */
    public function serializedCacheKeyParametersForCall(array $callOptions = []): string
    {
        $params = array_merge($this->identifyingParams(), $callOptions);
        $params['_type'] = $this->llmType();
        $params['_model'] = $this->modelType();

        $entries = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $entries[] = $key . ':' . json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        sort($entries, \SORT_STRING);

        return implode(',', $entries);
    }

    /**
     * A short, stable identifier for the model class.
     *
     * Appears in the trace namespace and in cache keys, so it must not change
     * between runs of the same class.
     */
    abstract public function llmType(): string;

    /**
     * Which family this model belongs to — 'chat' or 'llm'.
     *
     * Deliberately a constant per class rather than an instance property: it
     * describes the interface shape, not the configuration.
     */
    public function modelType(): string
    {
        return 'base_language_model';
    }

    /**
     * Run the model over one or more prompts.
     *
     * @param list<PromptValue>  $promptValues
     * @param array<string, mixed> $options
     */
    abstract public function generatePrompt(array $promptValues, array $options = [], ?array $callbacks = null, ?RunnableConfig $config = null): LLMResult;

    /**
     * Run the model and return the single first completion.
     *
     * The narrowing step: everything above deals in lists because a model may be
     * given many inputs, and everything below (chains, agents) wants one answer.
     *
     * @param array<string, mixed> $options
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $promptValue = self::convertInputToPromptValue($input);
        // The config goes across as a FOURTH argument, not folded into `$options`. `$options` is the
        // INVOCATION options (max_tokens, stop, tools, failOnDemand) — aliasing the two is what broke
        // four tests when this was first attempted. `invoke()` used to destructure the whole
        // `RunnableConfig` down to `options` and `callbacks`, silently discarding the other fourteen
        // fields: a caller who bound a `runName` or a tag got neither, and nothing reported it.
        $result = $this->generatePrompt([$promptValue], $config?->options ?? [], $config?->callbacks, $config);
        $this->lastResult = $result;

        return $this->narrowResult($result);
    }

    /**
     * Reduce an {@see LLMResult} to whatever this model class returns.
     *
     * @template-extends TOutput
     */
    protected function narrowResult(LLMResult $result): mixed
    {
        $generation = $result->firstGeneration();
        if ($generation === null) {
            throw new \RuntimeException('Model invocation produced no generations.');
        }

        return $generation->text;
    }

    /**
     * The parameters that would be sent on a call.
     *
     * Surfaced into tracer metadata rather than into token events. The split
     * matters: these values are useful once per run (to reproduce the call) and
     * pure noise on every token.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function invocationParams(array $options = []): array
    {
        return [];
    }

    /**
     * Invocation params minus the bulky fields.
     *
     * `tools`, `functions`, `messages`, and `response_format` are stripped:
     * they can be enormous, and a trace that embeds them is a trace nobody
     * reads.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function filterInvocationParamsForTracing(array $params): array
    {
        unset($params['tools'], $params['functions'], $params['messages'], $params['response_format']);

        return $params;
    }

    /**
     * Merge the caller's metadata with the model's own LangSmith parameters.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function inheritableMetadata(array $configMetadata, array $options): array
    {
        return array_merge($configMetadata, $this->lsParamsWithDefaults($options));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function lsParams(array $options): array
    {
        return [
            'ls_model_type' => $this->modelType() === 'llm' ? 'llm' : 'chat',
            'ls_stop' => $options['stop'] ?? null,
            'ls_provider' => $this->getName(),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function lsParamsWithDefaults(array $options): array
    {
        return array_merge($this->lsParams($options), ['ls_integration' => 'langchain_model']);
    }

    /**
     * Configure callbacks for one invocation.
     *
     * @param array<string, mixed> $metadata
     */
    protected function configureCallbacks(?RunnableConfig $config, array $metadata, array $invocationParams): ?CallbackManager
    {
        return CallbackManager::configure(
            $config?->callbacks ?? null,
            $this->callbacks === [] ? null : $this->callbacks,
            $config?->tags ?? $this->tags,
            $this->tags,
            array_merge($config?->metadata ?? [], $metadata),
            $this->metadata,
            ['verbose' => $this->verbose],
        );
    }
}
