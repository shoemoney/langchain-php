<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint;

use LangGraph\Checkpoint\Serde\BaseCheckpointSerializer;
use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;
use LangGraph\Pregel\Checkpoint\CheckpointTuple as PregelCheckpointTuple;

/**
 * Persistence contract for checkpoints and their pending writes.
 *
 * Port of `BaseCheckpointSaver` from `@langchain/langgraph-checkpoint`.
 *
 * The engine talks to a saver through exactly four operations, and the split
 * between them is the reason checkpointing works:
 *
 *  - {@see self::put()} — a new superstep boundary. Written *after* the writes of
 *    that superstep are applied, so a saved checkpoint is always internally
 *    consistent.
 *  - {@see self::putWrites()} — a task's output, written *as it finishes*, before
 *    the superstep is committed. This is what makes a crash mid-step resumable:
 *    the surviving tasks' outputs are already on disk.
 *  - {@see self::getTuple()} — everything at a given point, including the writes
 *    of a superstep that never committed.
 *  - {@see self::list()} — a thread's history, newest first.
 *
 * A run that dies between `putWrites` and `put` resumes by replaying the
 * committed checkpoint and re-attaching the already-recorded writes, so tasks
 * that succeeded are not re-executed.
 *
 * This class is the parent of the engine's own saver contract, so anything
 * implementing it can be handed to a `Pregel` graph as its checkpointer.
 */
abstract class BaseCheckpointSaver implements \JsonSerializable
{
    /** The latest checkpoint record version this saver understands. */
    public const CHECKPOINT_VERSION = CheckpointConstants::CHECKPOINT_VERSION;

    /** How values are turned into bytes. */
    public BaseCheckpointSerializer $serde;

    /**
     * The checkpoint namespace a config asks for, or `''` for the root.
     *
     * Read through this rather than casting. `(string) $value` on a non-string
     * does not fail — an array becomes the literal string `"Array"`, and an
     * object becomes `"Object"`. Either collides with a real namespace of that
     * name, so a checkpoint written under a malformed namespace is stored where
     * a resume will not look for it, and one saved under a colliding name
     * overwrites it. It also raises an "Array to string conversion" warning,
     * which fails the suite outright under `failOnWarning`.
     *
     * A namespace is a path the engine builds as a string; anything else in the
     * config is a caller mistake and is treated as "no namespace" rather than
     * being silently turned into a valid-looking one.
     *
     * @param array<string, mixed> $config
     */
    protected static function checkpointNamespace(array $config): string
    {
        $ns = $config['checkpoint_ns'] ?? null;

        return is_string($ns) ? $ns : '';
    }

    public function __construct(?BaseCheckpointSerializer $serde = null)
    {
        $this->serde = $serde ?? new JsonPlusSerializer();
    }

    /**
     * Keep a saver out of `json_encode`'s way.
     *
     * Port of `toJSON`. A saver is routinely parked in a runnable's `configurable`,
     * and a backend client holding a connection pool would otherwise be walked
     * property by property — or blow up on a handle that cannot be serialised.
     *
     * `JSON.stringify` reaches `toJSON` by name, so the PHP equivalent is
     * `JsonSerializable`: the saver serialises to a short marker string rather
     * than to itself.
     */
    public function jsonSerialize(): string
    {
        return $this->__toString();
    }

    public function __toString(): string
    {
        return '[' . static::class . ']';
    }

    /**
     * The latest checkpoint for a thread/namespace, or null.
     *
     * Port of `getTuple`.
     *
     * @param array<string, mixed> $config A whole config or the inner `configurable` map.
     */
    abstract public function getTuple(array $config): ?PregelCheckpointTuple;

    /**
     * Persist a new checkpoint.
     *
     * Port of `put`.
     *
     * @param  array<string, mixed>      $config
     * @param  array<string, mixed>      $metadata
     * @param  array<string, int|string> $newVersions Only the versions that advanced.
     * @return array<string, mixed>      The config identifying the new checkpoint.
     */
    abstract public function put(
        array $config,
        PregelCheckpoint $checkpoint,
        array $metadata = [],
        array $newVersions = [],
    ): array;

    /**
     * Persist one task's writes.
     *
     * Port of `putWrites`.
     *
     * @param  array<string, mixed>            $config
     * @param  list<array{0: string, 1: mixed}> $writes
     * @return array<string, mixed>            The config the writes were stored against.
     */
    abstract public function putWrites(array $config, array $writes, string $taskId): array;

    /**
     * A thread's checkpoints, newest first.
     *
     * Port of `list`. `before`/`filter` travel in {@see CheckpointListOptions};
     * a bare integer is accepted as a limit because that is what the engine
     * passes and PHP has no overloading.
     *
     * @param  array<string, mixed>                              $config
     * @param  CheckpointListOptions|int|null                    $options
     * @return list<PregelCheckpointTuple>
     */
    abstract public function list(array $config, CheckpointListOptions|int|null $options = null): array;

    /**
     * Forget a thread entirely.
     *
     * Port of `deleteThread`. Deletes the checkpoints *and* the writes, because a
     * write without its checkpoint is unreachable and a checkpoint without a
     * thread is meaningless.
     */
    abstract public function deleteThread(string $threadId): void;

    /**
     * The next version number for a channel counter.
     *
     * Port of `getNextVersion`. Integer versions increment by one. A saver that
     * wants string versions overrides this; the only requirement is that the
     * sequence increases monotonically, because scheduling compares versions.
     */
    public function getNextVersion(?int $current): int
    {
        return $current === null ? 1 : $current + 1;
    }

    /**
     * The latest checkpoint, without the rest of the tuple.
     *
     * Port of `get`.
     *
     * @param array<string, mixed> $config
     */
    public function get(array $config): ?PregelCheckpoint
    {
        return $this->getTuple($config)?->checkpoint;
    }

    /**
     * One channel's writes and seed across a checkpoint's ancestor chain.
     *
     * Port of `getDeltaChannelHistory`.
     *
     * Walks parents via `parentConfig`, accumulating each requested channel's
     * pending writes until an ancestor has a stored value for it — that value is
     * the seed and the walk stops there for that channel. A channel whose walk
     * reaches the root without finding one comes back with no seed, which the
     * consumer reads as "start empty".
     *
     * The parent chain is walked rather than `list()`d because on a forked thread
     * only on-path ancestors may contribute.
     *
     * @param  array<string, mixed> $config
     * @param  list<string>         $channels Empty yields an empty map.
     * @return array<string, DeltaChannelHistory>
     */
    public function getDeltaChannelHistory(array $config, array $channels): array
    {
        if ($channels === []) {
            return [];
        }

        /** @var array<string, list<array{0: string, 1: string, 2: mixed}>> $collected */
        $collected = array_fill_keys($channels, []);
        /** @var array<string, mixed> $seeds */
        $seeds = [];
        $remaining = array_fill_keys($channels, true);

        $target = $this->getTuple($config);
        $cursor = $target?->parentConfig;
        $cursorId = $cursor === null ? null : CheckpointId::fromConfig($cursor);

        while ($cursor !== null && $cursorId !== null && $remaining !== []) {
            $tuple = $this->getTuple($cursor);
            if ($tuple === null) {
                break;
            }

            foreach ($tuple->pendingWrites as $write) {
                $channel = (string) $write[1];
                if (isset($remaining[$channel])) {
                    $collected[$channel][] = [$write[0], $write[1], $write[2] ?? null];
                }
            }

            foreach (array_keys($remaining) as $channel) {
                if (array_key_exists($channel, $tuple->checkpoint->channelValues)) {
                    $seeds[$channel] = $tuple->checkpoint->channelValues[$channel];
                    unset($remaining[$channel]);
                }
            }

            $cursor = $tuple->parentConfig;
            $cursorId = $cursor === null ? null : CheckpointId::fromConfig($cursor);
        }

        $result = [];
        foreach ($channels as $channel) {
            $result[$channel] = array_key_exists($channel, $seeds)
                ? DeltaChannelHistory::withSeed($seeds[$channel], self::sortByTaskId($collected[$channel]))
                : DeltaChannelHistory::withoutSeed(self::sortByTaskId($collected[$channel]));
        }

        return $result;
    }

    /**
     * Order one channel's writes the way live execution applies them.
     *
     * Concurrent tasks can each write a channel in the same superstep, and the
     * order they are replayed in has to be the order the scheduler would have
     * used — otherwise a reconstructed delta channel diverges from the one a
     * live run produced. `usort` is not stable across PHP versions in the way
     * this needs, so the sort is decorated with the original position.
     *
     * @param  list<array{0: string, 1: string, 2: mixed}> $writes
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private static function sortByTaskId(array $writes): array
    {
        $decorated = [];
        foreach ($writes as $position => $write) {
            $decorated[] = [$position, $write];
        }
        usort(
            $decorated,
            static fn (array $a, array $b): int => [$a[1][0], $a[0]] <=> [$b[1][0], $b[0]],
        );

        return array_values(array_map(static fn (array $pair): array => $pair[1], $decorated));
    }

    /**
     * The `__pregel_tasks` writes recorded against a checkpoint's parent.
     *
     * The migration hook for checkpoints written before format 4, which kept
     * pending sends as their own record. Savers with direct write storage (like
     * `MemorySaver`) resolve it from that storage; a database-backed saver
     * overrides this to query.
     *
     * @param  array<string, mixed> $config
     * @return list<mixed> The decoded pending sends, in stored order.
     */
    protected function pendingSendsFor(array $config): array
    {
        return [];
    }

    /**
     * Fold pre-format-4 pending sends into a checkpoint.
     *
     * Port of `_migratePendingSends` / `migratePendingSends`. A `v < 4` checkpoint
     * stored its scheduled `Send`s beside itself rather than in its own channel
     * values, so reading one without this migration yields a checkpoint that
     * resumes with an empty task queue and silently drops the fan-out.
     *
     * @param array<string, mixed> $config The config of the checkpoint being read.
     */
    protected function migratePendingSends(PregelCheckpoint $checkpoint, array $config, ?string $parentCheckpointId): void
    {
        if ($checkpoint->v >= CheckpointConstants::CHECKPOINT_VERSION || $parentCheckpointId === null) {
            return;
        }

        // Address the PARENT checkpoint, keeping every routing key.
        //
        // This used to `unset($config['configurable'])` and put
        // `checkpoint_id` at the top level instead. That is fine for the two
        // shipped call sites, which both hand-build a flat
        // `['thread_id' =>, 'checkpoint_ns' =>, 'checkpoint_id' =>]` from the
        // row's own columns — there is no `configurable` key to remove. But
        // this method is `protected`, and a subclass passing the config shape
        // `put()` actually RETURNS (`['configurable' => [...]]`) got the
        // routing map deleted, so `configurable()` fell back to the outer array
        // where `thread_id` no longer lived, `pendingSendsFor()` returned [],
        // and the TASKS channel was overwritten with an empty list.
        //
        // Proved by reaching this method with both shapes and the same pending
        // writes on disk:
        //     flat    -> TASKS = ["send-1","send-2"]
        //     nested  -> TASKS = []              <- fan-out silently dropped
        //
        // A resumed run that dropped its pending sends loses every queued task,
        // with no error anywhere. So the nested form is written THROUGH, and
        // the top-level key is set too for a flat caller.
        $parentConfig = $config;
        if (isset($config['configurable']) && is_array($config['configurable'])) {
            $parentConfig['configurable']['checkpoint_id'] = $parentCheckpointId;
        } else {
            $parentConfig['checkpoint_id'] = $parentCheckpointId;
        }

        $pendingSends = $this->pendingSendsFor($parentConfig);
        $checkpoint->channelValues[CheckpointConstants::TASKS] = $pendingSends;

        $versions = $checkpoint->channelVersions;
        $checkpoint->channelVersions[CheckpointConstants::TASKS] = $versions !== []
            ? ChannelVersions::max(...array_values($versions))
            : $this->getNextVersion(null);
    }

    /**
     * The `configurable` map for a config, whether whole or already inner.
     *
     * @param  array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected static function configurable(array $config): array
    {
        if (isset($config['configurable']) && is_array($config['configurable'])) {
            return $config['configurable'];
        }

        return $config;
    }

    /**
     * A non-empty string, or null.
     *
     * An absent thread id is not the same as an empty one: the first means "this
     * config is not addressable" and the second would file a checkpoint under a
     * real but shared key, so every checkpoint in the process would land in the
     * same place and read back the wrong thread's state.
     */
    protected static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Build the config that locates a checkpoint.
     *
     * Only these three keys, always: a `put` is handed whatever extra fields the
     * caller had in `configurable`, and storing those would let an unrelated value
     * masquerade as part of a checkpoint's identity.
     *
     * @return array{configurable: array{thread_id: string, checkpoint_ns: string, checkpoint_id: string}}
     */
    protected static function tupleConfig(string $threadId, string $checkpointNs, string $checkpointId): array
    {
        return [
            'configurable' => [
                'thread_id' => $threadId,
                'checkpoint_ns' => $checkpointNs,
                'checkpoint_id' => $checkpointId,
            ],
        ];
    }

    /**
     * The write index a channel's value is stored at.
     *
     * Regular writes take their position in the batch; the four special channels
     * take a fixed negative index so they cannot collide with a concurrent task's
     * regular write.
     */
    protected static function writeIndex(string $channel, int $position): int
    {
        return CheckpointConstants::writeIndex($channel, $position);
    }
}
