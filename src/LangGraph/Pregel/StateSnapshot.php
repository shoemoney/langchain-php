<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableConfig;

/**
 * What a graph looks like at one checkpoint — the whole observable state.
 *
 * Port of the `StateSnapshot` interface from `@langchain/langgraph`
 * (`pregel/types.ts`). Upstream declares seven fields, and this class carries all
 * seven. The port previously returned a bare `array` with five of them, which meant
 * a caller reading a graph's state to decide what to do next — the human-in-the-loop
 * step — had no type describing the shape it was editing, and two of the fields
 * simply did not exist.
 *
 * The seven, as measured by RUNNING LangGraph JS and reading `getState()`:
 *
 *     values       the channel state
 *     next         nodes to execute in the next step
 *     config       the config that fetched this snapshot
 *     metadata     checkpoint metadata (`source`, `step`, `parents`, `thread_id`)
 *     createdAt    when the checkpoint was written
 *     parentConfig the config of the checkpoint before this one, if any
 *     tasks        the tasks in this step, with their pending interrupts
 *
 * `createdAt` and `parentConfig` are the two that were absent. `parentConfig` is
 * how a caller walks backwards through a thread without a saver of its own, so
 * omitting it removed the only route to time travel that does not require the
 * caller to enumerate history itself.
 *
 * This is a VALUE OBJECT: readonly, constructed once, no behaviour beyond
 * {@see self::toArray()}. `toArray()` exists because `getStateHistory()` returns a
 * list of these and the checkpoint's own wire form is array-shaped — a snapshot is
 * an observation, not something that gets saved.
 *
 * The array shape it replaces was a documented part of this port's public surface.
 * `toArray()` reproduces it EXACTLY — same five keys, same values — so a caller
 * that wants the old shape has one call to make, and the conversion is not silent.
 */
final class StateSnapshot
{
    /**
     * @param array<string, mixed>       $values    Current values of the channels.
     * @param list<string>               $next      Nodes to execute in the next step.
     * @param array<string, mixed>       $config    The config that fetched this snapshot.
     * @param array<string, mixed>       $metadata  Checkpoint metadata.
     * @param string|null                $createdAt When the checkpoint was written.
     * @param array<string, mixed>|null  $parentConfig Config of the preceding checkpoint.
     * @param list<PregelTaskDescription> $tasks  Tasks in this step.
     */
    public function __construct(
        public readonly array $values = [],
        public readonly array $next = [],
        public readonly array $config = [],
        public readonly array $metadata = [],
        public readonly ?string $createdAt = null,
        public readonly ?array $parentConfig = null,
        public readonly array $tasks = [],
    ) {
    }

    /**
     * The array shape `getState()` returned before this class existed.
     *
     * Identical to that shape — same five keys, same order, same values — so this
     * is a conversion a caller chooses rather than one imposed on them. `createdAt`,
     * `parentConfig` and the per-task `path` are NOT added here: the old shape had
     * no room for them, and quietly widening an array a caller indexes by key is how
     * a compatibility shim stops being one.
     *
     * @return array{
     *     values: array<string, mixed>,
     *     next: list<string>,
     *     config: array<string, mixed>,
     *     metadata: array<string, mixed>,
     *     tasks: list<array{id: string, name: string, interrupts: list<array{id: string|null, value: mixed}>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'values' => $this->values,
            'next' => $this->next,
            'config' => $this->config,
            'metadata' => $this->metadata,
            'tasks' => array_map(
                static fn (PregelTaskDescription $t): array => $t->toLegacyArray(),
                $this->tasks,
            ),
        ];
    }

    /**
     * The snapshot as the config that locates it.
     *
     * A convenience for the `updateState(config)` / `invoke(config)` pairing, where
     * the caller has a snapshot and needs the config to act on it. Returning the
     * snapshot's own `config` unchanged is deliberate: rewriting it here would
     * produce a config that does not round-trip through the saver.
     */
    public function toConfig(): RunnableConfig
    {
        return new RunnableConfig(configurable: $this->config);
    }

    /*
     * DELIBERATELY ABSENT: `isInterrupted()` and `interrupts()`.
     *
     * Both were written, and both were removed before this class shipped.
     *
     * Upstream populates `tasks[].interrupts` from the checkpoint's pending writes,
     * and a LangGraph JS run paused by `interrupt('need input')` reports
     * `interrupts: [{id, value: 'need input'}]`. This port reports `[]` for the
     * same graph — measured, not inferred — because nothing populates the field:
     * `PregelLoop::toDescription()` copies `PregelExecutableTask::$interrupts`,
     * which no code path ever writes to, and `getState()` builds its descriptions
     * from `Algorithm::prepareNextTasks()`, which does not read the INTERRUPT
     * pending writes it is handed.
     *
     * So `isInterrupted()` would have returned **false for a graph that is paused**,
     * and `interrupts()` would have returned an empty list for a thread with a
     * pending question. Two helpers that answer confidently and wrongly are worse
     * than two helpers that do not exist, and worse than the bug they paper over,
     * because a caller reaching for "am I paused?" would get a clean false and act
     * on it. That is the class of defect this project keeps recording — a guard
     * that cannot fail — arriving as a convenience method.
     *
     * The gap is real and is recorded at `audit/getstate-interrupts-empty`. The
     * data to fix it EXISTS: the pending write is
     * `[taskId, '__interrupt__', {id, value}]` on the checkpoint tuple. What is
     * missing is the identity to match it by — a task re-prepared by `getState()`
     * carries a different id from the task that actually raised the interrupt, so
     * the write cannot be attached by id today.
     *
     * `next` is NOT a substitute and is deliberately not offered as one: a
     * non-empty `next` means work is PENDING, which is true of every unfinished
     * graph and says nothing about whether a human is needed.
     */
}