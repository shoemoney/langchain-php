<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * Minimal push-based stream with named channels.
 *
 * Port of the `@langchain/core/utils/stream` `Observable`/`EventSource` pair
 * that every `stream()` implementation emits through. The JS original is a
 * WHATWG-style push stream where each push is a `[channelName, value]` tuple;
 * subscribers receive tuples and can filter on the channel name.
 *
 * In PHP the natural shape is a {@see \Generator}, so this class wraps a
 * generator and adds the subscribe/filter ergonomics the ported code relies on
 * (`forEach`, `filter`, `concat`, `tee`, `withLatest`).
 */
final class Observable implements \IteratorAggregate
{
    /** @var \Generator<int, array{string, mixed}> */
    private \Generator $gen;

    /** @var callable(string, mixed): void|null */
    private $tap;

    private ?self $shared = null;

    /** @param \Generator<int, array{string, mixed}> $gen */
    private function __construct(\Generator $gen)
    {
        $this->gen = $gen;
    }

    /**
     * @param iterable<array{string, mixed}> $iterable
     */
    public static function fromIterable(iterable $iterable): self
    {
        return new self(self::toGenerator($iterable));
    }

    /**
     * @param \Generator<int, array{string, mixed}> $gen
     */
    public static function fromGenerator(\Generator $gen): self
    {
        return new self($gen);
    }

    /**
     * Convert an arbitrary iterable of `[channel, value]` tuples into a
     * generator, re-keying so a filtered stream still yields 0..n-1.
     *
     * @param iterable<array{string, mixed}> $iterable
     */
    private static function toGenerator(iterable $iterable): \Generator
    {
        $i = 0;
        foreach ($iterable as $tuple) {
            yield $i++ => $tuple;
        }
    }

    public function getIterator(): \Generator
    {
        return $this->gen;
    }

    /**
     * Observe every push without consuming the sequence permanently.
     */
    public function tap(callable $fn): self
    {
        $this->tap = $fn;

        return $this;
    }

    /**
     * Replay the stream: the same tuples are available to every consumer,
     * buffered as they are produced.
     */
    public function share(): self
    {
        if ($this->shared !== null) {
            return $this->shared;
        }
        $buffer = [];
        $this->shared = new self((function () use (&$buffer) {
            $i = 0;
            foreach ($this->gen as [$channel, $value]) {
                $buffer[] = [$channel, $value];
                yield $i++ => [$channel, $value];
            }
        })());
        // Subsequent subscribers replay the buffer, then follow the live gen.
        $inner = $this->shared;
        $this->shared = new self((function () use (&$buffer, $inner) {
            $i = 0;
            foreach ($buffer as [$channel, $value]) {
                yield $i++ => [$channel, $value];
            }
        })());

        return $this->shared;
    }

    /**
     * Yield only tuples on $channels.
     */
    public function filter(callable $predicate): self
    {
        $inner = $this->gen;
        return new self((function () use ($inner, $predicate) {
            $i = 0;
            foreach ($inner as [$channel, $value]) {
                if ($predicate($value, $channel)) {
                    yield $i++ => [$channel, $value];
                }
            }
        })());
    }

    /**
     * Apply a mapper to each tuple, preserving the channel.
     */
    public function map(callable $mapper): self
    {
        $inner = $this->gen;
        return new self((function () use ($inner, $mapper) {
            $i = 0;
            foreach ($inner as [$channel, $value]) {
                yield $i++ => [$channel, $mapper($value, $channel)];
            }
        })());
    }

    /**
     * Concatenate a second stream after this one.
     */
    public function concat(self $other): self
    {
        $inner = $this->gen;
        return new self((function () use ($inner, $other) {
            $i = 0;
            foreach ($inner as [$channel, $value]) {
                yield $i++ => [$channel, $value];
            }
            foreach ($other->getIterator() as [$channel, $value]) {
                yield $i++ => [$channel, $value];
            }
        })());
    }

    /**
     * Split into two independent streams.
     *
     * @return array{0: self, 1: self}
     */
    public function tee(): array
    {
        $first = self::fromIterable([]);
        $second = self::fromIterable([]);
        $this->forEach(function ($channel, $value) use ($first, $second) {
            $first->push($channel, $value);
            $second->push($channel, $value);
        });

        return [$first, $second];
    }

    /**
     * Consume the stream, invoking $fn for every tuple.
     */
    public function forEach(callable $fn): void
    {
        foreach ($this->gen as [$channel, $value]) {
            if ($this->tap !== null) {
                ($this->tap)($channel, $value);
            }
            $fn($value, $channel);
        }
    }

    /**
     * Push a tuple onto a manual observable. Used by {@see tee()}.
     *
     * @internal
     */
    private function push(string $channel, mixed $value): void
    {
        // Manual pushes are appended through a queue-backed iterator so a
        // tee() target can be consumed after the fact.
        if (!$this->manualQueue instanceof \SplQueue) {
            $this->manualQueue = new \SplQueue();
        }
        $this->manualQueue->enqueue([$channel, $value]);
    }

    private ?\SplQueue $manualQueue = null;

    /**
     * Drain a manual observable, falling back to the backing generator.
     */
    public function drain(): \Generator
    {
        if ($this->manualQueue !== null) {
            $i = 0;
            while (!$this->manualQueue->isEmpty()) {
                yield $i++ => $this->manualQueue->dequeue();
            }

            return;
        }
        yield from $this->gen;
    }
}
