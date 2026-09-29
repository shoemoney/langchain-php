<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint;

use LangGraph\Pregel\Checkpoint\CheckpointFunctions;

/**
 * Checkpoint identity.
 *
 * Port of `id.ts` and `getCheckpointId` from `@langchain/langgraph-checkpoint`.
 *
 * Checkpoint ids are not opaque. Savers order a thread's history by comparing
 * them as strings, and a caller resumes a run by naming one. So an id has to be
 * two things at once: unique, and *sortable by time*.
 *
 * That is why this is a UUIDv6 and not a v4. The upstream implementation also
 * keeps a monotonic sub-millisecond counter: two checkpoints written inside the
 * same millisecond must still order correctly, or `list()` would return a thread
 * in an order that does not match the order it was written.
 */
final class CheckpointId
{
    private function __construct()
    {
    }

    /**
     * A time-ordered id, so ids sort chronologically as strings.
     *
     * Port of `uuid6`. The clock is kept strictly monotonic: if the wall clock
     * has not advanced since the previous call, the sub-millisecond counter is
     * bumped (rolling into the next millisecond at 10,000) so the emitted time
     * bits still increase.
     *
     * @param int $clockSeq Disambiguates concurrent generators; folded into the
     *                      id but never allowed to affect time ordering.
     */
    public static function uuid6(int $clockSeq = 0): string
    {
        return CheckpointFunctions::uuid6($clockSeq);
    }

    /**
     * A deterministic id derived from a name and a namespace checkpoint id.
     *
     * Port of `uuid5`. Determinism is the point: re-preparing the same task from
     * the same checkpoint must produce the same id, or a resume would schedule a
     * duplicate task instead of continuing the original one.
     *
     * @param string $name      The varying input (a serialised task descriptor).
     * @param string $namespace A checkpoint id.
     */
    public static function uuid5(string $name, string $namespace): string
    {
        return CheckpointFunctions::uuid5($name, $namespace);
    }

    /**
     * The checkpoint a config points at, or an empty string for "the latest".
     *
     * Port of `getCheckpointId`. `thread_ts` is the older key, still honoured so
     * a caller holding a config written by an earlier version keeps working.
     *
     * Accepts either a whole config or the inner `configurable` map, because the
     * engine hands savers the inner map while a caller naturally holds the whole
     * thing, and silently reading the wrong shape would file every lookup under
     * an empty thread id.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): string
    {
        $configurable = isset($config['configurable']) && is_array($config['configurable'])
            ? $config['configurable']
            : $config;

        $id = $configurable['checkpoint_id'] ?? $configurable['thread_ts'] ?? '';

        return is_scalar($id) ? (string) $id : '';
    }
}
