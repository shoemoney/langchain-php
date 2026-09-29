<?php

declare(strict_types=1);

namespace LangGraph\Pregel\Checkpoint;

/**
 * Persistence contract for checkpoints and their pending writes.
 *
 * Port of `BaseCheckpointSaver` from `@langchain/langgraph-checkpoint`.
 *
 * The engine talks to a saver through exactly three operations, and the split
 * between them is the reason checkpointing works:
 *
 *  - {@see self::put()} — a new superstep boundary. Written *after* the writes
 *    of that superstep are applied, so a saved checkpoint is always internally
 *    consistent.
 *  - {@see self::putWrites()} — a task's output, written *as it finishes*,
 *    before the superstep is committed. This is what makes a crash mid-step
 *    resumable: the surviving tasks' outputs are already on disk.
 *  - {@see self::getTuple()} — everything at a given point, including the
 *    writes of a superstep that never committed.
 *
 * A run that dies between `putWrites` and `put` resumes by replaying the
 * committed checkpoint and re-attaching the already-recorded writes, so tasks
 * that succeeded are not re-executed.
 */
abstract class BaseCheckpointSaver
{
    /**
     * Read the latest checkpoint for a thread/namespace.
     *
     * @param array<string, mixed> $config
     */
    abstract public function getTuple(array $config): ?CheckpointTuple;

    /**
     * Persist a new checkpoint.
     *
     * @param  array<string, mixed>    $config
     * @param  array<string, mixed>    $metadata
     * @param  array<string, int|string> $newVersions Only the versions that advanced.
     * @return array<string, mixed>    The config identifying the new checkpoint.
     */
    abstract public function put(
        array $config,
        Checkpoint $checkpoint,
        array $metadata = [],
        array $newVersions = [],
    ): array;

    /**
     * Persist one task's writes.
     *
     * @param  array<string, mixed>     $config
     * @param  list<array{0: string, 1: mixed}> $writes
     * @return array<string, mixed>
     */
    abstract public function putWrites(array $config, array $writes, string $taskId): array;

    /**
     * List checkpoints for a thread, newest first.
     *
     * @param  array<string, mixed> $config
     * @return list<CheckpointTuple>
     */
    abstract public function list(array $config, ?int $limit = null): array;

    /** The next version number for a channel counter. */
    public function getNextVersion(?int $current): int
    {
        return $current === null ? 1 : $current + 1;
    }
}
