<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * Merge a map's results into its input rather than replacing the input with them.
 *
 * Port of `RunnableAssign` from `@langchain_core/runnables`.
 *
 * This is the one runnable that can see its own input. Every branch of the inner
 * {@see RunnableParallel} receives the *whole* input, so a later branch can read
 * an earlier branch's result — which is how
 * {@see StructuredOutput::assembleStructuredOutputPipeline()} produces
 * `{raw: <message>, parsed: <parsed>}` rather than a bare parse.
 *
 * The distinction from {@see RunnableParallel} is entirely in the return value:
 *
 *  - `RunnableParallel` returns **only** the branch keys;
 *  - `RunnableAssign` returns the input **with** the branch keys layered on top.
 *
 * Collisions resolve in the mapping's favour, matching the JS object spread
 * `{...input, ...mapperResult}`. That ordering is what lets a mapping deliberately
 * override a value already present in the input.
 */
class RunnableAssign extends Runnable
{
    /** The branches whose results get layered onto the input. */
    public RunnableParallel $mapper;

    public function __construct(RunnableParallel $mapper)
    {
        $this->mapper = $mapper;
    }

    public function getName(): string
    {
        return 'RunnableAssign';
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $mapped = $this->mapper->invoke($input, $config);

        // A non-record input contributes no keys of its own, so there is
        // nothing to layer the mapping over and the mapping stands alone. The
        // JavaScript spread would produce character indices for a string; that
        // is never what a caller means by "pass a string through", and
        // reproducing it would be a surprise rather than a fidelity.
        return is_array($input) ? array_merge($input, $mapped) : $mapped;
    }

    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        yield [self::CHANNEL_DEFAULT, $this->invoke($input, $config)];
    }

    /** @param list<mixed> $inputs */
    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return array_map(
            fn (mixed $input): mixed => $this->invoke($input, $config),
            array_values($inputs),
        );
    }

    public function pipe(RunnableInterface $next): RunnableSequence
    {
        return new RunnableSequence([$this, $next]);
    }
}
