<?php

declare(strict_types=1);

namespace LangGraph\Store\Postgres;

use LangGraph\Checkpoint\Postgres\PostgresSaver;
use LangGraph\Store\BaseStore;
use LangGraph\Store\GetOperation;
use LangGraph\Store\Item;
use LangGraph\Store\ListNamespacesOperation;
use LangGraph\Store\MatchCondition;
use LangGraph\Store\PutOperation;
use LangGraph\Store\SearchItem;
use LangGraph\Store\SearchOperation;
use LangGraph\Store\Postgres\Modules\CrudOperations;
use LangGraph\Store\Postgres\Modules\DatabaseCore;
use LangGraph\Store\Postgres\Modules\IndexConfig;
use LangGraph\Store\Postgres\Modules\SearchOperations;
use LangGraph\Store\Postgres\Modules\TtlConfig;
use LangGraph\Store\Postgres\Modules\TtlManager;
use LangGraph\Store\Postgres\Modules\VectorOperations;

/**
 * A durable {@see BaseStore} backed by Postgres, over PDO.
 *
 * Port of `PostgresStore` from `@langchain/langgraph-checkpoint-postgres`'s
 * `store/index.ts`: a thin orchestrator delegating to the modules in
 * {@see \LangGraph\Store\Postgres\Modules}.
 *
 * ## Tables
 *
 * `store` holds `(namespace_path, key, value JSONB, created_at, updated_at,
 * expires_at)`; the namespace is stored as its labels joined with `:`.
 * `store_vectors` (only with an index config) holds one pgvector embedding per
 * indexed field. `store_migrations` records which migrations ran.
 *
 * ## Search
 *
 * {@see self::search()} picks a mode: with no query it filters metadata; with a
 * query it uses vector search when an {@see IndexConfig} is set and Postgres
 * full-text search otherwise. `mode` may force `text`, `vector` or `hybrid`.
 * Without an index config the store never touches pgvector and works on a server
 * that does not have it.
 *
 * ## Differences from upstream
 *
 * Methods are synchronous (no promises) and the connection pool is one PDO
 * connection. A batch runs its operations sequentially, as upstream does. The
 * background sweep timer becomes a lazy sweep, see {@see TtlManager}. A PDO
 * connection is opened eagerly, so an unreachable server or a malformed DSN
 * fails in {@see self::fromConnString()} rather than in `setup()`.
 */
class PostgresStore extends BaseStore
{
    private readonly DatabaseCore $core;

    private readonly CrudOperations $crudOps;

    private readonly SearchOperations $searchOps;

    private readonly TtlManager $ttlManager;

    private readonly bool $ensureTables;

    private bool $isSetup = false;

    private bool $isClosed = false;

    /**
     * @param array{schema?: string, ensureTables?: bool, ttl?: TtlConfig, index?: IndexConfig, textSearchLanguage?: string} $options
     */
    public function __construct(\PDO $db, array $options = [])
    {
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->core = new DatabaseCore(
            $db,
            $options['schema'] ?? 'public',
            $options['ttl'] ?? null,
            $options['index'] ?? null,
            $options['textSearchLanguage'] ?? null,
        );

        $vectorOps = new VectorOperations($this->core);
        $this->crudOps = new CrudOperations($this->core, $vectorOps);
        $this->searchOps = new SearchOperations($this->core, $vectorOps);
        $this->ttlManager = new TtlManager($this->core);
        $this->ensureTables = $options['ensureTables'] ?? true;
    }

    /**
     * Open a connection from a `postgresql://` URL, a `pgsql:` PDO DSN, or a
     * libpq `key=value` string.
     *
     * @param array{schema?: string, ensureTables?: bool, ttl?: TtlConfig, index?: IndexConfig, textSearchLanguage?: string} $options
     */
    public static function fromConnString(string $connString, array $options = []): self
    {
        [$dsn, $user, $password] = PostgresSaver::parseConnString($connString);

        return new self(new \PDO($dsn, $user, $password), $options);
    }

    /** The PDO connection, e.g. for inspecting the tables in tests. */
    public function db(): \PDO
    {
        return $this->core->pdo;
    }

    /**
     * Create the schema and run outstanding migrations. Safe to call twice.
     */
    public function setup(): void
    {
        if ($this->isSetup) {
            return;
        }

        $this->runStoreMigrations();
        $this->isSetup = true;

        if ($this->core->ttlConfig?->sweepIntervalMinutes) {
            $this->ttlManager->start();
        }
    }

    /**
     * Store or update an item.
     *
     * @param list<string>                $namespace
     * @param array<string, mixed>        $value
     * @param false|list<string>|null     $index
     * @param array{ttl?: int|float}|null $options  `ttl` is the item's time to live in minutes.
     */
    public function put(array $namespace, string $key, array $value, false|array|null $index = null, ?array $options = null): void
    {
        $this->ensureSetup();
        $this->crudOps->executePut(new PutOperation($namespace, $key, $value, $index), $options);
    }

    /**
     * @param list<string> $namespace
     */
    public function get(array $namespace, string $key): ?Item
    {
        $this->ensureSetup();

        return $this->crudOps->executeGet(new GetOperation($namespace, $key));
    }

    /**
     * @param list<string> $namespace
     */
    public function delete(array $namespace, string $key): void
    {
        $this->ensureSetup();
        $this->crudOps->executePut(new PutOperation($namespace, $key, null));
    }

    /**
     * @param array{prefix?: list<string>|null, suffix?: list<string>|null, maxDepth?: int|null, limit?: int, offset?: int} $options
     * @return list<list<string>>
     */
    public function listNamespaces(array $options = []): array
    {
        $this->ensureSetup();

        $matchConditions = [];
        if (isset($options['prefix'])) {
            $matchConditions[] = new MatchCondition(MatchCondition::PREFIX, $options['prefix']);
        }
        if (isset($options['suffix'])) {
            $matchConditions[] = new MatchCondition(MatchCondition::SUFFIX, $options['suffix']);
        }

        return $this->executeListNamespaces(new ListNamespacesOperation(
            $matchConditions,
            $options['maxDepth'] ?? null,
            $options['limit'] ?? 100,
            $options['offset'] ?? 0,
        ));
    }

    /**
     * Execute several operations in order.
     *
     * @param  list<\LangGraph\Store\Operation> $operations
     * @return list<mixed>
     */
    public function batch(array $operations): array
    {
        $this->ensureSetup();
        $this->ttlManager->tick();

        $results = [];
        foreach ($operations as $operation) {
            if ($operation instanceof SearchOperation) {
                $results[] = $this->searchOps->executeSearch($operation);
            } elseif ($operation instanceof GetOperation) {
                $results[] = $this->crudOps->executeGet($operation);
            } elseif ($operation instanceof PutOperation) {
                $this->crudOps->executePut($operation);
                $results[] = null;
            } elseif ($operation instanceof ListNamespacesOperation) {
                $results[] = $this->executeListNamespaces($operation);
            } else {
                throw new \InvalidArgumentException('Unsupported operation type: ' . get_debug_type($operation));
            }
        }

        return $results;
    }

    /** Run setup() if tables are managed by the store. */
    public function start(): void
    {
        if ($this->ensureTables && !$this->isSetup) {
            $this->setup();
        }
    }

    /** Stop the sweep schedule. The PDO connection closes when the store is released. */
    public function stop(): void
    {
        if ($this->isClosed) {
            return;
        }
        $this->ttlManager->stop();
        $this->isClosed = true;
    }

    /**
     * Delete every expired item.
     *
     * @return int The number of items removed.
     */
    public function sweepExpiredItems(): int
    {
        $this->ensureSetup();

        return $this->ttlManager->sweepExpiredItems();
    }

    /**
     * Counts and timestamps for the whole store.
     *
     * @return array{totalItems: int, expiredItems: int, namespaceCount: int, oldestItem: ?\DateTimeImmutable, newestItem: ?\DateTimeImmutable}
     */
    public function stats(): array
    {
        $this->ensureSetup();

        $row = $this->core->query(
            'SELECT COUNT(*) AS total_items,'
            . ' COUNT(CASE WHEN expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP THEN 1 END) AS expired_items,'
            . ' COUNT(DISTINCT namespace_path) AS namespace_count,'
            . ' MIN(created_at) AS oldest_item, MAX(created_at) AS newest_item'
            . ' FROM ' . $this->core->storeTable(),
        )->fetch();

        return [
            'totalItems' => (int) $row['total_items'],
            'expiredItems' => (int) $row['expired_items'],
            'namespaceCount' => (int) $row['namespace_count'],
            'oldestItem' => $row['oldest_item'] === null ? null : DatabaseCore::toDate($row['oldest_item']),
            'newestItem' => $row['newest_item'] === null ? null : DatabaseCore::toDate($row['newest_item']),
        ];
    }

    /**
     * Upstream's name for {@see self::stats()}.
     *
     * @return array{totalItems: int, expiredItems: int, namespaceCount: int, oldestItem: ?\DateTimeImmutable, newestItem: ?\DateTimeImmutable}
     */
    public function getStats(): array
    {
        return $this->stats();
    }

    /**
     * Search with metadata filters, full-text, vector or hybrid ranking.
     *
     * Beyond {@see BaseStore::search()}'s options: `refreshTtl`, `mode`
     * (`text`, `vector`, `hybrid`, `auto`; default `auto`), `similarityThreshold`,
     * `distanceMetric` (`cosine`, `l2`, `inner_product`) and `vectorWeight`
     * (hybrid only, default 0.7).
     *
     * @param  list<string> $namespacePrefix
     * @param  array{filter?: array<string, mixed>|null, query?: string|null, limit?: int, offset?: int, refreshTtl?: bool, mode?: string, similarityThreshold?: float|null, distanceMetric?: string|null, vectorWeight?: float|null} $options
     * @return list<SearchItem>
     */
    public function search(array $namespacePrefix, array $options = []): array
    {
        $this->ensureSetup();
        $this->ttlManager->tick();

        $mode = $options['mode'] ?? 'auto';
        $query = $options['query'] ?? null;
        $common = array_filter([
            'filter' => $options['filter'] ?? null,
            'limit' => $options['limit'] ?? null,
            'offset' => $options['offset'] ?? null,
            'refreshTtl' => $options['refreshTtl'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        if ($query === null || $query === '') {
            return $this->searchOps->textSearch($namespacePrefix, $common);
        }

        $hasVectorSearch = $this->core->indexConfig !== null;
        $effectiveMode = $mode === 'auto' ? ($hasVectorSearch ? 'vector' : 'text') : $mode;

        switch ($effectiveMode) {
            case 'vector':
                if (!$hasVectorSearch) {
                    throw new \RuntimeException('Vector search requested but not configured. Please provide an IndexConfig when creating the store.');
                }

                return $this->searchOps->vectorSearch($namespacePrefix, $query, $common + [
                    'similarityThreshold' => $options['similarityThreshold'] ?? null,
                    'distanceMetric' => $options['distanceMetric'] ?? null,
                ]);
            case 'hybrid':
                if (!$hasVectorSearch) {
                    throw new \RuntimeException('Hybrid search requested but vector search not configured. Please provide an IndexConfig when creating the store.');
                }

                return $this->searchOps->hybridSearch($namespacePrefix, $query, $common + [
                    'vectorWeight' => $options['vectorWeight'] ?? null,
                    'similarityThreshold' => $options['similarityThreshold'] ?? null,
                ]);
            case 'text':
                return $this->searchOps->textSearch($namespacePrefix, ['query' => $query] + $common);
            default:
                throw new \RuntimeException("Unknown search mode: {$mode}");
        }
    }

    private function ensureSetup(): void
    {
        if (!$this->isSetup && $this->ensureTables) {
            $this->setup();
        }
    }

    private function runStoreMigrations(): void
    {
        $db = $this->core->pdo;
        $schema = $this->core->schema;
        $tables = Sql::getStoreTablesWithSchema($schema);

        $db->exec('CREATE SCHEMA IF NOT EXISTS ' . Sql::quoteIdentifier($schema));

        $version = -1;
        $migrations = StoreMigrations::getStoreMigrations($schema, $this->core->indexConfig);

        try {
            $row = $db->query("SELECT v FROM {$tables['store_migrations']} ORDER BY v DESC LIMIT 1")?->fetch();
            if ($row !== false && $row !== null) {
                $version = (int) $row['v'];
            }
        } catch (\PDOException $e) {
            // 42P01 is undefined_table: nothing has been migrated yet.
            if ($e->getCode() !== '42P01') {
                throw $e;
            }
        }

        $record = $db->prepare("INSERT INTO {$tables['store_migrations']} (v) VALUES (?)");
        for ($v = $version + 1, $count = count($migrations); $v < $count; $v++) {
            $db->exec($migrations[$v]);
            $record->execute([$v]);
        }
    }

    /**
     * @return list<list<string>>
     */
    private function executeListNamespaces(ListNamespacesOperation $operation): array
    {
        $sql = 'SELECT DISTINCT namespace_path FROM ' . $this->core->storeTable();
        $params = [];
        $conditions = [];

        foreach ($operation->matchConditions ?? [] as $condition) {
            if ($condition->matchType === MatchCondition::PREFIX) {
                $conditions[] = 'namespace_path LIKE ?';
                $params[] = implode(':', $condition->path) . '%';
            } elseif ($condition->matchType === MatchCondition::SUFFIX) {
                $conditions[] = 'namespace_path LIKE ?';
                $params[] = '%' . implode(':', $condition->path);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY namespace_path LIMIT ? OFFSET ?';
        array_push($params, $operation->limit, $operation->offset);

        $namespaces = array_map(
            static fn (array $row): array => explode(':', $row['namespace_path']),
            $this->core->query($sql, $params)->fetchAll(),
        );

        if ($operation->maxDepth !== null) {
            $maxDepth = $operation->maxDepth;
            $namespaces = array_values(array_filter($namespaces, static fn (array $ns): bool => count($ns) <= $maxDepth));
        }

        return $namespaces;
    }
}
