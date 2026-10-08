<?php

declare(strict_types=1);

namespace LangChain\VectorStores;

/**
 * One row of a {@see MemoryVectorStore}: text, its embedding, and its metadata.
 *
 * Port of the `MemoryVector` interface in `langchain/vectorstores/memory`.
 */
final class MemoryVector
{
    /**
     * @param list<float|int>      $embedding
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $content,
        public readonly array $embedding,
        public readonly array $metadata,
        public readonly ?string $id = null,
    ) {
    }
}
