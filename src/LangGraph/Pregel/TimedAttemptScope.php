<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * The live progress record of one timed node attempt.
 *
 * Port of the internal `TimedAttemptScope` in `langgraph-core/src/pregel/timeout.ts`.
 *
 * It guards the observable-progress channels (writes, child-task calls, the custom-stream
 * writer, callbacks) so that the idle clock is refreshed by progress, and so that once an
 * attempt has been closed after a timeout, late writes from it are dropped rather than
 * leaking into the checkpoint.
 *
 * PHP cannot interrupt a running node, so the watchdog is evaluated after the fact. To keep
 * that faithful the scope also records the LONGEST GAP between two progress signals: a timer
 * would have fired at the first gap that reached `idleTimeout`, so "the largest gap reached
 * it" is exactly the question the timer would have answered.
 *
 * @internal
 */
final class TimedAttemptScope
{
    public bool $active = true;

    /** Monotonic ms of the last progress signal. */
    public float $lastProgress;

    /** Longest ms between two consecutive progress signals so far. */
    public float $maxGap = 0.0;

    public function __construct(private readonly string $refreshOn)
    {
        $this->lastProgress = self::now();
    }

    public static function now(): float
    {
        return hrtime(true) / 1_000_000;
    }

    /** Record progress now. Always honoured (used by `heartbeat()`). */
    public function touch(): void
    {
        $now = self::now();
        $this->maxGap = max($this->maxGap, $now - $this->lastProgress);
        $this->lastProgress = $now;
    }

    /**
     * Record progress for an automatic signal (write / call / stream / callback).
     * A no-op when `refreshOn` is `heartbeat`.
     */
    public function autoTouch(): void
    {
        if ($this->refreshOn === 'auto') {
            $this->touch();
        }
    }

    public function close(): void
    {
        $this->active = false;
    }

    /** The longest idle stretch, counting the one still open at `$now`. */
    public function longestIdleGap(float $now): float
    {
        return max($this->maxGap, $now - $this->lastProgress);
    }
}
