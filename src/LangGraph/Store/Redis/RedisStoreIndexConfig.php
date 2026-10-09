<?php

declare(strict_types=1);

namespace LangGraph\Store\Redis;

use LangChain\Embeddings\EmbeddingsInterface;

/**
 * How a {@see RedisStore} embeds and indexes documents for vector search.
 *
 * Port of the `IndexConfig` interface in `@langchain/langgraph-checkpoint-redis`'s `store.ts`.
 * It is not {@see \LangGraph\Store\IndexConfig}: Redis picks a distance metric and keeps a
 * similarity threshold, and its `embed` is optional.
 */
final class RedisStoreIndexConfig
{
    public const COSINE = 'cosine';

    public const L2 = 'l2';

    public const INNER_PRODUCT = 'ip';

    /**
     * @param int                 $dims                Number of dimensions in each embedding.
     * @param EmbeddingsInterface $embeddings          Produces the vectors; without it only the index is created.
     * @param string              $distanceType        `cosine` (default), `l2` (Euclidean) or `ip` (inner product).
     * @param list<string>|null   $fields              Top-level value fields to embed; null embeds `text`.
     * @param string|null         $vectorStorageType   Carried for parity with upstream, which never reads it.
     * @param float|null          $similarityThreshold Results scoring below this (in `[0, 1]`) are dropped.
     */
    public function __construct(
        public readonly int $dims,
        public readonly ?EmbeddingsInterface $embeddings = null,
        public readonly string $distanceType = self::COSINE,
        public readonly ?array $fields = null,
        public readonly ?string $vectorStorageType = null,
        public readonly ?float $similarityThreshold = null,
    ) {
    }
}
