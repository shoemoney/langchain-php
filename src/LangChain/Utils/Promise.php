<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * A lazily-settled, cooperatively-scheduled unit of asynchronous work.
 *
 * Port of `@langchain/core/utils/promise`. LangChain JS threads a `Promise`
 * through every public entry point (`invoke`, `stream`, `batch`), which makes
 * the whole surface combinable and lets callers `await` any of it. PHP has no
 * built-in equivalent, so this class provides the same algebra:
 *
 *  - a value may be a plain value, a {@see Generator}, or a {@see Promise};
 *  - {@see self::then()} flattens and chains, exactly like a JS `then`;
 *  - {@see Await::sync()} drives a promise to completion on the current stack
 *    by suspending into a {@see \Fiber}.
 *
 * The implementation is deliberately eager where it can be: when a value is
 * already available the promise is settled on construction, so the common
 * "no real async involved" case costs nothing and cannot deadlock.
 */
final class Promise
{
    public const PENDING = 'pending';
    public const FULFILLED = 'fulfilled';
    public const REJECTED = 'rejected';

    private string $state = self::PENDING;

    private mixed $value = null;

    private ?\Throwable $reason = null;

    /** @var list<callable(self):mixed> */
    private array $fulfillHandlers = [];

    /** @var list<callable(\Throwable):mixed> */
    private array $rejectHandlers = [];

    /** @var list<callable():void> */
    private array $finallyHandlers = [];

    private ?self $chainedSource = null;

    private function __construct()
    {
    }

    /**
     * Create a promise already fulfilled with $value.
     *
     * A nested promise is adopted rather than wrapped, so awaiting the outer
     * promise yields the inner value — the same flattening `Promise.resolve`
     * performs in JS.
     */
    public static function resolved(mixed $value = null): self
    {
        if ($value instanceof self) {
            return $value;
        }
        $p = new self();
        $p->settleFulfilled($value);

        return $p;
    }

    /**
     * Create a promise already rejected with $reason.
     */
    public static function rejected(\Throwable $reason): self
    {
        $p = new self();
        $p->settleRejected($reason);

        return $p;
    }

    /**
     * Lift any value into a promise.
     *
     * Unlike a naive `new Promise($fn)`, a `Generator` is *not* run here — it is
     * handed to the consumer, which advances it deliberately. That preserves
     * streaming semantics: a generator that yields chunks must not be drained
     * by the promise machinery.
     */
    public static function from(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::resolved($value);
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    public function isFulfilled(): bool
    {
        return $this->state === self::FULFILLED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::REJECTED;
    }

    public function settled(): bool
    {
        return $this->state !== self::PENDING;
    }

    private function settleFulfilled(mixed $value): void
    {
        if ($this->settled()) {
            return;
        }
        $this->state = self::FULFILLED;
        $this->value = $value;
        $this->flush();
    }

    private function settleRejected(\Throwable $reason): void
    {
        if ($this->settled()) {
            return;
        }
        $this->state = self::REJECTED;
        $this->reason = $reason;
        $this->flush();
    }

    private function flush(): void
    {
        $handlers = $this->state === self::FULFILLED ? $this->fulfillHandlers : $this->rejectHandlers;
        $this->fulfillHandlers = [];
        $this->rejectHandlers = [];

        foreach ($handlers as $handler) {
            $this->invokeHandler($handler);
        }

        $finally = $this->finallyHandlers;
        $this->finallyHandlers = [];
        foreach ($finally as $handler) {
            $handler();
        }
    }

    private function invokeHandler(callable $handler): void
    {
        try {
            $next = $handler($this);
        } catch (\Throwable $e) {
            // A throwing `then` callback becomes a rejected result rather than
            // propagating out of the scheduler, matching JS semantics.
            if ($this->settled() && $this->state === self::FULFILLED) {
                $this->settleRejected($e);
            }

            return;
        }

        if ($next === null) {
            return;
        }

        if ($next instanceof self) {
            $next->chainedSource = $this;
            $this->adopt($next);

            return;
        }

        // A callback returning a Generator is a stream; the promise adopts the
        // generator itself so the caller can iterate it lazily.
        $this->settleFulfilled($next);
    }

    private function adopt(self $other): void
    {
        if ($other->settled()) {
            $this->mirror($other);

            return;
        }
        $other->fulfillHandlers[] = fn (self $p) => $this->settleFulfilled($p->value);
        $other->rejectHandlers[] = fn (\Throwable $e) => $this->settleRejected($e);
    }

    private function mirror(self $other): void
    {
        if ($other->state === self::FULFILLED) {
            $this->settleFulfilled($other->value);
        } else {
            $this->settleRejected($other->reason);
        }
    }

    /**
     * Attach a fulfillment/rejection handler, mirroring `Promise.then`.
     *
     * The handler receives this promise, so it can inspect the settled state
     * exactly like a JS `.then(v => ...)` callback would.
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): self
    {
        $next = new self();
        $next->chainedSource = $this;

        $handler = static function (self $p) use ($onFulfilled, $onRejected): mixed {
            if ($p->state === self::FULFILLED) {
                return $onFulfilled === null ? $p->value : $onFulfilled($p->value);
            }
            if ($onRejected !== null) {
                return $onRejected($p->reason);
            }
            // Propagate rejection downstream.
            return Promise::rejected($p->reason);
        };

        $this->pushHandler($handler, $next);

        return $next;
    }

    private function pushHandler(callable $handler, self $next): void
    {
        if ($this->settled()) {
            $this->runHandlerOn($handler, $next);

            return;
        }
        $this->fulfillHandlers[] = function (self $p) use ($handler, $next): void {
            if ($p->state === self::FULFILLED) {
                $this->runHandlerOn($handler, $next);
            }
        };
        $this->rejectHandlers[] = function (self $p) use ($handler, $next): void {
            if ($p->state === self::REJECTED) {
                $this->runHandlerOn($handler, $next);
            }
        };
    }

    private function runHandlerOn(callable $handler, self $next): void
    {
        try {
            $result = $handler($this);
        } catch (\Throwable $e) {
            $next->settleRejected($e);

            return;
        }
        if ($result instanceof self) {
            $next->adopt($result);

            return;
        }
        $next->settleFulfilled($result);
    }

    public function catch(callable $onRejected): self
    {
        return $this->then(static fn ($v) => $v, $onRejected);
    }

    public function finally(callable $onFinally): self
    {
        $next = new self();
        $next->chainedSource = $this;
        if ($this->settled()) {
            // The flush already happened, so run inline or the callback never fires.
            $onFinally();
        } else {
            $this->finallyHandlers[] = $onFinally;
        }
        $this->pushHandler(static fn ($v) => $v, $next);

        return $next;
    }

    /**
     * Drive this promise to a settled state and return its value.
     *
     * Safe to call repeatedly; the first resolution is cached by the scheduler.
     *
     * @throws \Throwable the rejection reason, if the promise rejected
     */
    public function value(): mixed
    {
        $settled = Await::drain($this);
        if ($settled->state === self::REJECTED) {
            throw $settled->reason;
        }

        return $settled->value;
    }
}
