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
        public array $configFactories = [],
    ) {
    }

    public function getName(): string
    {
        return $this->bound->getName();
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $merged = $this->mergeConfig($config);

        return self::invokeWithConfig($this->bound, $input, $merged ?? new RunnableConfig());
    }

    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        yield from self::streamWithConfig($this->bound, $input, $this->mergeConfig($config) ?? new RunnableConfig());
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        $merged = $this->mergeConfig($config);

        return $this->bound->batch($inputs, $merged, $options);
    }

    public function streamEvents(
        mixed $input,
        ?RunnableConfig $config = null,
        string $version = 'v2',
        array $streamOptions = [],
        ?string $encoding = null,
    ): \Generator {
        if ($this->bound instanceof Runnable) {
            return $this->bound->streamEvents($input, $this->mergeConfig($config), $version, $streamOptions, $encoding);
        }

        // Interface-only implementers use the base event adapter; it resolves
        // this binding once before running the target stream.
        return parent::streamEvents($input, $config, $version, $streamOptions, $encoding);
    }

    public function pipe(RunnableInterface $next): RunnableSequence
    {
        return new RunnableSequence([$this, $next]);
    }

    /**
     * Source merges bound config, call config, kwargs, then each config factory.
     * Every factory receives the same initial merged values, once per call.
     */
    private function mergeConfig(?RunnableConfig $config): RunnableConfig
    {
        $merged = RunnableConfig::mergeConfigs($this->config, $config, $this->kwargs);
        $results = [];
        foreach ($this->configFactories as $factory) {
            $results[] = $factory(clone $merged);
        }

        return RunnableConfig::mergeConfigs($merged, ...$results);
    }

    /** @param array<string, mixed> $config */
    public function withConfig(array $config): RunnableBinding
    {
        return new self($this->bound, $this->kwargs, [...($this->config ?? []), ...$config]);
    }

    /** @param array{onStart?: callable, onEnd?: callable, onError?: callable} $listeners */
    public function withListeners(array $listeners): RunnableBinding
    {
        return new self($this->bound, $this->kwargs, $this->config, [self::listenerConfigFactory($listeners)]);
    }
}
