<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint;

/**
 * Reserved channel names and the write-index mapping.
 *
 * Port of the module-level constants in `base.ts` and `serde/types.ts` of
 * `@langchain/langgraph-checkpoint`.
 *
 * These names are not cosmetic. Four of them are *special* channels whose
 * writes are pinned to a negative index by {@see self::WRITES_IDX_MAP}, which is
 * the only reason a regular write from one task can never collide with an
 * `INTERRUPT` from another.
 */
final class CheckpointConstants
{
    private function __construct()
    {
    }

    /** The current checkpoint record version. Format 4 folded pending sends into the checkpoint. */
    public const CHECKPOINT_VERSION = 4;

    /** The queue channel of packets awaiting scheduling. */
    public const TASKS = '__pregel_tasks';

    /** A task's failure, written to a checkpoint so a resume knows to retry it. */
    public const ERROR = '__error__';

    /** A task scheduled by a fan-out. */
    public const SCHEDULED = '__scheduled__';

    /** A task paused on a human decision. */
    public const INTERRUPT = '__interrupt__';

    /** The value that resumes a paused task. */
    public const RESUME = '__resume__';

    /**
     * Index for a special channel's write.
     *
     * Port of `WRITES_IDX_MAP`. Regular writes take their ordinal position in
     * the list of writes being saved; special writes take a fixed *negative*
     * index so they cannot conflict with a regular write from a concurrent task.
     *
     * @var array<string, int>
     */
    public const WRITES_IDX_MAP = [
        self::ERROR => -1,
        self::SCHEDULED => -2,
        self::INTERRUPT => -3,
        self::RESUME => -4,
    ];

    /**
     * Metadata keys that are framework bookkeeping rather than user meaning.
     *
     * Port of `EXCLUDED_METADATA_KEYS`. Stream handlers drop these when
     * rendering tasks, because they are already implied by a task's own fields.
     *
     * @var list<string>
     */
    public const EXCLUDED_METADATA_KEYS = [
        'thread_id',
        'checkpoint_id',
        'checkpoint_ns',
        'checkpoint_map',
        'langgraph_step',
        'langgraph_node',
        'langgraph_triggers',
        'langgraph_path',
        'langgraph_checkpoint_ns',
    ];

    /**
     * The stored index for a write, honouring {@see self::WRITES_IDX_MAP}.
     *
     * @param string $channel  The channel being written to.
     * @param int    $position The write's ordinal position in the batch.
     */
    public static function writeIndex(string $channel, int $position): int
    {
        return self::WRITES_IDX_MAP[$channel] ?? $position;
    }

    /** Whether every write in a batch targets a special channel. */
    public static function allSpecialChannels(array $writes): bool
    {
        foreach ($writes as $write) {
            if (!isset(self::WRITES_IDX_MAP[(string) $write[0]])) {
                return false;
            }
        }

        return $writes !== [];
    }
}
