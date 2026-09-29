<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * Attach fixed kwargs and/or config to a runnable.
 *
 * Port of `RunnableBinding` from `@langchain_core/runnables`.
 *
 * A binding is transparent: it forwards `invoke` to the wrapped runnable with
 * the bound config merged in. This is how per-call settings (a different model,
 * a temperature override, extra tags) are applied to one branch of a chain
 * without rebuilding the chain.
 */
class RunnableBinding extends Runnable
{
    public function __construct(
        public RunnableInterface $bound,
        public array $kwargs = [],
        public ?array $config = null,
    ) {
    }

    public function getName(): string
    {
        return 'RunnableBinding';
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $merged = $this->mergeConfig($config);

        return $this->bound->invoke($input, $merged);
    }

    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        yield from $this->bound->stream($input, $this->mergeConfig($config));
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        $merged = $this->mergeConfig($config);

        return $this->bound->batch($inputs, $merged, $options);
    }

    public function pipe(RunnableInterface $next): RunnableSequence
    {
        return new RunnableSequence([$this, $next]);
    }

    /**
     * Merge the bound config *under* the call-time config, so an explicit
     * caller setting always wins over a bound default.
     */
    private function mergeConfig(?RunnableConfig $config): ?RunnableConfig
    {
        if ($this->config === null) {
            return $config;
        }

        $bound = RunnableConfig::fromArray($this->config);
        if ($config === null) {
            return $bound;
        }

        $merged = clone $config;
        if ($bound->tags !== []) {
            $merged->tags = array_merge($bound->tags, $config->tags);
        }
        if ($bound->metadata !== []) {
            $merged->metadata = $bound->metadata + $config->metadata;
        }
        if ($bound->callbacks !== []) {
            $merged->callbacks = array_merge($bound->callbacks, $config->callbacks);
        }
        if ($bound->configurable !== []) {
            $merged->configurable = $bound->configurable + $config->configurable;
        }
        if ($bound->maxConcurrency !== null) {
            $merged->maxConcurrency = $config->maxConcurrency ?? $bound->maxConcurrency;
        }
        if ($bound->runName !== null) {
            $merged->runName = $config->runName ?? $bound->runName;
        }

        return $merged;
    }
}
