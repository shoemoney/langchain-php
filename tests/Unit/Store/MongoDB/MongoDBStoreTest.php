<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Checkpoint\MongoDBFakeClient;
use LangGraph\Pregel\Constants;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use LangGraph\Store\GetOperation;
use LangGraph\Store\InvalidNamespaceError;
use LangGraph\Store\ListNamespacesOperation;
use LangGraph\Store\MatchCondition;
use LangGraph\Store\MongoDB\MongoDBIndexConfig;
use LangGraph\Store\MongoDB\MongoDBStore;
use LangGraph\Store\MongoDB\MongoDBTtlConfig;
use LangGraph\Store\PutOperation;
use LangGraph\Store\SearchOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tests/store.test.ts` from `@langchain/langgraph-checkpoint-mongodb`.
 *
 * Upstream drives the store over a `vi.fn()` collection mock and asserts on the calls it
 * received. Here the collection is {@see FakeMongoStoreCollection}: it records the same calls
 * (`callsTo()`), and it also executes them, so the cases that upstream had to stub a return value
 * for (`findOne.mockResolvedValueOnce`) seed `documents` instead. The tail of the file adds what
 * the mock could not pin: index setup, TTL, filter translation, de-duplication and a graph.
 */
#[CoversClass(MongoDBStore::class)]
#[CoversClass(MongoDBIndexConfig::class)]
#[CoversClass(MongoDBTtlConfig::class)]
final class MongoDBStoreTest extends TestCase
{
    private FakeMongoStoreClient $client;

    private FakeMongoStoreCollection $collection;

    private MongoDBStore $store;

    protected function setUp(): void
    {
        $this->client = new FakeMongoStoreClient();
        $this->collection = $this->client->store('store');
        $this->store = new MongoDBStore($this->client, 'test', 'store');
    }

    /**
     * @param list<string> $namespace
     * @param array<string, mixed> $value
     */
    private function seed(array $namespace, string $key, array $value): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        $this->collection->documents[] = [
            'namespace' => $namespace,
            'key' => $key,
            'value' => $value,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];

        return $now;
    }

    // ---- put --------------------------------------------------------------------------

    public function testShouldUpsertADocument(): void
    {
        $this->store->batch([new PutOperation(['documents', 'user123'], 'doc1', ['title' => 'Test', 'content' => 'Hello'])]);

        $calls = $this->collection->callsTo('bulkWrite');
        self::assertCount(1, $calls);
        $operations = $calls[0]['args']['operations'];
        self::assertCount(1, $operations);
        $updateOne = $operations[0]['updateOne'];
        self::assertSame(['namespace' => ['documents', 'user123'], 'key' => 'doc1'], $updateOne['filter']);
        self::assertSame(['title' => 'Test', 'content' => 'Hello'], $updateOne['update']['$set']['value']);
        self::assertTrue($updateOne['upsert']);
        self::assertSame('documents/user123', $this->collection->documents[0]['namespaceStr']);
    }

    public function testShouldDeleteADocumentWhenTheValueIsNull(): void
    {
        $this->seed(['documents', 'user123'], 'doc1', ['title' => 'Test']);

        $this->store->batch([new PutOperation(['documents', 'user123'], 'doc1', null)]);

        $operations = $this->collection->callsTo('bulkWrite')[0]['args']['operations'];
        self::assertSame(
            [['deleteOne' => ['filter' => ['namespace' => ['documents', 'user123'], 'key' => 'doc1']]]],
            $operations,
        );
        self::assertSame([], $this->collection->documents);
    }

    public function testShouldThrowInvalidNamespaceErrorForAnEmptyNamespace(): void
    {
        $this->expectException(InvalidNamespaceError::class);
        $this->expectExceptionMessage('Namespace cannot be empty');

        $this->store->batch([new PutOperation([], 'doc1', ['title' => 'Test'])]);
    }

    public function testShouldThrowInvalidNamespaceErrorForANamespaceWithPeriods(): void
    {
        $this->expectException(InvalidNamespaceError::class);
        $this->expectExceptionMessage('Namespace labels cannot contain periods');

        $this->store->batch([new PutOperation(['docs.invalid'], 'doc1', ['title' => 'Test'])]);
    }

    public function testShouldThrowInvalidNamespaceErrorForTheLanggraphRootLabel(): void
    {
        $this->expectException(InvalidNamespaceError::class);
        $this->expectExceptionMessage('Root label for namespace cannot be "langgraph"');

        $this->store->batch([new PutOperation(['langgraph', 'data'], 'doc1', ['title' => 'Test'])]);
    }

    // ---- get --------------------------------------------------------------------------

    public function testShouldReturnNullIfTheDocumentIsNotFound(): void
    {
        self::assertNull($this->store->batch([new GetOperation(['documents'], 'missing')])[0]);
    }

    public function testShouldReturnAnItemWithValueKeyNamespaceAndTimestamps(): void
    {
        $now = $this->seed(['documents'], 'doc1', ['title' => 'Test']);

        $item = $this->store->batch([new GetOperation(['documents'], 'doc1')])[0];

        self::assertNotNull($item);
        self::assertSame(['title' => 'Test'], $item->value);
        self::assertSame('doc1', $item->key);
        self::assertSame(['documents'], $item->namespace);
        self::assertEquals($now, $item->createdAt);
        self::assertEquals($now, $item->updatedAt);
    }

    // ---- search -----------------------------------------------------------------------

    public function testShouldBuildAPrefixQueryUsingDotNotation(): void
    {
        $this->store->batch([new SearchOperation(['users', 'profiles'], [], 10, 0)]);

        self::assertSame(
            ['namespace.0' => 'users', 'namespace.1' => 'profiles'],
            $this->collection->callsTo('find')[0]['args']['filter'],
        );
    }

    public function testShouldThrowWhenAQueryIsProvidedWithoutIndexConfig(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/indexConfig/i');

        $this->store->batch([new SearchOperation(['docs'], null, 10, 0, 'find something')]);
    }

    // ---- listNamespaces ---------------------------------------------------------------

    public function testShouldReturnAnEmptyArrayIfNoDocumentsExist(): void
    {
        self::assertSame([], $this->store->batch([new ListNamespacesOperation(null, null, 100, 0)])[0]);
    }

    /**
     * @param list<MatchCondition> $conditions
     * @return array<string, mixed>
     */
    private function matchStageFor(array $conditions): array
    {
        $this->store->batch([new ListNamespacesOperation($conditions, null, 100, 0)]);
        $pipeline = $this->collection->callsTo('aggregate')[0]['args']['pipeline'];
        foreach ($pipeline as $stage) {
            if (isset($stage['$match'])) {
                return $stage;
            }
        }

        self::fail('The pipeline has no $match stage.');
    }

    public function testShouldBuildAPrefixMatchConditionPipeline(): void
    {
        self::assertSame(
            ['$match' => ['$expr' => ['$and' => [
                ['$eq' => [['$arrayElemAt' => ['$namespace', 0]], 'users']],
                ['$gte' => [['$size' => '$namespace'], 1]],
            ]]]],
            $this->matchStageFor([new MatchCondition(MatchCondition::PREFIX, ['users'])]),
        );
    }

    public function testShouldBuildASuffixMatchConditionPipeline(): void
    {
        self::assertSame(
            ['$match' => ['$expr' => ['$and' => [
                ['$eq' => [['$arrayElemAt' => ['$namespace', -1]], 'v1']],
                ['$gte' => [['$size' => '$namespace'], 1]],
            ]]]],
            $this->matchStageFor([new MatchCondition(MatchCondition::SUFFIX, ['v1'])]),
        );
    }

    public function testShouldSkipWildcardPositionsInAMatchConditionPipeline(): void
    {
        self::assertSame(
            ['$match' => ['$expr' => ['$and' => [
                ['$eq' => [['$arrayElemAt' => ['$namespace', 0]], 'users']],
                ['$eq' => [['$arrayElemAt' => ['$namespace', 2]], 'settings']],
                ['$gte' => [['$size' => '$namespace'], 3]],
            ]]]],
            $this->matchStageFor([new MatchCondition(MatchCondition::PREFIX, ['users', '*', 'settings'])]),
        );
    }

    // ---- batch ------------------------------------------------------------------------

    public function testShouldExecuteMixedOperationsInOneBatch(): void
    {
        $results = $this->store->batch([
            new PutOperation(['batch'], 'item1', ['num' => 1]),
            new PutOperation(['batch'], 'item2', ['num' => 2]),
            new GetOperation(['batch'], 'item1'),
        ]);

        self::assertNull($results[0]);
        self::assertNull($results[1]);
        self::assertSame(['num' => 1], $results[2]->value);
    }

    // ---- vector search ----------------------------------------------------------------

    private function manualStore(?HashEmbeddings $embeddings = null, ?MongoDBIndexConfig $config = null): MongoDBStore
    {
        return new MongoDBStore(
            $this->client,
            'test',
            'store',
            embeddings: $embeddings ?? new HashEmbeddings(),
            indexConfig: $config ?? new MongoDBIndexConfig(name: 'test_index', dims: 2),
        );
    }

    public function testShouldStoreEmbeddingsOnPutInManualMode(): void
    {
        $embeddings = new HashEmbeddings();
        $store = $this->manualStore($embeddings);

        $store->batch([new PutOperation(['memories', 'alice'], 'mem1', ['text' => 'hello world'])]);

        self::assertSame([['{"text":"hello world"}']], $embeddings->documentCalls);
        $doc = $this->collection->documents[0];
        self::assertSame($embeddings->embedQuery('{"text":"hello world"}'), $doc['embedding']);
        self::assertSame(['memories', 'memories/alice'], $doc['namespacePath']);
    }

    public function testShouldNotWriteAnEmbeddingFieldOnPutInAutoMode(): void
    {
        $store = new MongoDBStore($this->client, 'test', 'store', indexConfig: new MongoDBIndexConfig(name: 'test_index', model: 'voyage-4', path: 'value.content'));

        $store->batch([new PutOperation(['memories', 'alice'], 'mem1', ['content' => 'hello world'])]);

        $doc = $this->collection->documents[0];
        self::assertArrayNotHasKey('embedding', $doc);
        self::assertSame(['content' => 'hello world'], $doc['value']);
        self::assertSame(['memories', 'memories/alice'], $doc['namespacePath']);
    }

    public function testShouldSkipEmbeddingWhenTheOperationIndexIsFalse(): void
    {
        $embeddings = new HashEmbeddings();

        $this->manualStore($embeddings)->batch([new PutOperation(['memories', 'alice'], 'mem1', ['text' => 'hello world'], false)]);

        self::assertSame([], $embeddings->documentCalls);
        self::assertArrayNotHasKey('embedding', $this->collection->documents[0]);
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function vectorStage(MongoDBStore $store, array $override = []): array
    {
        $this->collection->searchIndexes[] = ['name' => 'test_index', 'type' => 'vectorSearch', 'definition' => ['fields' => []]];
        $store->batch([new SearchOperation(
            $override['prefix'] ?? ['memories'],
            null,
            $override['limit'] ?? 10,
            $override['offset'] ?? 0,
            'find something',
        )]);
        $pipeline = $this->collection->callsTo('aggregate')[0]['args']['pipeline'];

        return $pipeline[0]['$vectorSearch'];
    }

    public function testShouldUseQueryVectorInManualModeSearch(): void
    {
        $embeddings = new HashEmbeddings();

        $stage = $this->vectorStage($this->manualStore($embeddings));

        self::assertSame(['find something'], $embeddings->queryCalls);
        self::assertSame($embeddings->embedQuery('find something'), $stage['queryVector']);
        self::assertArrayNotHasKey('query', $stage);
    }

    public function testShouldUseQueryTextInAutoModeSearch(): void
    {
        $store = new MongoDBStore($this->client, 'test', 'store', indexConfig: new MongoDBIndexConfig(name: 'test_index', model: 'voyage-4'));
        $this->collection->searchIndexes[] = ['name' => 'test_index', 'type' => 'vectorSearch', 'definition' => ['fields' => []]];

        try {
            $store->batch([new SearchOperation(['memories'], null, 10, 0, 'find something')]);
        } catch (\LogicException) {
            // The fake cannot embed server-side; the pipeline it was sent is what matters.
        }

        $stage = $this->collection->callsTo('aggregate')[0]['args']['pipeline'][0]['$vectorSearch'];
        self::assertSame(['text' => 'find something'], $stage['query']);
        self::assertArrayNotHasKey('queryVector', $stage);
    }

    public function testShouldIncludeANamespacePathFilterInVectorSearch(): void
    {
        $stage = $this->vectorStage($this->manualStore(), ['prefix' => ['memories', 'alice']]);

        self::assertSame(['namespacePath' => 'memories/alice'], $stage['filter']);
    }

    public function testShouldThrowWhenAQueryIsProvidedWithoutIndexConfigInVectorSearch(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/indexConfig/i');

        $this->store->batch([new SearchOperation(['docs'], null, 10, 0, 'find something')]);
    }

    // ---- beyond the mock: setup -------------------------------------------------------

    public function testTheConstructorTagsTheClientAndSelectsTheDatabase(): void
    {
        self::assertSame([['name' => 'langgraphjs_store']], $this->client->metadata);
        self::assertSame(['test'], $this->client->dbNames);
    }

    public function testSetupCreatesTheUniqueNamespaceStringIndexOnly(): void
    {
        $this->store->setup();

        self::assertSame(
            [['keys' => ['namespaceStr' => 1, 'key' => 1], 'options' => ['unique' => true]]],
            array_column($this->collection->callsTo('createIndex'), 'args'),
        );
        self::assertSame([], $this->collection->callsTo('createSearchIndex'));
    }

    public function testSetupAddsATtlIndexWhenATtlIsConfigured(): void
    {
        $store = new MongoDBStore($this->client, 'test', 'store', ttl: new MongoDBTtlConfig(60, false));

        $store->start();

        $indexes = array_column($this->collection->callsTo('createIndex'), 'args');
        self::assertSame(['keys' => ['expiresAt' => 1], 'options' => ['expireAfterSeconds' => 0]], $indexes[1]);
    }

    public function testSetupCreatesAManualVectorSearchIndex(): void
    {
        $store = $this->manualStore(config: new MongoDBIndexConfig(name: 'idx', dims: 8, similarityFunction: 'dotProduct', path: 'vec', filters: ['value.tag']));

        $store->setup();

        self::assertSame([
            'name' => 'idx',
            'type' => 'vectorSearch',
            'definition' => ['fields' => [
                ['type' => 'vector', 'path' => 'vec', 'numDimensions' => 8, 'similarity' => 'dotProduct'],
                ['type' => 'filter', 'path' => 'namespacePath'],
                ['type' => 'filter', 'path' => 'value.tag'],
            ]],
        ], $this->collection->callsTo('createSearchIndex')[0]['args']['definition']);
    }

    public function testSetupCreatesAnAutoEmbedIndexAndDefaultsTheSimilarityToCosine(): void
    {
        $auto = new MongoDBStore($this->client, 'test', 'store', indexConfig: new MongoDBIndexConfig(name: 'auto', path: 'value.content', model: 'voyage-4'));
        $auto->setup();
        $this->manualStore()->setup();

        $calls = $this->collection->callsTo('createSearchIndex');
        self::assertSame(
            ['type' => 'autoEmbed', 'path' => 'value.content', 'model' => 'voyage-4', 'modality' => 'text'],
            $calls[0]['args']['definition']['definition']['fields'][0],
        );
        self::assertSame('cosine', $calls[1]['args']['definition']['definition']['fields'][0]['similarity']);
        self::assertSame('embedding', $calls[1]['args']['definition']['definition']['fields'][0]['path']);
    }

    public function testSetupIsIdempotentWhenTheSearchIndexAlreadyExists(): void
    {
        $store = $this->manualStore();

        $store->setup();
        $store->setup();

        self::assertCount(2, $this->collection->callsTo('createSearchIndex'));
        self::assertCount(1, $this->collection->searchIndexes);
    }

    public function testSetupRethrowsOtherSearchIndexErrors(): void
    {
        $this->collection->searchIndexFailure = new \RuntimeException('not an Atlas cluster');

        $this->expectExceptionMessage('not an Atlas cluster');

        $this->manualStore()->setup();
    }

    public function testStopHasNothingToRelease(): void
    {
        $this->store->stop();

        self::assertSame([], $this->collection->calls);
    }

    public function testACollectionWithoutTheStoreSeamIsRefused(): void
    {
        $store = new MongoDBStore(new MongoDBFakeClient(), 'test');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('MongoStoreCollectionInterface');

        $store->setup();
    }

    // ---- puts -------------------------------------------------------------------------

    public function testConsecutivePutsAreOneBulkWriteDeduplicatedWithTheLastWriteWinning(): void
    {
        $this->store->batch([
            new PutOperation(['n'], 'a', ['v' => 1]),
            new PutOperation(['n'], 'b', ['v' => 2]),
            new PutOperation(['n'], 'a', ['v' => 3]),
        ]);

        $calls = $this->collection->callsTo('bulkWrite');
        self::assertCount(1, $calls);
        self::assertCount(2, $calls[0]['args']['operations']);
        self::assertSame(['v' => 3], $this->store->get(['n'], 'a')?->value);
    }

    public function testAReadBetweenPutsSplitsTheBulkWritesAndSeesTheEarlierPut(): void
    {
        $results = $this->store->batch([
            new PutOperation(['n'], 'a', ['v' => 1]),
            new GetOperation(['n'], 'a'),
            new PutOperation(['n'], 'a', ['v' => 2]),
        ]);

        self::assertCount(2, $this->collection->callsTo('bulkWrite'));
        self::assertSame(['v' => 1], $results[1]->value);
        self::assertSame(['v' => 2], $this->store->get(['n'], 'a')?->value);
    }

    public function testAnInvalidNamespaceLaterInTheRunWritesNothing(): void
    {
        try {
            $this->store->batch([
                new PutOperation(['ok'], 'a', ['v' => 1]),
                new PutOperation(['bad.label'], 'b', ['v' => 2]),
            ]);
            self::fail('Expected InvalidNamespaceError.');
        } catch (InvalidNamespaceError) {
            self::assertSame([], $this->collection->callsTo('bulkWrite'));
        }
    }

    public function testAnUpdateKeepsCreatedAtAndAdvancesUpdatedAt(): void
    {
        $this->store->put(['n'], 'a', ['v' => 1]);
        $first = $this->store->get(['n'], 'a');
        usleep(2000);
        $this->store->put(['n'], 'a', ['v' => 2]);
        $second = $this->store->get(['n'], 'a');

        self::assertEquals($first->createdAt, $second->createdAt);
        self::assertGreaterThan($first->updatedAt, $second->updatedAt);
        self::assertCount(1, $this->collection->documents);
    }

    public function testDeleteRemovesTheItem(): void
    {
        $this->store->put(['n'], 'a', ['v' => 1]);
        $this->store->delete(['n'], 'a');

        self::assertNull($this->store->get(['n'], 'a'));
    }

    public function testEnableTimestampsStampsUpsertedAt(): void
    {
        $store = new MongoDBStore($this->client, 'test', 'store', enableTimestamps: true);

        $store->put(['n'], 'a', ['v' => 1]);

        $update = $this->collection->callsTo('bulkWrite')[0]['args']['operations'][0]['updateOne']['update'];
        self::assertSame(['upserted_at' => true], $update['$currentDate']);
        self::assertInstanceOf(\DateTimeImmutable::class, $this->collection->documents[0]['upserted_at']);
    }

    public function testNoTimestampOperatorWithoutEnableTimestamps(): void
    {
        $this->store->put(['n'], 'a', ['v' => 1]);

        $update = $this->collection->callsTo('bulkWrite')[0]['args']['operations'][0]['updateOne']['update'];
        self::assertArrayNotHasKey('$currentDate', $update);
    }

    // ---- ttl --------------------------------------------------------------------------

    public function testAPutSetsExpiresAtToNowPlusTheTtl(): void
    {
        $store = new MongoDBStore($this->client, 'test', 'store', ttl: new MongoDBTtlConfig(120, false));
        $before = new \DateTimeImmutable();

        $store->put(['n'], 'a', ['v' => 1]);

        $doc = $this->collection->documents[0];
        self::assertEqualsWithDelta($before->getTimestamp() + 120, $doc['expiresAt']->getTimestamp(), 2);
    }

    public function testWithoutATtlThereIsNoExpiresAt(): void
    {
        $this->store->put(['n'], 'a', ['v' => 1]);

        self::assertArrayNotHasKey('expiresAt', $this->collection->documents[0]);
    }

    public function testRefreshOnReadExtendsTheTtlThroughFindOneAndUpdate(): void
    {
        $store = new MongoDBStore($this->client, 'test', 'store', ttl: new MongoDBTtlConfig(120, true));
        $store->put(['n'], 'a', ['v' => 1]);
        $this->collection->documents[0]['expiresAt'] = new \DateTimeImmutable('-1 minute');

        $item = $store->get(['n'], 'a');

        self::assertNotNull($item);
        self::assertCount(1, $this->collection->callsTo('findOneAndUpdate'));
        self::assertSame([], $this->collection->callsTo('findOne'));
        self::assertSame('after', $this->collection->callsTo('findOneAndUpdate')[0]['args']['options']['returnDocument']);
        self::assertGreaterThan(new \DateTimeImmutable('+100 seconds'), $this->collection->documents[0]['expiresAt']);
    }

    public function testWithoutRefreshOnReadAGetIsAPlainFindOne(): void
    {
        $store = new MongoDBStore($this->client, 'test', 'store', ttl: new MongoDBTtlConfig(120, false));
        $store->put(['n'], 'a', ['v' => 1]);

        $store->get(['n'], 'a');

        self::assertSame([], $this->collection->callsTo('findOneAndUpdate'));
        self::assertCount(1, $this->collection->callsTo('findOne'));
    }

    public function testRefreshOnReadOfAMissingItemIsNull(): void
    {
        $store = new MongoDBStore($this->client, 'test', 'store', ttl: new MongoDBTtlConfig(120, true));

        self::assertNull($store->get(['n'], 'missing'));
    }

    // ---- filters ----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private function queryFor(array $filter): array
    {
        $this->store->search(['n'], ['filter' => $filter]);

        return $this->collection->callsTo('find')[0]['args']['filter'];
    }

    public function testAFilterIsTranslatedToDottedValuePaths(): void
    {
        self::assertSame(
            ['namespace.0' => 'n', 'value.a' => 'x', 'value.b' => ['$ne' => 1], 'value.c' => ['$gt' => 2], 'value.d' => ['$gte' => 3], 'value.e' => ['$lt' => 4], 'value.f' => ['$lte' => 5]],
            $this->queryFor(['a' => 'x', 'b' => ['$ne' => 1], 'c' => ['$gt' => 2], 'd' => ['$gte' => 3], 'e' => ['$lt' => 4], 'f' => ['$lte' => 5]]),
        );
    }

    public function testASingleEqOperatorBecomesPlainEquality(): void
    {
        self::assertSame('x', $this->queryFor(['a' => ['$eq' => 'x']])['value.a']);
    }

    public function testSeveralOperatorsOnOneFieldAreAllKept(): void
    {
        $this->store->batch([
            new PutOperation(['n'], 'lo', ['price' => 5]),
            new PutOperation(['n'], 'mid', ['price' => 50]),
            new PutOperation(['n'], 'hi', ['price' => 500]),
        ]);

        $items = $this->store->search(['n'], ['filter' => ['price' => ['$gt' => 10, '$lt' => 100]]]);

        self::assertSame(['mid'], array_map(static fn ($i): string => $i->key, $items));
    }

    public function testInAndNinAreTranslated(): void
    {
        $this->store->batch([
            new PutOperation(['n'], 'a', ['tag' => 'x']),
            new PutOperation(['n'], 'b', ['tag' => 'y']),
            new PutOperation(['n'], 'c', ['tag' => 'z']),
        ]);

        $in = $this->store->search(['n'], ['filter' => ['tag' => ['$in' => ['x', 'z']]]]);
        $nin = $this->store->search(['n'], ['filter' => ['tag' => ['$nin' => ['x', 'z']]]]);

        self::assertSame(['a', 'c'], array_map(static fn ($i): string => $i->key, $in));
        self::assertSame(['b'], array_map(static fn ($i): string => $i->key, $nin));
    }

    public function testASearchHonoursLimitAndOffset(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $key) {
            $this->store->put(['n'], $key, ['k' => $key]);
        }

        $items = $this->store->search(['n'], ['limit' => 2, 'offset' => 1]);

        self::assertSame(['b', 'c'], array_map(static fn ($i): string => $i->key, $items));
        self::assertSame(3, $this->collection->callsTo('find')[0]['args']['limit']);
    }

    public function testASearchPrefixDoesNotMatchAShorterNamespace(): void
    {
        $this->store->put(['users'], 'a', ['v' => 1]);
        $this->store->put(['users', 'profiles'], 'b', ['v' => 2]);

        $items = $this->store->search(['users', 'profiles']);

        self::assertSame(['b'], array_map(static fn ($i): string => $i->key, $items));
        self::assertNull($items[0]->score);
    }

    // ---- listNamespaces ---------------------------------------------------------------

    public function testListNamespacesSortsDeduplicatesAndPages(): void
    {
        foreach ([['b'], ['a', 'x'], ['a'], ['a'], ['c', 'd', 'e']] as $i => $namespace) {
            $this->store->put($namespace, "k{$i}", ['v' => $i]);
        }

        self::assertSame([['a'], ['a', 'x'], ['b'], ['c', 'd', 'e']], $this->store->listNamespaces());
        self::assertSame([['a', 'x'], ['b']], $this->store->listNamespaces(['limit' => 2, 'offset' => 1]));
    }

    public function testListNamespacesMaxDepthDropsDeeperNamespaces(): void
    {
        $this->store->put(['a'], 'k', ['v' => 1]);
        $this->store->put(['a', 'b', 'c'], 'k', ['v' => 1]);

        self::assertSame([['a']], $this->store->listNamespaces(['maxDepth' => 2]));
    }

    public function testListNamespacesCombinesPrefixAndSuffixConditions(): void
    {
        $this->store->put(['a', 'x', 'z'], 'k', ['v' => 1]);
        $this->store->put(['a', 'y'], 'k', ['v' => 1]);
        $this->store->put(['b', 'x', 'z'], 'k', ['v' => 1]);

        self::assertSame([['a', 'x', 'z']], $this->store->listNamespaces(['prefix' => ['a'], 'suffix' => ['z']]));
    }

    // ---- embedding input --------------------------------------------------------------

    public function testAnIndexListEmbedsOnlyThoseFields(): void
    {
        $embeddings = new HashEmbeddings();

        $this->manualStore($embeddings)->put(['n'], 'a', ['title' => 'T', 'body' => 'B', 'skip' => 'S'], ['title', 'body']);

        self::assertSame([['{"title":"T","body":"B"}']], $embeddings->documentCalls);
    }

    public function testAnEmbeddingKeyEmbedsOnlyThatSubField(): void
    {
        $embeddings = new HashEmbeddings();
        $store = $this->manualStore($embeddings, new MongoDBIndexConfig(name: 'idx', dims: 10, embeddingKey: 'body'));

        $store->put(['n'], 'a', ['title' => 'T', 'body' => 'B/é']);

        self::assertSame([['"B/é"']], $embeddings->documentCalls);
    }

    public function testManualEmbeddingBatchesEveryPutIntoOneEmbedCallAndSkipsDeletes(): void
    {
        $embeddings = new HashEmbeddings();
        $store = $this->manualStore($embeddings);

        $store->batch([
            new PutOperation(['n'], 'a', ['t' => 1]),
            new PutOperation(['n'], 'gone', null),
            new PutOperation(['n'], 'b', ['t' => 2], false),
            new PutOperation(['n'], 'c', ['t' => 3]),
        ]);

        self::assertSame([['{"t":1}', '{"t":3}']], $embeddings->documentCalls);
        $byKey = array_column($this->collection->documents, null, 'key');
        self::assertArrayHasKey('embedding', $byKey['a']);
        self::assertArrayNotHasKey('embedding', $byKey['b']);
        self::assertArrayHasKey('embedding', $byKey['c']);
    }

    public function testACustomVectorPathIsUsedForWritesAndStripped(): void
    {
        $store = $this->manualStore(config: new MongoDBIndexConfig(name: 'test_index', dims: 10, path: 'vec'));
        $store->setup();
        $store->put(['m', 'a'], 'k', ['text' => 'hello']);
        $store->put(['m', 'a'], 'k2', ['text' => 'bye']);

        self::assertArrayHasKey('vec', $this->collection->documents[0]);
        $items = $store->search(['m'], ['query' => 'hello']);

        self::assertCount(2, $items);
        $pipeline = $this->collection->callsTo('aggregate')[0]['args']['pipeline'];
        self::assertSame(['$project' => ['vec' => 0]], $pipeline[2]);
    }

    public function testVectorSearchScoresRanksAndPages(): void
    {
        $embeddings = new HashEmbeddings();
        $store = $this->manualStore($embeddings, new MongoDBIndexConfig(name: 'test_index', dims: 10));
        $store->setup();
        $store->put(['m'], 'same', ['text' => 'alpha']);
        $store->put(['m'], 'other', ['text' => 'omega']);

        $query = '{"text":"alpha"}';
        $items = $store->search(['m'], ['query' => $query, 'limit' => 5]);

        self::assertCount(2, $items);
        self::assertSame('same', $items[0]->key);
        self::assertEqualsWithDelta(1.0, $items[0]->score, 1e-9);
        self::assertLessThan($items[0]->score, $items[1]->score);
        self::assertArrayNotHasKey('embedding', $this->collection->callsTo('aggregate')[0]['args']['pipeline'][0]);

        $paged = $store->search(['m'], ['query' => $query, 'limit' => 1, 'offset' => 1]);
        self::assertSame(['other'], array_map(static fn ($i): string => $i->key, $paged));

        $stage = $this->collection->callsTo('aggregate')[1]['args']['pipeline'][0]['$vectorSearch'];
        self::assertSame(40, $stage['numCandidates']);
        self::assertSame(2, $stage['limit']);
    }

    public function testNumCandidatesIsCappedAtTenThousand(): void
    {
        $stage = $this->vectorStage($this->manualStore(), ['limit' => 5000]);

        self::assertSame(10000, $stage['numCandidates']);
    }

    public function testAnEmptyQueryFallsBackToAStructuredSearch(): void
    {
        $this->store->put(['n'], 'a', ['v' => 1]);

        self::assertCount(1, $this->store->search(['n'], ['query' => '']));
    }

    // ---- end to end -------------------------------------------------------------------

    public function testAGraphNodeRemembersAcrossRunsThroughTheMongoStore(): void
    {
        $embeddings = new HashEmbeddings();
        $store = $this->manualStore($embeddings, new MongoDBIndexConfig(name: 'test_index', dims: 10));
        $store->start();

        $graph = (new StateGraph(Annotation::root([
            'user' => Annotation::last(),
            'seen' => Annotation::last(),
            'recalled' => Annotation::last(),
        ])))
            ->addNode('remember', static function (array $state, RunnableConfig $config): array {
                $mongo = $config->configurable[Constants::CONFIG_KEY_STORE];
                $namespace = ['visits', $state['user']];
                $seen = ($mongo->get($namespace, 'count')?->value['n'] ?? 0) + 1;
                $mongo->put($namespace, 'count', ['n' => $seen], false);
                $mongo->put($namespace, "note{$seen}", ['text' => "visit {$seen}"]);
                $recalled = $mongo->search(['visits'], ['query' => '{"text":"visit 1"}', 'limit' => 1]);

                return ['seen' => $seen, 'recalled' => $recalled[0]->value['text'] ?? null];
            })
            ->addEdge(Constants::START, 'remember')
            ->addEdge('remember', Constants::END)
            ->compile(['store' => $store]);

        $first = $graph->invoke(['user' => 'ada']);
        $second = $graph->invoke(['user' => 'ada']);

        self::assertSame(1, $first['seen']);
        self::assertSame(2, $second['seen']);
        self::assertSame('visit 1', $second['recalled']);
        self::assertSame(['n' => 2], $this->collection->findOne(['namespace' => ['visits', 'ada'], 'key' => 'count'])['value']);
        self::assertSame([['visits', 'ada']], $store->listNamespaces(['prefix' => ['visits']]));
    }
}
