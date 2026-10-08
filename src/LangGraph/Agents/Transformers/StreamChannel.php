<?php

declare(strict_types=1);

namespace LangGraph\Agents\Transformers;

/**
 * A replayable log of values a transformer pushes, readable by any number of consumers.
 *
 * Counterpart of langgraph's `StreamChannel.local()`. PHP has no event loop to suspend an iterator on, so
 * iterating yields what has been pushed so far and then ends; a failed channel raises its error once the
 * pushed values are exhausted.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class StreamChannel implements \IteratorAggregate
{
    /** @var list<mixed> */
    private array $items = [];

    private bool $closed = false;

    private ?\Throwable $error = null;

    public function push(mixed $item): void
    {
        if ($this->closed) {
            return;
        }
        $this->items[] = $item;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function fail(mixed $error): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->error = $error instanceof \Throwable ? $error : new \RuntimeException(\is_string($error) ? $error : get_debug_type($error));
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** @return list<mixed> */
    public function items(): array
    {
        return $this->items;
    }

    public function getIterator(): \Generator
    {
        yield from $this->items;

        if ($this->error !== null) {
            throw $this->error;
        }
    }
}
