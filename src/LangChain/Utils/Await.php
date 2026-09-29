<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * Cooperative scheduler for {@see Promise}.
 *
 * LangChain JS gets async from a language-level event loop. PHP 8.1+ has
 * {@see \Fiber}, which gives us genuine cooperative suspension, so a promise
 * that is not yet settled can be advanced while the caller blocks.
 *
 * The important honesty note: PHP fibers are *cooperative*, so a promise can
 * only make progress when something explicitly advances it. This class keeps a
 * registry of live, unsettled promises; anything registered as "driven" (via
 * {@see self::spawn()}) is stepped whenever the main stack waits, which is what
 * lets tool calls and network I/O resolve without a dedicated reactor.
 */
final class Await
{
    /** @var array<int, array{fiber: \Fiber<int, mixed, mixed, mixed>, started: bool}> */
    private static array $driven = [];

    private static int $nextId = 1;

    /**
     * Run a fiber to completion, stepping the pending registry whenever the
     * fiber suspends. This is the local event loop.
     */
    public static function runFiber(callable $fn): mixed
    {
        $fiber = new \Fiber($fn);

        try {
            $fiber->start();
            while (!$fiber->isTerminated()) {
                self::stepAll();
                if (!$fiber->isTerminated()) {
                    $fiber->resume();
                }
            }
        } catch (\Throwable $e) {
            self::stepAll();
            throw $e;
        }

        // start()/resume() hand back the value passed to suspend(), not the
        // fiber's return value — that only ever comes from getReturn().
        return $fiber->getReturn();
    }

    /**
     * Register a long-running fiber to be stepped whenever the scheduler waits.
     *
     * Returns the fiber id so it can be cancelled with {@see self::cancel()}.
     */
    public static function spawn(callable $fn): int
    {
        $id = self::$nextId++;
        $fiber = new \Fiber($fn);
        self::$driven[$id] = ['fiber' => $fiber, 'started' => false];

        return $id;
    }

    public static function cancel(int $id): void
    {
        if (isset(self::$driven[$id])) {
            $fiber = self::$driven[$id]['fiber'];
            if ($fiber->isSuspended()) {
                $fiber->throw(new \RuntimeException('Fiber cancelled'));
            }
            unset(self::$driven[$id]);
        }
    }

    /**
     * Advance every registered fiber by one step, ignoring ones that are done or
     * throw (their failure is surfaced when the consumer awaits the value).
     */
    private static function stepAll(): void
    {
        foreach (self::$driven as $id => $entry) {
            $fiber = $entry['fiber'];
            if ($fiber->isTerminated()) {
                unset(self::$driven[$id]);
                continue;
            }
            if ($fiber->isRunning()) {
                continue;
            }
            try {
                if (!$fiber->isStarted()) {
                    $fiber->start();
                } else {
                    $fiber->resume();
                }
            } catch (\Throwable) {
                unset(self::$driven[$id]);
            }
        }
    }

    /**
     * Advance the registry until $promise settles, or give up after $maxSteps.
     *
     * A promise that stays pending with nothing registered to drive it is a
     * genuine hang, so we bound the spin rather than looping forever.
     */
    public static function drain(Promise $promise, int $maxSteps = 1000): Promise
    {
        if ($promise->settled()) {
            return $promise;
        }
        $steps = 0;
        while (!$promise->settled() && $steps < $maxSteps) {
            if (self::$driven === []) {
                break;
            }
            self::stepAll();
            $steps++;
        }

        return $promise;
    }

    /**
     * Resolve any promise-or-plain-value to its value, blocking as needed.
     *
     * This is the PHP analogue of `await`, and the reason the rest of the port
     * can keep the `(await $runnable->invoke($x))` call shape.
     *
     * @throws \Throwable if the promise rejected
     */
    public static function sync(mixed $value): mixed
    {
        if ($value instanceof Promise) {
            return $value->value();
        }

        return $value;
    }

    /**
     * Resolve a value to a {@see Promise} without blocking.
     */
    public static function async(mixed $value): Promise
    {
        if ($value instanceof Promise) {
            return $value;
        }
        if ($value instanceof \Throwable) {
            return Promise::rejected($value);
        }

        return Promise::resolved($value);
    }

    /**
     * Adapt a value that may be a plain value or a promise-returning callable.
     */
    public static function call(mixed $valueOrThunk): mixed
    {
        if ($valueOrThunk instanceof \Closure) {
            return ($valueOrThunk)();
        }

        return $valueOrThunk;
    }
}
