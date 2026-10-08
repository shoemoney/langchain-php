<?php

declare(strict_types=1);

namespace LangChain\Embeddings;

/**
 * Base class for embedding models.
 *
 * Port of the abstract `Embeddings` class from `@langchain/core/embeddings`.
 * Upstream also builds an `AsyncCaller` here for retry and concurrency control;
 * that utility is not part of this port, so subclasses own their own retries.
 */
abstract class Embeddings implements EmbeddingsInterface
{
    abstract public function embedDocuments(array $documents): array;

    abstract public function embedQuery(string $document): array;
}
