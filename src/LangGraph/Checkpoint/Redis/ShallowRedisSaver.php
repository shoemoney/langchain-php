<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Redis;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\CheckpointTuple;
use LangGraph\Checkpoint\Serde\BaseCheckpointSerializer;
use LangGraph\Pregel\Checkpoint\BaseCheckpointSaver;
use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;
use LangGraph\Pregel\Checkpoint\CheckpointTuple as PregelCheckpointTuple;

/**
 * A Redis saver that keeps only the latest checkpoint per thread.
 *
 * Port of `ShallowRedisSaver` from `@langchain/langgraph-checkpoint-redis`.
 *
 * It trades history for footprint: one document per `(thread, namespace)` at
 * `checkpoint:{thread}:{ns}:shallow`, channel values stored inline (no blobs), and
 * every `put()` deletes the previous checkpoint's writes. `list()` therefore yields at
 * most one tuple per thread, and `getTuple()` with a `checkpoint_id` returns null unless
 * that id is the current one.
 *
 * It is NOT interchangeable with {@see RedisSaver} for time travel, and it does not
 * satisfy the checkpointer spec: a second `putWrites` for a task REPLACES the first
 * (upstream's documented behaviour here), where the full saver keeps it.
 */
class ShallowRedisSaver extends BaseCheckpointSaver
{
    public function __construct(
        private readonly RedisClientInterface $client,
        private readonly ?TtlConfig $ttlConfig = null,
        ?BaseCheckpointSerializer $serde = null,
    ) {
        parent::__construct($serde);
    }

    /**
     * Connect with ext-redis and create the indexes.
     *
     * Port of `fromUrl`.
     */
    public static function fromUrl(string $url, ?TtlConfig $ttlConfig = null): self
    {
        $saver = new self(PhpRedisClient::fromUrl($url), $ttlConfig);
        $saver->ensureIndexes();

        return $saver;
    }

    /**
     * Replace the thread's single checkpoint.
     *
     * `$newVersions` is accepted for interface parity and ignored: channel values are
     * always stored inline.
     *
     * @param  array<string, mixed>           $config
     * @param  array<string, mixed>           $metadata
     * @param  array<string, int|string>|null $newVersions
     * @return array<string, mixed>
     */
    public function put(
        array $config,
        PregelCheckpoint $checkpoint,
        array $metadata = [],
        ?array $newVersions = null,
    ): array {
        $this->ensureIndexes();

        $configurable = static::configurable($config);
        $threadId = $configurable['thread_id'] ?? null;
        $checkpointNs = $configurable['checkpoint_ns'] ?? '';
        $parentCheckpointId = $configurable['checkpoint_id'] ?? null;
        $hasParent = $parentCheckpointId !== null && $parentCheckpointId !== '';

        if (RedisUtils::isFalsy($threadId)) {
            throw new \InvalidArgumentException('thread_id is required');
        }

        RedisUtils::assertSafeKeyComponent('thread_id', $threadId);
        RedisUtils::assertSafeKeyComponent('checkpoint_ns', $checkpointNs, allowEmpty: true);
        if ($hasParent) {
            RedisUtils::assertSafeKeyComponent('parent_checkpoint_id', $parentCheckpointId);
        }

        $checkpointId = $checkpoint->id !== '' ? $checkpoint->id : CheckpointId::uuid6(0);
        RedisUtils::assertSafeKeyComponent('checkpoint_id', $checkpointId);

        // One key per thread: the checkpoint id is NOT part of it.
        $key = "checkpoint:{$threadId}:{$checkpointNs}:shallow";

        $previousId = null;
        $previousRaw = $this->client->jsonGet($key);
        if ($previousRaw !== null) {
            $previous = json_decode($previousRaw, false, 512, JSON_THROW_ON_ERROR);
            if (is_object($previous) && is_string($previous->checkpoint_id ?? null)) {
                $previousId = $previous->checkpoint_id;
            }
        }
        if ($previousId !== null && $previousId !== '' && $previousId !== $checkpointId) {
            $this->cleanupOldCheckpoint((string) $threadId, (string) $checkpointNs, $previousId);
        }

        $wire = RedisUtils::wireCheckpoint($checkpoint);
        $wire['id'] = $checkpointId;
        [, $checkpointPayload] = RedisUtils::embed($this->serde, $wire);
        if ($checkpointPayload instanceof \stdClass && ($checkpointPayload->channel_values ?? null) === []) {
            $checkpointPayload->channel_values = new \stdClass();
        }

        // Writes may already exist if `putWrites` ran before `put` (an interrupt).
        $zsetKey = RedisUtils::WRITE_KEYS_ZSET_PREFIX . ":{$threadId}:{$checkpointNs}:{$checkpointId}";
        $writesExist = $this->client->exists($zsetKey) > 0;

        $sanitized = $this->sanitizeMetadata($metadata);
        $doc = [
            'thread_id' => $threadId,
            'checkpoint_ns' => $checkpointNs,
            'checkpoint_id' => $checkpointId,
            'parent_checkpoint_id' => $hasParent ? $parentCheckpointId : null,
            'checkpoint' => $checkpointPayload,
            'metadata' => $sanitized === [] ? new \stdClass() : RedisUtils::embed($this->serde, $sanitized)[1],
            'checkpoint_ts' => RedisUtils::nextTimestamp(),
            'has_writes' => $writesExist ? 'true' : 'false',
        ];
        RedisUtils::addSearchableMetadataFields($doc, $metadata);

        $this->client->jsonSet($key, '$', RedisUtils::encode($doc));

        if ($this->ttlConfig?->enabled()) {
            RedisUtils::applyTtl($this->client, $this->ttlConfig, $key);
        }

        return static::tupleConfig((string) $threadId, (string) $checkpointNs, $checkpointId);
    }

    /**
     * The thread's single checkpoint, or null when `checkpoint_id` names a superseded one.
     *
     * @param array<string, mixed> $config
     */
    public function getTuple(array $config): ?PregelCheckpointTuple
    {
        $configurable = static::configurable($config);
        $threadId = $configurable['thread_id'] ?? null;
        $checkpointNs = $configurable['checkpoint_ns'] ?? '';
        $checkpointId = $configurable['checkpoint_id'] ?? null;
        $hasCheckpointId = $checkpointId !== null && $checkpointId !== '';

        if (RedisUtils::isFalsy($threadId)) {
            return null;
        }

        RedisUtils::assertSafeKeyComponent('thread_id', $threadId);
        RedisUtils::assertSafeKeyComponent('checkpoint_ns', $checkpointNs, allowEmpty: true);
        if ($hasCheckpointId) {
            RedisUtils::assertSafeKeyComponent('checkpoint_id', $checkpointId);
        }

        $key = "checkpoint:{$threadId}:{$checkpointNs}:shallow";
        $raw = $this->client->jsonGet($key);
        if ($raw === null) {
            return null;
        }
        $doc = $this->decodeDocument($raw);

        if ($hasCheckpointId && $doc->checkpoint_id !== $checkpointId) {
            return null;
        }

        if ($this->ttlConfig !== null && $this->ttlConfig->refreshOnRead && $this->ttlConfig->enabled()) {
            RedisUtils::applyTtl($this->client, $this->ttlConfig, $key);
        }

        $pendingWrites = [];
        if (($doc->has_writes ?? 'false') === 'true') {
            $pendingWrites = $this->loadPendingWrites(
                (string) $doc->thread_id,
                (string) $doc->checkpoint_ns,
                (string) $doc->checkpoint_id,
            );
        }

        return $this->createCheckpointTuple($doc, $this->loadCheckpoint($doc), $pendingWrites);
    }

    /**
     * At most one checkpoint per thread. With a thread id that is its single tuple;
     * without one, the newest checkpoint of each thread. Default page size is 10.
     *
     * @param  array<string, mixed>           $config
     * @param  CheckpointListOptions|int|null $options
     * @return list<PregelCheckpointTuple>
     */
    public function list(array $config, CheckpointListOptions|int|null $options = null): array
    {
        $this->ensureIndexes();

        $opts = CheckpointListOptions::of($options);
        $configurable = static::configurable($config);
        $threadId = $configurable['thread_id'] ?? null;
        $checkpointNs = $configurable['checkpoint_ns'] ?? null;

        // A `thread_id` of `*` would otherwise feed `KEYS checkpoint:*:*:shallow`.
        if ($threadId !== null) {
            RedisUtils::assertSafeKeyComponent('thread_id', $threadId);
        }
        if ($checkpointNs !== null) {
            RedisUtils::assertSafeKeyComponent('checkpoint_ns', $checkpointNs, allowEmpty: true);
        }

        $filter = $opts->filter;

        if (!RedisUtils::isFalsy($threadId)) {
            $tuple = $this->getTuple($config);
            if ($tuple === null) {
                return [];
            }
            if ($filter !== null && !RedisUtils::metadataMatches($tuple->metadata, $filter)) {
                return [];
            }

            return [$tuple];
        }

        $queryParts = RedisUtils::filterQueryParts($filter ?? []);
        $query = $queryParts === [] ? '*' : implode(' ', $queryParts);
        $limit = $opts->limit ?? 10;
        if ($limit <= 0) {
            return [];
        }

        try {
            // Twice the page: a thread can appear more than once before it is de-duplicated.
            $hits = $this->client->ftSearch('checkpoints', $query, 0, $limit * 2, 'checkpoint_ts', true);
            $documents = array_map(fn (array $hit): object => $this->decodeDocument($hit['value']), $hits);
        } catch (RedisClientException $e) {
            if (!RedisUtils::isMissingIndex($e)) {
                throw $e;
            }
            // Index missing: scan the shallow keys instead.
            $keys = $this->client->keys('checkpoint:*:*:shallow');
            rsort($keys, SORT_STRING);
            $documents = [];
            foreach ($keys as $key) {
                $raw = $this->client->jsonGet($key);
                if ($raw !== null) {
                    $documents[] = $this->decodeDocument($raw);
                }
            }
        }

        $seen = [];
        $tuples = [];
        foreach ($documents as $doc) {
            if (count($tuples) >= $limit) {
                break;
            }
            $threadKey = $doc->thread_id . ':' . $doc->checkpoint_ns;
            if (isset($seen[$threadKey])) {
                continue;
            }
            $seen[$threadKey] = true;

            $metadata = $this->loadMetadata($doc);
            if ($filter !== null && !RedisUtils::metadataMatches($metadata, $filter)) {
                continue;
            }

            $tuples[] = $this->createCheckpointTuple($doc, $this->loadCheckpoint($doc), [], $metadata);
        }

        return $tuples;
    }

    /**
     * Replace a task's writes for a checkpoint.
     *
     * @param  array<string, mixed>             $config
     * @param  list<array{0: string, 1: mixed}> $writes
     * @return array<string, mixed>
     */
    public function putWrites(array $config, array $writes, string $taskId): array
    {
        $this->ensureIndexes();

        $configurable = static::configurable($config);
        $threadId = $configurable['thread_id'] ?? null;
        $checkpointNs = $configurable['checkpoint_ns'] ?? '';
        $checkpointId = $configurable['checkpoint_id'] ?? null;

        if (RedisUtils::isFalsy($threadId) || RedisUtils::isFalsy($checkpointId)) {
            throw new \InvalidArgumentException('thread_id and checkpoint_id are required');
        }

        RedisUtils::assertSafeKeyComponent('thread_id', $threadId);
        RedisUtils::assertSafeKeyComponent('checkpoint_ns', $checkpointNs, allowEmpty: true);
        RedisUtils::assertSafeKeyComponent('checkpoint_id', $checkpointId);
        RedisUtils::assertSafeKeyComponent('task_id', $taskId);

        // Shallow mode overwrites: drop this task's earlier writes first.
        $stale = $this->client->keys("checkpoint_write:{$threadId}:{$checkpointNs}:{$checkpointId}:{$taskId}:*");
        if ($stale !== []) {
            $this->client->del($stale);
        }

        $writeKeys = [];
        foreach (array_values($writes) as $idx => $write) {
            $channel = (string) $write[0];
            $value = $write[1] ?? null;
            $writeKey = "checkpoint_write:{$threadId}:{$checkpointNs}:{$checkpointId}:{$taskId}:{$idx}";
            $writeKeys[] = $writeKey;

            [$embeddedType, $embedded] = RedisUtils::embed($this->serde, $value);
            $this->client->jsonSet($writeKey, '$', RedisUtils::encode([
                'thread_id' => $threadId,
                'checkpoint_ns' => $checkpointNs,
                'checkpoint_id' => $checkpointId,
                'task_id' => $taskId,
                'idx' => $idx,
                'channel' => $channel,
                'type' => $embeddedType === 'bytes' ? 'bytes' : (is_array($value) || is_object($value) ? 'json' : 'string'),
                'value' => $embedded,
            ]));
        }

        if ($writeKeys !== []) {
            $zsetKey = RedisUtils::WRITE_KEYS_ZSET_PREFIX . ":{$threadId}:{$checkpointNs}:{$checkpointId}";
            $members = [];
            foreach ($writeKeys as $idx => $writeKey) {
                $members[] = ['score' => $idx, 'value' => $writeKey];
            }
            $this->client->zAdd($zsetKey, $members);

            if ($this->ttlConfig?->enabled()) {
                RedisUtils::applyTtl($this->client, $this->ttlConfig, ...$writeKeys, ...[$zsetKey]);
            }
        }

        // Mark the checkpoint as having writes (a no-op if `put` has not happened yet).
        $checkpointKey = "checkpoint:{$threadId}:{$checkpointNs}:shallow";
        if ($this->client->exists($checkpointKey) > 0) {
            $raw = $this->client->jsonGet($checkpointKey);
            if ($raw !== null) {
                $current = $this->decodeDocument($raw);
                $current->has_writes = 'true';
                $this->client->jsonSet($checkpointKey, '$', RedisUtils::encode($current));
            }
        }

        return $config;
    }

    /**
     * Forget a thread: its shallow checkpoints, writes and write registries.
     */
    public function deleteThread(string $threadId): void
    {
        // A `thread_id` of `*` would expand to `checkpoint:*:*:shallow` and wipe every tenant.
        RedisUtils::assertSafeKeyComponent('thread_id', $threadId);

        foreach ([
            "checkpoint:{$threadId}:*:shallow",
            "checkpoint_write:{$threadId}:*",
            RedisUtils::WRITE_KEYS_ZSET_PREFIX . ":{$threadId}:*",
        ] as $pattern) {
            $keys = $this->client->keys($pattern);
            if ($keys !== []) {
                $this->client->del($keys);
            }
        }
    }

    /** Close the connection. */
    public function end(): void
    {
        $this->client->quit();
    }

    private function decodeDocument(string $raw): object
    {
        $doc = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($doc)) {
            throw new \UnexpectedValueException('A Redis checkpoint document is not a JSON object.');
        }

        return $doc;
    }

    private function loadCheckpoint(object $doc): Checkpoint
    {
        return Checkpoint::fromArray((array) $this->serde->loadsTyped('json', RedisUtils::encode($doc->checkpoint)));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadMetadata(object $doc): array
    {
        return (array) $this->serde->loadsTyped('json', RedisUtils::encode($doc->metadata ?? new \stdClass()));
    }

    /**
     * The writes recorded against a checkpoint, in write-registry (sorted-set) order.
     *
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private function loadPendingWrites(string $threadId, string $namespace, string $checkpointId): array
    {
        RedisUtils::assertSafeKeyComponent('thread_id', $threadId);
        RedisUtils::assertSafeKeyComponent('checkpoint_ns', $namespace, allowEmpty: true);
        RedisUtils::assertSafeKeyComponent('checkpoint_id', $checkpointId);

        $zsetKey = RedisUtils::WRITE_KEYS_ZSET_PREFIX . ":{$threadId}:{$namespace}:{$checkpointId}";
        $pending = [];
        foreach ($this->client->zRange($zsetKey, 0, -1) as $writeKey) {
            $raw = $this->client->jsonGet($writeKey);
            if ($raw === null) {
                continue;
            }
            $doc = $this->decodeDocument($raw);
            $pending[] = [
                (string) $doc->task_id,
                (string) $doc->channel,
                property_exists($doc, 'value')
                    ? RedisUtils::unembed($this->serde, (string) ($doc->type ?? 'json'), $doc->value)
                    : null,
            ];
        }

        return $pending;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: mixed}> $pendingWrites
     * @param  array<string, mixed>|null                   $metadata
     */
    private function createCheckpointTuple(
        object $doc,
        Checkpoint $checkpoint,
        array $pendingWrites,
        ?array $metadata = null,
    ): CheckpointTuple {
        $parentId = $doc->parent_checkpoint_id ?? null;

        return new CheckpointTuple(
            config: static::tupleConfig((string) $doc->thread_id, (string) $doc->checkpoint_ns, (string) $doc->checkpoint_id),
            checkpoint: $checkpoint,
            metadata: $metadata ?? $this->loadMetadata($doc),
            parentConfig: $parentId === null || $parentId === ''
                ? null
                : static::tupleConfig((string) $doc->thread_id, (string) $doc->checkpoint_ns, (string) $parentId),
            pendingWrites: $pendingWrites,
        );
    }

    /**
     * Delete the writes, write registry and any legacy blobs of the checkpoint being replaced.
     */
    private function cleanupOldCheckpoint(string $threadId, string $checkpointNs, string $oldCheckpointId): void
    {
        $oldWrites = $this->client->keys("checkpoint_write:{$threadId}:{$checkpointNs}:{$oldCheckpointId}:*");
        if ($oldWrites !== []) {
            $this->client->del($oldWrites);
        }

        $this->client->del([RedisUtils::WRITE_KEYS_ZSET_PREFIX . ":{$threadId}:{$checkpointNs}:{$oldCheckpointId}"]);

        // Shallow mode stores values inline, but clear any blobs an earlier layout left behind.
        $oldBlobs = $this->client->keys("checkpoint_blob:{$threadId}:{$checkpointNs}:{$oldCheckpointId}:*");
        if ($oldBlobs !== []) {
            $this->client->del($oldBlobs);
        }
    }

    /**
     * Remove NUL bytes from metadata keys and top-level string values.
     *
     * RedisJSON rejects them in some paths and RediSearch tokenises on them.
     *
     * @param  array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];
        foreach ($metadata as $key => $value) {
            $sanitized[str_replace("\0", '', (string) $key)] = is_string($value) ? str_replace("\0", '', $value) : $value;
        }

        return $sanitized;
    }

    private function ensureIndexes(): void
    {
        RedisUtils::ensureIndexes($this->client, [RedisSaver::SCHEMAS[0], RedisSaver::SCHEMAS[2]]);
    }
}
