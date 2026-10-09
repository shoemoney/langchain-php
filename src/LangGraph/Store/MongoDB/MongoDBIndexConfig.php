<?php

declare(strict_types=1);

namespace LangGraph\Store\MongoDB;

/**
 * Configuration for the store's MongoDB vector search index.
 *
 * Port of `IndexConfig` from `checkpoint-mongodb/src/store.ts`. Two modes, chosen by whether
 * the store is given an `Embeddings` object:
 *
 *  - **Manual embedding** (store has embeddings): the store computes vectors and writes them
 *    to `path` (default `embedding`). Needs `dims`; `similarityFunction` defaults to cosine.
 *  - **Auto embedding** (no embeddings): MongoDB embeds server-side with Voyage AI. Needs
 *    `model` and `path` (the source text field, for example `value.content`).
 */
final class MongoDBIndexConfig
{
    /**
     * @param string                                              $name               Vector search index name.
     * @param int|null                                            $dims               Embedding dimensionality (manual mode).
     * @param 'cosine'|'euclidean'|'dotProduct'|null              $similarityFunction Defaults to cosine.
     * @param string|null                                         $embeddingKey       Sub-field of the value to embed; null embeds the whole value.
     * @param string|null                                         $path               Vector field (manual) or source text field (auto).
     * @param string|null                                         $model              Voyage AI model (auto mode).
     * @param string|null                                         $modality           Auto-embedding modality, default `text`.
     * @param list<string>                                        $filters            Extra filter fields declared in the index.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?int $dims = null,
        public readonly ?string $similarityFunction = null,
        public readonly ?string $embeddingKey = null,
        public readonly ?string $path = null,
        public readonly ?string $model = null,
        public readonly ?string $modality = null,
        public readonly array $filters = [],
    ) {
    }
}
