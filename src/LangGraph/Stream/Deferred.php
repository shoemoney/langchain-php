<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * A value settled later, from outside: the PHP form of a `new Promise((res, rej) => ...)` cell.
 *
 * Stands in for the `PromiseLike` projections ({@see Mux::wireChannels()}) and for the run's `output`.
 * Settling is once only, later calls are ignored, as with a promise. Distinct from
 * `LangGraph\Agents\Transformers\Deferred`, which belongs to the agent transformers.
 */
final class Deferred
{
    private bool $settled = false;

    private bool $rejected = false;

    private mixed $value = null;

    private ?\Throwable $reason = null;

    public function resolve(mixed $value = null): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->value = $value;
    }

    public function reject(mixed $reason): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->rejected = true;
        $this->reason = Types::toThrowable($reason);
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }

    public function isRejected(): bool
    {
        return $this->rejected;
    }

    /**
     * @throws \Throwable the rejection reason when rejected
     * @throws \LogicException when not settled yet
     */
    public function value(): mixed
    {
        if (!$this->settled) {
            throw new \LogicException('The value has not been settled yet.');
        }
        if ($this->rejected) {
            throw $this->reason;
        }

        return $this->value;
    }
}
