<?php

declare(strict_types=1);

namespace LangChain\VectorStores;

use LangChain\Schema\Document;
use LangChain\Tracers\CallbackManager;

/**
 * The contract every vector store honours.
 *
 * Port of `VectorStoreInterface` from `@langchain/core/vectorstores`.
 *
 * Upstream's optional `maxMarginalRelevanceSearch?()` has no PHP interface
 * equivalent; a store that supports MMR simply defines the method (see
 * {@see MemoryVectorStore}) and {@see VectorStoreRetriever} probes for it.
 * The `FilterType` is whatever the store defines, so `$filter` is `mixed`.
 */
interface VectorStoreInterface
{
    public function vectorstoreType(): string;

    /**
     * @param list<list<float|int>> $vectors
     * @param list<Document>        $documents
     * @param array<string, mixed>  $options
     *
     * @return list<string>|null
     */
    public function addVectors(array $vectors, array $documents, array $options = []): ?array;

    /**
     * @param list<Document>       $documents
     * @param array<string, mixed> $options
     *
     * @return list<string>|null
     */
    public function addDocuments(array $documents, array $options = []): ?array;

    /**
     * @param array<string, mixed> $params
     */
    public function delete(array $params = []): void;

    /**
     * @param list<float|int> $query
     *
     * @return list<array{0: Document, 1: float}>
     */
    public function similaritySearchVectorWithScore(array $query, int $k, mixed $filter = null): array;

    /**
     * @param array<array-key, mixed>|CallbackManager|null $callbacks
     *
     * @return list<Document>
     */
    public function similaritySearch(string $query, int $k = 4, mixed $filter = null, array|CallbackManager|null $callbacks = null): array;

    /**
     * @param array<array-key, mixed>|CallbackManager|null $callbacks
     *
     * @return list<array{0: Document, 1: float}>
     */
    public function similaritySearchWithScore(string $query, int $k = 4, mixed $filter = null, array|CallbackManager|null $callbacks = null): array;

    /**
     * @param int|array<string, mixed>|null                $kOrFields
     * @param array<array-key, mixed>|CallbackManager|null $callbacks
     * @param list<string>|null                            $tags
     * @param array<string, mixed>|null                    $metadata
     */
    public function asRetriever(
        int|array|null $kOrFields = null,
        mixed $filter = null,
        array|CallbackManager|null $callbacks = null,
        ?array $tags = null,
        ?array $metadata = null,
        ?bool $verbose = null,
    ): VectorStoreRetriever;
}
