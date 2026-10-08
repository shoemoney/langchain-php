<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Indexing;

use LangChain\DocumentLoaders\BaseDocumentLoader;
use LangChain\Indexing\HashedDocument;
use LangChain\Indexing\Index;
use LangChain\Indexing\InMemoryRecordManager;
use LangChain\Schema\Document;
use LangChain\Tests\Unit\VectorStores\SyntheticEmbeddings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Own tests: upstream has none for `index()`.
 *
 * Time is hand-cranked, so "an earlier run" is exactly 10 seconds before "this run".
 */
#[CoversClass(Index::class)]
final class IndexTest extends TestCase
{
    private TestClock $clock;

    private InMemoryRecordManager $records;

    private IdTrackingMemoryVectorStore $store;

    protected function setUp(): void
    {
        $this->clock = new TestClock();
        $this->records = $this->clock->manager();
        $this->store = new IdTrackingMemoryVectorStore(new SyntheticEmbeddings(8));
    }

    /**
     * @param list<Document>       $docs
     * @param array<string, mixed> $options
     *
     * @return array{numAdded: int, numDeleted: int, numUpdated: int, numSkipped: int}
     */
    private function indexRun(array $docs, array $options = []): array
    {
        $this->clock->advance();

        return Index::index([
            'docsSource' => $docs,
            'recordManager' => $this->records,
            'vectorStore' => $this->store,
            'options' => $options,
        ]);
    }

    private static function counts(int $added = 0, int $deleted = 0, int $updated = 0, int $skipped = 0): array
    {
        return ['numAdded' => $added, 'numDeleted' => $deleted, 'numUpdated' => $updated, 'numSkipped' => $skipped];
    }

    private static function doc(string $content, string $source = 's'): Document
    {
        return new Document($content, ['source' => $source]);
    }

    // ---- no cleanup -------------------------------------------------------

    public function testFirstRunAddsEverythingAndReindexingUnchangedInputSkipsIt(): void
    {
        $docs = [self::doc('one'), self::doc('two')];

        $this->assertSame(self::counts(added: 2), $this->indexRun($docs));
        $this->assertSame(self::counts(skipped: 2), $this->indexRun($docs));
        $this->assertSame(['one', 'two'], $this->store->contents());
    }

    public function testVectorStoreIsHandedTheHashesAsIds(): void
    {
        $this->indexRun([self::doc('one')]);

        $expected = HashedDocument::fromDocument(self::doc('one'))->uid;
        $this->assertSame([[$expected]], $this->store->addCalls);
        $this->assertSame($expected, $this->store->memoryVectors[0]->id);
    }

    public function testWithoutCleanupAChangedDocumentIsAddedAndTheOldOneStays(): void
    {
        $this->indexRun([self::doc('one'), self::doc('two')]);

        $result = $this->indexRun([self::doc('one'), self::doc('two edited')]);

        $this->assertSame(self::counts(added: 1, skipped: 1), $result);
        $this->assertSame(['one', 'two', 'two edited'], $this->store->contents());
    }

    public function testDocumentsWithDifferentMetadataAreDifferentDocuments(): void
    {
        $this->indexRun([self::doc('one', 'a')]);

        $this->assertSame(self::counts(added: 1), $this->indexRun([self::doc('one', 'b')]));
    }

    public function testEmptySourceIsANoOp(): void
    {
        $this->assertSame(self::counts(), $this->indexRun([]));
        $this->assertSame([], $this->store->addCalls);
    }

    // ---- batching and in-batch dedup -------------------------------------

    public function testDuplicatesWithinABatchAreIndexedOnce(): void
    {
        $result = $this->indexRun([self::doc('same'), self::doc('same'), self::doc('other')]);

        $this->assertSame(self::counts(added: 2), $result);
        $this->assertSame(['other', 'same'], $this->store->contents());
    }

    public function testDuplicatesAcrossBatchesAreCaughtByTheRecordManager(): void
    {
        $result = $this->indexRun([self::doc('same'), self::doc('same')], ['batchSize' => 1]);

        $this->assertSame(self::counts(added: 1, skipped: 1), $result);
    }

    public function testBatchSizeSplitsTheWritesIntoBatches(): void
    {
        $docs = array_map(fn (int $i): Document => self::doc("doc $i"), range(1, 5));

        $this->assertSame(self::counts(added: 5), $this->indexRun($docs, ['batchSize' => 2]));
        $this->assertSame([2, 2, 1], array_map('count', $this->store->addCalls));
    }

    // ---- forceUpdate -------------------------------------------------------

    public function testForceUpdateRewritesKnownDocumentsAndCountsThemAsUpdated(): void
    {
        $docs = [self::doc('one'), self::doc('two')];
        $this->indexRun($docs);

        $result = $this->indexRun($docs, ['forceUpdate' => true]);

        $this->assertSame(self::counts(updated: 2), $result);
        $this->assertCount(2, $this->store->addCalls[1]);
    }

    public function testForceUpdateMixesNewAndKnownDocuments(): void
    {
        $this->indexRun([self::doc('one')]);

        $result = $this->indexRun([self::doc('one'), self::doc('new')], ['forceUpdate' => true]);

        $this->assertSame(self::counts(added: 1, updated: 1), $result);
    }

    // ---- incremental cleanup ----------------------------------------------

    public function testIncrementalRequiresASourceIdKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("sourceIdKey is required when cleanup mode is incremental. Please provide through 'options.sourceIdKey'.");

        $this->indexRun([self::doc('one')], ['cleanup' => 'incremental']);
    }

    public function testIncrementalDeletesTheStaleVersionOfAChangedDocument(): void
    {
        $this->indexRun([self::doc('one', 'A'), self::doc('two', 'A')], ['cleanup' => 'incremental', 'sourceIdKey' => 'source']);

        $result = $this->indexRun(
            [self::doc('one', 'A'), self::doc('two edited', 'A')],
            ['cleanup' => 'incremental', 'sourceIdKey' => 'source'],
        );

        $this->assertSame(self::counts(added: 1, deleted: 1, skipped: 1), $result);
        $this->assertSame(['one', 'two edited'], $this->store->contents());
    }

    public function testIncrementalLeavesOtherSourcesAndUnseenSourcesAlone(): void
    {
        $options = ['cleanup' => 'incremental', 'sourceIdKey' => 'source'];
        $this->indexRun([self::doc('a1', 'A'), self::doc('b1', 'B')], $options);

        $result = $this->indexRun([self::doc('a1 edited', 'A')], $options);

        $this->assertSame(self::counts(added: 1, deleted: 1), $result);
        $this->assertSame(['a1 edited', 'b1'], $this->store->contents(), 'source B was not seen, so it is not cleaned');
    }

    public function testIncrementalDoesNotDeleteWhatThisRunJustWrote(): void
    {
        $options = ['cleanup' => 'incremental', 'sourceIdKey' => 'source'];

        $result = $this->indexRun([self::doc('a', 'A'), self::doc('b', 'A')], $options);

        $this->assertSame(self::counts(added: 2), $result);
        $this->assertSame(['a', 'b'], $this->store->contents());
    }

    public function testIncrementalAcceptsACallableSourceIdKey(): void
    {
        $options = [
            'cleanup' => 'incremental',
            'sourceIdKey' => static fn (Document $d): string => strtoupper((string) $d->metadata['source']),
        ];
        $this->indexRun([self::doc('v1', 'a')], $options);

        $result = $this->indexRun([self::doc('v2', 'a')], $options);

        $this->assertSame(self::counts(added: 1, deleted: 1), $result);
        $this->assertSame(['v2'], $this->store->contents());
    }

    public function testIncrementalRejectsADocumentWithoutASourceBeforeWritingAnything(): void
    {
        $options = ['cleanup' => 'incremental', 'sourceIdKey' => 'source'];

        try {
            $this->indexRun([self::doc('ok', 'A'), new Document('orphan', [])], $options);
            $this->fail('a document with no source id must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('sourceIdKey must be provided when cleanup is incremental', $e->getMessage());
        }

        $this->assertSame([], $this->store->addCalls);
        $this->assertSame([], $this->records->listKeys());
    }

    public function testIncrementalRejectsAnEmptySourceIdAfterTheWrite(): void
    {
        $options = ['cleanup' => 'incremental', 'sourceIdKey' => 'source'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Source id cannot be null');

        $this->indexRun([new Document('x', ['source' => ''])], $options);
    }

    public function testIncrementalCleansPerBatchSoLaterBatchesSeeEarlierBatchesAsFresh(): void
    {
        $options = ['cleanup' => 'incremental', 'sourceIdKey' => 'source', 'batchSize' => 1];
        $this->indexRun([self::doc('a', 'A'), self::doc('b', 'A')], $options);

        $result = $this->indexRun([self::doc('a', 'A'), self::doc('b edited', 'A')], $options);

        $this->assertSame(self::counts(added: 1, deleted: 1, skipped: 1), $result);
        $this->assertSame(['a', 'b edited'], $this->store->contents());
    }

    // ---- full cleanup ------------------------------------------------------

    public function testFullDeletesEverythingTheRunDidNotSee(): void
    {
        $this->indexRun([self::doc('one'), self::doc('two'), self::doc('three')], ['cleanup' => 'full']);

        $result = $this->indexRun([self::doc('two')], ['cleanup' => 'full']);

        $this->assertSame(self::counts(deleted: 2, skipped: 1), $result);
        $this->assertSame(['two'], $this->store->contents());
        $this->assertCount(1, $this->records->listKeys());
    }

    public function testFullKeepsDocumentsItSkippedBecauseTheirTimestampWasRefreshed(): void
    {
        $docs = [self::doc('one'), self::doc('two')];
        $this->indexRun($docs, ['cleanup' => 'full']);

        $result = $this->indexRun($docs, ['cleanup' => 'full']);

        $this->assertSame(self::counts(skipped: 2), $result);
        $this->assertSame(['one', 'two'], $this->store->contents());
    }

    public function testFullWithEmptyInputWipesTheIndex(): void
    {
        $this->indexRun([self::doc('one'), self::doc('two')], ['cleanup' => 'full']);

        $result = $this->indexRun([], ['cleanup' => 'full']);

        $this->assertSame(self::counts(deleted: 2), $result);
        $this->assertSame([], $this->store->contents());
    }

    public function testFullDeletesInCleanupBatches(): void
    {
        $this->indexRun(array_map(fn (int $i): Document => self::doc("doc $i"), range(1, 5)), ['cleanup' => 'full']);

        $result = $this->indexRun([self::doc('doc 1')], ['cleanup' => 'full', 'cleanupBatchSize' => 2]);

        $this->assertSame(self::counts(deleted: 4, skipped: 1), $result);
        $this->assertSame(['doc 1'], $this->store->contents());
    }

    public function testFullDoesNotNeedASourceIdKey(): void
    {
        $result = $this->indexRun([new Document('no metadata at all')], ['cleanup' => 'full']);

        $this->assertSame(self::counts(added: 1), $result);
    }

    // ---- sources -----------------------------------------------------------

    public function testADocumentLoaderCanBeTheSource(): void
    {
        $loader = new class extends BaseDocumentLoader {
            public function load(): array
            {
                return [new Document('from loader', ['source' => 'l'])];
            }
        };
        $this->clock->advance();

        $result = Index::index(['docsSource' => $loader, 'recordManager' => $this->records, 'vectorStore' => $this->store]);

        $this->assertSame(self::counts(added: 1), $result);
        $this->assertSame(['from loader'], $this->store->contents());
    }

    // ---- helpers -----------------------------------------------------------

    public function testBatchSplitsInOrderAndKeepsTheRemainder(): void
    {
        $this->assertSame([[1, 2], [3, 4], [5]], Index::batch(2, [1, 2, 3, 4, 5]));
        $this->assertSame([[1, 2]], Index::batch(2, [1, 2]));
        $this->assertSame([], Index::batch(3, []));
    }

    public function testDeduplicateInOrderKeepsFirstOccurrences(): void
    {
        $a = HashedDocument::fromDocument(new Document('a'));
        $b = HashedDocument::fromDocument(new Document('b'));
        $a2 = HashedDocument::fromDocument(new Document('a'));

        $this->assertSame([$a, $b], Index::deduplicateInOrder([$a, $b, $a2]));
    }

    public function testDeduplicateInOrderRejectsAnUnhashedDocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hashed document does not have a hash');

        Index::deduplicateInOrder([new HashedDocument(['pageContent' => 'x', 'metadata' => []])]);
    }

    public function testSourceIdAssignerForNullStringAndCallable(): void
    {
        $doc = new Document('x', ['source' => 'S', 'n' => 3]);

        $this->assertNull(Index::getSourceIdAssigner(null)($doc));
        $this->assertSame('S', Index::getSourceIdAssigner('source')($doc));
        $this->assertSame('3', Index::getSourceIdAssigner('n')($doc));
        $this->assertNull(Index::getSourceIdAssigner('missing')($doc));
        $this->assertSame('custom', Index::getSourceIdAssigner(static fn (Document $d): string => 'custom')($doc));
    }

    public function testIsBaseDocumentLoader(): void
    {
        $loader = new class extends BaseDocumentLoader {
            public function load(): array
            {
                return [];
            }
        };

        $this->assertTrue(Index::isBaseDocumentLoader($loader));
        $this->assertFalse(Index::isBaseDocumentLoader([new Document('x')]));
    }
}
