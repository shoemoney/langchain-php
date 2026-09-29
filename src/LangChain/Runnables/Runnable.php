<?php

declare(strict_types=1);

namespace LangChain\Runnables;

use LangChain\Utils\Promise;

/**
 * Base implementation of {@see RunnableInterface}.
 *
 * Port of `Runnable` (the abstract base class) from `@langchain_core/runnables`.
 *
 * Subclasses override `invoke()` and, if they can produce output incrementally,
 * `stream()`. The `stream` default yields the whole `invoke` result as one
 * `default`-channel chunk, which is the correct — if not especially lively —
 * behaviour for a component that computes atomically.
 */
abstract class Runnable implements RunnableInterface
{
    /** The default output channel name. */
    public const CHANNEL_DEFAULT = 'default';

    /** The channel carrying retriever results. */
    public const CHANNEL_RETRIEVER = 'retriever';

    /** The channel carrying tool output. */
    public const CHANNEL_TOOL = 'tool';

    /** The channel carrying model output. */
    public const CHANNEL_MODEL = 'model';

    /** The channel carrying prompt output. */
    public const CHANNEL_PROMPT = 'prompt';

    public function getName(): string
    {
        $parts = explode('\\', static::class);

        return end($parts);
    }

    abstract public function invoke(mixed $input, ?RunnableConfig $config = null): mixed;

    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        yield [self::CHANNEL_DEFAULT, $this->invoke($input, $config)];
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return array_map(
            fn (mixed $input): mixed => $this->invoke($input, $config),
            array_values($inputs)
        );
    }

    public function transform(iterable $input, ?RunnableConfig $config = null): \Generator
    {
        foreach ($input as $item) {
            foreach ($this->stream($item, $config) as $pair) {
                yield $pair;
            }
        }
    }

    // ---- composition ----------------------------------------------------

    /**
     * Compose: run `$next` on this runnable's output.
     */
    public function pipe(RunnableInterface $next): RunnableSequence
    {
        return new RunnableSequence([$this, $next]);
    }

    /**
     * Feed this runnable's output into a callback rather than another runnable.
     */
    public function pipeTo(callable $fn): RunnableLambda
    {
        return new RunnableLambda(
            fn (mixed $input): mixed => $input,
            ['func' => $fn]
        );
    }

    /**
     * Bind kwargs/config that are applied to every invocation of this runnable.
     *
     * @param array<string, mixed> $kwargs
     */
    public function bind(array $kwargs = [], ?array $config = null): RunnableBinding
    {
        return new RunnableBinding($this, $kwargs, $config);
    }

    /**
     * Run a list of branches in parallel, keyed by name, and return every
     * branch's result keyed the same way.
     */
    public function map(): RunnableParallel
    {
        return new RunnableParallel([]);
    }

    /**
     * Try each runnable in turn, returning the first that succeeds.
     *
     * @param list<RunnableInterface> $runnables
     */
    public function withFallbacks(array $fallbacks): RunnableWithFallbacks
    {
        return new RunnableWithFallbacks($this, $fallbacks);
    }
}
