<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * Apply a runnable to every element of a list input.
 *
 * Port of `RunnableEach` from `@langchain_core/runnables`. Distinct from
 * `batch`, which is a *separate* input per call; this is one call applied N
 * times, which is how you embed a document list or classify a batch of strings
 * inside a chain.
 */
class RunnableEach extends Runnable
{
    public function __construct(private RunnableInterface $runnable)
    {
    }

    public function getName(): string
    {
        return 'RunnableEach';
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        if (!is_array($input)) {
            throw new \InvalidArgumentException(
                'RunnableEach expects a list input, got ' . get_debug_type($input) . '.'
            );
        }

        return array_map(
            fn (mixed $item): mixed => $this->runnable->invoke($item, $config),
            array_values($input)
        );
    }

    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        if (!is_array($input)) {
            throw new \InvalidArgumentException(
                'RunnableEach expects a list input, got ' . get_debug_type($input) . '.'
            );
        }

        foreach (array_values($input) as $index => $item) {
            foreach ($this->runnable->stream($item, $config) as [$channel, $chunk]) {
                yield [$channel === self::CHANNEL_DEFAULT ? $index : $channel, $chunk];
            }
        }
    }
}
