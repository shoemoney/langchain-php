<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\MongoDB;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointConstants;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\CheckpointTuple;
use LangGraph\Checkpoint\Serde\BaseCheckpointSerializer;
use LangGraph\Pregel\Checkpoint\BaseCheckpointSaver;
use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;
use LangGraph\Pregel\Checkpoint\CheckpointTuple as PregelCheckpointTuple;

/**
 * A checkpoint saver backed by a MongoDB database.
 *
 * Port of `MongoDBSaver` from `@langchain/langgraph-checkpoint-mongodb`, over
 * {@see MongoClientInterface} rather than the driver.
 *
 * ## Layout
 *
 * Two collections. `checkpoints` holds one document per checkpoint, keyed
 * `(thread_id, checkpoint_ns, checkpoint_id)`: the whole serialised checkpoint and its
 * metadata as binary, plus `metadata_search`, the metadata as plain fields so `list()`
 * can filter server-side. `checkpoint_writes` holds one document per pending write,
 * keyed `(thread_id, checkpoint_ns, checkpoint_id, task_id, idx)`.
 *
 * Checkpoint ids are time-ordered strings, so `sort checkpoint_id desc` is newest-first
 * and a "latest checkpoint" read depends on the sort being honoured.
 *
 * ## Setup
 *
 * Call {@see self::setup()} once to create the indexes.
 *
 * ## Known non-exact behaviour
 *
 *  - Upstream submits a write batch as one `bulkWrite`; the seam has no bulk call, so each
 *    write is its own `updateOne` upsert. The per-document semantics are identical.
 *  - `list()` returns an array, not an async generator, and a `limit` of zero or less
 *    yields nothing where MongoDB's own `limit(0)` means "no limit".
 *  - Pre-format-4 pending sends are folded in on read (the shared base-class migration),
 *    which upstream's Mongo saver does not do.
 */
class MongoDBSaver extends BaseCheckpointSaver
{
    public string $checkpointCollectionName = 'checkpoints';

    public string $checkpointWritesCollectionName = 'checkpoint_writes';

    protected readonly MongoDatabaseInterface $db;

    protected readonly bool $enableTimestamps;

    /**
     * @param int|null $ttl Seconds of inactivity before documents expire. Implies `enableTimestamps`:
     *                      the TTL index keys on `upserted_at`, so without it nothing would ever expire.
     */
    public function __construct(
        protected readonly MongoClientInterface $client,
        ?string $dbName = null,
        ?string $checkpointCollectionName = null,
        ?string $checkpointWritesCollectionName = null,
        bool $enableTimestamps = false,
        protected readonly ?int $ttl = null,
        ?BaseCheckpointSerializer $serde = null,
    ) {
        parent::__construct($serde);
        $this->client->appendMetadata(['name' => 'langgraphjs_checkpoint_saver']);
        $this->db = $this->client->db($dbName);
        $this->checkpointCollectionName = $checkpointCollectionName ?? $this->checkpointCollectionName;
        $this->checkpointWritesCollectionName = $checkpointWritesCollectionName ?? $this->checkpointWritesCollectionName;
        $this->enableTimestamps = $enableTimestamps || $ttl !== null;
    }

    /**
     * The update operator that stamps `upserted_at`, or nothing when timestamps are off.
     *
     * @return array<string, mixed>
     */
    protected function timestampOp(): array
    {
        return $this->enableTimestamps ? ['$currentDate' => ['upserted_at' => true]] : [];
    }

    /**
     * Create the indexes the saver's queries and upserts use.
     *
     * Idempotent. With a `ttl`, also creates TTL indexes on `upserted_at`. Failures do not
     * stop the other indexes: they come back as a list for the caller to handle.
     *
     * @return list<\Throwable> Empty on success.
     */
    public function setup(): array
    {
        $checkpoints = $this->db->collection($this->checkpointCollectionName);
        $writes = $this->db->collection($this->checkpointWritesCollectionName);

        $operations = [
            [$checkpoints, ['thread_id' => 1, 'checkpoint_ns' => 1, 'checkpoint_id' => -1], ['name' => 'thread_ns_checkpoint_idx']],
            [$writes, ['thread_id' => 1, 'checkpoint_ns' => 1, 'checkpoint_id' => 1, 'task_id' => 1, 'idx' => 1], ['name' => 'thread_ns_checkpoint_task_idx']],
        ];

        if ($this->ttl !== null) {
            $operations[] = [$checkpoints, ['upserted_at' => 1], ['expireAfterSeconds' => $this->ttl]];
            $operations[] = [$writes, ['upserted_at' => 1], ['expireAfterSeconds' => $this->ttl]];
        }

        $errors = [];
        foreach ($operations as [$collection, $keys, $options]) {
            try {
                $collection->createIndex($keys, $options);
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }

        return $errors;
    }

    /**
     * The latest checkpoint for a thread/namespace, or the one `checkpoint_id` names.
     *
     * @param array<string, mixed> $config
     */
    public function getTuple(array $config): ?PregelCheckpointTuple
    {
        $configurable = static::configurable($config);
        $threadId = self::stringConfigValue('thread_id', $configurable['thread_id'] ?? null);
        if ($threadId === null) {
            return null;
        }
        $namespace = self::stringConfigValue('checkpoint_ns', $configurable['checkpoint_ns'] ?? null) ?? '';
        $checkpointId = self::stringConfigValue('checkpoint_id', $configurable['checkpoint_id'] ?? null);

        $query = ['thread_id' => $threadId, 'checkpoint_ns' => $namespace];
        if ($checkpointId !== null && $checkpointId !== '') {
            $query['checkpoint_id'] = $checkpointId;
        }

        $doc = $this->db
            ->collection($this->checkpointCollectionName)
            ->findOne($query, ['checkpoint_id' => -1]);

        return $doc === null ? null : $this->docToTuple($doc, $threadId, $namespace);
    }

    /**
     * A thread's checkpoints, newest first.
     *
     * Filter values must be primitives: an object or array there would be handed to MongoDB
     * as an operator (`{"$regex": ".*"}`), which is query injection.
     *
     * @param  array<string, mixed>           $config
     * @param  CheckpointListOptions|int|null $options
     * @return list<PregelCheckpointTuple>
     */
    public function list(array $config, CheckpointListOptions|int|null $options = null): array
    {
        $opts = CheckpointListOptions::of($options);
        $configurable = static::configurable($config);
        $query = [];

        $threadId = self::stringConfigValue('thread_id', $configurable['thread_id'] ?? null);
        $namespace = self::stringConfigValue('checkpoint_ns', $configurable['checkpoint_ns'] ?? null);
        if ($threadId !== null && $threadId !== '') {
            $query['thread_id'] = $threadId;
        }
        if ($namespace !== null) {
            $query['checkpoint_ns'] = $namespace;
        }

        foreach ($opts->filter ?? [] as $key => $value) {
            if (is_array($value) || is_object($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid filter value for key "%s": filter values must be primitives (string, number, boolean, or null)',
                    $key,
                ));
            }
            $query['metadata_search.' . $key] = $value;
        }

        if ($opts->before !== null) {
            $beforeId = self::stringConfigValue(
                'checkpoint_id',
                static::configurable($opts->before)['checkpoint_id'] ?? null,
            );
            if ($beforeId !== null) {
                $query['checkpoint_id'] = ['$lt' => $beforeId];
            }
        }

        if ($opts->limit !== null && $opts->limit <= 0) {
            return [];
        }

        $tuples = [];
        foreach ($this->db->collection($this->checkpointCollectionName)->find($query, ['checkpoint_id' => -1], $opts->limit) as $doc) {
            $tuples[] = $this->docToTuple($doc, (string) $doc['thread_id'], (string) $doc['checkpoint_ns']);
        }

        return $tuples;
    }

    /**
     * Persist a checkpoint, replacing any stored under the same key.
     *
     * @param  array<string, mixed>      $config
     * @param  array<string, mixed>      $metadata
     * @param  array<string, int|string> $newVersions Unused: the whole checkpoint is stored.
     * @return array<string, mixed>
     */
    public function put(
        array $config,
        PregelCheckpoint $checkpoint,
        array $metadata = [],
        array $newVersions = [],
    ): array {
        $configurable = static::configurable($config);
        $threadId = self::stringConfigValue('thread_id', $configurable['thread_id'] ?? null, required: true);
        $namespace = self::stringConfigValue('checkpoint_ns', $configurable['checkpoint_ns'] ?? null) ?? '';
        $parentId = self::stringConfigValue('checkpoint_id', $configurable['checkpoint_id'] ?? null);
        $checkpointId = $checkpoint->id;

        $wire = $checkpoint instanceof Checkpoint ? $checkpoint->toArray() : (new Checkpoint(
            v: $checkpoint->v,
            id: $checkpoint->id,
            ts: $checkpoint->ts,
            channelValues: $checkpoint->channelValues,
            channelVersions: $checkpoint->channelVersions,
            versionsSeen: $checkpoint->versionsSeen,
        ))->toArray();
        if ($wire['channel_values'] === []) {
            $wire['channel_values'] = new \stdClass();
        }
        $storedMetadata = $metadata === [] ? new \stdClass() : $metadata;

        [$checkpointType, $serializedCheckpoint] = $this->serde->dumpsTyped($wire);
        [$metadataType, $serializedMetadata] = $this->serde->dumpsTyped($storedMetadata);
        if ($checkpointType !== $metadataType) {
            throw new \RuntimeException('Mismatched checkpoint and metadata types.');
        }

        $doc = [
            'parent_checkpoint_id' => $parentId,
            'type' => $checkpointType,
            'checkpoint' => new MongoBinary($serializedCheckpoint),
            'metadata' => new MongoBinary($serializedMetadata),
            'metadata_search' => $storedMetadata,
        ];

        $this->db->collection($this->checkpointCollectionName)->updateOne(
            ['thread_id' => $threadId, 'checkpoint_ns' => $namespace, 'checkpoint_id' => $checkpointId],
            ['$set' => $doc] + $this->timestampOp(),
            ['upsert' => true],
        );

        return static::tupleConfig($threadId, $namespace, $checkpointId);
    }

    /**
     * Persist one task's writes.
     *
     * Each write is upserted at `(thread_id, checkpoint_ns, checkpoint_id, task_id, idx)`.
     * When every write targets a special channel (error, scheduled, interrupt, resume — each
     * pinned to a negative `idx`) the row is overwritten with `$set`, so a resume can replace
     * the interrupt it answers. Otherwise the row is written with `$setOnInsert`, so a regular
     * write can never clobber one a peer task already stored at the same position.
     *
     * @param  array<string, mixed>             $config
     * @param  list<array{0: string, 1: mixed}> $writes
     * @return array<string, mixed>
     */
    public function putWrites(array $config, array $writes, string $taskId): array
    {
        $configurable = static::configurable($config);
        $threadId = self::stringConfigValue('thread_id', $configurable['thread_id'] ?? null, required: true);
        $namespace = self::stringConfigValue('checkpoint_ns', $configurable['checkpoint_ns'] ?? null, required: true);
        $checkpointId = self::stringConfigValue('checkpoint_id', $configurable['checkpoint_id'] ?? null, required: true);

        $writes = array_values($writes);
        // `[].every(...)` is true upstream, but an empty batch writes nothing either way.
        $allSpecial = CheckpointConstants::allSpecialChannels($writes);

        // MongoDB rejects an empty bulk write, and `interrupt()` flows send empty batches.
        $collection = $this->db->collection($this->checkpointWritesCollectionName);
        foreach ($writes as $position => $write) {
            $channel = (string) $write[0];
            [$type, $serialized] = $this->serde->dumpsTyped($write[1] ?? null);
            $fields = ['channel' => $channel, 'type' => $type, 'value' => new MongoBinary($serialized)];

            $update = $allSpecial
                ? ['$set' => $fields] + $this->timestampOp()
                : ['$setOnInsert' => $this->enableTimestamps ? $fields + ['upserted_at' => new \DateTimeImmutable()] : $fields];

            $collection->updateOne([
                'thread_id' => $threadId,
                'checkpoint_ns' => $namespace,
                'checkpoint_id' => $checkpointId,
                'task_id' => $taskId,
                'idx' => static::writeIndex($channel, $position),
            ], $update, ['upsert' => true]);
        }

        return $config;
    }

    /** Forget a thread: its checkpoints and its writes. */
    public function deleteThread(string $threadId): void
    {
        $this->db->collection($this->checkpointCollectionName)->deleteMany(['thread_id' => $threadId]);
        $this->db->collection($this->checkpointWritesCollectionName)->deleteMany(['thread_id' => $threadId]);
    }

    /**
     * The `__pregel_tasks` writes recorded against a checkpoint.
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

        $query = [
            'thread_id' => $threadId,
            'checkpoint_id' => $checkpointId,
            'channel' => CheckpointConstants::TASKS,
        ];
        $namespace = $configurable['checkpoint_ns'] ?? null;
        if (is_string($namespace)) {
            $query['checkpoint_ns'] = $namespace;
        }

        $sends = [];
        foreach ($this->db->collection($this->checkpointWritesCollectionName)->find($query, ['task_id' => 1, 'idx' => 1]) as $doc) {
            $sends[] = $this->serde->loadsTyped((string) $doc['type'], self::binaryValue($doc['value']));
        }

        return $sends;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function docToTuple(array $doc, string $threadId, string $namespace): PregelCheckpointTuple
    {
        $checkpointId = (string) $doc['checkpoint_id'];
        $type = (string) $doc['type'];
        $parentId = $doc['parent_checkpoint_id'] ?? null;
        $parentId = is_string($parentId) && $parentId !== '' ? $parentId : null;

        $loaded = $this->serde->loadsTyped($type, self::binaryValue($doc['checkpoint']));
        $checkpoint = Checkpoint::fromArray((array) $loaded);
        $metadata = (array) $this->serde->loadsTyped($type, self::binaryValue($doc['metadata']));

        $this->migratePendingSends($checkpoint, [
            'thread_id' => $threadId,
            'checkpoint_ns' => $namespace,
            'checkpoint_id' => $checkpointId,
        ], $parentId);

        $pendingWrites = [];
        $writes = $this->db->collection($this->checkpointWritesCollectionName)->find([
            'thread_id' => $threadId,
            'checkpoint_ns' => $namespace,
            'checkpoint_id' => $checkpointId,
        ]);
        foreach ($writes as $write) {
            $pendingWrites[] = [
                (string) $write['task_id'],
                (string) $write['channel'],
                $this->serde->loadsTyped((string) $write['type'], self::binaryValue($write['value'])),
            ];
        }

        return new CheckpointTuple(
            config: static::tupleConfig($threadId, $namespace, $checkpointId),
            checkpoint: $checkpoint,
            metadata: $metadata,
            parentConfig: $parentId === null ? null : static::tupleConfig($threadId, $namespace, $parentId),
            pendingWrites: $pendingWrites,
        );
    }

    /**
     * A configurable value that must be a string if present.
     *
     * Port of `getStringConfigValue`. An object or array here would reach the query as an
     * operator (`{"$gt": ""}`), so anything but a string is refused.
     *
     * @throws \InvalidArgumentException
     */
    private static function stringConfigValue(string $name, mixed $value, bool $required = false): ?string
    {
        if ($value === null) {
            if ($required) {
                throw new \InvalidArgumentException("Invalid configurable.{$name}: expected a string");
            }

            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Invalid configurable.{$name}: expected a string");
        }

        return $value;
    }

    /** The bytes of a stored binary field, whichever form the collection hands back. */
    private static function binaryValue(mixed $binary): string
    {
        return match (true) {
            $binary instanceof MongoBinary => $binary->value(),
            is_string($binary) => $binary,
            is_object($binary) && method_exists($binary, 'getData') => (string) $binary->getData(),
            default => throw new \UnexpectedValueException('Expected a binary field.'),
        };
    }
}
