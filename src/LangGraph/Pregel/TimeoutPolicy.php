<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * Configuration for timing out one attempt of a node or task.
 *
 * Port of the `TimeoutPolicy` type in `langgraph-core/src/pregel/utils/timeout.ts`.
 *
 * Timeouts are MILLISECONDS, like every other LangGraph duration (`RetryPolicy` intervals,
 * `stepTimeout`). With a retry policy the timer resets for each attempt.
 *
 *  - `runTimeout`  — hard wall-clock cap for one attempt; never refreshed.
 *  - `idleTimeout` — longest an attempt may go without observable progress (a write, a
 *    custom-stream write, a child-task call, a callback event, or an explicit heartbeat).
 *  - `refreshOn`   — `auto` (default): all of those refresh the idle clock; `heartbeat`:
 *    only an explicit `heartbeat()` does.
 *
 * Build one with {@see Timeout::coerceTimeoutPolicy()}, which validates and normalises.
 */
final class TimeoutPolicy
{
    public function __construct(
        public readonly int|float|null $runTimeout = null,
        public readonly int|float|null $idleTimeout = null,
        public readonly string $refreshOn = 'auto',
    ) {
    }
}
