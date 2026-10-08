<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Pregel\Checkpoint\BaseCheckpointSaver;
use LangGraph\Pregel\Checkpoint\CheckpointTuple;

/**
 * Tracks subgraph checkpoint loading while a parent graph time travels.
 *
 * Port of `ReplayState` from `langgraph-core/src/pregel/replay.ts`.
 *
 * When a parent replays from a historical checkpoint, a nested subgraph that keeps its own
 * checkpoint lineage (`checkpointer: true`) must start from the checkpoint it had BEFORE the
 * replay point, not from its newest one, or the replay would see a future that has not happened
 * yet. That is true on the subgraph's FIRST visit in the run only: once it has run, its newer
 * checkpoints belong to this replay and normal latest-checkpoint loading applies.
 */
final class ReplayState
{
    /** @var array<string, true> */
    private array $visitedNs = [];

    /**
     * @param string $checkpointId Parent checkpoint id at the replay point; the `before` cursor.
     */
    public function __construct(public readonly string $checkpointId)
    {
    }

    /**
     * Load the checkpoint tuple for a subgraph namespace during replay.
     *
     * On the first visit to `$checkpointNs`, the newest checkpoint saved before
     * {@see self::$checkpointId}; on later visits, the saver's normal answer for the config.
     */
    public function getCheckpoint(string $checkpointNs, BaseCheckpointSaver $checkpointer, RunnableConfig $checkpointConfig): ?CheckpointTuple
    {
        if ($this->isFirstVisit($checkpointNs)) {
            $results = $checkpointer->list(
                $checkpointConfig->configurable,
                new CheckpointListOptions(before: ['checkpoint_id' => $this->checkpointId], limit: 1),
            );

            return $results[0] ?? null;
        }

        return $checkpointer->getTuple($checkpointConfig->configurable);
    }

    /**
     * Whether this is the first visit to a LOGICAL subgraph namespace in the run.
     *
     * The task-id suffix is stripped, so the same subgraph invoked across loop iterations shares
     * one visit record.
     */
    private function isFirstVisit(string $checkpointNs): bool
    {
        $position = strrpos($checkpointNs, Constants::CHECKPOINT_NAMESPACE_END);
        $stableNs = $position === false ? $checkpointNs : substr($checkpointNs, 0, $position);
        if (isset($this->visitedNs[$stableNs])) {
            return false;
        }
        $this->visitedNs[$stableNs] = true;

        return true;
    }
}
