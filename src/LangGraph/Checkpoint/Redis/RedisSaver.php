<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Redis;

use LangGraph\Checkpoint\ChannelVersions;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointConstants;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\CheckpointTuple;
use LangGraph\Checkpoint\Serde\BaseCheckpointSerializer;
use LangGraph\Pregel\Checkpoint\BaseCheckpointSaver;
use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;
use LangGraph\Pregel\Checkpoint\CheckpointTuple as PregelCheckpointTuple;

/**
 * A durable checkpoint saver backed by Redis (RedisJSON + RediSearch).
 *
 * Port of `RedisSaver` from `@langchain/langgraph-checkpoint-redis`, against
 * {@see RedisClientInterface} rather than a concrete client.
 *
 * ## Key layout
 *
 *  - `checkpoint:{thread}:{ns}:{checkpointId}` — one JSON document per superstep.
 *    An empty namespace is the empty string in the KEY but `__empty__` in the
 *    DOCUMENT, because RediSearch does not index an empty TAG.
 *  - `checkpoint_blob:{thread}:{ns}:{channel}:{version}` — one document per channel
 *    VERSION. A checkpoint document carries only the channels that changed in its
 *    superstep ({@see self::put()}'s `$newVersions`); the rest are rebuilt from the
 *    blobs their versions name.
 *  - `checkpoint_write:{thread}:{ns}:{checkpointId}:{task}:{idx}` — one document per
 *    pending write, ordered by a microsecond `global_idx`.
 *  - `write_keys_zset:{thread}:{ns}:{checkpointId}` — a sorted set marking that writes
 *    exist, which is what lets `put()` set `has_writes` when `putWrites()` ran first.
 *
 * Three RediSearch indexes (`checkpoints`, `checkpoint_blobs`, `checkpoint_writes`)
 * serve `list()`. Where an index is missing the saver falls back to key scans.
 *
 * ## Known non-exact behaviour
 *
 *  - Upstream issues the blob writes with `Promise.all`; they are sequential here.
 *  - `fromCluster` is not ported (no cluster client is bundled).
 *  - `list()` keeps upstream's default limit of 10.
 */
class RedisSaver extends BaseCheckpointSaver
{
    /** @var list<array{index: string, prefix: string, schema: array<string, array{type: string, as: string}>}> */
    public const SCHEMAS = [
        [
            'index' => 'checkpoints',
            'prefix' => 'checkpoint:',
            'schema' => [
                '$.thread_id' => ['type' => 'TAG', 'as' => 'thread_id'],
                '$.checkpoint_ns' => ['type' => 'TAG', 'as' => 'checkpoint_ns'],
                '$.checkpoint_id' => ['type' => 'TAG', 'as' => 'checkpoint_id'],
                '$.parent_checkpoint_id' => ['type' => 'TAG', 'as' => 'parent_checkpoint_id'],
                '$.checkpoint_ts' => ['type' => 'NUMERIC', 'as' => 'checkpoint_ts'],
                '$.has_writes' => ['type' => 'TAG', 'as' => 'has_writes'],
                '$.source' => ['type' => 'TAG', 'as' => 'source'],
                '$.step' => ['type' => 'NUMERIC', 'as' => 'step'],
            ],
        ],
        [
            'index' => 'checkpoint_blobs',
            'prefix' => 'checkpoint_blob:',
            'schema' => [
                '$.thread_id' => ['type' => 'TAG', 'as' => 'thread_id'],
                '$.checkpoint_ns' => ['type' => 'TAG', 'as' => 'checkpoint_ns'],
                '$.checkpoint_id' => ['type' => 'TAG', 'as' => 'checkpoint_id'],
                '$.channel' => ['type' => 'TAG', 'as' => 'channel'],
                '$.version' => ['type' => 'TAG', 'as' => 'version'],
                '$.type' => ['type' => 'TAG', 'as' => 'type'],
            ],
        ],
        [
            'index' => 'checkpoint_writes',
            'prefix' => 'checkpoint_write:',
            'schema' => [
                '$.thread_id' => ['type' => 'TAG', 'as' => 'thread_id'],
                '$.checkpoint_ns' => ['type' => 'TAG', 'as' => 'checkpoint_ns'],
                '$.checkpoint_id' => ['type' => 'TAG', 'as' => 'checkpoint_id'],
                '$.task_id' => ['type' => 'TAG', 'as' => 'task_id'],
                '$.idx' => ['type' => 'NUMERIC', 'as' => 'idx'],
                '$.channel' => ['type' => 'TAG', 'as' => 'channel'],
                '$.type' => ['type' => 'TAG', 'as' => 'type'],
            ],
        ],
    ];

    /** The document's stand-in for the empty root namespace. */
    private const EMPTY_NS = '__empty__';

    /** Upstream's default `list()` page size. */
    private const DEFAULT_LIMIT = 10;

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
     * The latest checkpoint for a thread/namespace, or the one `checkpoint_id` names.
     *
     * @param array<string, mixed> $config
     */
    public function getTuple(array $config): ?PregelCheckpointTuple
    {
        $configurable = static::configurable($config);
        $threadId = $configurable['thread_id'] ?? null;
        $checkpointNs = $configurable['checkpoint_ns'] ?? '';
        $checkpointId = $configurable['checkpoint_id'] ?? null;

        if (RedisUtils::isFalsy($threadId)) {
            return null;
        }

        RedisUtils::assertSafeKeyComponent('thread_id', $threadId);
        RedisUtils::assertSafeKeyComponent('checkpoint_ns', $checkpointNs, allowEmpty: true);
        $hasCheckpointId = $checkpointId !== null && $checkpointId !== '';
        if ($hasCheckpointId) {
            RedisUtils::assertSafeKeyComponent('checkpoint_id', $checkpointId);
        }

        if ($hasCheckpointId) {
            $key = "checkpoint:{$threadId}:{$checkpointNs}:{$checkpointId}";
            $raw = $this->client->jsonGet($key);
        } else {
            // The newest id sorts last: ids are time-ordered, so string order is write order.
            $keys = $this->client->keys("checkpoint:{$threadId}:{$checkpointNs}:*");
            if ($keys === []) {
                return null;
            }
            sort($keys, SORT_STRING);
            $key = $keys[count($keys) - 1];
            $raw = $this->client->jsonGet($key);
        }

        if ($raw === null) {
            return null;
        }
        $doc = $this->decodeDocument($raw);

        if ($this->refreshesOnRead()) {
            $this->applyTtl($key);
        }

        [$checkpoint, $pendingWrites] = $this->loadCheckpointWithWrites($doc);

        return $this->createCheckpointTuple($doc, $checkpoint, $pendingWrites);
    }

    /**
     * Persist a new checkpoint.
     *
     * `$newVersions` is `null` when the caller does not say which channels changed, in
     * which case every channel value is stored inline. When it is given (the engine
     * always does), only those channels are stored in the document and each also gets
     * a blob; an EMPTY map stores no channel values at all.
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
        $key = "checkpoint:{$threadId}:{$checkpointNs}:{$checkpointId}";

        $wire = RedisUtils::wireCheckpoint($checkpoint);
        $wire['id'] = $checkpointId;
        /** @var array<string, mixed> $channelValues */
        $channelValues = $wire['channel_values'];

        $storedValues = $channelValues;
        if ($newVersions !== null) {
            // Only what changed in this superstep lives in the document; the rest is in blobs.
            $storedValues = [];
            foreach (array_keys($newVersions) as $channel) {
                if (array_key_exists($channel, $channelValues)) {
                    $storedValues[$channel] = $channelValues[$channel];
                }
            }
        }
        $wire['channel_values'] = $storedValues;

        // Writes may already exist if `putWrites` ran before `put` (an interrupt).
        $zsetKey = RedisUtils::WRITE_KEYS_ZSET_PREFIX . ":{$threadId}:{$checkpointNs}:{$checkpointId}";
        $writesExist = $this->client->exists($zsetKey) > 0;

        $storedNs = $checkpointNs === '' ? self::EMPTY_NS : $checkpointNs;
        [, $checkpointPayload] = RedisUtils::embed($this->serde, $wire);
        if ($checkpointPayload instanceof \stdClass && ($checkpointPayload->channel_values ?? null) === []) {
            $checkpointPayload->channel_values = new \stdClass();
        }

        $doc = [
            'thread_id' => $threadId,
            'checkpoint_ns' => $storedNs,
            'checkpoint_id' => $checkpointId,
            'parent_checkpoint_id' => $hasParent ? $parentCheckpointId : null,
            'checkpoint' => $checkpointPayload,
            'metadata' => $this->embedMetadata($metadata),
            'checkpoint_ts' => RedisUtils::nextTimestamp(),
            'has_writes' => $writesExist ? 'true' : 'false',
        ];
        RedisUtils::addSearchableMetadataFields($doc, $metadata);

        if ($newVersions !== null) {
            foreach ($newVersions as $channel => $version) {
                $channel = (string) $channel;
                if (!array_key_exists($channel, $channelValues)) {
                    continue;
                }
                [$blobType, $blobValue] = RedisUtils::embed($this->serde, $channelValues[$channel]);
                $this->client->jsonSet(
                    "checkpoint_blob:{$threadId}:{$checkpointNs}:{$channel}:{$version}",
                    '$',
                    RedisUtils::encode([
                        'thread_id' => $threadId,
                        'checkpoint_ns' => $storedNs,
                        'checkpoint_id' => $checkpointId,
                        'channel' => $channel,
                        'version' => (string) $version,
                        'type' => $blobType,
                        'value' => $blobValue,
                    ]),
                );
            }

            // Refresh every blob this checkpoint depends on, not just the ones written now:
            // a channel carried over from an earlier node lives under an older TTL and would
            // otherwise expire while this checkpoint is still alive.
            if ($this->ttlConfig?->enabled()) {
                $referenced = [];
                foreach ($checkpoint->channelVersions as $channel => $version) {
                    $referenced[] = "checkpoint_blob:{$threadId}:{$checkpointNs}:{$channel}:{$version}";
                }
                if ($referenced !== []) {
                    $this->applyTtl(...$referenced);
                }
            }
        }

        $this->client->jsonSet($key, '$', RedisUtils::encode($doc));

        if ($this->ttlConfig?->enabled()) {
            $this->applyTtl($key);
        }

        return static::tupleConfig((string) $threadId, (string) $checkpointNs, $checkpointId);
    }

    /**
     * A thread's checkpoints, newest first. Default page size is 10, as upstream.
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

        // Caller-controlled values reach KEYS patterns in the fallback path and RediSearch
        // tag clauses in the search path; refuse the dangerous ones up front.
        if ($threadId !== null) {
            RedisUtils::assertSafeKeyComponent('thread_id', $threadId);
        }
        if ($checkpointNs !== null) {
            RedisUtils::assertSafeKeyComponent('checkpoint_ns', $checkpointNs, allowEmpty: true);
        }
        if ($opts->before !== null) {
            $beforeConfigurable = static::configurable($opts->before);
            if (($beforeConfigurable['checkpoint_id'] ?? null) !== null) {
                RedisUtils::assertSafeKeyComponent('checkpoint_id', $beforeConfigurable['checkpoint_id']);
            }
            if (($beforeConfigurable['thread_id'] ?? null) !== null) {
                RedisUtils::assertSafeKeyComponent('thread_id', $beforeConfigurable['thread_id']);
            }
            if (($beforeConfigurable['checkpoint_ns'] ?? null) !== null) {
                RedisUtils::assertSafeKeyComponent('checkpoint_ns', $beforeConfigurable['checkpoint_ns'], allowEmpty: true);
            }
        }

        $filter = $opts->filter ?? [];
        $limit = $opts->limit ?? self::DEFAULT_LIMIT;
        if ($limit <= 0) {
            return [];
        }
        $beforeId = $opts->beforeCheckpointId();
        $hasThread = !RedisUtils::isFalsy($threadId);

        $queryParts = [];
        if ($hasThread) {
            $queryParts[] = '(@thread_id:{' . RedisUtils::escapeRediSearchTagValue((string) $threadId) . '})';
        }
        if ($checkpointNs !== null) {
            $queryParts[] = $checkpointNs === ''
                ? '(@checkpoint_ns:{' . self::EMPTY_NS . '})'
                : '(@checkpoint_ns:{' . RedisUtils::escapeRediSearchTagValue((string) $checkpointNs) . '})';
        }
        // With a `before` cursor the metadata filter is applied after it, on the page the
        // search returns, rather than inside the query.
        if ($opts->before === null) {
            array_push($queryParts, ...RedisUtils::filterQueryParts($filter));
        }
        $query = $queryParts === [] ? '*' : implode(' ', $queryParts);

        // The search window must be wider than the page whenever results are dropped afterwards.
        $dropsAfterSearch = $opts->before !== null || RedisUtils::hasInexpressibleFilter($filter);
        $fetchLimit = $dropsAfterSearch ? ($hasThread ? $limit * 10 : 1000) : $limit;

        try {
            $hits = $this->client->ftSearch('checkpoints', $query, 0, $fetchLimit, 'checkpoint_ts', true);
            $documents = array_map(fn (array $hit): object => $this->decodeDocument($hit['value']), $hits);
        } catch (RedisClientException $e) {
            if (!RedisUtils::isMissingIndex($e)) {
                throw $e;
            }
            // The index does not exist yet: fall back to scanning keys.
            $documents = $this->scanCheckpointDocuments($hasThread ? (string) $threadId : null, $checkpointNs);
        }

        $tuples = [];
        foreach ($documents as $doc) {
            if (count($tuples) >= $limit) {
                break;
            }
            // Ids are time-ordered, so a string comparison orders them. `strcmp`, not `>=`:
            // PHP compares two numeric-looking strings as numbers.
            if ($beforeId !== '' && strcmp((string) $doc->checkpoint_id, $beforeId) >= 0) {
                continue;
            }

            $metadata = $this->loadMetadata($doc);
            if ($filter !== [] && !RedisUtils::metadataMatches($metadata, $filter)) {
                continue;
            }

            [$checkpoint, $pendingWrites] = $this->loadCheckpointWithWrites($doc);
            $tuples[] = $this->createCheckpointTuple($doc, $checkpoint, $pendingWrites, $metadata);
        }

        return $tuples;
    }

    /**
     * Persist one task's writes.
     *
     * Special channels (error, scheduled, interrupt, resume) sit at fixed negative
     * indexes and OVERWRITE, so a resume can replace the interrupt it answers. A batch
     * with any regular write is insert-or-ignore (`JSON.SET ... NX`), so a retried task
     * cannot clobber a write another task already stored at the same position.
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

        $writes = array_values($writes);
        $baseTimestamp = RedisUtils::reserveWriteIndexes(count($writes));
        $allSpecial = $writes === [] || CheckpointConstants::allSpecialChannels($writes);

        $writeKeys = [];
        foreach ($writes as $idx => $write) {
            $channel = (string) $write[0];
            $value = $write[1] ?? null;
            $writeIdx = static::writeIndex($channel, $idx);
            $writeKey = "checkpoint_write:{$threadId}:{$checkpointNs}:{$checkpointId}:{$taskId}:{$writeIdx}";
            $writeKeys[] = $writeKey;

            [$embeddedType, $embedded] = RedisUtils::embed($this->serde, $value);
            $this->client->jsonSet($writeKey, '$', RedisUtils::encode([
                'thread_id' => $threadId,
                'checkpoint_ns' => $checkpointNs,
                'checkpoint_id' => $checkpointId,
                'task_id' => $taskId,
                'idx' => $writeIdx,
                'channel' => $channel,
                'type' => $embeddedType === 'bytes' ? 'bytes' : (is_array($value) || is_object($value) ? 'json' : 'string'),
                'value' => $embedded,
                'timestamp' => $baseTimestamp,
                'global_idx' => $baseTimestamp + $idx,
            ]), onlyIfAbsent: !$allSpecial);
        }

        if ($writeKeys !== []) {
            $zsetKey = RedisUtils::WRITE_KEYS_ZSET_PREFIX . ":{$threadId}:{$checkpointNs}:{$checkpointId}";
            $members = [];
            foreach ($writeKeys as $idx => $writeKey) {
                $members[] = ['score' => $baseTimestamp + $idx, 'value' => $writeKey];
            }
            $this->client->zAdd($zsetKey, $members);

            if ($this->ttlConfig?->enabled()) {
                $this->applyTtl(...$writeKeys, ...[$zsetKey]);
            }
        }

        // Mark the checkpoint as having writes (a no-op if `put` has not happened yet).
        $checkpointKey = "checkpoint:{$threadId}:{$checkpointNs}:{$checkpointId}";
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
     * Forget a thread: its checkpoints, writes, write registries and channel blobs.
     */
    public function deleteThread(string $threadId): void
    {
        // A `thread_id` of `*` would expand to `checkpoint:*:*` and wipe every tenant.
        RedisUtils::assertSafeKeyComponent('thread_id', $threadId);

        foreach ([
            "checkpoint:{$threadId}:*",
            "checkpoint_write:{$threadId}:*",
            RedisUtils::WRITE_KEYS_ZSET_PREFIX . ":{$threadId}:*",
            "checkpoint_blob:{$threadId}:*",
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

    private function refreshesOnRead(): bool
    {
        return $this->ttlConfig !== null && $this->ttlConfig->refreshOnRead && $this->ttlConfig->enabled();
    }

    private function applyTtl(string ...$keys): void
    {
        RedisUtils::applyTtl($this->client, $this->ttlConfig, ...$keys);
    }

    private function ensureIndexes(): void
    {
        RedisUtils::ensureIndexes($this->client, self::SCHEMAS);
    }

    private function decodeDocument(string $raw): object
    {
        $doc = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($doc)) {
            throw new \UnexpectedValueException('A Redis checkpoint document is not a JSON object.');
        }

        return $doc;
    }

    /**
     * Metadata as a document subtree. Always an object, so an empty map is `{}`.
     *
     * @param array<string, mixed> $metadata
     */
    private function embedMetadata(array $metadata): mixed
    {
        if ($metadata === []) {
            return new \stdClass();
        }
        [, $payload] = RedisUtils::embed($this->serde, $metadata);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadMetadata(object $doc): array
    {
        return (array) $this->serde->loadsTyped('json', RedisUtils::encode($doc->metadata ?? new \stdClass()));
    }

    /**
     * The namespace as callers see it (`__empty__` is the document's private spelling of '').
     */
    private function actualNamespace(object $doc): string
    {
        return $doc->checkpoint_ns === self::EMPTY_NS ? '' : (string) $doc->checkpoint_ns;
    }

    /**
     * Rebuild a checkpoint from its document, its blobs and its pending writes.
     *
     * @return array{0: Checkpoint, 1: list<array{0: string, 1: string, 2: mixed}>}
     */
    private function loadCheckpointWithWrites(object $doc): array
    {
        $checkpoint = Checkpoint::fromArray(
            (array) $this->serde->loadsTyped('json', RedisUtils::encode($doc->checkpoint)),
        );
        $namespace = $this->actualNamespace($doc);
        $threadId = (string) $doc->thread_id;

        // The document holds only the channels written by the last node; the rest are blobs.
        $usedBlobKeys = [];
        foreach ($checkpoint->channelVersions as $channel => $version) {
            if (array_key_exists($channel, $checkpoint->channelValues)) {
                continue;
            }
            $blobKey = "checkpoint_blob:{$threadId}:{$namespace}:{$channel}:{$version}";
            $rawBlob = $this->client->jsonGet($blobKey);
            if ($rawBlob === null) {
                continue;
            }
            $blob = $this->decodeDocument($rawBlob);
            // A carried-over blob can expire during an idle gap longer than the TTL. Skip the
            // channel (absent, not corrupt) rather than failing the whole read.
            if (!property_exists($blob, 'value')) {
                continue;
            }
            $checkpoint->channelValues[(string) $channel] = RedisUtils::unembed(
                $this->serde,
                (string) ($blob->type ?? 'json'),
                $blob->value,
            );
            $usedBlobKeys[] = $blobKey;
        }

        // Blobs arrive after the inline values; put channels back in `channel_versions` order so a
        // reconstructed checkpoint reads like the one that was saved.
        if ($usedBlobKeys !== []) {
            $ordered = [];
            foreach (array_keys($checkpoint->channelVersions) as $channel) {
                if (array_key_exists($channel, $checkpoint->channelValues)) {
                    $ordered[$channel] = $checkpoint->channelValues[$channel];
                }
            }
            $checkpoint->channelValues = $ordered + $checkpoint->channelValues;
        }

        // Keep the blobs alive for as long as the checkpoint that reads them.
        if ($usedBlobKeys !== [] && $this->refreshesOnRead()) {
            $this->applyTtl(...$usedBlobKeys);
        }

        // Pending sends are migrated ONLY for pre-v4 checkpoints that have a parent.
        if ($checkpoint->v < CheckpointConstants::CHECKPOINT_VERSION && ($doc->parent_checkpoint_id ?? null) !== null) {
            $this->migratePendingSendsFromParent($checkpoint, $threadId, $namespace, (string) $doc->parent_checkpoint_id);
        }

        $pendingWrites = [];
        if (($doc->has_writes ?? 'false') === 'true') {
            $pendingWrites = $this->loadPendingWrites($threadId, $namespace, (string) $doc->checkpoint_id);
        }

        return [$checkpoint, $pendingWrites];
    }

    /**
     * Fold a parent's `__pregel_tasks` writes into a pre-v4 checkpoint.
     *
     * Unlike the base-class migration this leaves the checkpoint untouched when the
     * parent recorded no sends, as upstream does.
     */
    private function migratePendingSendsFromParent(
        Checkpoint $checkpoint,
        string $threadId,
        string $namespace,
        string $parentCheckpointId,
    ): void {
        $sends = [];
        foreach ($this->loadPendingWrites($threadId, $namespace, $parentCheckpointId) as $write) {
            if ($write[1] === CheckpointConstants::TASKS) {
                $sends[] = $write[2];
            }
        }
        if ($sends === []) {
            return;
        }

        $checkpoint->channelValues[CheckpointConstants::TASKS] = $sends;
        $versions = $checkpoint->channelVersions;
        $checkpoint->channelVersions[CheckpointConstants::TASKS] = $versions !== []
            ? ChannelVersions::max(...array_values($versions))
            : $this->getNextVersion(null);
    }

    /**
     * The writes recorded against a checkpoint, in the order they were stored.
     *
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private function loadPendingWrites(string $threadId, string $namespace, string $checkpointId): array
    {
        // Defence in depth: every public entry point validates, but this is reachable
        // from the migration path too.
        RedisUtils::assertSafeKeyComponent('thread_id', $threadId);
        RedisUtils::assertSafeKeyComponent('checkpoint_ns', $namespace, allowEmpty: true);
        RedisUtils::assertSafeKeyComponent('checkpoint_id', $checkpointId);

        $docs = [];
        foreach ($this->client->keys("checkpoint_write:{$threadId}:{$namespace}:{$checkpointId}:*") as $writeKey) {
            $raw = $this->client->jsonGet($writeKey);
            if ($raw !== null) {
                $docs[] = $this->decodeDocument($raw);
            }
        }

        // `global_idx` is insertion order across every `putWrites` call.
        usort($docs, static fn (object $a, object $b): int => ($a->global_idx ?? 0) <=> ($b->global_idx ?? 0));

        $pending = [];
        foreach ($docs as $doc) {
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
     * @param  array<string, mixed>|null                   $metadata Already-decoded metadata, if the caller has it.
     */
    private function createCheckpointTuple(
        object $doc,
        Checkpoint $checkpoint,
        array $pendingWrites,
        ?array $metadata = null,
    ): CheckpointTuple {
        $namespace = $this->actualNamespace($doc);
        $parentId = ($doc->parent_checkpoint_id ?? null);

        return new CheckpointTuple(
            config: static::tupleConfig((string) $doc->thread_id, $namespace, (string) $doc->checkpoint_id),
            checkpoint: $checkpoint,
            metadata: $metadata ?? $this->loadMetadata($doc),
            parentConfig: $parentId === null || $parentId === ''
                ? null
                : static::tupleConfig((string) $doc->thread_id, $namespace, (string) $parentId),
            pendingWrites: $pendingWrites,
        );
    }

    /**
     * Checkpoint documents found by scanning keys, for when the search index is missing.
     *
     * With a thread, one namespace (root by default) newest key first. Without one,
     * every thread newest `checkpoint_ts` first.
     *
     * @return list<object>
     */
    private function scanCheckpointDocuments(?string $threadId, mixed $checkpointNs): array
    {
        if ($threadId !== null) {
            $keys = $this->client->keys("checkpoint:{$threadId}:" . ($checkpointNs ?? '') . ':*');
            rsort($keys, SORT_STRING);
        } else {
            $keys = $this->client->keys(
                $checkpointNs !== null
                    ? 'checkpoint:*:' . ($checkpointNs === '' ? self::EMPTY_NS : $checkpointNs) . ':*'
                    : 'checkpoint:*',
            );
        }

        $documents = [];
        foreach ($keys as $key) {
            $raw = $this->client->jsonGet($key);
            if ($raw !== null) {
                $documents[] = $this->decodeDocument($raw);
            }
        }
        if ($threadId === null) {
            usort($documents, static fn (object $a, object $b): int => ($b->checkpoint_ts ?? 0) <=> ($a->checkpoint_ts ?? 0));
        }

        return $documents;
    }
}
