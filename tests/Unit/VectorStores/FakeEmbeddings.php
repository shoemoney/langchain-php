<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\VectorStores;

use LangChain\Embeddings\Embeddings;

/**
 * Test double ported from `FakeEmbeddings` in `@langchain/core/utils/testing`: fixed vectors.
 */
final class FakeEmbeddings extends Embeddings
{
    public function embedDocuments(array $documents): array
    {
        return array_map(static fn (): array => [0.1, 0.2, 0.3, 0.4], $documents);
    }

    public function embedQuery(string $document): array
    {
        return [0.1, 0.2, 0.3, 0.4];
    }
}
