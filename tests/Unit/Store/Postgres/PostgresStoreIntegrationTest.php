<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Postgres;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Pregel\Constants;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use LangGraph\Store\GetOperation;
use LangGraph\Store\InvalidNamespaceError;
use LangGraph\Store\PutOperation;
use LangGraph\Store\Postgres\Modules\IndexConfig;
use LangGraph\Store\Postgres\Modules\TtlConfig;
use LangGraph\Store\Postgres\PostgresStore;
use LangGraph\Store\SearchItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tests/store.int.test.ts` from `@langchain/langgraph-checkpoint-postgres`
 * (Batch, CRUD, Error Handling, Hybrid Search, Namespace Listing, Search,
 * Statistics, TTL, Vector Search and Migration System), plus a real graph run
 * through the store.
 *
 * Env-gated on `LANGGRAPH_PG_DSN` (see {@see PostgresStoreTestConnection}); each
 * test skips when no server is reachable, and the vector cases also skip when
 * pgvector cannot be enabled. Upstream gives each test its own database; here
 * each store gets its own schema, dropped in tearDown.
 */
#[CoversClass(PostgresStore::class)]
final class PostgresStoreIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        PostgresStoreTestConnection::dropAll();
    }

    private function store(): PostgresStore
    {
        return PostgresStoreTestConnection::store()
            ?? self::markTestSkippedAndStop('No Postgres reachable: set LANGGRAPH_PG_DSN to run the Postgres store integration tests.');
    }

    /**
     * @param list<string>|null $fields
     * @param array{ttl?: TtlConfig} $options
     */
    private function vectorStore(MockEmbeddings $embeddings, ?array $fields = ['content', 'title'], array $options = [], string $indexType = 'hnsw'): PostgresStore
    {
        return PostgresStoreTestConnection::vectorStore(new IndexConfig(128, $embeddings, $fields, indexType: $indexType), $options)
            ?? self::markTestSkippedAndStop('No Postgres with pgvector reachable: set LANGGRAPH_PG_DSN to a server where CREATE EXTENSION vector works.');
    }

    private static function markTestSkippedAndStop(string $message): never
    {
        self::markTestSkipped($message);
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(PostgresStore $store, string $sql, array $params = []): array
    {
        $statement = $store->db()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    // ---- Batch Operations ---------------------------------------------------

    public function testShouldHandleBatchPutAndGetOperations(): void
    {
        $store = $this->store();

        $results = $store->batch([
            new PutOperation(['batch'], 'item1', ['data' => 'first']),
            new PutOperation(['batch'], 'item2', ['data' => 'second']),
            new GetOperation(['batch'], 'item1'),
        ]);

        self::assertCount(3, $results);
        self::assertNull($results[0]);
        self::assertNull($results[1]);
        self::assertSame(['data' => 'first'], $results[2]->value);
    }

    public function testShouldHandleBatchWithMixedValidAndInvalidOperations(): void
    {
        $store = $this->store();

        $this->expectException(InvalidNamespaceError::class);
        $this->expectExceptionMessage('Namespace cannot be empty');
        $store->batch([
            new PutOperation(['batch'], 'item1', ['data' => 'first']),
            new PutOperation([], 'item2', ['data' => 'invalid']),
            new GetOperation(['batch'], 'item1'),
        ]);
    }

    // ---- CRUD Operations ----------------------------------------------------

    public function testShouldStoreAndRetrieveASimpleItem(): void
    {
        $store = $this->store();
        $value = ['foo' => 'bar', 'num' => 42];

        $store->put(['crud', 'simple'], 'item1', $value);
        $item = $store->get(['crud', 'simple'], 'item1');

        self::assertNotNull($item);
        self::assertSame(['crud', 'simple'], $item->namespace);
        self::assertSame('item1', $item->key);
        self::assertEquals($value, $item->value);
        self::assertInstanceOf(\DateTimeImmutable::class, $item->createdAt);
        self::assertInstanceOf(\DateTimeImmutable::class, $item->updatedAt);
    }

    public function testShouldUpdateAnExistingItem(): void
    {
        $store = $this->store();
        $store->put(['crud', 'update'], 'item2', ['foo' => 'bar']);
        $original = $store->get(['crud', 'update'], 'item2');

        $store->put(['crud', 'update'], 'item2', ['foo' => 'baz', 'extra' => 123]);
        $updated = $store->get(['crud', 'update'], 'item2');

        self::assertSame(['foo' => 'bar'], $original?->value);
        self::assertEquals(['foo' => 'baz', 'extra' => 123], $updated?->value);
        self::assertGreaterThan((float) $original?->updatedAt->format('U.u'), (float) $updated?->updatedAt->format('U.u'));
        self::assertSame($original?->createdAt->format('U.u'), $updated?->createdAt->format('U.u'));
    }

    public function testShouldDeleteAnItem(): void
    {
        $store = $this->store();
        $store->put(['crud', 'delete'], 'item3', ['toDelete' => true]);
        self::assertNotNull($store->get(['crud', 'delete'], 'item3'));

        $store->delete(['crud', 'delete'], 'item3');

        self::assertNull($store->get(['crud', 'delete'], 'item3'));
    }

    public function testShouldHandleComplexJsonValues(): void
    {
        $store = $this->store();
        $complex = [
            'string' => 'test',
            'number' => 42,
            'boolean' => true,
            'null' => null,
            'array' => [1, 2, 3, 'four'],
            'nested' => ['deep' => ['value' => 'nested data']],
        ];

        $store->put(['crud', 'complex'], 'item4', $complex);

        // JSONB does not keep key order, so compare as maps.
        self::assertEquals($complex, $store->get(['crud', 'complex'], 'item4')?->value);
    }

    public function testShouldStoreAnEmptyObject(): void
    {
        $store = $this->store();

        $store->put(['crud', 'empty'], 'item', []);

        self::assertSame([], $store->get(['crud', 'empty'], 'item')?->value);
    }

    public function testShouldReturnNullForNonExistentItems(): void
    {
        self::assertNull($this->store()->get(['crud', 'missing'], 'nope'));
    }

    public function testShouldNotAllowEmptyNamespace(): void
    {
        $store = $this->store();
        $this->expectExceptionMessage('Namespace cannot be empty');
        $store->put([], 'key', ['foo' => 'bar']);
    }

    public function testShouldNotAllowNamespaceLabelsWithPeriods(): void
    {
        $store = $this->store();
        $this->expectExceptionMessage('Namespace labels cannot contain periods');
        $store->put(['invalid.namespace'], 'key', ['foo' => 'bar']);
    }

    public function testShouldNotAllowEmptyNamespaceLabel(): void
    {
        $store = $this->store();
        $this->expectExceptionMessage('Namespace labels cannot be empty strings');
        $store->put(['valid', ''], 'key', ['foo' => 'bar']);
    }

    public function testShouldNotAllowNonStringNamespaceLabel(): void
    {
        $store = $this->store();
        $this->expectExceptionMessage('Namespace labels must be strings');
        $store->put(['valid', 123], 'key', ['foo' => 'bar']);
    }

    public function testShouldNotAllowReservedNamespaceLabel(): void
    {
        $store = $this->store();
        $this->expectExceptionMessage('Root label for namespace cannot be "langgraph"');
        $store->put(['langgraph'], 'key', ['foo' => 'bar']);
    }

    public function testShouldSupportTtlOptions(): void
    {
        $store = $this->store();
        $value = ['data' => 'temporary data'];

        $store->put(['ttl', 'custom'], 'tempItem', $value, null, ['ttl' => 5]);

        self::assertSame($value, $store->get(['ttl', 'custom'], 'tempItem')?->value);
        $expiry = $this->rows($store, 'SELECT expires_at > CURRENT_TIMESTAMP + interval \'4 minutes\' AS long_lived FROM "' . PostgresStoreTestConnection::schemaOf($store) . '".store');
        self::assertTrue($expiry[0]['long_lived']);
    }

    public function testAnItemPastItsTtlIsInvisibleAndSweptAway(): void
    {
        $store = $this->store();
        $store->put(['ttl', 'gone'], 'old', ['data' => 'x']);
        $store->put(['ttl', 'gone'], 'fresh', ['data' => 'y']);
        $schema = PostgresStoreTestConnection::schemaOf($store);
        $store->db()->exec("UPDATE \"{$schema}\".store SET expires_at = CURRENT_TIMESTAMP - interval '1 minute' WHERE key = 'old'");

        self::assertNull($store->get(['ttl', 'gone'], 'old'));
        self::assertCount(1, $store->search(['ttl', 'gone']));
        self::assertSame(1, $store->stats()['expiredItems']);
        self::assertSame(1, $store->sweepExpiredItems());
        self::assertSame(1, $store->stats()['totalItems']);
    }

    public function testShouldSupportIndexFalseToDisableVectorIndexing(): void
    {
        $embeddings = new MockEmbeddings(128, true);
        $store = $this->vectorStore($embeddings);
        $before = count($embeddings->calls);
        $namespace = ['vectors', 'no-index'];

        $store->put($namespace, 'doc1', ['title' => 'Test Document', 'content' => 'This content should not be indexed'], false);

        self::assertCount($before, $embeddings->calls);
        self::assertSame([], $store->search($namespace, ['query' => 'test document', 'mode' => 'vector']));
    }

    public function testAnEmptyIndexListIndexesNothingAndClearsExistingVectors(): void
    {
        $embeddings = new MockEmbeddings(128, true);
        $store = $this->vectorStore($embeddings);
        $schema = PostgresStoreTestConnection::schemaOf($store);
        $namespace = ['vectors'];

        $store->put($namespace, 'k', ['title' => 'first'], null);
        self::assertNotSame([], $this->rows($store, "SELECT 1 FROM \"{$schema}\".store_vectors WHERE key = ?", ['k']));

        $before = count($embeddings->calls);
        $store->put($namespace, 'k', ['title' => 'second'], []);

        self::assertCount($before, $embeddings->calls);
        self::assertSame([], $this->rows($store, "SELECT 1 FROM \"{$schema}\".store_vectors WHERE key = ?", ['k']));
    }

    public function testShouldSupportSpecificFieldsToIndex(): void
    {
        $embeddings = new MockEmbeddings(128, true);
        $store = $this->vectorStore($embeddings);
        $before = count($embeddings->calls);
        $namespace = ['vectors', 'selective'];

        $store->put($namespace, 'doc1', [
            'title' => 'Indexed Title',
            'content' => 'Not indexed content',
            'summary' => 'Indexed summary',
            'metadata' => ['author' => 'Test Author'],
        ], ['title', 'summary']);

        self::assertCount($before + 1, $embeddings->calls);
        $call = $embeddings->calls[$before];
        self::assertContains('Indexed Title', $call);
        self::assertContains('Indexed summary', $call);
        self::assertNotContains('Not indexed content', $call);
        self::assertNotEmpty($store->search($namespace, ['query' => 'indexed summary', 'mode' => 'vector']));
    }

    public function testShouldSupportBothTtlAndIndexingOptions(): void
    {
        $embeddings = new MockEmbeddings(128, true);
        $store = $this->vectorStore($embeddings);
        $before = count($embeddings->calls);
        $namespace = ['vectors', 'combined'];
        $value = ['title' => 'Combined Options Test', 'content' => 'Testing both TTL and indexing options'];

        $store->put($namespace, 'doc1', $value, ['title'], ['ttl' => 10]);

        self::assertSame($value, $store->get($namespace, 'doc1')?->value);
        self::assertCount($before + 1, $embeddings->calls);
        self::assertContains('Combined Options Test', $embeddings->calls[$before]);
        self::assertNotContains('Testing both TTL and indexing options', $embeddings->calls[$before]);

        $schema = PostgresStoreTestConnection::schemaOf($store);
        $vectors = $this->rows($store, "SELECT field_path, text_content FROM \"{$schema}\".store_vectors WHERE namespace_path = ? AND key = ?", ['vectors:combined', 'doc1']);
        self::assertCount(1, $vectors);
        self::assertSame('title', $vectors[0]['field_path']);
        self::assertSame('Combined Options Test', $vectors[0]['text_content']);
    }

    // ---- Error Handling -----------------------------------------------------

    public function testShouldHandleDatabaseConnectionErrorsGracefully(): void
    {
        // PDO connects eagerly, so the failure surfaces when the store is opened.
        $this->expectException(\PDOException::class);
        PostgresStore::fromConnString('postgresql://invalid:invalid@localhost:9999/invalid')->setup();
    }

    public function testShouldHandleMalformedConnectionStrings(): void
    {
        $this->expectException(\PDOException::class);
        PostgresStore::fromConnString('not-a-valid-connection-string');
    }

    public function testShouldThrowErrorWhenUsingVectorSearchModeWithoutVectorConfiguration(): void
    {
        $store = $this->store();
        $this->expectExceptionMessageMatches('/Vector search requested but not configured/');
        $store->search(['docs'], ['query' => 'test query', 'mode' => 'vector']);
    }

    public function testShouldThrowErrorWhenUsingHybridSearchModeWithoutVectorConfiguration(): void
    {
        $store = $this->store();
        $this->expectExceptionMessageMatches('/Hybrid search requested but vector search not configured/');
        $store->search(['docs'], ['query' => 'test query', 'mode' => 'hybrid']);
    }

    public function testShouldThrowErrorWhenUsingVectorSearchDirectlyWithoutVectorConfiguration(): void
    {
        $store = $this->store();
        $this->expectExceptionMessageMatches('/Vector search requested but not configured/');
        $store->search(['docs'], ['mode' => 'vector', 'query' => 'test query']);
    }

    public function testShouldThrowErrorWhenUsingHybridSearchDirectlyWithoutVectorConfiguration(): void
    {
        $store = $this->store();
        $this->expectExceptionMessageMatches('/Hybrid search requested but vector search not configured/');
        $store->search(['docs'], ['query' => 'test query', 'mode' => 'hybrid']);
    }

    public function testShouldHandleUnknownSearchMode(): void
    {
        $store = $this->store();
        $this->expectExceptionMessageMatches('/Unknown search mode/');
        $store->search(['docs'], ['query' => 'test', 'mode' => 'invalid-mode']);
    }

    // ---- Hybrid Search ------------------------------------------------------

    private function hybridStore(MockEmbeddings $embeddings): PostgresStore
    {
        $store = $this->vectorStore($embeddings);
        $store->put(['docs'], 'doc1', [
            'title' => 'Machine Learning Guide',
            'content' => 'Comprehensive guide to machine learning algorithms and techniques',
        ]);
        $store->put(['docs'], 'doc2', [
            'title' => 'Data Science Handbook',
            'content' => 'Statistical methods and data analysis for scientists',
        ]);
        $store->put(['docs'], 'doc3', [
            'title' => 'AI Research Paper',
            'content' => 'Latest research in artificial intelligence and neural networks',
        ]);

        return $store;
    }

    public function testShouldCombineVectorAndTextSearchEffectively(): void
    {
        $store = $this->hybridStore(new MockEmbeddings(128, false));

        foreach ([0.9, 0.1, 0.5] as $weight) {
            $results = $store->search(['docs'], [
                'mode' => 'hybrid',
                'query' => 'machine learning algorithms',
                'vectorWeight' => $weight,
                'limit' => 10,
            ]);

            self::assertNotEmpty($results);
            foreach ($results as $item) {
                self::assertIsFloat($item->score);
            }
        }
    }

    public function testShouldMaintainResultOrderingByScore(): void
    {
        $store = $this->hybridStore(new MockEmbeddings(128, false));

        $results = $store->search(['docs'], ['mode' => 'hybrid', 'query' => 'machine learning algorithms', 'vectorWeight' => 0.5, 'limit' => 10]);

        $scores = array_map(static fn (SearchItem $item): ?float => $item->score, $results);
        $sorted = $scores;
        rsort($sorted);
        self::assertSame($sorted, $scores);
    }

    public function testShouldSupportHybridModeInUnifiedSearchMethod(): void
    {
        $embeddings = new MockEmbeddings(128, false);
        $store = $this->hybridStore($embeddings);

        $results = $store->search(['docs'], ['query' => 'machine learning algorithms', 'mode' => 'hybrid', 'vectorWeight' => 0.7, 'limit' => 10]);

        self::assertNotEmpty($results);
        foreach ($results as $item) {
            self::assertIsFloat($item->score);
        }
        self::assertTrue($embeddings->wasCalled());
    }

    public function testShouldPassAppropriateParametersThroughToHybridSearch(): void
    {
        $store = $this->hybridStore(new MockEmbeddings(128, false));
        $options = ['query' => 'neural networks research', 'mode' => 'hybrid', 'vectorWeight' => 0.65, 'similarityThreshold' => 0.25, 'limit' => 5];

        $results = $store->search(['docs'], $options);
        $again = $store->search(['docs'], $options);

        self::assertCount(count($again), $results);
        if ($results !== []) {
            self::assertSame($again[0]->key, $results[0]->key);
            self::assertSame(gettype($again[0]->score), gettype($results[0]->score));
        }
    }

    // ---- Namespace Listing --------------------------------------------------

    public function testShouldListAllNamespaces(): void
    {
        $store = $this->store();
        $store->put(['docs', 'v1'], 'item1', ['data' => 'test']);
        $store->put(['docs', 'v2'], 'item2', ['data' => 'test']);
        $store->put(['cache', 'temp'], 'item3', ['data' => 'test']);

        $namespaces = $store->listNamespaces();

        self::assertContains(['docs', 'v1'], $namespaces);
        self::assertContains(['docs', 'v2'], $namespaces);
        self::assertContains(['cache', 'temp'], $namespaces);
    }

    public function testShouldListNamespacesWithPrefixFilter(): void
    {
        $store = $this->store();
        $store->put(['docs', 'v1'], 'item1', ['data' => 'test']);
        $store->put(['docs', 'v2'], 'item2', ['data' => 'test']);
        $store->put(['cache', 'temp'], 'item3', ['data' => 'test']);

        $namespaces = $store->listNamespaces(['prefix' => ['docs']]);

        self::assertCount(2, $namespaces);
        self::assertContains(['docs', 'v1'], $namespaces);
        self::assertContains(['docs', 'v2'], $namespaces);
        self::assertNotContains(['cache', 'temp'], $namespaces);
    }

    public function testShouldListNamespacesWithSuffixAndMaxDepth(): void
    {
        $store = $this->store();
        $store->put(['docs', 'v1'], 'item1', ['data' => 'test']);
        $store->put(['docs', 'v1', 'deep'], 'item2', ['data' => 'test']);
        $store->put(['cache', 'v1'], 'item3', ['data' => 'test']);

        self::assertSame([['cache', 'v1'], ['docs', 'v1']], $store->listNamespaces(['suffix' => ['v1']]));
        self::assertSame([['cache', 'v1'], ['docs', 'v1']], $store->listNamespaces(['maxDepth' => 2]));
        self::assertSame([['docs', 'v1']], $store->listNamespaces(['prefix' => ['docs'], 'maxDepth' => 2, 'limit' => 1]));
    }

    // ---- Search -------------------------------------------------------------

    private function searchStore(): PostgresStore
    {
        $store = $this->store();
        $store->put(['docs'], 'doc1', [
            'title' => 'JavaScript Guide',
            'content' => 'Complete guide to JavaScript programming',
            'category' => 'programming',
            'difficulty' => 'beginner',
            'tags' => ['javascript', 'web', 'tutorial'],
            'stars' => 5,
        ]);
        $store->put(['docs'], 'doc2', [
            'title' => 'TypeScript Handbook',
            'content' => 'Advanced TypeScript programming techniques',
            'category' => 'programming',
            'difficulty' => 'intermediate',
            'tags' => ['typescript', 'javascript', 'types'],
            'stars' => 8,
        ]);
        $store->put(['docs'], 'doc3', [
            'title' => 'Python Basics',
            'content' => 'Introduction to Python programming language',
            'category' => 'programming',
            'difficulty' => 'beginner',
            'tags' => ['python', 'basics'],
            'stars' => 3,
        ]);
        $store->put(['recipes'], 'recipe1', [
            'title' => 'Chocolate Cake',
            'content' => 'Delicious chocolate cake recipe with detailed instructions',
            'category' => 'dessert',
            'difficulty' => 'easy',
            'tags' => ['chocolate', 'cake', 'baking'],
        ]);

        return $store;
    }

    public function testShouldPerformBasicSearchWithNoOptions(): void
    {
        $results = $this->searchStore()->search(['docs']);

        self::assertCount(3, $results);
        foreach ($results as $item) {
            self::assertSame('docs', $item->namespace[0]);
            self::assertNull($item->score);
        }
    }

    public function testShouldSearchWithSimpleFilter(): void
    {
        $results = $this->searchStore()->search(['docs'], ['filter' => ['category' => 'programming']]);

        self::assertCount(3, $results);
        foreach ($results as $item) {
            self::assertSame('programming', $item->value['category']);
        }
    }

    public function testShouldSearchWithAdvancedFilterOperators(): void
    {
        $results = $this->searchStore()->search(['docs'], ['filter' => ['difficulty' => ['$eq' => 'beginner']]]);

        self::assertCount(2, $results);
        foreach ($results as $item) {
            self::assertSame('beginner', $item->value['difficulty']);
        }
    }

    public function testShouldSearchWithMultipleFilterConditions(): void
    {
        $results = $this->searchStore()->search(['docs'], ['filter' => ['category' => 'programming', 'difficulty' => ['$ne' => 'advanced']]]);

        self::assertNotEmpty($results);
        foreach ($results as $item) {
            self::assertSame('programming', $item->value['category']);
            self::assertNotSame('advanced', $item->value['difficulty']);
        }
    }

    public function testShouldSearchWithInOperator(): void
    {
        $results = $this->searchStore()->search(['docs'], ['filter' => ['difficulty' => ['$in' => ['beginner', 'intermediate']]]]);

        self::assertCount(3, $results);
        foreach ($results as $item) {
            self::assertContains($item->value['difficulty'], ['beginner', 'intermediate']);
        }
    }

    public function testShouldSearchWithNinAndNumericComparisonOperators(): void
    {
        $store = $this->searchStore();

        $notBeginner = $store->search(['docs'], ['filter' => ['difficulty' => ['$nin' => ['beginner']]]]);
        self::assertSame(['doc2'], array_map(static fn (SearchItem $i): string => $i->key, $notBeginner));

        $range = $store->search(['docs'], ['filter' => ['stars' => ['$gte' => 5, '$lt' => 8]]]);
        self::assertSame(['doc1'], array_map(static fn (SearchItem $i): string => $i->key, $range));

        $greater = $store->search(['docs'], ['filter' => ['stars' => ['$gt' => 3]]]);
        self::assertCount(2, $greater);

        $atMost = $store->search(['docs'], ['filter' => ['stars' => ['$lte' => 3]]]);
        self::assertSame(['doc3'], array_map(static fn (SearchItem $i): string => $i->key, $atMost));
    }

    public function testShouldFilterOnExistsAndNestedObjects(): void
    {
        $store = $this->store();
        $store->put(['n'], 'a', ['meta' => ['author' => 'Ada'], 'flag' => true]);
        $store->put(['n'], 'b', ['meta' => ['author' => 'Bob']]);

        self::assertCount(1, $store->search(['n'], ['filter' => ['flag' => ['$exists' => true]]]));
        self::assertCount(1, $store->search(['n'], ['filter' => ['flag' => ['$exists' => false]]]));
        self::assertCount(1, $store->search(['n'], ['filter' => ['meta' => ['author' => 'Bob']]]));
        self::assertCount(1, $store->search(['n'], ['filter' => ['flag' => true]]));
    }

    public function testShouldApplyLimitAndOffset(): void
    {
        $store = $this->searchStore();

        $page1 = $store->search(['docs'], ['limit' => 2, 'offset' => 0]);
        $page2 = $store->search(['docs'], ['limit' => 2, 'offset' => 2]);

        self::assertCount(2, $page1);
        self::assertCount(1, $page2);
        $keys1 = array_map(static fn (SearchItem $i): string => $i->key, $page1);
        $keys2 = array_map(static fn (SearchItem $i): string => $i->key, $page2);
        self::assertSame([], array_intersect($keys1, $keys2));
    }

    public function testShouldReturnEmptyArrayForNonExistentNamespace(): void
    {
        self::assertSame([], $this->searchStore()->search(['nonexistent']));
    }

    public function testShouldSupportTextSearchMode(): void
    {
        $results = $this->searchStore()->search(['docs'], ['query' => 'JavaScript', 'mode' => 'text']);

        self::assertNotEmpty($results);
        $found = array_filter($results, static fn (SearchItem $i): bool => str_contains($i->value['title'], 'JavaScript') || str_contains($i->value['content'], 'JavaScript'));
        self::assertNotEmpty($found);
    }

    public function testShouldPerformFullTextSearchWithAutoModeDefaultingToText(): void
    {
        $results = $this->searchStore()->search(['docs'], ['query' => 'programming', 'mode' => 'auto']);

        self::assertNotEmpty($results);
        $found = array_filter($results, static fn (SearchItem $i): bool => $i->value['category'] === 'programming');
        self::assertNotEmpty($found);
        self::assertIsFloat($results[0]->score);
    }

    public function testShouldCombineFilteringWithTextSearch(): void
    {
        $results = $this->searchStore()->search(['docs'], ['query' => 'guide', 'filter' => ['difficulty' => 'beginner'], 'mode' => 'text']);

        self::assertNotEmpty($results);
        foreach ($results as $item) {
            self::assertSame('beginner', $item->value['difficulty']);
        }
        $found = array_filter($results, static fn (SearchItem $i): bool => str_contains(strtolower($i->value['title']), 'guide') || str_contains(strtolower($i->value['content']), 'guide'));
        self::assertNotEmpty($found);
    }

    public function testABatchedSearchWithAQueryFallsBackToTextSearchWithoutAnIndex(): void
    {
        $results = $this->searchStore()->batch([new \LangGraph\Store\SearchOperation(['docs'], null, 10, 0, 'python')])[0];

        self::assertCount(1, $results);
        self::assertSame('doc3', $results[0]->key);
    }

    // ---- Statistics ---------------------------------------------------------

    public function testShouldProvideAccurateStoreStatistics(): void
    {
        $store = $this->store();
        $store->put(['namespace1'], 'key1', ['data' => 'value1']);
        $store->put(['namespace1'], 'key2', ['data' => 'value2']);
        $store->put(['namespace2'], 'key1', ['data' => 'value3']);

        $stats = $store->stats();

        self::assertSame(3, $stats['totalItems']);
        self::assertSame(2, $stats['namespaceCount']);
        self::assertSame(0, $stats['expiredItems']);
        self::assertInstanceOf(\DateTimeImmutable::class, $stats['oldestItem']);
        self::assertInstanceOf(\DateTimeImmutable::class, $stats['newestItem']);
        self::assertGreaterThanOrEqual($stats['oldestItem']->getTimestamp(), $stats['newestItem']->getTimestamp());
        self::assertEquals($stats, $store->getStats());
    }

    public function testShouldHandleEmptyStoreStatistics(): void
    {
        $stats = $this->store()->stats();

        self::assertSame(0, $stats['totalItems']);
        self::assertSame(0, $stats['expiredItems']);
        self::assertSame(0, $stats['namespaceCount']);
        self::assertNull($stats['oldestItem']);
        self::assertNull($stats['newestItem']);
    }

    // ---- TTL ----------------------------------------------------------------

    private function ttlStore(): PostgresStore
    {
        return PostgresStoreTestConnection::store(['ttl' => new TtlConfig(defaultTtl: 1, refreshOnRead: true, sweepIntervalMinutes: 1)])
            ?? self::markTestSkippedAndStop('No Postgres reachable: set LANGGRAPH_PG_DSN to run the Postgres store integration tests.');
    }

    public function testShouldSupportTtlConfigurationAndSweep(): void
    {
        $store = $this->ttlStore();
        $store->put(['test'], 'ttl-item', ['data' => 'expires']);

        $item = $store->get(['test'], 'ttl-item');
        $swept = $store->sweepExpiredItems();

        self::assertSame(['data' => 'expires'], $item?->value);
        self::assertSame(0, $swept);
    }

    public function testShouldRefreshTtlOnRead(): void
    {
        $store = $this->ttlStore();
        $store->put(['test'], 'refresh-item', ['data' => 'refresh test']);
        $schema = PostgresStoreTestConnection::schemaOf($store);
        $store->db()->exec("UPDATE \"{$schema}\".store SET expires_at = CURRENT_TIMESTAMP + interval '5 seconds'");

        $item1 = $store->get(['test'], 'refresh-item');
        $item2 = $store->get(['test'], 'refresh-item');

        self::assertNotNull($item1);
        self::assertSame(['data' => 'refresh test'], $item2?->value);
        $refreshed = $this->rows($store, "SELECT expires_at > CURRENT_TIMESTAMP + interval '30 seconds' AS refreshed FROM \"{$schema}\".store");
        self::assertTrue($refreshed[0]['refreshed']);
    }

    // ---- Vector Search ------------------------------------------------------

    private function vectorSearchStore(MockEmbeddings $embeddings): PostgresStore
    {
        return $this->vectorStore($embeddings);
    }

    public function testShouldSupportVectorSearch(): void
    {
        $embeddings = new MockEmbeddings(128, false);
        $store = $this->vectorSearchStore($embeddings);
        $store->put(['docs'], 'doc1', ['title' => 'Machine Learning Basics', 'content' => 'Introduction to neural networks and deep learning']);
        $store->put(['docs'], 'doc2', ['title' => 'Data Science Guide', 'content' => 'Statistical analysis and data visualization techniques']);

        $results = $store->search(['docs'], ['query' => 'artificial intelligence', 'mode' => 'vector', 'limit' => 5]);

        self::assertNotEmpty($results);
        self::assertTrue($embeddings->wasCalled());
    }

    public function testShouldHandleDifferentDistanceMetrics(): void
    {
        $store = $this->vectorSearchStore(new MockEmbeddings(128, false));
        $store->put(['test'], 'item1', ['content' => 'sample content for testing']);

        foreach (['cosine', 'l2', 'inner_product'] as $metric) {
            $results = $store->search(['test'], ['query' => 'query text', 'mode' => 'vector', 'distanceMetric' => $metric]);
            self::assertCount(1, $results, $metric);
            self::assertIsFloat($results[0]->score, $metric);
        }
    }

    public function testDistanceMetricsScoreAnIdenticalVectorAtTheirBest(): void
    {
        $embeddings = new MockEmbeddings(128, true);
        $store = $this->vectorStore($embeddings, ['title']);
        $store->put(['m'], 'hit', ['title' => 'Combined Options Test']);
        $store->put(['m'], 'miss', ['title' => 'Testing both TTL and indexing options']);

        $cosine = $store->search(['m'], ['query' => 'combined options', 'mode' => 'vector']);
        self::assertSame(['hit', 'miss'], array_map(static fn (SearchItem $i): string => $i->key, $cosine));
        self::assertEqualsWithDelta(1.0, $cosine[0]->score, 1e-6);
        self::assertEqualsWithDelta(0.0, $cosine[1]->score, 1e-6);

        $l2 = $store->search(['m'], ['query' => 'combined options', 'mode' => 'vector', 'distanceMetric' => 'l2']);
        self::assertSame('hit', $l2[0]->key);
        self::assertEqualsWithDelta(1.0, $l2[0]->score, 1e-6);

        $ip = $store->search(['m'], ['query' => 'combined options', 'mode' => 'vector', 'distanceMetric' => 'inner_product']);
        self::assertSame('hit', $ip[0]->key);
    }

    public function testShouldRespectSimilarityThresholds(): void
    {
        $store = $this->vectorSearchStore(new MockEmbeddings(128, false));
        $store->put(['test'], 'item1', ['text' => 'very different content']);
        $store->put(['test'], 'item2', ['text' => 'similar query content']);

        $high = $store->search(['test'], ['query' => 'query content', 'mode' => 'vector', 'similarityThreshold' => 0.9]);
        $low = $store->search(['test'], ['query' => 'query content', 'mode' => 'vector', 'similarityThreshold' => 0.1]);

        self::assertGreaterThanOrEqual(count($high), count($low));
    }

    public function testShouldExtractTextFromJsonPathsCorrectly(): void
    {
        $store = $this->vectorSearchStore(new MockEmbeddings(128, false));
        $store->put(['test'], 'doc1', [
            'title' => 'Test Document',
            'content' => ['sections' => [['text' => 'Section 1 content'], ['text' => 'Section 2 content']]],
            'tags' => ['ai', 'ml', 'nlp'],
            'metadata' => ['author' => 'Test Author', 'version' => 1],
        ]);

        $results = $store->search(['test'], ['query' => 'Test Document', 'mode' => 'vector']);

        self::assertNotEmpty($results);
        self::assertSame('doc1', $results[0]->key);
        $schema = PostgresStoreTestConnection::schemaOf($store);
        $paths = $this->rows($store, "SELECT field_path FROM \"{$schema}\".store_vectors ORDER BY field_path");
        self::assertSame([['field_path' => 'content'], ['field_path' => 'title']], $paths);
    }

    public function testShouldSupportVectorModeInUnifiedSearchMethod(): void
    {
        $embeddings = new MockEmbeddings(128, false);
        $store = $this->vectorSearchStore($embeddings);
        $store->put(['unified'], 'doc1', ['title' => 'Neural Networks', 'content' => 'Deep learning architectures and applications']);

        $results = $store->search(['unified'], ['query' => 'artificial intelligence', 'mode' => 'vector', 'similarityThreshold' => 0.1]);

        self::assertNotEmpty($results);
        self::assertNotNull($results[0]->score);
        self::assertTrue($embeddings->wasCalled());
    }

    public function testShouldUseVectorSearchByDefaultWhenInAutoModeWithVectorConfig(): void
    {
        $embeddings = new MockEmbeddings(128, false);
        $store = $this->vectorSearchStore($embeddings);
        $store->put(['auto'], 'doc1', ['title' => 'Machine Learning', 'content' => 'Algorithms for pattern recognition']);

        $results = $store->search(['auto'], ['query' => 'data science techniques', 'mode' => 'auto']);

        self::assertNotEmpty($results);
        self::assertContains('data science techniques', $embeddings->calls[array_key_last($embeddings->calls)]);
    }

    public function testShouldHonorIndexFalseParameterWhenPuttingItems(): void
    {
        $embeddings = new MockEmbeddings(128, false);
        $store = $this->vectorSearchStore($embeddings);
        $before = count($embeddings->calls);

        $store->put(['no-index'], 'doc1', ['title' => 'Not Indexed Document', 'content' => 'This content should not be indexed for vector search'], false);

        self::assertCount($before, $embeddings->calls);
        self::assertSame([], $store->search(['no-index'], ['query' => 'not indexed content', 'mode' => 'vector']));
    }

    public function testShouldRespectSpecificFieldsToIndexUsingIndexArrayParameter(): void
    {
        $embeddings = new MockEmbeddings(128, false);
        $store = $this->vectorSearchStore($embeddings);
        $before = count($embeddings->calls);

        $store->put(['selective-index'], 'doc1', [
            'title' => 'Selective Indexing Test',
            'content' => 'Main content here',
            'summary' => 'Summary text here',
            'author' => 'Test Author',
        ], ['title', 'summary']);

        self::assertCount($before + 1, $embeddings->calls);
        self::assertContains('Selective Indexing Test', $embeddings->calls[$before]);
        self::assertContains('Summary text here', $embeddings->calls[$before]);
        self::assertNotContains('Main content here', $embeddings->calls[$before]);
        self::assertNotEmpty($store->search(['selective-index'], ['query' => 'summary text', 'mode' => 'vector']));
    }

    public function testReindexingAnItemReplacesItsVectorsAndDeletingCascades(): void
    {
        $store = $this->vectorStore(new MockEmbeddings(128, false), ['title']);
        $schema = PostgresStoreTestConnection::schemaOf($store);
        $store->put(['r'], 'doc', ['title' => 'first']);
        $store->put(['r'], 'doc', ['title' => 'second']);

        self::assertSame([['text_content' => 'second']], $this->rows($store, "SELECT text_content FROM \"{$schema}\".store_vectors"));

        $store->delete(['r'], 'doc');
        self::assertSame([], $this->rows($store, "SELECT 1 FROM \"{$schema}\".store_vectors"));
    }

    public function testAVectorSearchWithTheWrongDimensionsIsRejected(): void
    {
        $embeddings = new MockEmbeddings(128, false);
        $store = PostgresStoreTestConnection::vectorStore(new IndexConfig(4, $embeddings, ['title']))
            ?? self::markTestSkippedAndStop('No Postgres with pgvector reachable.');
        $store->put(['d'], 'doc', ['title' => 'anything']);

        $this->expectExceptionMessage('Query embedding dimension mismatch: expected 4, got 128');
        $store->search(['d'], ['query' => 'q', 'mode' => 'vector']);
    }

    // ---- Migration System ---------------------------------------------------

    public function testShouldProperlyTrackAndApplyStoreMigrations(): void
    {
        $store = $this->store();
        $schema = PostgresStoreTestConnection::schemaOf($store);

        $versions = $this->rows($store, "SELECT v FROM \"{$schema}\".store_migrations ORDER BY v");
        self::assertSame([0, 1, 2, 3], array_column($versions, 'v'));

        $tables = $this->rows($store, 'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name IN (\'store\', \'store_migrations\') ORDER BY table_name', [$schema]);
        self::assertSame(['store', 'store_migrations'], array_column($tables, 'table_name'));

        // Setting up again, even from a fresh store on the same schema, adds nothing.
        $store->setup();
        $second = PostgresStore::fromConnString(PostgresStoreTestConnection::dsn(), ['schema' => $schema]);
        $second->setup();
        $versions = $this->rows($store, "SELECT v FROM \"{$schema}\".store_migrations ORDER BY v");
        self::assertCount(4, $versions);
    }

    public function testShouldApplyVectorMigrationsWhenVectorIndexingIsConfigured(): void
    {
        $store = $this->vectorStore(new MockEmbeddings(128, false), ['content']);
        $schema = PostgresStoreTestConnection::schemaOf($store);

        $versions = $this->rows($store, "SELECT v FROM \"{$schema}\".store_migrations ORDER BY v");
        self::assertSame([0, 1, 2, 3, 4, 5, 6], array_column($versions, 'v'));

        $tables = $this->rows($store, 'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name IN (\'store\', \'store_vectors\', \'store_migrations\') ORDER BY table_name', [$schema]);
        self::assertSame(['store', 'store_migrations', 'store_vectors'], array_column($tables, 'table_name'));

        $indexes = $this->rows($store, "SELECT indexname FROM pg_indexes WHERE schemaname = ? AND indexname LIKE 'idx_store_vectors_embedding_%'", [$schema]);
        self::assertSame([['indexname' => 'idx_store_vectors_embedding_cosine_hnsw']], $indexes);
    }

    public function testShouldHandleMigrationFailuresGracefully(): void
    {
        $store = $this->store();

        $store->put(['test'], 'key1', ['data' => 'value1']);

        self::assertSame(['data' => 'value1'], $store->get(['test'], 'key1')?->value);
    }

    public function testShouldSupportMultipleSchemasWithIndependentMigrations(): void
    {
        $store1 = $this->store();
        $store2 = $this->store();
        $schema1 = PostgresStoreTestConnection::schemaOf($store1);
        $schema2 = PostgresStoreTestConnection::schemaOf($store2);

        $store1->put(['only-in-one'], 'k', ['a' => 1]);

        self::assertNotSame($schema1, $schema2);
        self::assertSame([0, 1, 2, 3], array_column($this->rows($store1, "SELECT v FROM \"{$schema1}\".store_migrations ORDER BY v"), 'v'));
        self::assertSame([0, 1, 2, 3], array_column($this->rows($store1, "SELECT v FROM \"{$schema2}\".store_migrations ORDER BY v"), 'v'));
        self::assertNull($store2->get(['only-in-one'], 'k'));
    }

    // ---- End to end ---------------------------------------------------------

    public function testAGraphRemembersAcrossRunsThroughThePostgresStore(): void
    {
        $store = $this->store();

        $graph = (new StateGraph(Annotation::root([
            'user' => Annotation::last(),
            'visits' => Annotation::last(),
            'found' => Annotation::last(),
        ])))
            ->addNode('remember', static function (array $state, RunnableConfig $config): array {
                /** @var PostgresStore $store */
                $store = $config->configurable[Constants::CONFIG_KEY_STORE];
                $seen = $store->get(['visits'], $state['user'])?->value['n'] ?? 0;
                $store->put(['visits'], $state['user'], ['n' => $seen + 1, 'note' => "{$state['user']} likes graphs"]);

                $hits = $store->search(['visits'], ['query' => 'graphs', 'filter' => ['n' => ['$gte' => 1]]]);

                return ['visits' => $seen + 1, 'found' => count($hits)];
            })
            ->addEdge(Constants::START, 'remember')
            ->addEdge('remember', Constants::END)
            ->compile(['store' => $store]);

        self::assertSame(1, $graph->invoke(['user' => 'ada'])['visits']);
        $second = $graph->invoke(['user' => 'ada']);

        self::assertSame(2, $second['visits']);
        self::assertSame(1, $second['found']);
        self::assertSame(2, $store->get(['visits'], 'ada')?->value['n']);
        self::assertSame($store, $graph->store);
    }
}
