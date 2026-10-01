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
// This file is BOTH a composer `autoload.files` entry (PHP cannot autoload functions, so the
// bootstrap must include it) AND reachable through the PSR-4 class loader, because its basename is a
// valid class name in this namespace. Composer's `files` guard only protects its own includes; the
// class loader uses a plain `include`. So `class_exists(__NAMESPACE__ . '\\coerceToRunnable')` includes this
// file a SECOND time, and an unguarded declaration raises an uncatchable
// `Cannot redeclare function` fatal that kills the process.
//
// The guard makes the second include a no-op. Every test in this suite calls the function and none
// probes for the class, so nothing else would ever observe this.
if (!\function_exists(__NAMESPACE__ . '\\coerceToRunnable')) {
    function coerceToRunnable(mixed $thing, ?string $name = null): RunnableInterface
    {
        if ($thing instanceof RunnableInterface) {
            return $thing;
        }

        // `array_is_list` matters here and its absence was a real defect: a NAMED
        // two-key map such as ['a' => $one, 'b' => $one] has count 2 but no index 0,
        // so `$thing[0]` raised `Warning: Undefined array key 0` from inside the
        // type test, twice over — once here and once in RunnableSequence::from() —
        // before the clean InvalidArgumentException. The `[name, runnable]` tuple is
        // positional by definition, so the list check is the correct guard, not a
        // workaround. Upstream has no equivalent hazard: a JS object has no
        // positional indexing to guess at, and `coerceToRunnableLike` tests for
        // `invoke`/`stream` and throws otherwise.
        if (is_array($thing) && array_is_list($thing) && count($thing) === 2 && is_string($thing[0]) && (is_callable($thing[1]) || $thing[1] instanceof RunnableInterface)) {
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

}
