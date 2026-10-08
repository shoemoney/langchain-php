<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * A run-scoped switch for stopping a graph cooperatively.
 *
 * Port of `RunControl` from `langgraph-core/src/pregel/runtime.ts`.
 *
 * Hand one to `invoke()` / `stream()` as `options['control']` on the config, and call
 * {@see self::requestDrain()} from a signal handler, a watchdog, or a node. The graph stops at
 * the next superstep boundary with a {@see \LangGraph\Errors\GraphDrained} carrying the reason,
 * after saving a checkpoint, so the same config resumes the thread later. A drain never
 * interrupts work that is already running; if the drain is requested on the last superstep the
 * run simply finishes (check {@see self::$drainRequested} afterwards).
 *
 * Nodes receive the control on their config (`$config->options['control']`), so a node can
 * request a drain itself. A fresh control is created when the caller supplies none.
 */
final class RunControl
{
    private ?string $reason = null;

    /**
     * Ask the run to drain at the next superstep boundary.
     *
     * @param string $reason Surfaced on the resulting `GraphDrained` error.
     */
    public function requestDrain(string $reason = 'shutdown'): void
    {
        $this->reason = $reason;
    }

    /** Whether a drain has been requested for this run. */
    public function drainRequested(): bool
    {
        return $this->reason !== null;
    }

    /** The reason passed to {@see self::requestDrain()}, if any. */
    public function drainReason(): ?string
    {
        return $this->reason;
    }
}
