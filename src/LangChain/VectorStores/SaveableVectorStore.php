<?php

declare(strict_types=1);

namespace LangChain\VectorStores;

use LangChain\Embeddings\EmbeddingsInterface;

/**
 * A vector store that can persist itself to a directory.
 *
 * Port of `SaveableVectorStore` from `@langchain/core/vectorstores`.
 */
abstract class SaveableVectorStore extends VectorStore
{
    abstract public function save(string $directory): void;

    public static function load(string $directory, EmbeddingsInterface $embeddings): static
    {
        throw new \RuntimeException('Not implemented');
    }
}
