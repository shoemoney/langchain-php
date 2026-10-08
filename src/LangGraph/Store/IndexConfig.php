<?php

declare(strict_types=1);

namespace LangGraph\Store;

use LangChain\Embeddings\EmbeddingsInterface;

/**
 * How a store embeds and indexes documents for semantic search.
 *
 * Port of `IndexConfig` from `store/base.ts`.
 */
final class IndexConfig
{
    /**
     * @param int                 $dims       Number of dimensions in the embedding vectors.
     * @param EmbeddingsInterface $embeddings The embeddings model that produces the vectors.
     * @param list<string>|null   $fields     Field paths to embed (`"metadata.title"`, `"chapters[*].content"`,
     *                                        `"array[-1]"`); null embeds the whole document as one vector (`"$"`).
     */
    public function __construct(
        public readonly int $dims,
        public readonly EmbeddingsInterface $embeddings,
        public readonly ?array $fields = null,
    ) {
    }
}
