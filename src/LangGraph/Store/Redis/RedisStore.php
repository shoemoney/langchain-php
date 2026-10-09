<?php

declare(strict_types=1);

namespace LangGraph\Store\Redis;

use LangChain\Embeddings\EmbeddingsInterface;
use LangChain\Utils\Uuid;
use LangGraph\Checkpoint\Redis\PhpRedisClient;
use LangGraph\Checkpoint\Redis\RedisClientException;
use LangGraph\Checkpoint\Redis\RedisClientInterface;
use LangGraph\Checkpoint\Redis\RedisUtils;
use LangGraph\Checkpoint\Redis\TtlConfig;
use LangGraph\Store\BaseStore;
use LangGraph\Store\GetOperation;
use LangGraph\Store\InvalidNamespaceError;
use LangGraph\Store\Item;
use LangGraph\Store\ListNamespacesOperation;
use LangGraph\Store\MatchCondition;
use LangGraph\Store\Operation;
use LangGraph\Store\PutOperation;
use LangGraph\Store\SearchItem;
use LangGraph\Store\SearchOperation;

/**
 * A {@see BaseStore} on Redis: JSON documents, RediSearch lookups and optional vector search.
 *
 * Port of `RedisStore` from `@langchain/langgraph-checkpoint-redis`'s `store.ts`. It needs a
 * server with RedisJSON and RediSearch (Redis Stack, or Redis 8) and talks to it through
 * {@see RedisClientInterface}, the same seam the Redis checkpoint savers use.
 *
 * Layout, as upstream:
 *  - `store:<uuid>` holds `{prefix, key, value, created_at, updated_at}`, where `prefix` is the
 *    namespace joined with `.` and the timestamps are nanoseconds since the epoch;
 *  - `store_vectors:<same uuid>` holds `{prefix, key, field_name, embedding, ...}` and exists
 *    only when an index is configured and the item has embeddable text;
 *  - the `store` index covers prefix (TEXT), key (TAG) and the timestamps (NUMERIC); the
 *    `store_vectors` index adds a `VECTOR FLAT FLOAT32` field with the configured distance metric.
 *
 * Retained from upstream, on purpose: namespace prefix matching is a TEXT match on every label
 * token (so `["docs"]` also finds `["x", "docs"]`), a vector search narrows on the FIRST label
 * only, filters run after the page is fetched (a filtered page can be short), only the last
 * embedded field of an item survives (one vector document per item), `refreshOnRead` is stored
 * but never read (an explicit `refreshTtl` drives refreshing), and `listNamespaces` lists at most
 * the first 1000 documents. Changed: an exact namespace and key are checked on the hits of
 * `get`/`put`, so `put(["a"], "k")` can no longer delete `["a", "b"] / "k"`.
 *
 * Not ported: `fromCluster` (Redis Cluster through `createCluster`); PhpRedis cluster is out of
 * scope for this port.
 */
final class RedisStore extends BaseStore
{
    private const SEPARATOR = ':';

    private const STORE_PREFIX = 'store';

    private const VECTOR_PREFIX = 'store_vectors';

    /** @var int how many hits an exact `get`/`put` lookup inspects */
    private const LOOKUP_SIZE = 100;

    /** @var int how many documents `listNamespaces` reads before de-duplicating */
    private const NAMESPACE_SCAN = 1000;

    private static int $lastNanos = 0;

    public function __construct(
        private readonly RedisClientInterface $client,
        private readonly ?RedisStoreIndexConfig $index = null,
        private readonly ?TtlConfig $ttl = null,
    ) {
    }

    /**
     * Connect with ext-redis and create the indexes.
     *
     * Port of `fromConnString`.
     */
    public static function fromUrl(string $url, ?RedisStoreIndexConfig $index = null, ?TtlConfig $ttl = null): self
    {
        $store = new self(PhpRedisClient::fromUrl($url), $index, $ttl);
        $store->setup();

        return $store;
    }

    /** Upstream's name for {@see self::fromUrl()}. */
    public static function fromConnString(string $connString, ?RedisStoreIndexConfig $index = null, ?TtlConfig $ttl = null): self
    {
        return self::fromUrl($connString, $index, $ttl);
    }

    public static function isPutOperation(Operation $op): bool
    {
        return $op instanceof PutOperation;
    }

    public static function isGetOperation(Operation $op): bool
    {
        return $op instanceof GetOperation;
    }

    public static function isSearchOperation(Operation $op): bool
    {
        return $op instanceof SearchOperation;
    }

    public static function isListNamespacesOperation(Operation $op): bool
    {
        return $op instanceof ListNamespacesOperation;
    }

    /**
     * Create the `store` index and, when an index config is set, the `store_vectors` index.
     *
     * An index that already exists is fine; any other failure is logged and swallowed, as upstream.
     */
    public function setup(): void
    {
        $schemas = [[
            'index' => self::STORE_PREFIX,
            'prefix' => self::STORE_PREFIX . self::SEPARATOR,
            'schema' => [
                '$.prefix' => ['type' => 'TEXT', 'as' => 'prefix'],
                '$.key' => ['type' => 'TAG', 'as' => 'key'],
                '$.created_at' => ['type' => 'NUMERIC', 'as' => 'created_at'],
                '$.updated_at' => ['type' => 'NUMERIC', 'as' => 'updated_at'],
            ],
        ]];

        if ($this->index !== null) {
            $schemas[] = [
                'index' => self::VECTOR_PREFIX,
                'prefix' => self::VECTOR_PREFIX . self::SEPARATOR,
                'schema' => [
                    '$.prefix' => ['type' => 'TEXT', 'as' => 'prefix'],
                    '$.key' => ['type' => 'TAG', 'as' => 'key'],
                    '$.field_name' => ['type' => 'TAG', 'as' => 'field_name'],
                    '$.created_at' => ['type' => 'NUMERIC', 'as' => 'created_at'],
                    '$.updated_at' => ['type' => 'NUMERIC', 'as' => 'updated_at'],
                    '$.embedding' => [
                        'type' => 'VECTOR',
                        'as' => 'embedding',
                        'algorithm' => 'FLAT',
                        'attributes' => [
                            'TYPE' => 'FLOAT32',
                            'DIM' => $this->index->dims,
                            'DISTANCE_METRIC' => self::distanceMetric($this->index->distanceType),
                        ],
                    ],
                ],
            ];
        }

        RedisUtils::ensureIndexes($this->client, $schemas);
    }

    /**
     * Retrieve an item, optionally re-arming its TTL.
     *
     * @param list<string> $namespace
     */
    public function get(array $namespace, string $key, bool $refreshTtl = false): ?Item
    {
        try {
            $hit = $this->findExact(implode('.', $namespace), $key);
        } catch (RedisClientException $e) {
            if (self::isMissingIndex($e)) {
                return null;
            }

            throw $e;
        }
        if ($hit === null) {
            return null;
        }
        if ($refreshTtl) {
            $this->refreshItemTtl($hit['id']);
        }

        return self::itemFromDocument($hit['doc']);
    }

    /**
     * Store or update an item.
     *
     * @param list<string>            $namespace
     * @param array<string, mixed>    $value
     * @param false|list<string>|null $index     Fields to embed; `false` skips embedding; null uses the config's fields.
     * @param float|int|null          $ttl       Minutes to live, overriding the configured default.
     *
     * @throws InvalidNamespaceError
     */
    public function put(array $namespace, string $key, array $value, false|array|null $index = null, float|int|null $ttl = null): void
    {
        $this->write($namespace, $key, $value, $index, $ttl);
    }

    /**
     * Delete an item.
     *
     * @param list<string> $namespace
     *
     * @throws InvalidNamespaceError
     */
    public function delete(array $namespace, string $key): void
    {
        $this->write($namespace, $key, null, null, null);
    }

    /**
     * Search under a namespace prefix, by filter and/or by vector similarity to a query.
     *
     * Besides the {@see BaseStore::search()} options: `refreshTtl` re-arms the TTL of every hit,
     * and `similarityThreshold` overrides the configured threshold for this query.
     *
     * @param  list<string>                                                                                                                                         $namespacePrefix
     * @param  array{filter?: array<string, mixed>|null, limit?: int, offset?: int, query?: string|null, refreshTtl?: bool, similarityThreshold?: float|null} $options
     * @return list<SearchItem>
     */
    public function search(array $namespacePrefix, array $options = []): array
    {
        $prefix = implode('.', $namespacePrefix);
        $limit = ($options['limit'] ?? 0) ?: 10;
        $offset = $options['offset'] ?? 0;
        $filter = $options['filter'] ?? null;
        $refresh = $options['refreshTtl'] ?? false;
        $query = $options['query'] ?? null;

        $embeddings = $this->index?->embeddings;
        if ($query !== null && $query !== '' && $embeddings !== null) {
            return $this->vectorSearch($embeddings, $prefix, $query, $limit, $offset, $filter, $refresh, $options['similarityThreshold'] ?? null);
        }

        $queryString = '*';
        $tokens = FilterBuilder::prefixTokens($prefix);
        if ($prefix !== '' && $tokens !== []) {
            // Match every token of the prefix, so the right namespaces come back.
            $queryString = '@prefix:(' . implode(' ', $tokens) . ')';
        }

        try {
            $hits = $this->client->ftSearch(self::STORE_PREFIX, $queryString, $offset, $limit, 'created_at', true);
        } catch (RedisClientException $e) {
            if (self::isMissingIndex($e)) {
                return [];
            }

            throw $e;
        }

        $items = [];
        foreach ($hits as $hit) {
            $doc = self::decode($hit['value']);
            if ($filter !== null && !FilterBuilder::matchesFilter(self::valueOf($doc), $filter)) {
                continue;
            }
            if ($refresh) {
                $this->refreshItemTtl($hit['id']);
            }
            $items[] = SearchItem::fromItem(self::itemFromDocument($doc));
        }

        return $items;
    }

    /**
     * List the distinct namespaces, filtered by literal prefix and suffix, truncated to a depth and paged.
     *
     * Unlike {@see BaseStore::listNamespaces()} nothing is paged unless `limit` or `offset` is given,
     * and `*` is not a wildcard in a prefix or suffix: upstream's Redis store compares labels literally.
     *
     * @param  array{prefix?: list<string>|null, suffix?: list<string>|null, maxDepth?: int|null, limit?: int|null, offset?: int|null} $options
     * @return list<list<string>>
     */
    public function listNamespaces(array $options = []): array
    {
        try {
            $hits = $this->client->ftSearch(self::STORE_PREFIX, '*', 0, self::NAMESPACE_SCAN, '', false, ['prefix']);
        } catch (RedisClientException $e) {
            if (self::isMissingIndex($e)) {
                return [];
            }

            throw $e;
        }

        $wantedPrefix = $options['prefix'] ?? null;
        $wantedSuffix = $options['suffix'] ?? null;
        $maxDepth = $options['maxDepth'] ?? null;
        $found = [];
        foreach ($hits as $hit) {
            $prefix = (string) (self::decode($hit['value'])['prefix'] ?? '');
            $parts = explode('.', $prefix);

            if ($wantedPrefix !== null) {
                if (count($parts) < count($wantedPrefix) || array_slice($parts, 0, count($wantedPrefix)) !== $wantedPrefix) {
                    continue;
                }
            }
            if ($wantedSuffix !== null) {
                if (count($parts) < count($wantedSuffix) || array_slice($parts, count($parts) - count($wantedSuffix)) !== $wantedSuffix) {
                    continue;
                }
            }

            $found[$maxDepth ? implode('.', array_slice($parts, 0, $maxDepth)) : $prefix] = true;
        }

        $joined = array_map('strval', array_keys($found));
        usort($joined, static fn (string $a, string $b): int => strcasecmp($a, $b) ?: strcmp($b, $a));
        $namespaces = array_map(static fn (string $ns): array => explode('.', $ns), $joined);

        $offset = (int) ($options['offset'] ?? 0);
        $limit = (int) ($options['limit'] ?? 0);
        if ($offset !== 0 || $limit !== 0) {
            $namespaces = array_slice($namespaces, $offset, $limit ?: 10);
        }

        return $namespaces;
    }

    /**
     * Run the operations in order, so a later one sees the effect of an earlier one.
     *
     * @param  list<Operation> $operations
     * @return list<mixed>
     */
    public function batch(array $operations): array
    {
        $results = [];
        foreach ($operations as $op) {
            if ($op instanceof PutOperation) {
                $this->write($op->namespace, $op->key, $op->value, $op->index, null);
                $results[] = null;
            } elseif ($op instanceof SearchOperation) {
                $results[] = $this->search($op->namespacePrefix, [
                    'filter' => $op->filter,
                    'query' => $op->query,
                    'limit' => $op->limit,
                    'offset' => $op->offset,
                ]);
            } elseif ($op instanceof ListNamespacesOperation) {
                $prefix = null;
                $suffix = null;
                foreach ($op->matchConditions ?? [] as $condition) {
                    if ($condition->matchType === MatchCondition::PREFIX) {
                        $prefix = $condition->path;
                    } elseif ($condition->matchType === MatchCondition::SUFFIX) {
                        $suffix = $condition->path;
                    }
                }
                $results[] = $this->listNamespaces([
                    'prefix' => $prefix,
                    'suffix' => $suffix,
                    'maxDepth' => $op->maxDepth,
                    'limit' => $op->limit,
                    'offset' => $op->offset,
                ]);
            } elseif ($op instanceof GetOperation) {
                $results[] = $this->get($op->namespace, $op->key);
            } else {
                throw new \InvalidArgumentException('Unknown operation type: ' . get_debug_type($op));
            }
        }

        return $results;
    }

    /** Close the connection. */
    public function close(): void
    {
        $this->client->quit();
    }

    /**
     * Document counts and index details.
     *
     * `vectorDocuments` and `indexInfo` are present only when an index config is set. Counts come from
     * `FT.INFO`'s `num_docs`, where upstream reads the total of a zero-row `FT.SEARCH`.
     *
     * @return array{totalDocuments: int, namespaceCount: int, vectorDocuments?: int, indexInfo?: array<string, mixed>}
     */
    public function getStatistics(): array
    {
        $stats = ['totalDocuments' => 0, 'namespaceCount' => 0];

        try {
            $info = $this->client->ftInfo(self::STORE_PREFIX);
            $stats['totalDocuments'] = (int) ($info['num_docs'] ?? 0);
            $stats['namespaceCount'] = count($this->listNamespaces(['limit' => 1000]));

            if ($this->index !== null) {
                try {
                    $stats['vectorDocuments'] = (int) ($this->client->ftInfo(self::VECTOR_PREFIX)['num_docs'] ?? 0);
                } catch (RedisClientException) {
                    // The vector index might not exist.
                    $stats['vectorDocuments'] = 0;
                }
                $stats['indexInfo'] = $info;
            }
        } catch (RedisClientException $e) {
            if (!self::isMissingIndex($e)) {
                throw $e;
            }
        }

        return $stats;
    }

    /**
     * Alias of {@see self::getStatistics()}.
     *
     * @return array{totalDocuments: int, namespaceCount: int, vectorDocuments?: int, indexInfo?: array<string, mixed>}
     */
    public function stats(): array
    {
        return $this->getStatistics();
    }

    /**
     * The shared body of put and delete (a null value deletes).
     *
     * @param list<string>             $namespace
     * @param array<string, mixed>|null $value
     * @param false|list<string>|null  $index
     */
    private function write(array $namespace, string $key, ?array $value, false|array|null $index, float|int|null $ttl): void
    {
        self::validateNamespace($namespace);
        $prefix = implode('.', $namespace);
        $docId = Uuid::v4();
        $now = self::nowNanos();
        $createdAt = $now;

        // Replace any existing item, keeping its creation time.
        try {
            $existing = $this->findExact($prefix, $key, all: true);
            foreach ($existing as $n => $hit) {
                if ($n === 0 && isset($hit['doc']['created_at']) && is_int($hit['doc']['created_at'])) {
                    $createdAt = $hit['doc']['created_at'];
                }
                $this->client->del([$hit['id']]);
                if ($this->index !== null) {
                    $this->client->del([self::vectorKeyFor($hit['id'])]);
                }
            }
        } catch (RedisClientException) {
            // The index might not exist yet.
        }

        if ($value === null) {
            return;
        }

        $storeKey = self::STORE_PREFIX . self::SEPARATOR . $docId;
        $this->client->jsonSet($storeKey, '$', self::encode([
            'prefix' => $prefix,
            'key' => $key,
            'value' => $value === [] ? new \stdClass() : $value,
            'created_at' => $createdAt,
            'updated_at' => $now,
        ]));

        $ttlSeconds = self::ttlSeconds($ttl) ?: ($this->ttl?->seconds() ?? 0);

        $embeddings = $this->index?->embeddings;
        if ($embeddings !== null && $index !== false) {
            $fields = $index ?? $this->index?->fields ?? ['text'];
            $texts = [];
            $names = [];
            foreach ($fields as $field) {
                if (is_string($value[$field] ?? null) && $value[$field] !== '') {
                    $texts[] = $value[$field];
                    $names[] = $field;
                }
            }

            if ($texts !== []) {
                $vectors = $embeddings->embedDocuments($texts);
                foreach ($vectors as $i => $embedding) {
                    $vectorKey = self::VECTOR_PREFIX . self::SEPARATOR . $docId;
                    $this->client->jsonSet($vectorKey, '$', self::encode([
                        'prefix' => $prefix,
                        'key' => $key,
                        'field_name' => $names[$i],
                        'embedding' => array_map('floatval', $embedding),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
                    if ($ttlSeconds > 0) {
                        $this->client->expire($vectorKey, $ttlSeconds);
                    }
                }
            }
        }

        if ($ttlSeconds > 0) {
            $this->client->expire($storeKey, $ttlSeconds);
        }
    }

    /**
     * @param  array<string, mixed>|null $filter
     * @return list<SearchItem>
     */
    private function vectorSearch(EmbeddingsInterface $embeddings, string $prefix, string $query, int $limit, int $offset, ?array $filter, bool $refresh, ?float $threshold): array
    {
        [$embedding] = $embeddings->embedDocuments([$query]);

        // Narrow on the first token of the prefix, wildcarded, as upstream does.
        $first = FilterBuilder::prefixTokens($prefix)[0] ?? null;
        $scope = $prefix !== '' && $first !== null ? "@prefix:{$first}*" : '*';

        try {
            $hits = $this->client->ftSearch(
                self::VECTOR_PREFIX,
                "({$scope})=>[KNN {$limit} @embedding \$BLOB]",
                $offset,
                $limit,
                '',
                false,
                ['prefix', 'key', '__embedding_score'],
                ['BLOB' => pack('g*', ...array_map('floatval', $embedding))],
                2,
            );
        } catch (RedisClientException $e) {
            if (self::isMissingIndex($e)) {
                return [];
            }

            throw $e;
        }

        $threshold ??= $this->index?->similarityThreshold;
        $items = [];
        foreach ($hits as $hit) {
            $storeKey = self::STORE_PREFIX . self::SEPARATOR . self::uuidOf($hit['id']);
            $json = $this->client->jsonGet($storeKey);
            if ($json === null) {
                continue;
            }
            $storeDoc = self::decode($json);

            if ($filter !== null && !FilterBuilder::matchesFilter(self::valueOf($storeDoc), $filter)) {
                continue;
            }
            if ($refresh) {
                $this->refreshItemTtl($storeKey);
                $this->refreshItemTtl($hit['id']);
            }

            $distance = self::decode($hit['value'])['__embedding_score'] ?? null;
            $score = is_string($distance) && $distance !== '' ? $this->similarityScore((float) $distance) : 0.0;
            if ($threshold !== null && $score < $threshold) {
                continue;
            }

            $items[] = SearchItem::fromItem(self::itemFromDocument($storeDoc), $score);
        }

        return $items;
    }

    /**
     * The stored document for exactly this namespace and key.
     *
     * RediSearch matches TAG values case-insensitively and a TEXT prefix by token, so the hits are
     * compared exactly here. An empty key cannot be a TAG query, so it is found by prefix alone.
     *
     * @return ($all is true ? list<array{id: string, doc: array<string, mixed>}> : array{id: string, doc: array<string, mixed>}|null)
     */
    private function findExact(string $prefix, string $key, bool $all = false): array|null
    {
        $tokens = FilterBuilder::prefixTokens($prefix);
        $prefixQuery = $tokens !== [] ? '@prefix:(' . implode(' ', $tokens) . ')' : '*';
        $query = $key === ''
            ? $prefixQuery
            : "({$prefixQuery}) (@key:{" . RedisUtils::escapeRediSearchTagValue($key) . '})';

        $matches = [];
        foreach ($this->client->ftSearch(self::STORE_PREFIX, $query, 0, self::LOOKUP_SIZE, '', false) as $hit) {
            $doc = self::decode($hit['value']);
            if (($doc['prefix'] ?? null) === $prefix && ($doc['key'] ?? null) === $key) {
                $matches[] = ['id' => $hit['id'], 'doc' => $doc];
            }
        }

        return $all ? $matches : ($matches[0] ?? null);
    }

    private function refreshItemTtl(string $docId): void
    {
        if ($this->ttl === null || !$this->ttl->enabled()) {
            return;
        }
        $seconds = $this->ttl->seconds();
        $this->client->expire($docId, $seconds);

        try {
            $this->client->expire(self::vectorKeyFor($docId), $seconds);
        } catch (RedisClientException) {
            // The vector key might not exist.
        }
    }

    /**
     * Turn a raw distance into a similarity in `[0, 1]`.
     *
     * cosine: `max(0, 1 - d/2)`; l2: `e^-d`; ip: `1 / (1 + e^-d)`.
     */
    private function similarityScore(float $distance): float
    {
        return match ($this->index?->distanceType) {
            RedisStoreIndexConfig::L2 => exp(-$distance),
            RedisStoreIndexConfig::INNER_PRODUCT => 1 / (1 + exp(-$distance)),
            default => max(0.0, 1 - $distance / 2),
        };
    }

    private static function distanceMetric(string $distanceType): string
    {
        return match ($distanceType) {
            RedisStoreIndexConfig::L2 => 'L2',
            RedisStoreIndexConfig::INNER_PRODUCT => 'IP',
            default => 'COSINE',
        };
    }

    /** Whether a search failed because the index does not exist (Redis 8 says `Index not found`). */
    private static function isMissingIndex(RedisClientException $e): bool
    {
        return RedisUtils::isMissingIndex($e) || str_contains(strtolower($e->getMessage()), 'index not found');
    }

    private static function ttlSeconds(float|int|null $minutes): int
    {
        return $minutes === null || $minutes <= 0 ? 0 : (int) floor($minutes * 60);
    }

    private static function vectorKeyFor(string $storeKey): string
    {
        return self::VECTOR_PREFIX . self::SEPARATOR . self::uuidOf($storeKey);
    }

    private static function uuidOf(string $key): string
    {
        $at = strrpos($key, self::SEPARATOR);

        return $at === false ? $key : substr($key, $at + 1);
    }

    /**
     * Nanoseconds since the epoch, strictly increasing within the process.
     *
     * Upstream adds `performance.now() * 1000` to `Date.now() * 1e6`, a figure that drifts with
     * process uptime; this keeps microsecond resolution without the drift.
     */
    private static function nowNanos(): int
    {
        $nanos = (int) (microtime(true) * 1_000_000) * 1000;
        if ($nanos <= self::$lastNanos) {
            $nanos = self::$lastNanos + 1000;
        }

        return self::$lastNanos = $nanos;
    }

    /** @param array<string, mixed> $doc */
    private static function encode(array $doc): string
    {
        return json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        $doc = json_decode($json, true);

        return is_array($doc) ? $doc : [];
    }

    /**
     * @param  array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private static function valueOf(array $doc): array
    {
        return is_array($doc['value'] ?? null) ? $doc['value'] : [];
    }

    /** @param array<string, mixed> $doc */
    private static function itemFromDocument(array $doc): Item
    {
        return new Item(
            self::valueOf($doc),
            (string) ($doc['key'] ?? ''),
            explode('.', (string) ($doc['prefix'] ?? '')),
            self::fromNanos((int) ($doc['created_at'] ?? 0)),
            self::fromNanos((int) ($doc['updated_at'] ?? 0)),
        );
    }

    private static function fromNanos(int $nanos): \DateTimeImmutable
    {
        $seconds = intdiv($nanos, 1_000_000_000);
        $micros = intdiv($nanos % 1_000_000_000, 1000);

        return new \DateTimeImmutable(sprintf('@%d.%06d', $seconds, $micros));
    }

    /**
     * @param list<mixed> $namespace
     *
     * @throws InvalidNamespaceError
     */
    private static function validateNamespace(array $namespace): void
    {
        if ($namespace === []) {
            throw new InvalidNamespaceError('Namespace cannot be empty.');
        }
        $shown = implode(',', array_map(static fn (mixed $l): string => is_scalar($l) ? (string) $l : get_debug_type($l), $namespace));
        foreach ($namespace as $label) {
            if (!is_string($label)) {
                throw new InvalidNamespaceError(sprintf(
                    "Invalid namespace label '%s' found in %s. Namespace labels must be strings.",
                    is_scalar($label) ? (string) $label : get_debug_type($label),
                    $shown,
                ));
            }
            if (str_contains($label, '.')) {
                throw new InvalidNamespaceError("Invalid namespace label '{$label}' found in {$shown}. Namespace labels cannot contain periods ('.').");
            }
            if ($label === '') {
                throw new InvalidNamespaceError("Namespace labels cannot be empty strings. Got {$label} in {$shown}");
            }
        }
        if ($namespace[0] === 'langgraph') {
            throw new InvalidNamespaceError("Root label for namespace cannot be \"langgraph\". Got: {$shown}");
        }
    }
}
