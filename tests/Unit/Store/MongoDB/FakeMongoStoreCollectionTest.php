<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The fake's own contract. The store tests lean on it, so a fake that drifted from MongoDB
 * (unsorted "sorted" reads, numeric string ordering, limit before sort) would make them lie.
 */
#[CoversNothing]
final class FakeMongoStoreCollectionTest extends TestCase
{
    private FakeMongoStoreCollection $collection;

    protected function setUp(): void
    {
        $this->collection = new FakeMongoStoreCollection();
    }

    public function testSortAscendingAndDescendingAreHonoured(): void
    {
        $this->collection->insertMany([['k' => 'b'], ['k' => 'c'], ['k' => 'a']]);

        self::assertSame(['a', 'b', 'c'], array_column($this->collection->find([], ['k' => 1]), 'k'));
        self::assertSame(['c', 'b', 'a'], array_column($this->collection->find([], ['k' => -1]), 'k'));
    }

    public function testStringsOrderByStrcmpNotAsNumbers(): void
    {
        $this->collection->insertMany([['k' => '9'], ['k' => '10'], ['k' => '2']]);

        self::assertSame(['10', '2', '9'], array_column($this->collection->find([], ['k' => 1]), 'k'));
    }

    public function testLimitIsAppliedAfterTheSort(): void
    {
        $this->collection->insertMany([['k' => 'b'], ['k' => 'c'], ['k' => 'a']]);

        self::assertSame(['c'], array_column($this->collection->find([], ['k' => -1], 1), 'k'));
        self::assertSame('a', $this->collection->findOne([], ['k' => 1])['k']);
    }

    public function testTiesKeepInsertionOrder(): void
    {
        $this->collection->insertMany([['g' => 1, 'n' => 'first'], ['g' => 1, 'n' => 'second'], ['g' => 0, 'n' => 'zero']]);

        self::assertSame(['zero', 'first', 'second'], array_column($this->collection->find([], ['g' => 1]), 'n'));
    }

    public function testDottedPathsReachListPositionsAndNestedFields(): void
    {
        $this->collection->insertMany([['ns' => ['a', 'b'], 'v' => ['x' => 1]], ['ns' => ['a'], 'v' => ['x' => 2]]]);

        self::assertCount(1, $this->collection->find(['ns.1' => 'b']));
        self::assertCount(1, $this->collection->find(['v.x' => ['$gt' => 1]]));
    }

    public function testAScalarMatchesAListContainingItAndNullMatchesMissing(): void
    {
        $this->collection->insertMany([['paths' => ['a', 'a/b']], ['other' => 1]]);

        self::assertCount(1, $this->collection->find(['paths' => 'a/b']));
        self::assertCount(1, $this->collection->find(['paths' => null]));
    }

    public function testComparisonOperatorsOnlyCompareLikeTypes(): void
    {
        $this->collection->insertMany([['v' => 5], ['v' => '5'], ['v' => null]]);

        self::assertCount(1, $this->collection->find(['v' => ['$gt' => 1]]));
        self::assertCount(2, $this->collection->find(['v' => ['$ne' => 5]]));
    }

    public function testAnUpsertSeedsFromEqualityFieldsAndAppliesSetOnInsertOnlyOnce(): void
    {
        $update = ['$set' => ['v' => 1], '$setOnInsert' => ['created' => 'first']];
        $this->collection->bulkWrite([['updateOne' => ['filter' => ['key' => 'a'], 'update' => $update, 'upsert' => true]]]);
        $update['$setOnInsert']['created'] = 'second';
        $update['$set']['v'] = 2;
        $this->collection->bulkWrite([['updateOne' => ['filter' => ['key' => 'a'], 'update' => $update, 'upsert' => true]]]);

        self::assertSame([['key' => 'a', 'v' => 2, 'created' => 'first']], $this->collection->documents);
    }

    public function testAnUpdateWithoutUpsertDoesNotCreate(): void
    {
        $this->collection->updateOne(['key' => 'a'], ['$set' => ['v' => 1]]);

        self::assertSame([], $this->collection->documents);
    }

    public function testFindOneAndUpdateReturnsTheRequestedImage(): void
    {
        $this->collection->insertMany([['key' => 'a', 'v' => 1]]);

        $before = $this->collection->findOneAndUpdate(['key' => 'a'], ['$set' => ['v' => 2]]);
        $after = $this->collection->findOneAndUpdate(['key' => 'a'], ['$set' => ['v' => 3]], ['returnDocument' => 'after']);

        self::assertSame(1, $before['v']);
        self::assertSame(3, $after['v']);
        self::assertNull($this->collection->findOneAndUpdate(['key' => 'nope'], ['$set' => ['v' => 9]]));
    }

    public function testDeleteOneRemovesOnlyTheFirstMatch(): void
    {
        $this->collection->insertMany([['k' => 1], ['k' => 1]]);

        $this->collection->bulkWrite([['deleteOne' => ['filter' => ['k' => 1]]]]);

        self::assertCount(1, $this->collection->documents);
    }

    public function testAggregateGroupsSortsPagesAndEvaluatesExpr(): void
    {
        $this->collection->insertMany([['ns' => ['b']], ['ns' => ['a', 'x']], ['ns' => ['a']], ['ns' => ['a']]]);

        $groups = $this->collection->aggregate([
            ['$match' => ['$expr' => ['$eq' => [['$arrayElemAt' => ['$ns', -1]], 'a']]]],
            ['$group' => ['_id' => '$ns']],
        ]);
        $ordered = $this->collection->aggregate([['$group' => ['_id' => '$ns']], ['$sort' => ['_id' => 1]], ['$skip' => 1], ['$limit' => 2]]);

        self::assertSame([['_id' => ['a']]], $groups);
        self::assertSame([['_id' => ['a', 'x']], ['_id' => ['b']]], $ordered);
    }

    public function testVectorSearchNeedsAnIndexAndQueryVector(): void
    {
        $this->collection->insertMany([['embedding' => [1, 0]]]);

        try {
            $this->collection->aggregate([['$vectorSearch' => ['index' => 'i', 'path' => 'embedding', 'queryVector' => [1, 0], 'limit' => 1]]]);
            self::fail('Expected a missing-index error.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('No search index named i', $e->getMessage());
        }

        $this->collection->createSearchIndex(['name' => 'i', 'type' => 'vectorSearch', 'definition' => ['fields' => []]]);
        $this->expectException(\LogicException::class);
        $this->collection->aggregate([['$vectorSearch' => ['index' => 'i', 'path' => 'embedding', 'query' => ['text' => 'x'], 'limit' => 1]]]);
    }

    public function testVectorSearchScoresLikeAtlasAndFiltersOnTheNamespacePath(): void
    {
        $this->collection->createSearchIndex(['name' => 'i', 'type' => 'vectorSearch', 'definition' => ['fields' => []]]);
        $this->collection->insertMany([
            ['key' => 'same', 'embedding' => [1, 0], 'namespacePath' => ['a', 'a/b']],
            ['key' => 'opposite', 'embedding' => [-1, 0], 'namespacePath' => ['a']],
            ['key' => 'elsewhere', 'embedding' => [1, 0], 'namespacePath' => ['z']],
        ]);

        $docs = $this->collection->aggregate([
            ['$vectorSearch' => ['index' => 'i', 'path' => 'embedding', 'queryVector' => [1, 0], 'limit' => 5, 'filter' => ['namespacePath' => 'a']]],
            ['$addFields' => ['score' => ['$meta' => 'vectorSearchScore']]],
        ]);

        self::assertSame(['same', 'opposite'], array_column($docs, 'key'));
        self::assertEqualsWithDelta(1.0, $docs[0]['score'], 1e-9);
        self::assertEqualsWithDelta(0.0, $docs[1]['score'], 1e-9);
    }

    public function testCreatingTheSameSearchIndexTwiceFails(): void
    {
        $this->collection->createSearchIndex(['name' => 'i']);

        $this->expectExceptionMessage('already exists');

        $this->collection->createSearchIndex(['name' => 'i']);
    }
}
