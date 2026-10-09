<?php

declare(strict_types=1);

namespace LangGraph\Store\Postgres\Modules;

use LangChain\Embeddings\EmbeddingsInterface;

/**
 * Vector indexing configuration for {@see \LangGraph\Store\Postgres\PostgresStore}.
 *
 * Port of `IndexConfig` (plus `VectorIndexType`, `DistanceMetric`, `HNSWConfig`
 * and `IVFFlatConfig`) from `store/modules/types.ts`.
 */
final class IndexConfig
{
    /**
     * @param int                                              $dims                   Number of dimensions in the embedding vectors.
     * @param EmbeddingsInterface|callable(list<string>): list<list<float>> $embed       The embeddings model, or a function embedding a list of texts.
     * @param list<string>|null                                $fields                 Field paths to embed; null embeds the whole document (`"$"`).
     * @param 'hnsw'|'ivfflat'                                 $indexType              Vector index type.
     * @param 'cosine'|'l2'|'inner_product'                    $distanceMetric         Metric the vector index is built for.
     * @param bool                                             $createAllMetricIndexes Build an index for every metric.
     * @param array{m?: int, efConstruction?: int, ef?: int}|null $hnsw
     * @param array{lists?: int, probes?: int}|null            $ivfflat
     */
    public function __construct(
        public readonly int $dims,
        public readonly mixed $embed,
        public readonly ?array $fields = null,
        public readonly string $indexType = 'hnsw',
        public readonly string $distanceMetric = 'cosine',
        public readonly bool $createAllMetricIndexes = false,
        public readonly ?array $hnsw = null,
        public readonly ?array $ivfflat = null,
    ) {
    }
}
