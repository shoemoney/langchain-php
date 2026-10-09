<?php

declare(strict_types=1);

namespace LangGraph\Store\MongoDB;

use LangChain\Embeddings\EmbeddingsInterface;
use LangGraph\Checkpoint\MongoDB\MongoClientInterface;
use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;
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
 * A long-term persistent key-value store backed by MongoDB.
 *
 * Port of `MongoDBStore` from `@langchain/langgraph-checkpoint-mongodb` (`store.ts`), over
 * {@see MongoClientInterface} and {@see MongoStoreCollectionInterface} rather than the driver.
 * There is still no production driver adapter in this package: like {@see \LangGraph\Checkpoint\MongoDB\MongoDBSaver}
 * this class has not been run against a real MongoDB by the unit suite.
 *
 * ## Layout
 *
 * One document per item: `namespace` (the label list), `namespaceStr` (labels joined by `/`,
 * the unique-index key), `key`, `value`, `createdAt`, `updatedAt`, plus `expiresAt` with a TTL,
 * and `namespacePath` (every prefix, `["a", "a/b"]`) plus the embedding when a vector index is
 * configured.
 *
 * ## Known non-exact behaviour
 *
 *  - `fromConnString` and client ownership are not ported: the seam has no connect/close, so
 *    `stop()` has nothing to close. {@see self::setup()} is the whole of upstream's `start()`.
 *  - The seam's `find` has no `skip`, so a search fetches `offset + limit` documents and drops
 *    the first `offset` in PHP. Like upstream, a structured search has no sort order.
 *  - A field filter with several operators (`{$gt: 1, $lt: 5}`) keeps all of them; upstream's
 *    loop overwrote the field on every operator so only the last survived. `$in` / `$nin`,
 *    documented on {@see SearchOperation} but silently ignored upstream, are translated.
 *    Other unknown operators are ignored, as upstream does.
 *  - `ListNamespacesOperation::$maxDepth` drops namespaces deeper than the limit (upstream's
 *    `$size <= maxDepth`) rather than truncating them as {@see \LangGraph\Store\InMemoryStore} does.
 *  - `$vectorSearch` exists only on Atlas; against a plain server a query fails server-side.
 */
class MongoDBStore extends BaseStore
{
    protected readonly MongoDatabaseInterface $db;

    protected readonly bool $enableTimestamps;

    public function __construct(
        protected readonly MongoClientInterface $client,
        ?string $dbName = null,
        protected readonly string $collectionName = 'store',
        bool $enableTimestamps = false,
        protected readonly ?MongoDBTtlConfig $ttl = null,
        protected readonly ?EmbeddingsInterface $embeddings = null,
        protected readonly ?MongoDBIndexConfig $indexConfig = null,
    ) {
        $this->client->appendMetadata(['name' => 'langgraphjs_store']);
        $this->db = $this->client->db($dbName);
        $this->enableTimestamps = $enableTimestamps;
    }

    /**
     * Execute a batch of operations.
     *
     * Operations run in order, but a run of consecutive puts is sent as one bulk write and is
     * de-duplicated by (namespace, key): the last write wins.
     *
     * @param list<Operation> $operations
     */
    public function batch(array $operations): array
    {
        $results = array_fill(0, count($operations), null);

        $i = 0;
        $count = count($operations);
        while ($i < $count) {
            $op = $operations[$i];
            if ($op instanceof PutOperation) {
                $run = [];
                while ($i < $count && $operations[$i] instanceof PutOperation) {
                    $run[] = $operations[$i];
                    $i++;
                }
                $this->batchPuts($run);

                continue;
            }

            $results[$i] = match (true) {
                $op instanceof GetOperation => $this->getOp($op),
                $op instanceof SearchOperation => $this->searchOp($op),
                $op instanceof ListNamespacesOperation => $this->listNamespacesOp($op),
                default => throw new \InvalidArgumentException('Unknown store operation ' . get_debug_type($op) . '.'),
            };
            $i++;
        }

        return $results;
    }

    /**
     * Create the indexes: a unique one on `namespaceStr` + `key`, a TTL one on `expiresAt` when a
     * TTL is configured, and the vector search index when an index config is given.
     *
     * Port of upstream's `start()`. The unique index is on the joined string, not the namespace
     * array, because a multikey index would index each label separately and
     * `["users","alice","prefs"]` and `["users","bob","prefs"]` would collide on a shared key.
     *
     * Atlas builds the vector index asynchronously: this returns once the build is scheduled,
     * not when it is queryable, so a vector search can fail until the index reaches READY.
     * An "already exists" error from the search index is swallowed so setup is idempotent.
     */
    public function setup(): void
    {
        $collection = $this->collection();

        $collection->createIndex(['namespaceStr' => 1, 'key' => 1], ['unique' => true]);

        if ($this->ttl !== null) {
            $collection->createIndex(['expiresAt' => 1], ['expireAfterSeconds' => 0]);
        }

        if ($this->indexConfig !== null) {
            $path = $this->vectorPath();
            if ($this->embeddings !== null) {
                $fields = [[
                    'type' => 'vector',
                    'path' => $path,
                    'numDimensions' => $this->indexConfig->dims,
                    'similarity' => $this->indexConfig->similarityFunction ?? 'cosine',
                ]];
            } else {
                $fields = [[
                    'type' => 'autoEmbed',
                    'path' => $path,
                    'model' => $this->indexConfig->model,
                    'modality' => $this->indexConfig->modality ?? 'text',
                ]];
            }
            $fields[] = ['type' => 'filter', 'path' => 'namespacePath'];
            foreach ($this->indexConfig->filters as $filterField) {
                $fields[] = ['type' => 'filter', 'path' => $filterField];
            }

            try {
                $collection->createSearchIndex([
                    'name' => $this->indexConfig->name,
                    'type' => 'vectorSearch',
                    'definition' => ['fields' => $fields],
                ]);
            } catch (\Throwable $e) {
                if (!str_contains(strtolower($e->getMessage()), 'already exists')) {
                    throw $e;
                }
            }
        }
    }

    /** Initialise the store: runs {@see self::setup()}. Upstream's `start()`. */
    public function start(): void
    {
        $this->setup();
    }

    /** Nothing to release: the caller owns the client. */
    public function stop(): void
    {
    }

    protected function collection(): MongoStoreCollectionInterface
    {
        $collection = $this->db->collection($this->collectionName);
        if (!$collection instanceof MongoStoreCollectionInterface) {
            throw new \LogicException(sprintf(
                'MongoDBStore needs a %s; the database returned %s.',
                MongoStoreCollectionInterface::class,
                get_debug_type($collection),
            ));
        }

        return $collection;
    }

    private function vectorPath(): string
    {
        return $this->indexConfig?->path ?? 'embedding';
    }

    /**
     * @param list<PutOperation> $puts
     */
    private function batchPuts(array $puts): void
    {
        $deduped = [];
        foreach ($puts as $op) {
            $deduped[json_encode(['namespace' => $op->namespace, 'key' => $op->key], JSON_THROW_ON_ERROR)] = $op;
        }
        $ops = array_values($deduped);

        $vectors = $this->embedPuts($ops);

        $bulk = [];
        foreach ($ops as $position => $op) {
            self::validateNamespace($op->namespace);

            if ($op->value === null) {
                $bulk[] = ['deleteOne' => ['filter' => ['namespace' => $op->namespace, 'key' => $op->key]]];

                continue;
            }

            $now = new \DateTimeImmutable();
            $doc = [
                'namespace' => $op->namespace,
                'namespaceStr' => implode('/', $op->namespace),
                'key' => $op->key,
                'value' => $op->value,
                'updatedAt' => $now,
            ];
            if ($this->indexConfig !== null) {
                $doc['namespacePath'] = self::computeNamespacePath($op->namespace);
            }
            if ($this->ttl !== null) {
                $doc['expiresAt'] = $now->modify("+{$this->ttl->defaultTtl} seconds");
            }
            if (isset($vectors[$position])) {
                $doc[$this->vectorPath()] = $vectors[$position];
            }

            $bulk[] = ['updateOne' => [
                'filter' => ['namespace' => $op->namespace, 'key' => $op->key],
                'update' => ['$set' => $doc, '$setOnInsert' => ['createdAt' => $now]] + $this->timestampOp(),
                'upsert' => true,
            ]];
        }

        if ($bulk !== []) {
            $this->collection()->bulkWrite($bulk);
        }
    }

    /**
     * Manual embedding: one `embedDocuments` call for every put that wants a vector. In auto
     * mode MongoDB reads the text from the document, so nothing is computed here.
     *
     * @param  list<PutOperation>  $ops
     * @return array<int, list<float>> Vectors keyed by position in `$ops`.
     */
    private function embedPuts(array $ops): array
    {
        if ($this->indexConfig === null || $this->embeddings === null) {
            return [];
        }

        $texts = [];
        foreach ($ops as $position => $op) {
            if ($op->value === null || $op->index === false) {
                continue;
            }
            if (is_array($op->index)) {
                $fields = [];
                foreach ($op->index as $field) {
                    if (array_key_exists($field, $op->value)) {
                        $fields[$field] = $op->value[$field];
                    }
                }
                $texts[$position] = self::jsonText((object) $fields);
            } elseif ($this->indexConfig->embeddingKey !== null) {
                $texts[$position] = self::jsonText($op->value[$this->indexConfig->embeddingKey] ?? null);
            } else {
                $texts[$position] = self::jsonText($op->value === [] ? (object) [] : $op->value);
            }
        }
        if ($texts === []) {
            return [];
        }

        $positions = array_keys($texts);
        $embedded = $this->embeddings->embedDocuments(array_values($texts));
        $vectors = [];
        foreach ($positions as $n => $position) {
            $vectors[$position] = $embedded[$n];
        }

        return $vectors;
    }

    /** `JSON.stringify` equivalent: no escaped slashes or unicode. */
    private static function jsonText(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function timestampOp(): array
    {
        return $this->enableTimestamps ? ['$currentDate' => ['upserted_at' => true]] : [];
    }

    private function getOp(GetOperation $op): ?Item
    {
        $filter = ['namespace' => $op->namespace, 'key' => $op->key];

        if ($this->ttl?->refreshOnRead) {
            $now = new \DateTimeImmutable();
            $doc = $this->collection()->findOneAndUpdate(
                $filter,
                ['$set' => ['updatedAt' => $now, 'expiresAt' => $now->modify("+{$this->ttl->defaultTtl} seconds")]],
                ['returnDocument' => 'after'],
            );
        } else {
            $doc = $this->collection()->findOne($filter);
        }

        return $doc === null ? null : self::toItem($doc);
    }

    private function listNamespacesOp(ListNamespacesOperation $op): array
    {
        $pipeline = [];

        if ($op->matchConditions !== null && $op->matchConditions !== []) {
            $conditions = [];
            foreach ($op->matchConditions as $condition) {
                $conditions[] = self::matchExpression($condition);
            }
            $pipeline[] = ['$match' => ['$expr' => count($conditions) === 1 ? $conditions[0] : ['$and' => $conditions]]];
        }

        $pipeline[] = ['$group' => ['_id' => '$namespace']];

        if ($op->maxDepth !== null) {
            $pipeline[] = ['$match' => ['$expr' => ['$lte' => [['$size' => '$_id'], $op->maxDepth]]]];
        }

        $pipeline[] = ['$sort' => ['_id' => 1]];

        if ($op->offset > 0) {
            $pipeline[] = ['$skip' => $op->offset];
        }
        $pipeline[] = ['$limit' => $op->limit];

        return array_map(
            static fn (array $doc): array => $doc['_id'],
            $this->collection()->aggregate($pipeline),
        );
    }

    /**
     * Positional element matching via `$arrayElemAt`: wildcards and suffixes cannot be
     * expressed with dot notation. Suffix positions are negative, counting from the end.
     *
     * @return array<string, mixed>
     */
    private static function matchExpression(MatchCondition $condition): array
    {
        $path = $condition->path;
        $elements = [];
        foreach ($path as $i => $label) {
            if ($label === '*') {
                continue;
            }
            $position = $condition->matchType === MatchCondition::SUFFIX ? -(count($path) - $i) : $i;
            $elements[] = ['$eq' => [['$arrayElemAt' => ['$namespace', $position]], $label]];
        }
        $elements[] = ['$gte' => [['$size' => '$namespace'], count($path)]];

        return ['$and' => $elements];
    }

    /**
     * @return list<SearchItem>
     */
    private function searchOp(SearchOperation $op): array
    {
        if ($op->query !== null && $op->query !== '') {
            if ($this->indexConfig === null) {
                throw new \RuntimeException('Vector search (query parameter) requires indexConfig to be configured.');
            }

            return $this->vectorSearch($op->query, $op->namespacePrefix, $op->limit, $op->offset);
        }

        $query = [];
        foreach ($op->namespacePrefix as $idx => $label) {
            $query["namespace.{$idx}"] = $label;
        }
        foreach (self::translateFilter($op->filter ?? []) as $field => $condition) {
            $query[$field] = $condition;
        }

        $docs = $this->collection()->find($query, [], $op->offset + $op->limit);

        return array_map(
            static fn (array $doc): SearchItem => SearchItem::fromItem(self::toItem($doc)),
            array_slice($docs, $op->offset),
        );
    }

    /**
     * Translate a store filter into Mongo query conditions on `value.<field>`.
     *
     * @param  array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private static function translateFilter(array $filter): array
    {
        $query = [];
        foreach ($filter as $field => $condition) {
            $path = "value.{$field}";
            if (!is_array($condition) || array_is_list($condition)) {
                $query[$path] = $condition;

                continue;
            }

            $operators = [];
            foreach ($condition as $operator => $operand) {
                if (in_array($operator, ['$eq', '$ne', '$gt', '$gte', '$lt', '$lte', '$in', '$nin'], true)) {
                    $operators[$operator] = $operand;
                }
            }
            if (array_keys($operators) === ['$eq']) {
                $query[$path] = $operators['$eq'];
            } elseif ($operators !== []) {
                $query[$path] = $operators;
            }
        }

        return $query;
    }

    /**
     * Similarity search with `$vectorSearch`.
     *
     * Manual mode embeds the query client-side and sends `queryVector`; auto mode sends
     * `query.text` and MongoDB embeds it. The namespace prefix is filtered on `namespacePath`
     * because `$vectorSearch` supports neither `$expr` nor `$slice`.
     *
     * @param  list<string>     $namespacePrefix
     * @return list<SearchItem>
     */
    private function vectorSearch(string $query, array $namespacePrefix, int $limit, int $offset): array
    {
        $path = $this->vectorPath();
        $stage = [
            'index' => $this->indexConfig?->name,
            'path' => $path,
            // 10-20x the limit for good recall, capped at 10000.
            'numCandidates' => min(($limit + $offset) * 20, 10000),
            'limit' => $limit + $offset,
        ];

        if ($this->embeddings !== null) {
            $stage['queryVector'] = $this->embeddings->embedQuery($query);
        } else {
            $stage['query'] = ['text' => $query];
        }

        if ($namespacePrefix !== []) {
            $stage['filter'] = ['namespacePath' => implode('/', $namespacePrefix)];
        }

        $pipeline = [
            ['$vectorSearch' => $stage],
            ['$addFields' => ['score' => ['$meta' => 'vectorSearchScore']]],
        ];
        if ($this->embeddings !== null) {
            $pipeline[] = ['$project' => [$path => 0]];
        }
        if ($offset > 0) {
            $pipeline[] = ['$skip' => $offset];
        }
        $pipeline[] = ['$limit' => $limit];

        return array_map(
            static fn (array $doc): SearchItem => SearchItem::fromItem(
                self::toItem($doc),
                isset($doc['score']) ? (float) $doc['score'] : null,
            ),
            $this->collection()->aggregate($pipeline),
        );
    }

    /**
     * `["a","b","c"]` becomes `["a","a/b","a/b/c"]`, so a prefix is an array-equality filter.
     *
     * @param  list<string> $namespace
     * @return list<string>
     */
    private static function computeNamespacePath(array $namespace): array
    {
        $paths = [];
        for ($i = 1; $i <= count($namespace); $i++) {
            $paths[] = implode('/', array_slice($namespace, 0, $i));
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private static function toItem(array $doc): Item
    {
        return new Item(
            $doc['value'],
            $doc['key'],
            $doc['namespace'],
            self::toDate($doc['createdAt'] ?? null),
            self::toDate($doc['updatedAt'] ?? null),
        );
    }

    private static function toDate(mixed $value): \DateTimeImmutable
    {
        return match (true) {
            $value instanceof \DateTimeImmutable => $value,
            $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value),
            default => new \DateTimeImmutable('@0'),
        };
    }

    /**
     * Upstream validates in the store as well as in `BaseStore::put`, because `batch()` is public.
     *
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
                    "Invalid namespace label '%s' found in %s. Namespace labels must be strings, but got %s.",
                    is_scalar($label) ? (string) $label : get_debug_type($label),
                    $shown,
                    get_debug_type($label),
                ));
            }
            if (str_contains($label, '.')) {
                throw new InvalidNamespaceError(sprintf(
                    "Invalid namespace label '%s' found in %s. Namespace labels cannot contain periods ('.').",
                    $label,
                    $shown,
                ));
            }
            if ($label === '') {
                throw new InvalidNamespaceError(sprintf('Namespace labels cannot be empty strings. Got %s in %s', $label, $shown));
            }
        }
        if ($namespace[0] === 'langgraph') {
            throw new InvalidNamespaceError(sprintf('Root label for namespace cannot be "langgraph". Got: %s', $shown));
        }
    }
}
