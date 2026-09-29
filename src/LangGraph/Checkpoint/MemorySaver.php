<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint;

use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;
use LangGraph\Pregel\Checkpoint\CheckpointTuple as PregelCheckpointTuple;
use LangGraph\Pregel\Checkpoint\MemorySaver as PregelMemorySaver;

/**
 * An in-process checkpoint saver.
 *
 * Port of `MemorySaver` from `@langchain/langgraph-checkpoint`.
 *
 * Everything lives in PHP arrays for the life of the process. That makes it ideal
 * for tests and for a single long-lived worker, and useless for surviving a
 * restart — which is the honest boundary of this class.
 *
 * Two ordering rules carry the correctness:
 *
 *  - **Checkpoints are newest-first**, sorted by id. Ids are time-ordered UUIDs
 *    (see {@see CheckpointId}), so a plain string comparison *is* a chronological
 *    one and {@see self::getTuple()} with no `checkpoint_id` is a head read rather
 *    than a search.
 *  - **Pending writes are keyed by `(task id, write index)`**, so a re-run of the
 *    same task replaces its earlier writes at the same position rather than
 *    appending. Without that, a retried task would double-apply its output and a
 *    resumed run would accumulate duplicates on every attempt.
 *
 * Nothing is stored until it is serialised, exactly as in the TypeScript saver.
 * That is deliberate: it is what makes this class a faithful test of a
 * serializer, because a value that cannot round-trip fails here rather than at
 * resume time.
 */
class MemorySaver extends PregelMemorySaver
{
    /**
     * thread id => namespace => checkpoint id => stored row.
     *
     * A row is `[checkpointType, checkpointPayload, metadataPayload, parentId]`.
     *
     * @var array<string, array<string, array<string, array{0: string, 1: string, 2: string, 3: string|null}>>>
     */
    protected array $storage = [];

    /**
     * `["threadId", "namespace", "checkpointId"]` => `"{taskId},{idx}"` => write.
     *
     * A write is `[taskId, channel, type, payload]`.
     *
     * @var array<string, array<string, array{0: string, 1: string, 2: string, 3: string}>>
     */
    protected array $writes = [];

    /**
     * The latest checkpoint for a thread/namespace, or null.
     *
     * @param array<string, mixed> $config
     */
    public function getTuple(array $config): ?PregelCheckpointTuple
    {
        $configurable = static::configurable($config);
        $threadId = self::stringOrNull($configurable['thread_id'] ?? null);
        if ($threadId === null) {
            return null;
        }

        $namespace = (string) ($configurable['checkpoint_ns'] ?? '');
        $checkpointId = CheckpointId::fromConfig($config);
        if ($checkpointId === '') {
            $checkpointId = self::headId($this->storage[$threadId][$namespace] ?? []);
            if ($checkpointId === null) {
                return null;
            }
        }

        $row = $this->storage[$threadId][$namespace][$checkpointId] ?? null;
        if ($row === null) {
            return null;
        }

        return $this->buildTuple($threadId, $namespace, $checkpointId, $row);
    }

    /**
     * A thread's checkpoints, newest first.
     *
     * @param  array<string, mixed>           $config
     * @param  CheckpointListOptions|int|null $options
     * @return list<PregelCheckpointTuple>
     */
    public function list(array $config, CheckpointListOptions|int|null $options = null): array
    {
        $listOptions = CheckpointListOptions::of($options);
        $configurable = static::configurable($config);

        $threadId = self::stringOrNull($configurable['thread_id'] ?? null);
        $namespaces = array_key_exists('checkpoint_ns', $configurable)
            ? [(string) $configurable['checkpoint_ns']]
            : null;
        $onlyCheckpointId = self::stringOrNull($configurable['checkpoint_id'] ?? null);
        $before = $listOptions->beforeCheckpointId();
        $limit = $listOptions->limit;

        $threads = $threadId === null ? array_keys($this->storage) : [$threadId];
        $tuples = [];

        foreach ($threads as $thread) {
            if (!isset($this->storage[$thread])) {
                continue;
            }
            $threadNamespaces = $namespaces ?? array_keys($this->storage[$thread]);

            foreach ($threadNamespaces as $namespace) {
                $checkpoints = $this->storage[$thread][$namespace] ?? [];
                // Newest first: ids are time-ordered, so this is chronological.
                krsort($checkpoints, SORT_STRING);

                foreach ($checkpoints as $checkpointId => $row) {
                    if ($onlyCheckpointId !== null && $checkpointId !== $onlyCheckpointId) {
                        continue;
                    }
                    if ($before !== '' && (string) $checkpointId >= $before) {
                        continue;
                    }
                    if ($limit !== null && $limit <= 0) {
                        break 3;
                    }

                    $tuple = $this->buildTuple($thread, $namespace, (string) $checkpointId, $row);
                    if (!$listOptions->matches($tuple->metadata)) {
                        continue;
                    }

                    $tuples[] = $tuple;
                    if ($limit !== null) {
                        $limit--;
                    }
                }
            }
        }

        return $tuples;
    }

    /**
     * Persist a new checkpoint.
     *
     * The returned config names the new checkpoint; the `checkpoint_id` the caller
     * passed in becomes its parent. That is the whole mechanism by which a thread
     * becomes a chain.
     *
     * @param  array<string, mixed>      $config
     * @param  array<string, mixed>      $metadata
     * @param  array<string, int|string> $newVersions
     * @return array<string, mixed>
     */
    public function put(
        array $config,
        PregelCheckpoint $checkpoint,
        array $metadata = [],
        array $newVersions = [],
    ): array {
        $configurable = static::configurable($config);
        $threadId = self::stringOrNull($configurable['thread_id'] ?? null);
        if ($threadId === null) {
            throw new \InvalidArgumentException(
                'Failed to put checkpoint. The passed RunnableConfig is missing a required "thread_id" field in its '
                . '"configurable" property. When using a checkpointer, you must pass a "thread_id" so the checkpointer '
                . 'knows which conversation thread to persist state for. '
                . 'Example: graph.stream(input, { configurable: { thread_id: "my-thread-id" } })'
            );
        }

        $checkpointId = $checkpoint->id;
        if ($checkpointId === '') {
            throw new \InvalidArgumentException('A checkpoint must have an id to be saved.');
        }

        $namespace = (string) ($configurable['checkpoint_ns'] ?? '');
        $parentId = self::stringOrNull($configurable['checkpoint_id'] ?? null);

        [$type, $serializedCheckpoint] = $this->serde->dumpsTyped($this->wireCheckpoint($checkpoint));
        // Metadata is always a map, so it is always JSON. Only the checkpoint's
        // own tag is worth storing: that is the one that can be `bytes`.
        [, $serializedMetadata] = $this->serde->dumpsTyped($metadata);

        $this->storage[$threadId][$namespace][$checkpointId] = [
            $type,
            $serializedCheckpoint,
            $serializedMetadata,
            $parentId,
        ];

        return static::tupleConfig($threadId, $namespace, $checkpointId);
    }

    /**
     * Persist one task's writes.
     *
     * A regular write is stored once and then left alone: a second task must
     * never be able to clobber a write another task already stored at the same
     * `(task id, index)`. A special-channel write (error, scheduled, interrupt,
     * resume) always replaces, which is what lets a resume overwrite the
     * interrupt it is answering.
     *
     * @param  array<string, mixed>             $config
     * @param  list<array{0: string, 1: mixed}>  $writes
     * @return array<string, mixed>
     */
    public function putWrites(array $config, array $writes, string $taskId): array
    {
        $configurable = static::configurable($config);
        $threadId = self::stringOrNull($configurable['thread_id'] ?? null);
        if ($threadId === null) {
            throw new \InvalidArgumentException(
                'Failed to put writes. The passed RunnableConfig is missing a required "thread_id" field in its '
                . '"configurable" property. When using a checkpointer, you must pass a "thread_id" so the checkpointer '
                . 'knows which conversation thread to persist state for.'
            );
        }
        $checkpointId = self::stringOrNull($configurable['checkpoint_id'] ?? null);
        if ($checkpointId === null) {
            throw new \InvalidArgumentException(
                'Failed to put writes. The passed RunnableConfig is missing a required "checkpoint_id" field in its '
                . '"configurable" property.'
            );
        }

        $namespace = (string) ($configurable['checkpoint_ns'] ?? '');
        $key = $this->writesKey($threadId, $namespace, $checkpointId);
        $existing = $this->writes[$key] ?? [];

        foreach (array_values($writes) as $position => $write) {
            $channel = (string) $write[0];
            $index = static::writeIndex($channel, $position);
            $innerKey = $taskId . ',' . $index;
            if ($index >= 0 && isset($existing[$innerKey])) {
                continue;
            }
            [$type, $serialized] = $this->serde->dumpsTyped($write[1] ?? null);
            $existing[$innerKey] = [$taskId, $channel, $type, $serialized];
        }

        $this->writes[$key] = $existing;

        return $config;
    }

    /**
     * Forget a thread entirely.
     */
    public function deleteThread(string $threadId): void
    {
        unset($this->storage[$threadId]);
        foreach (array_keys($this->writes) as $key) {
            $parsed = json_decode($key, true);
            if (is_array($parsed) && ($parsed[0] ?? null) === $threadId) {
                unset($this->writes[$key]);
            }
        }
    }

    /**
     * The `__pregel_tasks` writes stored against a checkpoint.
     *
     * @param  array<string, mixed> $config
     * @return list<mixed>
     */
    protected function pendingSendsFor(array $config): array
    {
        $configurable = static::configurable($config);
        $threadId = self::stringOrNull($configurable['thread_id'] ?? null);
        $checkpointId = self::stringOrNull($configurable['checkpoint_id'] ?? null);
        if ($threadId === null || $checkpointId === null) {
            return [];
        }

        $namespace = (string) ($configurable['checkpoint_ns'] ?? '');
        $sends = [];
        foreach ($this->pendingWrites($threadId, $namespace, $checkpointId) as $write) {
            if ($write[1] === CheckpointConstants::TASKS) {
                $sends[] = $write[2];
            }
        }

        return $sends;
    }

    /**
     * Reassemble a stored row into a tuple, decoding and migrating as needed.
     *
     * @param array{0: string, 1: string, 2: string, 3: string|null} $row
     */
    private function buildTuple(string $threadId, string $namespace, string $checkpointId, array $row): PregelCheckpointTuple
    {
        $checkpoint = Checkpoint::fromArray((array) $this->serde->loadsTyped($row[0], $row[1]));
        $this->migratePendingSends($checkpoint, [
            'thread_id' => $threadId,
            'checkpoint_ns' => $namespace,
            'checkpoint_id' => $checkpointId,
        ], $row[3]);

        return new CheckpointTuple(
            config: static::tupleConfig($threadId, $namespace, $checkpointId),
            checkpoint: $checkpoint,
            metadata: (array) $this->serde->loadsTyped('json', $row[2]),
            parentConfig: $row[3] === null ? null : static::tupleConfig($threadId, $namespace, $row[3]),
            pendingWrites: $this->pendingWrites($threadId, $namespace, $checkpointId),
        );
    }

    /**
     * The writes recorded against a checkpoint, in `(task id, index)` order.
     *
     * This is the order live execution applies them in, and a consumer that
     * replays them in any other order can reconstruct a different state from the
     * same saved data.
     *
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private function pendingWrites(string $threadId, string $namespace, string $checkpointId): array
    {
        $stored = $this->writes[$this->writesKey($threadId, $namespace, $checkpointId)] ?? [];
        uksort($stored, static function (string $a, string $b): int {
            [$taskA, $indexA] = array_pad(explode(',', $a, 2), 2, '0');
            [$taskB, $indexB] = array_pad(explode(',', $b, 2), 2, '0');

            return [$taskA, (int) $indexA] <=> [$taskB, (int) $indexB];
        });

        $out = [];
        foreach ($stored as $write) {
            $out[] = [$write[0], $write[1], $this->serde->loadsTyped($write[2], $write[3])];
        }

        return $out;
    }

    /**
     * The wire record for a checkpoint, whichever checkpoint class was handed in.
     *
     * @return array<string, mixed>
     */
    private function wireCheckpoint(PregelCheckpoint $checkpoint): array
    {
        return $checkpoint instanceof Checkpoint
            ? $checkpoint->toArray()
            : [
                'v' => $checkpoint->v,
                'id' => $checkpoint->id,
                'ts' => $checkpoint->ts,
                'channel_values' => $checkpoint->channelValues,
                'channel_versions' => $checkpoint->channelVersions,
                'versions_seen' => $checkpoint->versionsSeen,
            ];
    }

    private function writesKey(string $threadId, string $namespace, string $checkpointId): string
    {
        return json_encode([$threadId, $namespace, $checkpointId], JSON_THROW_ON_ERROR);
    }

    /**
     * The newest checkpoint id in a namespace, or null when it holds none.
     *
     * @param array<string, mixed> $checkpoints
     */
    private static function headId(array $checkpoints): ?string
    {
        if ($checkpoints === []) {
            return null;
        }

        $ids = array_map('strval', array_keys($checkpoints));
        rsort($ids, SORT_STRING);

        return $ids[0];
    }
}
