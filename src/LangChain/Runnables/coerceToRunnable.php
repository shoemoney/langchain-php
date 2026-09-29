<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * Accept any "runnable-like" and produce a {@see RunnableInterface}.
 *
 * Port of the coercion helper used throughout `@langchain_core/runnables`.
 * A runnable-like is a {@see RunnableInterface}, a `callable`, or a `[$name,
 * $callable]` pair. Prompts, models, and tools also pass through untouched,
 * which lets `pipe()` accept a raw closure wherever a component is expected.
 */
function coerceToRunnable(mixed $thing, ?string $name = null): RunnableInterface
{
    if ($thing instanceof RunnableInterface) {
        return $thing;
    }

    if (is_array($thing) && count($thing) === 2 && is_string($thing[0]) && (is_callable($thing[1]) || $thing[1] instanceof RunnableInterface)) {
        return RunnableLambda::from(
            static fn (mixed $input): mixed => coerceToRunnable($thing[1])->invoke($input),
            []
        );
    }

    if (is_callable($thing)) {
        return RunnableLambda::from($thing);
    }

    throw new \InvalidArgumentException(sprintf(
        'Expected a RunnableInterface or callable%s, got %s.',
        $name !== null ? " for \"{$name}\"" : '',
        get_debug_type($thing)
    ));
}
