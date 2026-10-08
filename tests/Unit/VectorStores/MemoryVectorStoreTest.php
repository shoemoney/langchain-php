<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\VectorStores;

use LangChain\Embeddings\Embeddings;
use LangChain\Schema\Document;
use LangChain\Utils\MathUtils;
use LangChain\VectorStores\MemoryVector;
use LangChain\VectorStores\MemoryVectorStore;
use LangChain\VectorStores\SaveableVectorStore;
use LangChain\VectorStores\VectorStore;
use LangChain\VectorStores\VectorStoreRetriever;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langchain-classic/src/vectorstores/tests/memory.test.ts`, plus the
 * base-class behaviour (`VectorStore`, `asRetriever`) it rides on.
 */
#[CoversClass(MemoryVectorStore::class)]
#[CoversClass(MemoryVector::class)]
#[CoversClass(VectorStore::class)]
#[CoversClass(SaveableVectorStore::class)]
#[CoversClass(VectorStoreRetriever::class)]
final class MemoryVectorStoreTest extends TestCase
{
    /** @return list<Document> */
    private static function fourDocs(): array
    {
        return [
            new Document('hello', ['a' => 1]),
            new Document('hi', ['a' => 1]),
            new Document('bye', ['a' => 1]),
            new Document("what's this", ['a' => 1]),
        ];
    }

    public function testSimilaritySearchReturnsTheClosestDocument(): void
    {
        $store = new MemoryVectorStore(new SyntheticEmbeddings(1536));
        $store->addDocuments(self::fourDocs());

        $results = $store->similaritySearch('hello', 1);

        $this->assertCount(1, $results);
        $this->assertEquals([new Document('hello', ['a' => 1])], $results);
    }

    public function testRetrieverFiltersByMetadata(): void
    {
        $store = new MemoryVectorStore(new SyntheticEmbeddings(1536));
        $retriever = $store->asRetriever([
            'k' => 2,
            'filter' => static fn (Document $doc): bool => $doc->metadata['namespace'] <= 2,
        ]);

        $retriever->addDocuments([
            new Document('hello', ['namespace' => 1]),
            new Document('hello', ['namespace' => 2]),
            new Document('hello', ['namespace' => 3]),
            new Document('hello', ['namespace' => 4]),
        ]);

        $results = $retriever->invoke('hello');

        $this->assertCount(2, $results);
        $this->assertEquals([
            new Document('hello', ['namespace' => 1]),
            new Document('hello', ['namespace' => 2]),
        ], $results);
    }

    public function testCustomSimilarityIsCalledOncePerStoredVector(): void
    {
        $calls = 0;
        $store = new MemoryVectorStore(new SyntheticEmbeddings(1536), [
            'similarity' => static function (array $a, array $b) use (&$calls): float {
                ++$calls;

                return MathUtils::cosineSimilarity([$a], [$b])[0][0];
            },
        ]);
        $store->addDocuments(self::fourDocs());

        $results = $store->similaritySearch('hello', 3);

        $this->assertSame(4, $calls);
        $this->assertCount(3, $results);
    }

    public function testMaxMarginalRelevanceSearch(): void
    {
        $calls = 0;
        $store = new MemoryVectorStore(new SyntheticEmbeddings(1536), [
            'similarity' => static function (array $a, array $b) use (&$calls): float {
                ++$calls;

                return MathUtils::cosineSimilarity([$a], [$b])[0][0];
            },
        ]);
        $store->addDocuments(self::fourDocs());

        $results = $store->maxMarginalRelevanceSearch('hello', ['k' => 3]);

        $this->assertSame(4, $calls);
        $this->assertCount(3, $results);
    }

    public function testResultsAreSortedByDescendingSimilarityForEveryInsertionOrder(): void
    {
        $embeddings = ['Document A' => [0], 'Document B' => [1], 'Document C' => [2], 'Document D' => [3]];
        $textByVector = [];
        foreach ($embeddings as $text => $vector) {
            $textByVector[json_encode($vector)] = $text;
        }

        $contrived = new class($embeddings) extends Embeddings {
            /** @param array<string, list<int>> $embeddings */
            public function __construct(private array $embeddings)
            {
            }

            public function embedDocuments(array $documents): array
            {
                return array_map(fn (string $t): array => $this->embeddings[$t], $documents);
            }

            public function embedQuery(string $document): array
            {
                return $this->embeddings[$document] ?? throw new \RuntimeException("Document $document not found");
            }
        };

        $similarity = static function (array $query, array $vector) use ($textByVector): float {
            if ($textByVector[json_encode($query)] !== 'Document D') {
                throw new \RuntimeException('Similarity metric only valid for Document D');
            }

            return match ($textByVector[json_encode($vector)]) {
                'Document A' => 0.23351,
                'Document B' => 0.062168,
                'Document C' => 0.169842,
                default => 0.0,
            };
        };

        $orderings = [
            ['A', 'B', 'C'], ['A', 'C', 'B'], ['B', 'A', 'C'],
            ['B', 'C', 'A'], ['C', 'A', 'B'], ['C', 'B', 'A'],
        ];
        foreach ($orderings as $ordering) {
            $store = new MemoryVectorStore($contrived, ['similarity' => $similarity]);
            foreach ($ordering as $letter) {
                $store->addDocuments([new Document("Document $letter", ['a' => 1])]);
            }

            $results = $store->similaritySearchWithScore('Document D', 3);

            $this->assertSame(
                ['Document A', 'Document C', 'Document B'],
                array_map(static fn (array $r): string => $r[0]->pageContent, $results),
            );
            $this->assertGreaterThan($results[1][1], $results[0][1]);
            $this->assertGreaterThan($results[2][1], $results[1][1]);
        }
    }

    public function testDefaultSimilarityIsCosineThroughTheSharedMathHelper(): void
    {
        $embeddings = new class extends Embeddings {
            public function embedDocuments(array $documents): array
            {
                return array_map($this->embedQuery(...), $documents);
            }

            public function embedQuery(string $document): array
            {
                return match ($document) {
                    'north' => [0.0, 1.0],
                    'east' => [1.0, 0.0],
                    'northeast' => [1.0, 1.0],
                    default => [0.0, 0.0],
                };
            }
        };
        $store = MemoryVectorStore::fromTexts(['east', 'northeast', 'zero'], [[], [], []], $embeddings);

        $results = $store->similaritySearchWithScore('north', 3);

        $this->assertSame(['northeast', 'east', 'zero'], array_map(static fn (array $r): string => $r[0]->pageContent, $results));
        $this->assertEqualsWithDelta(M_SQRT1_2, $results[0][1], 1e-12);
        $this->assertEqualsWithDelta(0.0, $results[1][1], 1e-12);
        // a zero vector has no direction; the helper folds its NaN to 0 rather than propagating it
        $this->assertSame(0.0, $results[2][1]);
    }

    public function testFromTextsAppliesOneMetadataMapToEveryTextOrAListPerText(): void
    {
        $shared = MemoryVectorStore::fromTexts(['a', 'b'], ['src' => 'x'], new FakeEmbeddings());
        $this->assertSame([['src' => 'x'], ['src' => 'x']], array_map(static fn (MemoryVector $v): array => $v->metadata, $shared->memoryVectors));

        $perText = MemoryVectorStore::fromTexts(['a', 'b'], [['n' => 1], ['n' => 2]], new FakeEmbeddings());
        $this->assertSame([['n' => 1], ['n' => 2]], array_map(static fn (MemoryVector $v): array => $v->metadata, $perText->memoryVectors));
    }

    public function testFromDocumentsEmbedsAndFromExistingIndexStartsEmpty(): void
    {
        $store = MemoryVectorStore::fromDocuments(self::fourDocs(), new FakeEmbeddings());
        $this->assertCount(4, $store->memoryVectors);

        $this->assertSame([], MemoryVectorStore::fromExistingIndex(new FakeEmbeddings())->memoryVectors);
    }

    public function testMmrSearchTypeOnTheRetrieverUsesMaxMarginalRelevance(): void
    {
        $store = new MemoryVectorStore(new SyntheticEmbeddings(64));
        $store->addDocuments(self::fourDocs());

        $retriever = $store->asRetriever(['k' => 2, 'searchType' => 'mmr', 'searchKwargs' => ['fetchK' => 4, 'lambda' => 0.5]]);

        $this->assertSame('mmr', $retriever->searchType);
        $this->assertCount(2, $retriever->invoke('hello'));
    }

    public function testMmrOnAStoreWithoutMmrSupportExplainsItself(): void
    {
        $store = new class(new FakeEmbeddings()) extends VectorStore {
            public function vectorstoreType(): string
            {
                return 'bare';
            }

            public function addVectors(array $vectors, array $documents, array $options = []): ?array
            {
                return null;
            }

            public function addDocuments(array $documents, array $options = []): ?array
            {
                return null;
            }

            public function similaritySearchVectorWithScore(array $query, int $k, mixed $filter = null): array
            {
                return [];
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bare does not support max marginal relevance search');

        $store->asRetriever(['searchType' => 'mmr'])->invoke('q');
    }

    public function testBaseClassDefaults(): void
    {
        $store = new MemoryVectorStore(new FakeEmbeddings());

        try {
            $store->delete(['ids' => ['x']]);
            $this->fail('the in-memory store does not implement delete, like upstream');
        } catch (\RuntimeException $e) {
            $this->assertSame('Not implemented.', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forgot to override this');
        VectorStore::fromTexts(['a'], [], new FakeEmbeddings());
    }

    public function testSaveableVectorStoreLoadIsNotImplementedByDefault(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not implemented');

        SaveableVectorStore::load('/tmp/nowhere', new FakeEmbeddings());
    }

    public function testAsRetrieverFromAnIntegerTagsTheRetrieverWithTheStoreType(): void
    {
        $store = new MemoryVectorStore(new FakeEmbeddings());

        $retriever = $store->asRetriever(7, null, null, ['mine']);

        $this->assertSame(7, $retriever->k);
        $this->assertSame(['mine', 'memory'], $retriever->tags);
        $this->assertSame('memory', $retriever->vectorstoreType());
    }

    public function testSerializedIdCarriesTheStoreType(): void
    {
        $serialized = (new MemoryVectorStore(new FakeEmbeddings()))->toSerializedConstructor();

        $this->assertSame(['langchain', 'vectorstores', 'memory', 'MemoryVectorStore'], $serialized['id']);
        $this->assertSame(['langchain', 'vectorstores', 'MemoryVectorStore'], MemoryVectorStore::lcId());
    }
}
