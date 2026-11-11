<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Stream;

use LangGraph\Stream\StreamChannel;

/** Replayable pull-driven projection; value() replaces PromiseLike/await. */
final class ThreadProjection implements \IteratorAggregate
{
    private readonly StreamChannel $buffer;
    private ?\Generator $cursor = null;
    private bool $started = false;
    private mixed $last = null;

    /** Transform returns zero or more projected items. */
    public function __construct(
        private readonly ThreadSubscription|ThreadProjection $source,
        private readonly \Closure $transform,
        private readonly bool $captureOnPush = false,
    ) {
        $this->buffer = StreamChannel::local();
        $this->buffer->setDriver(fn (): bool => $this->pull());
        if ($captureOnPush) {
            if (!$source instanceof ThreadSubscription) {
                throw new \InvalidArgumentException('Push capture requires a raw subscription.');
            }
            $source->setPushObserver(fn ($event) => $this->publish($event));
        }
    }

    private function publish(mixed $event): void
    {
        foreach (($this->transform)($event) as $item) {
            $this->last = $item;
            $this->buffer->push($item);
        }
    }

    private function pull(): bool
    {
        $this->cursor ??= $this->source->getIterator();
        try {
            if ($this->started) {
                $this->cursor->next();
            }
            $this->started = true;
            if (!$this->cursor->valid()) {
                $this->buffer->close();

                return false;
            }
            if (!$this->captureOnPush) {
                $this->publish($this->cursor->current());
            }

            return true;
        } catch (\Throwable $error) {
            $this->buffer->fail($error);
            throw $error;
        }
    }

    public function getIterator(): \Generator
    {
        yield from $this->buffer->iterate();
    }

    public function value(): mixed
    {
        foreach ($this as $ignored) {
        }

        return $this->last;
    }
}
