<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Channels\BaseChannel;
use LangGraph\Channels\ChannelRegistry;
use LangGraph\Channels\Missing;
use LangGraph\Errors\EmptyChannelError;
use LangGraph\Errors\InvalidUpdateError;
use LangGraph\Pregel\Checkpoint\Checkpoint;
use LangGraph\Pregel\Checkpoint\CheckpointFunctions;

/**
 * Pre-indexed pending writes, so per-task lookups are O(1).
 *
 * Port of `PendingWritesIndex` / `_indexPendingWrites` from `algo.ts`.
 *
 * The scheduler asks the same three questions once per task per step: *does
 * this task already have a successful write?* *what resume values belong to it?*
 * *is there a null-task resume?* Answering each by scanning the whole pending
 * -writes list is quadratic, and pending writes is the one structure in the
 * engine that grows with (tasks x nodes). This index is built once per step.
 *
 * It is a pure optimisation: {@see Algorithm::prepareNextTasks()} produces
 * identical tasks with or without it, which the upstream `algo.test.ts` asserts
 * directly and which is pinned here too.
 */
final class PendingWritesIndex
{
    /**
     * @param list<array{0: string, 1: string, 2: mixed}> $pendingWrites
     */
    public function __construct(
        public readonly mixed $nullResume = null,
        public readonly array $resumeByTaskId = [],
        public readonly array $successfulWriteTaskIds = [],
    ) {
    }

    /**
     * @param list<array{0: string, 1: string, 2: mixed}>|null $pendingWrites
     */
    public static function build(?array $pendingWrites): self
    {
        $nullResume = null;
        $nullResumeSet = false;
        $resumeByTaskId = [];
        $successfulWriteTaskIds = [];

        foreach ($pendingWrites ?? [] as $write) {
            $taskId = (string) $write[0];
            $channel = (string) $write[1];
            $value = $write[2] ?? null;

            if ($taskId === Constants::NULL_TASK_ID
                && $channel === Constants::RESUME
                && !$nullResumeSet) {
                $nullResume = $value;
                $nullResumeSet = true;
            }

            if ($channel === Constants::RESUME && $taskId !== Constants::NULL_TASK_ID) {
                $resumeByTaskId[$taskId][] = $value;
            }

            if ($channel !== Constants::ERROR) {
                $successfulWriteTaskIds[$taskId] = true;
            }
        }

        return new self($nullResume, $resumeByTaskId, $successfulWriteTaskIds);
    }

    /** Whether a task has at least one write that is not an `ERROR` marker. */
    public function hasSuccessfulWrite(string $taskId): bool
    {
        return isset($this->successfulWriteTaskIds[$taskId]);
    }

    /**
     * Resume values recorded against one task id.
     *
     * @return list<mixed>
     */
    public function resumeFor(string $taskId): array
    {
        return $this->resumeByTaskId[$taskId] ?? [];
    }
}
