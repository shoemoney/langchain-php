<?php

declare(strict_types=1);

namespace LangChain\Embeddings;

/**
 * Anything that can turn text into vectors.
 *
 * Port of `EmbeddingsInterface` from `@langchain/core/embeddings`.
 */
interface EmbeddingsInterface
{
    /**
     * Embed a list of documents.
     *
     * @param  list<string>       $documents
     * @return list<list<float>>  One vector per document, in order.
     */
    public function embedDocuments(array $documents): array;

    /**
     * Embed a single query.
     *
     * @return list<float>
     */
    public function embedQuery(string $document): array;
}
