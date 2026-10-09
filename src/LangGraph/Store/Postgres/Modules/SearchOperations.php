<?php

declare(strict_types=1);

namespace LangGraph\Store\Postgres\Modules;

use LangGraph\Store\SearchItem;
use LangGraph\Store\SearchOperation;

/**
 * Every kind of search: metadata, text, vector and hybrid.
 *
 * Port of `store/modules/search-operations.ts`. SQL uses positional `?`
 * placeholders; each method appends to `$params` in the order the placeholders
 * appear, repeating a value where upstream reuses a `$n`.
 */
final class SearchOperations
{
    public function __construct(
        private readonly DatabaseCore $core,
        private readonly VectorOperations $vectorOps,
    ) {
    }

    /**
     * Run one {@see SearchOperation} from a batch.
     *
     * @return list<SearchItem>
     */
    public function executeSearch(SearchOperation $operation): array
    {
        Utils::validateNamespace($operation->namespacePrefix);

        $query = $operation->query;
        $hasQuery = $query !== null && $query !== '';

        if ($hasQuery && $this->core->indexConfig !== null) {
            return $this->executeVectorSearch($operation);
        }

        if ($hasQuery) {
            return $this->textSearch($operation->namespacePrefix, [
                'query' => $query,
                'filter' => $operation->filter,
                'limit' => $operation->limit,
                'offset' => $operation->offset,
            ]);
        }

        $params = [implode(':', $operation->namespacePrefix) . '%'];
        $sql = 'SELECT namespace_path, key, value, created_at, updated_at FROM ' . $this->core->storeTable()
            . ' WHERE namespace_path LIKE ? AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)';

        if ($operation->filter !== null && $operation->filter !== []) {
            $conditions = QueryBuilder::buildFilterConditions($operation->filter, $params);
            if ($conditions !== []) {
                $sql .= ' AND (' . implode(' AND ', $conditions) . ')';
            }
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
        array_push($params, $operation->limit, $operation->offset);

        return $this->toItems($this->core->query($sql, $params)->fetchAll());
    }

    /**
     * The cosine-similarity search a batched {@see SearchOperation} with a query uses.
     *
     * @return list<SearchItem>
     */
    public function executeVectorSearch(SearchOperation $operation): array
    {
        if ($this->core->indexConfig === null || $operation->query === null || $operation->query === '') {
            return [];
        }

        Utils::validateNamespace($operation->namespacePrefix);

        $queryVector = $this->queryVector($operation->query);

        $params = [$queryVector, implode(':', $operation->namespacePrefix) . '%'];
        $sql = 'SELECT DISTINCT s.namespace_path, s.key, s.value, s.created_at, s.updated_at,'
            . ' MIN(v.embedding <=> ?::vector) AS similarity_score'
            . ' FROM ' . $this->core->storeTable() . ' s'
            . ' JOIN ' . $this->core->vectorsTable() . ' v ON s.namespace_path = v.namespace_path AND s.key = v.key'
            . ' WHERE s.namespace_path LIKE ? AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)';

        $sql .= $this->filterClause($operation->filter, $params);
        $sql .= ' GROUP BY s.namespace_path, s.key, s.value, s.created_at, s.updated_at'
            . ' ORDER BY similarity_score ASC LIMIT ? OFFSET ?';
        array_push($params, $operation->limit, $operation->offset);

        return $this->toItems($this->core->query($sql, $params)->fetchAll(), 'similarity_score', static fn (float $d): float => 1 - $d);
    }

    /**
     * Full-text and metadata search over the `store` table.
     *
     * @param list<string>                                                                                 $namespacePrefix
     * @param array{filter?: array<string, mixed>|null, limit?: int, offset?: int, query?: string|null, refreshTtl?: bool} $options
     * @return list<SearchItem>
     */
    public function textSearch(array $namespacePrefix, array $options = []): array
    {
        Utils::validateNamespace($namespacePrefix);

        $filter = $options['filter'] ?? null;
        $limit = $options['limit'] ?? 10;
        $offset = $options['offset'] ?? 0;
        $query = $options['query'] ?? null;
        $hasQuery = $query !== null && $query !== '';
        $language = $this->core->textSearchLanguage;

        $params = [
            $hasQuery ? $query : null,
            $language,
            $language,
            $hasQuery ? $query : null,
            implode(':', $namespacePrefix) . '%',
        ];
        $sql = 'SELECT namespace_path, key, value, created_at, updated_at,'
            . ' CASE WHEN ?::text IS NOT NULL THEN'
            . ' ts_rank(to_tsvector(?::regconfig, value::text), plainto_tsquery(?::regconfig, ?::text))'
            . ' ELSE 0 END AS score'
            . ' FROM ' . $this->core->storeTable()
            . ' WHERE namespace_path LIKE ? AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)';

        if ($filter !== null && $filter !== []) {
            $conditions = QueryBuilder::buildFilterConditions($filter, $params);
            if ($conditions !== []) {
                $sql .= ' AND (' . implode(' AND ', $conditions) . ')';
            }
        }

        if ($hasQuery) {
            $sql .= ' AND (to_tsvector(?::regconfig, value::text) @@ plainto_tsquery(?::regconfig, ?::text) OR value::text ILIKE ?)';
            array_push($params, $language, $language, $query, '%' . $query . '%');
            $sql .= ' ORDER BY score DESC, updated_at DESC';
        } else {
            $sql .= ' ORDER BY updated_at DESC';
        }

        $sql .= ' LIMIT ? OFFSET ?';
        array_push($params, $limit, $offset);

        $items = $this->toItems($this->core->query($sql, $params)->fetchAll(), 'score');

        if (($options['refreshTtl'] ?? false) || $this->core->ttlConfig?->refreshOnRead) {
            foreach ($items as $item) {
                $this->core->refreshTtl(implode(':', $item->namespace), $item->key);
            }
        }

        return $items;
    }

    /**
     * Rank by vector similarity under the chosen distance metric.
     *
     * @param list<string>                                                                                                  $namespacePrefix
     * @param array{filter?: array<string, mixed>|null, limit?: int, offset?: int, similarityThreshold?: float|null, distanceMetric?: string|null} $options
     * @return list<SearchItem>
     */
    public function vectorSearch(array $namespacePrefix, string $query, array $options = []): array
    {
        if ($this->core->indexConfig === null) {
            throw new \RuntimeException('Vector search not configured. Please provide an IndexConfig when creating the store.');
        }

        Utils::validateNamespace($namespacePrefix);

        $limit = $options['limit'] ?? 10;
        $offset = $options['offset'] ?? 0;
        $threshold = $options['similarityThreshold'] ?? 0.0;
        $metric = $options['distanceMetric'] ?? 'cosine';

        $queryVector = $this->queryVector($query);

        [$distanceOp, $scoreTransform] = match ($metric) {
            'l2' => ['<->', '1 / (1 + MIN(v.embedding <-> ?::vector))'],
            // pgvector's `<#>` is the NEGATIVE inner product; upstream sorts it descending, which
            // returns the least similar item first. The score is negated so higher is better.
            'inner_product' => ['<#>', '-MIN(v.embedding <#> ?::vector)'],
            default => ['<=>', '1 - MIN(v.embedding <=> ?::vector)'],
        };

        $params = [$queryVector, implode(':', $namespacePrefix) . '%'];
        $sql = "SELECT DISTINCT s.namespace_path, s.key, s.value, s.created_at, s.updated_at, {$scoreTransform} AS similarity_score"
            . ' FROM ' . $this->core->storeTable() . ' s'
            . ' JOIN ' . $this->core->vectorsTable() . ' v ON s.namespace_path = v.namespace_path AND s.key = v.key'
            . ' WHERE s.namespace_path LIKE ? AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)';

        if ($threshold > 0) {
            if ($metric === 'inner_product') {
                $sql .= ' AND -(v.embedding <#> ?::vector) >= ?::float8';
            } else {
                $sql .= " AND v.embedding {$distanceOp} ?::vector <= ?::float8";
            }
            array_push($params, $queryVector, $metric === 'cosine' ? 1 - $threshold : $threshold);
        }

        $sql .= $this->filterClause($options['filter'] ?? null, $params);
        $sql .= ' GROUP BY s.namespace_path, s.key, s.value, s.created_at, s.updated_at'
            . ' ORDER BY similarity_score DESC LIMIT ? OFFSET ?';
        array_push($params, $limit, $offset);

        return $this->toItems($this->core->query($sql, $params)->fetchAll(), 'similarity_score');
    }

    /**
     * Blend cosine similarity with full-text rank: `vectorWeight * cosine + (1 - vectorWeight) * ts_rank`.
     *
     * @param list<string>                                                                                          $namespacePrefix
     * @param array{filter?: array<string, mixed>|null, limit?: int, offset?: int, vectorWeight?: float|null, similarityThreshold?: float|null} $options
     * @return list<SearchItem>
     */
    public function hybridSearch(array $namespacePrefix, string $query, array $options = []): array
    {
        if ($this->core->indexConfig === null) {
            throw new \RuntimeException('Vector search not configured. Please provide an IndexConfig when creating the store.');
        }

        Utils::validateNamespace($namespacePrefix);

        $limit = $options['limit'] ?? 10;
        $offset = $options['offset'] ?? 0;
        $vectorWeight = (float) ($options['vectorWeight'] ?? 0.7);
        $threshold = (float) ($options['similarityThreshold'] ?? 0.0);
        $language = $this->core->textSearchLanguage;

        $queryVector = $this->queryVector($query);

        $params = [
            $vectorWeight, $queryVector, $vectorWeight, $language, $language, $query,
            implode(':', $namespacePrefix) . '%',
            $language, $language, $query, $queryVector, 1 - $threshold,
        ];
        $sql = 'SELECT DISTINCT s.namespace_path, s.key, s.value, s.created_at, s.updated_at,'
            . ' (?::float8 * (1 - MIN(v.embedding <=> ?::vector)) + (1 - ?::float8)'
            . ' * ts_rank(to_tsvector(?::regconfig, s.value::text), plainto_tsquery(?::regconfig, ?::text))) AS hybrid_score'
            . ' FROM ' . $this->core->storeTable() . ' s'
            . ' JOIN ' . $this->core->vectorsTable() . ' v ON s.namespace_path = v.namespace_path AND s.key = v.key'
            . ' WHERE s.namespace_path LIKE ? AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)'
            . ' AND (to_tsvector(?::regconfig, s.value::text) @@ plainto_tsquery(?::regconfig, ?::text)'
            . ' OR v.embedding <=> ?::vector <= ?::float8)';

        $sql .= $this->filterClause($options['filter'] ?? null, $params);
        $sql .= ' GROUP BY s.namespace_path, s.key, s.value, s.created_at, s.updated_at'
            . ' ORDER BY hybrid_score DESC LIMIT ? OFFSET ?';
        array_push($params, $limit, $offset);

        return $this->toItems($this->core->query($sql, $params)->fetchAll(), 'hybrid_score');
    }

    /** Embed a query and check it against the configured dimensions; returns a pgvector literal. */
    private function queryVector(string $query): string
    {
        $embedding = $this->vectorOps->generateQueryEmbedding($query);
        $dims = $this->core->indexConfig?->dims;

        if (count($embedding) !== $dims) {
            throw new \RuntimeException(sprintf('Query embedding dimension mismatch: expected %s, got %d', (string) $dims, count($embedding)));
        }

        return VectorOperations::toVectorLiteral($embedding);
    }

    /**
     * @param  array<string, mixed>|null $filter
     * @param  list<mixed>               $params Appended to.
     */
    private function filterClause(?array $filter, array &$params): string
    {
        if ($filter === null || $filter === []) {
            return '';
        }
        $conditions = QueryBuilder::buildFilterConditions($filter, $params);
        if ($conditions === []) {
            return '';
        }

        return ' AND (' . str_replace('value ->', 's.value ->', implode(' AND ', $conditions)) . ')';
    }

    /**
     * @param  list<array<string, mixed>>   $rows
     * @param  (callable(float): float)|null $transform
     * @return list<SearchItem>
     */
    private function toItems(array $rows, ?string $scoreColumn = null, ?callable $transform = null): array
    {
        $items = [];
        foreach ($rows as $row) {
            $score = null;
            if ($scoreColumn !== null && isset($row[$scoreColumn])) {
                $score = (float) $row[$scoreColumn];
                if ($transform !== null) {
                    $score = $transform($score);
                }
                // Upstream's text search reports a 0 rank as "no score".
                if ($scoreColumn === 'score' && $score == 0.0) {
                    $score = null;
                }
            }
            $items[] = new SearchItem(
                CrudOperations::decode($row['value']),
                $row['key'],
                explode(':', $row['namespace_path']),
                DatabaseCore::toDate($row['created_at']),
                DatabaseCore::toDate($row['updated_at']),
                $score,
            );
        }

        return $items;
    }
}
