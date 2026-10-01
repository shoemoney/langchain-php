<?php

declare(strict_types=1);

namespace LangGraph\Pregel;


/**
 * A `TaskPath` — the address of one task within a superstep.
 *
 * Port of `SimpleTaskPath` / `VariadicTaskPath` / `CallTaskPath` from
 * `langgraph-core/src/pregel/types.ts`.
 *
 * The path's first element is its *kind*:
 *
 *  - `PULL` — the node ran because an edge led to it. Path: `[PULL, nodeName]`.
 *  - `PUSH` — the node ran because someone sent it a packet, either via a
 *    `Send` in the `__pregel_tasks` channel (path `[PUSH, index]`) or because a
 *    task called it mid-superstep (path `[PUSH, ..., writeIdx, taskId]`).
 *
 * Only the **first three elements** take part in the deterministic ordering that
 * `_applyWrites` imposes. Everything after them is either a write index, a
 * parent task id, or the trailing `true` flag marking a call — none of which
 * should influence ordering. This class exists so that truncation is a
 * deliberate, documented operation rather than an `array_slice(0, 3)` sprinkled
 * through the engine.
 */
final class TaskPath
{
    /**
     * @param list<string|int|bool> $segments The full path.
     */
    public function __construct(public array $segments = [])
    {
    }

    /** The `PULL` path for a node. */
    public static function pull(string $nodeName): self
    {
        return new self([Constants::PULL, $nodeName]);
    }

    /** The `PUSH` path for a `Send` at `$index` in the tasks channel. */
    public static function push(int $index): self
    {
        return new self([Constants::PUSH, $index]);
    }

    public function isPush(): bool
    {
        return ($this->segments[0] ?? null) === Constants::PUSH;
    }

    public function isPull(): bool
    {
        return ($this->segments[0] ?? null) === Constants::PULL;
    }

    public function kind(): ?string
    {
        $kind = $this->segments[0] ?? null;

        return is_string($kind) ? $kind : null;
    }

    /**
     * The first three segments — the ordering key.
     *
     * Port of `task.path?.slice(0, 3)`. Sorting on this and nothing else is
     * what makes a superstep's writes apply in a reproducible order regardless
     * of which order tasks happened to finish in.
     *
     * @return list<string|int|bool>
     */
    public function orderingKey(): array
    {
        return array_slice($this->segments, 0, 3);
    }

    /**
     * Whether this path ends in the "a call is in progress" flag.
     *
     * The trailing `true` marks a task invoked by a parent task rather than
     * scheduled directly. Interrupts from such a task are the parent's to
     * report, so the loop suppresses them — see `PregelLoop::outputWrites()`.
     */
    public function isCallPath(): bool
    {
        if ($this->segments === []) {
            return false;
        }

        return $this->segments[count($this->segments) - 1] === true;
    }

    /**
     * A copy with `true` appended, marking the path as a call.
     */
    public function asCallPath(): self
    {
        return new self(array_slice($this->segments, 0, 3) + [true]);
    }

    /** @return list<string|int|bool> */
    public function toArray(): array
    {
        return $this->segments;
    }
}
