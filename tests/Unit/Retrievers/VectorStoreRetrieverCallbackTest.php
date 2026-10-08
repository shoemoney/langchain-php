<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Retrievers;

use LangChain\Schema\Document;
use LangChain\Tests\Unit\VectorStores\FakeEmbeddings;
use LangChain\Tracers\CallbackHandler;
use LangChain\VectorStores\MemoryVectorStore;
use LangChain\VectorStores\VectorStoreRetriever;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langchain-classic/src/retrievers/tests/vectorstores.test.ts`.
 */
#[CoversClass(VectorStoreRetriever::class)]
final class VectorStoreRetrieverCallbackTest extends TestCase
{
    public function testMemoryRetrieverWithCallback(): void
    {
        $pageContent = 'Hello world';
        $vectorStore = new MemoryVectorStore(new FakeEmbeddings());
        $vectorStore->addDocuments(array_map(
            static fn (): Document => new Document($pageContent, ['a' => 1]),
            range(1, 4),
        ));

        $queryStr = 'testing testing';
        $startRun = 0;
        $endRun = 0;

        $retriever = $vectorStore->asRetriever([
            'k' => 1,
            'vectorStore' => $vectorStore,
            'callbacks' => [new CallbackHandler([
                'handleRetrieverStart' => function ($retriever, string $query) use ($queryStr, &$startRun): void {
                    $this->assertSame($queryStr, $query);
                    ++$startRun;
                },
                'handleRetrieverEnd' => function (array $documents) use ($pageContent, &$endRun): void {
                    $this->assertSame($pageContent, $documents[0]->pageContent);
                    ++$endRun;
                },
            ])],
        ]);

        $results = $retriever->invoke($queryStr);

        $this->assertEquals([new Document($pageContent, ['a' => 1])], $results);
        $this->assertSame(1, $startRun);
        $this->assertSame(1, $endRun);
    }
}
