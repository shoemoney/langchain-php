<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * Cancellation for a run: upstream's `AbortController` and its `AbortSignal` in one object.
 *
 * Callable as a poll (`$signal()` is true once aborted), which is the form the SDK and the engine take, as
 * in {@see \LangGraph\Pregel\RemoteRunStream::signal()}. PHP has no event loop to interrupt, so abort is
 * observed between chunks: the run stops at the next chunk boundary and fails with the abort reason.
 */
final class AbortSignal
{
    private bool $aborted = false;

    private mixed $reason = null;

    public function abort(mixed $reason = null): void
    {
        if ($this->aborted) {
            return;
        }
        $this->aborted = true;
        $this->reason = $reason;
    }

    public function aborted(): bool
    {
        return $this->aborted;
    }

    public function reason(): mixed
    {
        return $this->reason;
    }

    public function __invoke(): bool
    {
        return $this->aborted;
    }
}
