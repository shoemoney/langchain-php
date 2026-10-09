<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * An iterable that starts a fresh cursor every time it is iterated: upstream's
 * `{ [Symbol.asyncIterator]: () => ... }` objects (`toAsyncIterable`, `subgraphs`, `lifecycle`, `messages`).
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class ReplayableIterable implements \IteratorAggregate
{
    /** @var \Closure(): \Generator<int, mixed> */
    private readonly \Closure $factory;

    /**
     * @param callable(): \Generator<int, mixed> $factory
     */
    public function __construct(callable $factory)
    {
        $this->factory = \Closure::fromCallable($factory);
    }

    public function getIterator(): \Generator
    {
        return ($this->factory)();
    }
}
