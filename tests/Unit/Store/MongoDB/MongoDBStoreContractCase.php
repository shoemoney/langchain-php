<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use LangGraph\Store\GetOperation;
use LangGraph\Store\ListNamespacesOperation;
use LangGraph\Store\MatchCondition;
use LangGraph\Store\MongoDB\MongoDBIndexConfig;
use LangGraph\Store\MongoDB\MongoDBStore;
use LangGraph\Store\PutOperation;
use LangGraph\Store\SearchItem;
use LangGraph\Store\SearchOperation;
use PHPUnit\Framework\TestCase;

/**
 * The store behaviours `store.int.test.ts` pins against a real server, written once so they run
 * both over the in-memory fake ({@see MongoDBStoreContractTest}) and against a live MongoDB
 * ({@see MongoDBStoreIntegrationTest}).
 *
 * A subclass supplies a started store per collection name plus two raw reads: the driver's
 * own view of a document and of a search index, which is how upstream asserts that the
 * embedding and the index really landed.
 */
abstract class MongoDBStoreContractCase extends TestCase
{
    /**
     * A started store (indexes created) on an empty collection.
     */
    abstract protected function makeStore(string $collection, ?HashEmbeddings $embeddings = null, ?MongoDBIndexConfig $indexConfig = null): MongoDBStore;

    /**
     * @param  list<string>          $namespace
     * @return array<string, mixed>|null
     */
    abstract protected function rawDocument(string $collection, array $namespace, string $key): ?array;

    /**
     * @return array<string, mixed>|null The search index definition, or null.
     */
    abstract protected function searchIndex(string $collection, string $name): ?array;

    /** Poll until a vector search returns something: Atlas builds the index asynchronously. */
    protected function searchWithRetry(MongoDBStore $store, SearchOperation $op): array
    {
        $deadline = microtime(true) + 90;
        do {
            /** @var list<SearchItem> $results */
            $results = $store->batch([$op])[0];
            if ($results !== []) {
                return $results;
            }
            usleep(3_000_000);
        } while (microtime(true) < $deadline);

        self::fail('Search returned no results within 90s.');
    }

    protected function vectorCasesEnabled(): bool
    {
        return true;
    }

    /** Whether the server can embed text itself (Atlas with Voyage AI). */
    protected function autoEmbeddingEnabled(): bool
    {
        return false;
    }

    private function store(): MongoDBStore
    {
        return $this->makeStore('test_store');
    }

    // ---- put --------------------------------------------------------------------------

    public function testShouldStoreAndRetrieveAnItem(): void
    {
        $store = $this->store();
        $store->batch([new PutOperation(['put_test', 'profiles'], 'user123', ['name' => 'Alice', 'email' => 'alice@example.com'])]);

        $item = $store->batch([new GetOperation(['put_test', 'profiles'], 'user123')])[0];

        self::assertNotNull($item);
        self::assertSame(['name' => 'Alice', 'email' => 'alice@example.com'], $item->value);
        self::assertSame(['put_test', 'profiles'], $item->namespace);
        self::assertSame('user123', $item->key);
    }

    public function testShouldUpdateAnExistingItem(): void
    {
        $store = $this->store();
        $store->batch([new PutOperation(['put_test'], 'doc1', ['title' => 'Original', 'version' => 1])]);
        $store->batch([new PutOperation(['put_test'], 'doc1', ['title' => 'Updated', 'version' => 2])]);

        $item = $store->batch([new GetOperation(['put_test'], 'doc1')])[0];

        self::assertSame(2, $item->value['version']);
        self::assertSame('Updated', $item->value['title']);
    }

    public function testShouldDeleteAnItemWhenTheValueIsNull(): void
    {
        $store = $this->store();
        $store->batch([new PutOperation(['put_test'], 'to_delete', ['data' => 'will delete'])]);
        $store->batch([new PutOperation(['put_test'], 'to_delete', null)]);

        self::assertNull($store->batch([new GetOperation(['put_test'], 'to_delete')])[0]);
    }

    // ---- get --------------------------------------------------------------------------

    public function testShouldReturnNullForANonExistentItem(): void
    {
        self::assertNull($this->store()->batch([new GetOperation(['nonexistent'], 'missing')])[0]);
    }

    // ---- search -----------------------------------------------------------------------

    private function seedSearch(): MongoDBStore
    {
        $store = $this->store();
        $store->batch([
            new PutOperation(['products'], 'prod1', ['name' => 'Budget Item', 'price' => 29.99, 'category' => 'electronics']),
            new PutOperation(['products'], 'prod2', ['name' => 'Standard Item', 'price' => 99.99, 'category' => 'electronics']),
            new PutOperation(['products'], 'prod3', ['name' => 'Premium Item', 'price' => 299.99, 'category' => 'electronics']),
            new PutOperation(['products'], 'prod4', ['name' => 'Book', 'price' => 19.99, 'category' => 'books']),
            new PutOperation(['search_users'], 'user1', ['username' => 'alice', 'status' => 'active', 'score' => 95]),
            new PutOperation(['search_users'], 'user2', ['username' => 'bob', 'status' => 'inactive', 'score' => 42]),
            new PutOperation(['search_users'], 'user3', ['username' => 'charlie', 'status' => 'active', 'score' => 87]),
            new PutOperation(['search_users', 'profiles'], 'profile1', ['bio' => 'Engineer', 'city' => 'SF']),
            new PutOperation(['search_users', 'profiles'], 'profile2', ['bio' => 'Designer', 'city' => 'NYC']),
        ]);

        return $store;
    }

    /**
     * @param  array<string, mixed> $filter
     * @return list<SearchItem>
     */
    private function searchProducts(MongoDBStore $store, array $filter, int $limit = 100, int $offset = 0, string $prefix = 'products'): array
    {
        return $store->batch([new SearchOperation([$prefix], $filter, $limit, $offset)])[0];
    }

    public function testShouldFilterWithExactMatch(): void
    {
        $items = $this->searchProducts($this->seedSearch(), ['category' => 'electronics']);

        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertSame('electronics', $item->value['category']);
        }
    }

    public function testShouldFilterWithComparisonOperatorGt(): void
    {
        $items = $this->searchProducts($this->seedSearch(), ['price' => ['$gt' => 100]]);

        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertGreaterThan(100, $item->value['price']);
        }
    }

    public function testShouldFilterWithComparisonOperatorLte(): void
    {
        $items = $this->searchProducts($this->seedSearch(), ['price' => ['$lte' => 50]]);

        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertLessThanOrEqual(50, $item->value['price']);
        }
    }

    public function testShouldFilterWithMultipleConditions(): void
    {
        $items = $this->searchProducts($this->seedSearch(), ['category' => 'electronics', 'price' => ['$gte' => 50]]);

        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertSame('electronics', $item->value['category']);
            self::assertGreaterThanOrEqual(50, $item->value['price']);
        }
    }

    public function testShouldMatchNestedNamespacesWhenSearchingByPrefix(): void
    {
        $items = $this->searchProducts($this->seedSearch(), [], prefix: 'search_users');

        $namespaces = array_map(static fn (SearchItem $item): string => json_encode($item->namespace), $items);
        self::assertContains(json_encode(['search_users']), $namespaces);
        self::assertContains(json_encode(['search_users', 'profiles']), $namespaces);
    }

    public function testShouldApplyLimit(): void
    {
        self::assertLessThanOrEqual(2, count($this->searchProducts($this->seedSearch(), [], limit: 2)));
    }

    public function testShouldApplyOffset(): void
    {
        $store = $this->seedSearch();

        $first = $this->searchProducts($store, ['status' => 'active'], prefix: 'search_users');
        $second = $this->searchProducts($store, ['status' => 'active'], offset: 1, prefix: 'search_users');

        self::assertLessThan(count($first), count($second));
    }

    public function testShouldReturnAnEmptyArrayForANonMatchingFilter(): void
    {
        self::assertSame([], $this->searchProducts($this->seedSearch(), ['category' => 'nonexistent']));
    }

    // ---- listNamespaces ---------------------------------------------------------------

    private function seedNamespaces(): MongoDBStore
    {
        $store = $this->store();
        $store->batch([
            new PutOperation(['threads'], 't1', ['id' => 1]),
            new PutOperation(['threads'], 't2', ['id' => 2]),
            new PutOperation(['threads', 'messages'], 'm1', ['text' => 'hi']),
            new PutOperation(['threads', 'messages'], 'm2', ['text' => 'bye']),
            new PutOperation(['ns_users'], 'u1', ['name' => 'Alice']),
            new PutOperation(['ns_users', 'profiles'], 'p1', ['bio' => '...']),
        ]);

        return $store;
    }

    /**
     * @param  list<MatchCondition>|null $conditions
     * @return list<list<string>>
     */
    private function listNamespaces(MongoDBStore $store, ?array $conditions = null, int $limit = 100, int $offset = 0): array
    {
        return $store->batch([new ListNamespacesOperation($conditions, null, $limit, $offset)])[0];
    }

    public function testShouldListAllUniqueNamespaces(): void
    {
        $namespaces = $this->listNamespaces($this->seedNamespaces());

        self::assertContains(['threads'], $namespaces);
        self::assertContains(['threads', 'messages'], $namespaces);
        self::assertContains(['ns_users'], $namespaces);
        self::assertContains(['ns_users', 'profiles'], $namespaces);
    }

    public function testShouldApplyLimitToNamespaces(): void
    {
        self::assertLessThanOrEqual(2, count($this->listNamespaces($this->seedNamespaces(), limit: 2)));
    }

    public function testShouldApplyOffsetToNamespaces(): void
    {
        $store = $this->seedNamespaces();

        self::assertLessThan(count($this->listNamespaces($store)), count($this->listNamespaces($store, offset: 2)));
    }

    public function testShouldFilterByPrefixMatchCondition(): void
    {
        $namespaces = $this->listNamespaces($this->seedNamespaces(), [new MatchCondition(MatchCondition::PREFIX, ['threads'])]);

        self::assertContains(['threads'], $namespaces);
        self::assertContains(['threads', 'messages'], $namespaces);
        self::assertNotContains(['ns_users'], $namespaces);
        self::assertNotContains(['ns_users', 'profiles'], $namespaces);
    }

    public function testShouldFilterBySuffixMatchCondition(): void
    {
        $namespaces = $this->listNamespaces($this->seedNamespaces(), [new MatchCondition(MatchCondition::SUFFIX, ['profiles'])]);

        self::assertContains(['ns_users', 'profiles'], $namespaces);
        self::assertNotContains(['threads'], $namespaces);
        self::assertNotContains(['ns_users'], $namespaces);
    }

    public function testShouldSupportAWildcardInAMatchCondition(): void
    {
        $namespaces = $this->listNamespaces($this->seedNamespaces(), [new MatchCondition(MatchCondition::PREFIX, ['*', 'messages'])]);

        self::assertContains(['threads', 'messages'], $namespaces);
        self::assertNotContains(['threads'], $namespaces);
        self::assertNotContains(['ns_users'], $namespaces);
    }

    // ---- batch ------------------------------------------------------------------------

    public function testShouldExecuteMixedOperationsInOneBatch(): void
    {
        $results = $this->store()->batch([
            new PutOperation(['batch_test'], 'item1', ['num' => 1]),
            new PutOperation(['batch_test'], 'item2', ['num' => 2]),
            new GetOperation(['batch_test'], 'item1'),
        ]);

        self::assertNull($results[0]);
        self::assertNull($results[1]);
        self::assertSame(['num' => 1], $results[2]->value);
    }

    // ---- vector search: manual embedding ----------------------------------------------

    private function manualStore(): MongoDBStore
    {
        if (!$this->vectorCasesEnabled()) {
            self::markTestSkipped('Vector search needs Atlas (set TEST_MONGODB_VECTORSEARCH=1 on an atlas-local container).');
        }

        $store = $this->makeStore('test_manual_embedding', new HashEmbeddings(), new MongoDBIndexConfig(name: 'test_manual_index', dims: 10));
        $store->batch([
            new PutOperation(['docs', 'ai'], 'ml', ['content' => 'Machine learning algorithms for classification']),
            new PutOperation(['docs', 'ai'], 'dl', ['content' => 'Deep neural networks and backpropagation']),
            new PutOperation(['docs', 'db'], 'idx', ['content' => 'Database indexing strategies for performance']),
            new PutOperation(['docs', 'db'], 'sql', ['content' => 'SQL query optimization techniques']),
        ]);

        return $store;
    }

    public function testManualShouldCreateAVectorSearchIndexWithAVectorField(): void
    {
        $this->manualStore();

        $index = $this->searchIndex('test_manual_embedding', 'test_manual_index');
        self::assertNotNull($index);
        $vectorFields = array_values(array_filter($index['fields'], static fn (array $f): bool => $f['type'] === 'vector'));
        self::assertCount(1, $vectorFields);
        self::assertSame('embedding', $vectorFields[0]['path']);
        self::assertSame(10, $vectorFields[0]['numDimensions']);
    }

    public function testManualShouldStoreTheEmbeddingVectorOnPut(): void
    {
        $this->manualStore();

        $doc = $this->rawDocument('test_manual_embedding', ['docs', 'ai'], 'ml');
        self::assertNotNull($doc);
        self::assertIsArray($doc['embedding']);
        self::assertCount(10, $doc['embedding']);
        foreach ($doc['embedding'] as $component) {
            self::assertIsFloat($component);
        }
    }

    public function testManualShouldStoreNamespacePathOnPut(): void
    {
        $this->manualStore();

        self::assertSame(['docs', 'docs/ai'], $this->rawDocument('test_manual_embedding', ['docs', 'ai'], 'ml')['namespacePath']);
    }

    public function testManualShouldSkipEmbeddingWhenTheOperationIndexIsFalse(): void
    {
        $store = $this->manualStore();
        $store->batch([new PutOperation(['docs', 'skip'], 'skip1', ['content' => 'Should not embed'], false)]);

        $doc = $this->rawDocument('test_manual_embedding', ['docs', 'skip'], 'skip1');
        self::assertNotNull($doc);
        self::assertArrayNotHasKey('embedding', $doc);
    }

    public function testManualShouldReturnScoredResultsRankedBySimilarity(): void
    {
        $results = $this->searchWithRetry($this->manualStore(), new SearchOperation(['docs'], null, 4, 0, 'neural networks'));

        self::assertGreaterThan(1, count($results));
        self::assertIsFloat($results[0]->score);
        // Only the order is checkable: the test embeddings are hash-based, not a real model.
        for ($i = 0; $i < count($results) - 1; $i++) {
            self::assertGreaterThanOrEqual($results[$i + 1]->score, $results[$i]->score);
        }
    }

    public function testManualShouldScopeResultsToTheSearchedNamespacePrefix(): void
    {
        $results = $this->searchWithRetry($this->manualStore(), new SearchOperation(['docs', 'db'], null, 10, 0, 'database performance'));

        self::assertNotEmpty($results);
        foreach ($results as $item) {
            self::assertSame(['docs', 'db'], array_slice($item->namespace, 0, 2));
        }
    }

    // ---- vector search: auto embedding (Atlas + Voyage AI only) -----------------------

    private function autoStore(): MongoDBStore
    {
        if (!$this->vectorCasesEnabled() || !$this->autoEmbeddingEnabled()) {
            self::markTestSkipped('Auto embedding needs Atlas with Voyage AI (set TEST_MONGODB_VECTORSEARCH and TEST_MONGODB_AUTOEMBEDDING).');
        }

        $store = $this->makeStore('test_auto_embedding', null, new MongoDBIndexConfig(name: 'test_auto_index', path: 'value.content', model: 'voyage-4'));
        $store->batch([
            new PutOperation(['docs', 'ai'], 'ml', ['content' => 'Machine learning algorithms for classification']),
            new PutOperation(['docs', 'ai'], 'dl', ['content' => 'Deep neural networks and backpropagation']),
            new PutOperation(['docs', 'db'], 'idx', ['content' => 'Database indexing strategies for performance']),
            new PutOperation(['docs', 'db'], 'sql', ['content' => 'SQL query optimization techniques']),
        ]);

        return $store;
    }

    public function testAutoShouldCreateAVectorSearchIndexWithAnAutoEmbedField(): void
    {
        $this->autoStore();

        $index = $this->searchIndex('test_auto_embedding', 'test_auto_index');
        self::assertNotNull($index);
        $fields = array_values(array_filter($index['fields'], static fn (array $f): bool => $f['type'] === 'autoEmbed'));
        self::assertCount(1, $fields);
        self::assertSame('value.content', $fields[0]['path']);
    }

    public function testAutoShouldStoreDocumentsWithoutASeparateEmbeddingField(): void
    {
        $this->autoStore();

        $doc = $this->rawDocument('test_auto_embedding', ['docs', 'ai'], 'ml');
        self::assertNotNull($doc);
        self::assertArrayNotHasKey('embedding', $doc);
        self::assertArrayHasKey('content', $doc['value']);
    }

    public function testAutoShouldReturnTheMostRelevantResultFirst(): void
    {
        $results = $this->searchWithRetry($this->autoStore(), new SearchOperation(['docs'], null, 4, 0, 'neural networks'));

        self::assertGreaterThan(1, count($results));
        self::assertStringContainsString('neural networks', $results[0]->value['content']);
        for ($i = 0; $i < count($results) - 1; $i++) {
            self::assertGreaterThanOrEqual($results[$i + 1]->score, $results[$i]->score);
        }
    }

    public function testAutoShouldScopeResultsToTheSearchedNamespacePrefix(): void
    {
        $results = $this->searchWithRetry($this->autoStore(), new SearchOperation(['docs', 'db'], null, 10, 0, 'database performance'));

        self::assertNotEmpty($results);
        foreach ($results as $item) {
            self::assertSame(['docs', 'db'], array_slice($item->namespace, 0, 2));
        }
    }
}
