<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Indexing;

use LangChain\VectorStores\MemoryVector;
use LangChain\VectorStores\MemoryVectorStore;

/**
 * A memory store that honours the `ids` option and supports `delete(['ids' => ...])`,
 * which `index()` needs and the (faithfully minimal) upstream memory store lacks.
 */
final class IdTrackingMemoryVectorStore extends MemoryVectorStore
{
    /** @var list<list<string>> ids passed on each addDocuments call */
    public array $addCalls = [];

    public function addDocuments(array $documents, array $options = []): ?array
    {
        $this->addCalls[] = $options['ids'] ?? [];

        return parent::addDocuments($documents, $options);
    }

    public function addVectors(array $vectors, array $documents, array $options = []): ?array
    {
        $ids = $options['ids'] ?? [];
        foreach ($vectors as $i => $embedding) {
            $this->memoryVectors[] = new MemoryVector(
                $documents[$i]->pageContent,
                $embedding,
                $documents[$i]->metadata,
                $ids[$i] ?? null,
            );
        }

        return $ids === [] ? null : $ids;
    }

    public function delete(array $params = []): void
    {
        $ids = $params['ids'] ?? [];
        $this->memoryVectors = array_values(array_filter(
            $this->memoryVectors,
            static fn (MemoryVector $v): bool => !in_array($v->id, $ids, true),
        ));
    }

    /** @return list<string> page contents currently stored, sorted */
    public function contents(): array
    {
        $contents = array_map(static fn (MemoryVector $v): string => $v->content, $this->memoryVectors);
        sort($contents);

        return $contents;
    }
}
