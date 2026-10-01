<?php

declare(strict_types=1);

namespace LangGraph\Pregel;


/**
 * The minimal shape `_applyWrites` needs from a task.
 *
 * Port of `WritesProtocol` from `langgraph-core/src/pregel/algo.ts`.
 *
 * `_applyWrites` never executes anything — it folds already-produced writes
 * into channels. So it needs only three things: the task's `name` (to record
 * `versions_seen` against), its `triggers` (to know which channels the task
 * consumed, and therefore which to bump and consume), and the `writes`
 * themselves. Deliberately not depending on the full task type is what lets the
 * same function serve real tasks, synthetic ones, and the `{name: INPUT}`
 * pseudo-task that applies a run's input.
 */
interface WritesProtocol
{
    /** The task's name, recorded as the `versions_seen` key. */
    public function writesName(): string;

    /**
     * Channels whose versions this task consumed.
     *
     * @return list<string>
     */
    public function triggers(): array;

    /**
     * The `[channel, value]` pairs the task produced.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    public function writesList(): array;

    /** The task's ordering path, or null. */
    public function path(): ?TaskPath;
}
