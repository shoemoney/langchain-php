<?php

declare(strict_types=1);

namespace LangGraph\Agents\Transformers;

/**
 * A value settled later, from outside: the PHP form of the `new Promise((res, rej) => ...)` cells the
 * transformers hand out as `output`, `status` and `error`.
 *
 * Settling is once only; later calls are ignored, as with a promise.
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
        $this->reason = $reason instanceof \Throwable ? $reason : new \RuntimeException(\is_string($reason) ? $reason : get_debug_type($reason));
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
     * The settled value.
     *
     * @throws \Throwable the rejection reason when rejected
     * @throws \LogicException when not settled yet (there is no event loop to wait on)
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
