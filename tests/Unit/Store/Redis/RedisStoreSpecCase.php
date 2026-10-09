<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Redis;

use LangGraph\Checkpoint\Redis\RedisClientInterface;
use LangGraph\Checkpoint\Redis\TtlConfig;
use LangGraph\Store\GetOperation;
use LangGraph\Store\InvalidNamespaceError;
use LangGraph\Store\Item;
use LangGraph\Store\ListNamespacesOperation;
use LangGraph\Store\MatchCondition;
use LangGraph\Store\PutOperation;
use LangGraph\Store\Redis\RedisStore;
use LangGraph\Store\Redis\RedisStoreIndexConfig;
use LangGraph\Store\SearchOperation;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tests/store.int.test.ts` (`RedisStore`, `RedisStore Comprehensive Tests`,
 * `RedisStore Advanced Features`, `RedisStore Vector Search with Distance Metrics`), minus the
 * `FilterBuilder` and `Operation Type Guards` blocks, which need no server and live in
 * `FilterBuilderTest` and `RedisStoreOperationGuardsTest`.
 *
 * Subclasses say where the client comes from: `RedisStoreTest` runs every scenario against
 * {@see \LangChain\Tests\Unit\Checkpoint\FakeRedisClient}, `RedisStoreIntegrationTest` against a
 * real Redis Stack server. Upstream gives each test its own container; here `newClient()` returns
 * a client on an empty keyspace and `pass()` lets TTLs run out (the fake's clock jumps, a real
 * server is waited for).
 */
abstract class RedisStoreSpecCase extends TestCase
{
    /** 2 seconds, as minutes. */
    private const TWO_SECONDS = 2 / 60;

    protected RedisClientInterface $client;

    /** A client on an empty store keyspace. */
    abstract protected function newClient(): RedisClientInterface;

    /** Let `$seconds` of TTL elapse. */
    abstract protected function pass(float $seconds): void;

    /**
     * A store built the way `RedisStore.fromConnString` builds one, over a connection of its own.
     */
    abstract protected function connect(?RedisStoreIndexConfig $index = null, ?TtlConfig $ttl = null): RedisStore;

    protected function setUp(): void
    {
        $this->client = $this->newClient();
    }

    protected function store(?RedisStoreIndexConfig $index = null, ?TtlConfig $ttl = null): RedisStore
    {
        $store = new RedisStore($this->client, $index, $ttl);
        $store->setup();

        return $store;
    }

    private function ttlStore(): RedisStore
    {
        return $this->store(ttl: new TtlConfig(defaultTtl: self::TWO_SECONDS, refreshOnRead: true));
    }

    /** @return list<string> */
    private static function titles(array $items): array
    {
        $titles = array_map(static fn (Item $i): string => (string) $i->value['title'], $items);
        sort($titles);

        return $titles;
    }

    /**
     * @param  list<Item> $items
     * @return list<string>
     */
    private static function names(array $items): array
    {
        $names = array_map(static fn (Item $i): string => (string) $i->value['name'], $items);
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private static function keys(array $items): array
    {
        $keys = array_map(static fn (Item $i): string => $i->key, $items);
        sort($keys);

        return $keys;
    }

    private static function assertRejects(callable $call, string $message): void
    {
        try {
            $call();
        } catch (InvalidNamespaceError $e) {
            self::assertStringContainsString($message, $e->getMessage());

            return;
        }
        self::fail('Expected InvalidNamespaceError containing: ' . $message);
    }

    // ===== RedisStore / Basic Operations ======================================

    public function testShouldPutAndGetAnItem(): void
    {
        $store = $this->ttlStore();
        $value = ['title' => 'Test Document', 'content' => 'Hello, World!'];

        $store->put(['test', 'documents'], 'doc1', $value);
        $item = $store->get(['test', 'documents'], 'doc1');

        self::assertNotNull($item);
        self::assertSame(['test', 'documents'], $item->namespace);
        self::assertSame('doc1', $item->key);
        self::assertSame($value, $item->value);
    }

    public function testShouldUpdateAnExistingItem(): void
    {
        $store = $this->ttlStore();
        $namespace = ['test', 'documents'];

        $store->put($namespace, 'doc1', ['title' => 'Test Document', 'content' => 'Hello, World!']);
        $item = $store->get($namespace, 'doc1');

        $updatedValue = ['title' => 'Updated Document', 'content' => 'Hello, Updated!'];
        $store->put($namespace, 'doc1', $updatedValue);
        $updated = $store->get($namespace, 'doc1');

        self::assertSame($updatedValue, $updated?->value);
        self::assertGreaterThan($item->updatedAt, $updated->updatedAt);
        self::assertEquals($item->createdAt, $updated->createdAt, 'an update keeps the creation time');
        self::assertCount(1, $store->search($namespace), 'the old document is replaced, not duplicated');
    }

    public function testShouldReturnNullForNonExistentItem(): void
    {
        self::assertNull($this->ttlStore()->get(['test'], 'nonexistent'));
    }

    public function testShouldDeleteAnItem(): void
    {
        $store = $this->ttlStore();

        $store->put(['test'], 'doc1', ['data' => 'test']);
        self::assertNotNull($store->get(['test'], 'doc1'));

        $store->delete(['test'], 'doc1');
        self::assertNull($store->get(['test'], 'doc1'));
    }

    // ===== Batch Operations ===================================================

    public function testShouldHandleBatchOperationsInCorrectOrder(): void
    {
        $store = $this->ttlStore();
        $store->put(['test', 'foo'], 'key1', ['data' => 'value1']);
        $store->put(['test', 'bar'], 'key2', ['data' => 'value2']);

        $results = $store->batch([
            new GetOperation(['test', 'foo'], 'key1'),
            new PutOperation(['test', 'bar'], 'key2', ['data' => 'value2']),
            new PutOperation(['test', 'baz'], 'key3', ['data' => 'value3']),
            new GetOperation(['test', 'baz'], 'key3'),
        ]);

        self::assertCount(4, $results);
        self::assertSame(['data' => 'value1'], $results[0]->value);
        self::assertNull($results[1]);
        self::assertNull($results[2]);
        self::assertSame(['data' => 'value3'], $results[3]->value);
    }

    public function testShouldHandleMultiplePutOperations(): void
    {
        $store = $this->ttlStore();

        $store->batch([
            new PutOperation(['batch', 'test'], 'item1', ['id' => 1]),
            new PutOperation(['batch', 'test'], 'item2', ['id' => 2]),
            new PutOperation(['batch', 'test'], 'item3', ['id' => 3]),
        ]);

        self::assertSame(1, $store->get(['batch', 'test'], 'item1')?->value['id']);
        self::assertSame(2, $store->get(['batch', 'test'], 'item2')?->value['id']);
        self::assertSame(3, $store->get(['batch', 'test'], 'item3')?->value['id']);
    }

    // ===== Search Operations ==================================================

    public function testShouldSearchAllItemsInNamespace(): void
    {
        $store = $this->ttlStore();
        $namespace = ['search', 'test'];
        $store->put($namespace, 'item1', ['type' => 'doc', 'title' => 'First']);
        $store->put($namespace, 'item2', ['type' => 'doc', 'title' => 'Second']);
        $store->put($namespace, 'item3', ['type' => 'note', 'title' => 'Third']);

        $results = $store->search($namespace);

        self::assertCount(3, $results);
        self::assertSame(['First', 'Second', 'Third'], self::titles($results));
    }

    public function testShouldFilterByNamespacePrefix(): void
    {
        $store = $this->ttlStore();
        $store->put(['docs', 'public'], 'doc1', ['title' => 'Public Doc']);
        $store->put(['docs', 'private'], 'doc2', ['title' => 'Private Doc']);
        $store->put(['notes', 'personal'], 'note1', ['title' => 'Personal Note']);

        self::assertCount(2, $store->search(['docs']));

        $publicResults = $store->search(['docs', 'public']);
        self::assertCount(1, $publicResults);
        self::assertSame('Public Doc', $publicResults[0]->value['title']);
    }

    public function testShouldFilterByValueProperties(): void
    {
        $store = $this->ttlStore();
        $namespace = ['filter', 'test'];
        $store->put($namespace, 'item1', ['type' => 'doc', 'status' => 'draft']);
        $store->put($namespace, 'item2', ['type' => 'doc', 'status' => 'published']);
        $store->put($namespace, 'item3', ['type' => 'note', 'status' => 'draft']);

        $results = $store->search($namespace, ['filter' => ['type' => 'doc']]);

        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertSame('doc', $result->value['type']);
        }
    }

    public function testShouldHandlePagination(): void
    {
        $store = $this->ttlStore();
        $namespace = ['pagination', 'test'];
        for ($i = 0; $i < 10; $i++) {
            $store->put($namespace, "item{$i}", ['index' => $i]);
        }

        $page1 = $store->search($namespace, ['limit' => 3, 'offset' => 0]);
        $page2 = $store->search($namespace, ['limit' => 3, 'offset' => 3]);

        self::assertCount(3, $page1);
        self::assertCount(3, $page2);
        self::assertNotSame(self::keys($page1), self::keys($page2));
        self::assertSame([], array_intersect(self::keys($page1), self::keys($page2)), 'pages do not overlap');
    }

    // ===== List Namespaces ====================================================

    private function seededNamespaces(): RedisStore
    {
        $store = $this->ttlStore();
        foreach ([
            ['test', 'documents', 'public'],
            ['test', 'documents', 'private'],
            ['test', 'images', 'public'],
            ['test', 'images', 'private'],
            ['prod', 'documents', 'public'],
            ['prod', 'documents', 'private'],
        ] as $namespace) {
            $store->put($namespace, 'dummy', ['content' => 'dummy']);
        }

        return $store;
    }

    public function testShouldListAllNamespaces(): void
    {
        self::assertGreaterThanOrEqual(6, count($this->seededNamespaces()->listNamespaces()));
    }

    public function testListNamespacesShouldFilterByPrefix(): void
    {
        $namespaces = $this->seededNamespaces()->listNamespaces(['prefix' => ['test']]);

        self::assertCount(4, $namespaces);
        foreach ($namespaces as $namespace) {
            self::assertSame('test', $namespace[0]);
        }
    }

    public function testListNamespacesShouldFilterBySuffix(): void
    {
        $namespaces = $this->seededNamespaces()->listNamespaces(['suffix' => ['public']]);

        self::assertCount(3, $namespaces);
        foreach ($namespaces as $namespace) {
            self::assertSame('public', $namespace[count($namespace) - 1]);
        }
    }

    public function testShouldLimitDepth(): void
    {
        $namespaces = $this->seededNamespaces()->listNamespaces(['maxDepth' => 2]);

        self::assertSame([['prod', 'documents'], ['test', 'documents'], ['test', 'images']], $namespaces);
    }

    public function testListNamespacesShouldHandlePagination(): void
    {
        $store = $this->seededNamespaces();

        self::assertCount(3, $store->listNamespaces(['limit' => 3]));
        self::assertSame(
            [['prod', 'documents', 'private'], ['prod', 'documents', 'public'], ['test', 'documents', 'private']],
            $store->listNamespaces(['limit' => 3]),
            'namespaces come back sorted',
        );
        self::assertSame(
            [['test', 'documents', 'public'], ['test', 'images', 'private'], ['test', 'images', 'public']],
            $store->listNamespaces(['limit' => 3, 'offset' => 3]),
        );
    }

    // ===== TTL Support ========================================================

    public function testShouldExpireItemsWithTtl(): void
    {
        $store = $this->ttlStore();
        $value = ['data' => 'will expire'];

        $store->put(['ttl', 'test'], 'expiring-item', $value);
        self::assertSame($value, $store->get(['ttl', 'test'], 'expiring-item')?->value);

        $this->pass(3);

        self::assertNull($store->get(['ttl', 'test'], 'expiring-item'));
    }

    public function testShouldRefreshTtlOnReadWhenConfigured(): void
    {
        $store = $this->ttlStore();
        $value = ['data' => 'should refresh'];

        $store->put(['ttl', 'refresh'], 'refreshed-item', $value);

        $this->pass(1);
        self::assertSame($value, $store->get(['ttl', 'refresh'], 'refreshed-item', refreshTtl: true)?->value);

        $this->pass(1.5);
        self::assertSame($value, $store->get(['ttl', 'refresh'], 'refreshed-item')?->value, 'alive at 2.5s only because the read re-armed the TTL');
    }

    // ===== Vector Search ======================================================

    private function vectorStore(): RedisStore
    {
        return $this->store(
            new RedisStoreIndexConfig(dims: 4, embeddings: CallbackEmbeddings::characters(), distanceType: 'cosine', fields: ['text']),
            new TtlConfig(defaultTtl: 2, refreshOnRead: true),
        );
    }

    public function testShouldPerformVectorSearch(): void
    {
        $store = $this->vectorStore();
        foreach (['doc1' => 'short text', 'doc2' => 'longer text document', 'doc3' => 'longest text document here'] as $key => $text) {
            $store->put(['test'], $key, ['text' => $text]);
        }

        $results = $store->search(['test'], ['query' => 'longer text']);

        self::assertGreaterThanOrEqual(2, count($results));
        $keys = array_map(static fn (Item $i): string => $i->key, $results);
        self::assertContains('doc2', $keys);
        self::assertContains('doc3', $keys);
    }

    public function testShouldFilterVectorSearchResults(): void
    {
        $store = $this->vectorStore();
        $docs = [
            'doc1' => ['text' => 'red apple', 'color' => 'red', 'score' => 4.5],
            'doc2' => ['text' => 'red car', 'color' => 'red', 'score' => 3.0],
            'doc3' => ['text' => 'green apple', 'color' => 'green', 'score' => 4.0],
            'doc4' => ['text' => 'blue car', 'color' => 'blue', 'score' => 3.5],
        ];
        foreach ($docs as $key => $value) {
            $store->put(['test'], $key, $value);
        }

        $results = $store->search(['test'], ['query' => 'red', 'filter' => ['color' => 'red']]);

        self::assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $result) {
            self::assertSame('red', $result->value['color']);
        }
    }

    public function testShouldUpdateEmbeddingsWhenDocumentChanges(): void
    {
        $store = $this->vectorStore();

        $store->put(['test'], 'updateable', ['text' => 'original content']);
        $store->put(['test'], 'updateable', ['text' => 'updated content here']);

        $results = $store->search(['test'], ['query' => 'updated']);
        self::assertGreaterThanOrEqual(1, count($results));
        self::assertCount(1, $results, 'the first document\'s vector was deleted with it');
        self::assertSame('updated content here', $results[0]->value['text']);
    }

    // ===== Comprehensive: fromConnString ======================================

    public function testShouldCreateARedisStoreFromConnectionString(): void
    {
        $store = $this->connect();

        $store->put(['fromUrl', 'test'], 'testkey', ['data' => 'test value']);
        $retrieved = $store->get(['fromUrl', 'test'], 'testkey');

        self::assertSame(['data' => 'test value'], $retrieved?->value);
        self::assertSame(['fromUrl', 'test'], $retrieved->namespace);
        self::assertSame('testkey', $retrieved->key);

        $store->close();
    }

    public function testShouldCreateARedisStoreWithTtlConfigFromConnectionString(): void
    {
        $store = $this->connect(ttl: new TtlConfig(defaultTtl: 1 / 60, refreshOnRead: false));
        $value = ['data' => 'will expire'];

        $store->put(['fromUrl', 'ttl'], 'ttlkey', $value);
        self::assertSame($value, $store->get(['fromUrl', 'ttl'], 'ttlkey')?->value);

        $this->pass(1.5);

        self::assertNull($store->get(['fromUrl', 'ttl'], 'ttlkey'));
        $store->close();
    }

    // ===== Comprehensive: Namespace Validation ================================

    public function testShouldNotAllowEmptyNamespace(): void
    {
        $store = $this->store();

        self::assertRejects(fn () => $store->put([], 'key', ['foo' => 'bar']), 'Namespace cannot be empty');
    }

    public function testShouldNotAllowNamespaceLabelsWithPeriods(): void
    {
        $store = $this->store();

        self::assertRejects(fn () => $store->put(['invalid.namespace'], 'key', ['foo' => 'bar']), 'Namespace labels cannot contain periods');
    }

    public function testShouldNotAllowEmptyNamespaceLabel(): void
    {
        $store = $this->store();

        self::assertRejects(fn () => $store->put(['valid', ''], 'key', ['foo' => 'bar']), 'Namespace labels cannot be empty strings');
    }

    public function testShouldNotAllowNonStringNamespaceLabel(): void
    {
        $store = $this->store();

        self::assertRejects(fn () => $store->put(['valid', 123], 'key', ['foo' => 'bar']), 'Namespace labels must be strings');
    }

    public function testShouldNotAllowReservedNamespaceLabelLanggraph(): void
    {
        $store = $this->store();

        self::assertRejects(fn () => $store->put(['langgraph'], 'key', ['foo' => 'bar']), 'Root label for namespace cannot be "langgraph"');
    }

    public function testShouldAllowLanggraphAsNonRootNamespaceLabel(): void
    {
        $store = $this->store();
        $namespace = ['foo', 'langgraph', 'bar'];

        $store->put($namespace, 'key', ['data' => 'test']);
        $item = $store->get($namespace, 'key');

        self::assertNotNull($item);
        self::assertSame(['data' => 'test'], $item->value);
        self::assertSame($namespace, $item->namespace);

        $store->delete($namespace, 'key');
        self::assertNull($store->get($namespace, 'key'));
    }

    // ===== Comprehensive: Complex JSON Values =================================

    public function testShouldHandleComplexNestedJsonValues(): void
    {
        $store = $this->store();
        $complexValue = [
            'string' => 'test',
            'number' => 42,
            'boolean' => true,
            'null' => null,
            'array' => [1, 2, 3, 'four', [5, 6]],
            'nested' => [
                'deep' => [
                    'value' => 'nested data',
                    'deeper' => [
                        'array' => [['id' => 1], ['id' => 2]],
                        'timestamp' => '2026-10-08T12:00:00.000Z',
                    ],
                ],
            ],
            'unicode' => 'emoji 🎉 and special chars: äöü',
        ];

        $store->put(['complex', 'json'], 'nested', $complexValue);

        self::assertSame($complexValue, $store->get(['complex', 'json'], 'nested')?->value);
    }

    public function testShouldHandleLargeJsonValues(): void
    {
        $store = $this->store();
        $items = [];
        for ($i = 0; $i < 1000; $i++) {
            $items[] = ['id' => $i, 'data' => "item-{$i}", 'nested' => ['value' => $i * 2]];
        }
        $largeValue = ['items' => $items, 'metadata' => ['count' => 1000, 'timestamp' => '2026-10-08T12:00:00.000Z']];

        $store->put(['large', 'json'], 'big', $largeValue);
        $retrieved = $store->get(['large', 'json'], 'big');

        self::assertSame($largeValue, $retrieved?->value);
        self::assertCount(1000, $retrieved->value['items']);
    }

    // ===== Comprehensive: Batch with Mixed Types ==============================

    public function testShouldHandleBatchWithAllOperationTypes(): void
    {
        $store = $this->store();
        $store->put(['batch', 'test'], 'item1', ['value' => 1]);
        $store->put(['batch', 'test'], 'item2', ['value' => 2]);
        $store->put(['batch', 'other'], 'item3', ['value' => 3]);

        $results = $store->batch([
            new GetOperation(['batch', 'test'], 'item1'),
            new PutOperation(['batch', 'test'], 'item4', ['value' => 4]),
            new SearchOperation(['batch'], ['value' => 2], 10, 0),
            new ListNamespacesOperation([new MatchCondition(MatchCondition::PREFIX, ['batch'])], null, 10, 0),
        ]);

        self::assertCount(4, $results);
        self::assertSame(['value' => 1], $results[0]->value);
        self::assertNull($results[1]);
        self::assertCount(1, $results[2]);
        self::assertSame('item2', $results[2][0]->key);
        self::assertSame([['batch', 'other'], ['batch', 'test']], $results[3]);
    }

    public function testShouldMaintainOperationOrderInBatch(): void
    {
        $store = $this->store();

        $results = $store->batch([
            new PutOperation(['order', 'test'], 'item1', ['step' => 1]),
            new PutOperation(['order', 'test'], 'item2', ['step' => 2]),
            new GetOperation(['order', 'test'], 'item1'),
            new GetOperation(['order', 'test'], 'item2'),
        ]);

        self::assertCount(4, $results);
        self::assertNull($results[0]);
        self::assertNull($results[1]);
        self::assertSame(1, $results[2]->value['step']);
        self::assertSame(2, $results[3]->value['step']);
    }

    // ===== Comprehensive: Search with Complex Filters =========================

    public function testShouldFilterByBooleanValues(): void
    {
        $store = $this->store();
        $namespace = ['filter', 'boolean'];
        $store->put($namespace, 'item1', ['active' => true, 'type' => 'user']);
        $store->put($namespace, 'item2', ['active' => false, 'type' => 'user']);
        $store->put($namespace, 'item3', ['active' => true, 'type' => 'admin']);

        $activeItems = $store->search($namespace, ['filter' => ['active' => true]]);

        self::assertCount(2, $activeItems);
        foreach ($activeItems as $item) {
            self::assertTrue($item->value['active']);
        }
    }

    public function testShouldFilterByArrayValues(): void
    {
        $store = $this->store();
        $namespace = ['filter', 'array'];
        $store->put($namespace, 'item1', ['tags' => ['red', 'blue'], 'type' => 'A']);
        $store->put($namespace, 'item2', ['tags' => ['green', 'blue'], 'type' => 'B']);
        $store->put($namespace, 'item3', ['tags' => ['red', 'yellow'], 'type' => 'A']);

        $results = $store->search($namespace, ['filter' => ['type' => 'A']]);

        self::assertCount(2, $results);
        foreach ($results as $item) {
            self::assertSame('A', $item->value['type']);
        }
        self::assertSame(['item1', 'item3'], self::keys($store->search($namespace, ['filter' => ['tags' => 'red']])), 'a scalar matches an array holding it');
    }

    public function testShouldFilterByMultipleConditions(): void
    {
        $store = $this->store();
        $namespace = ['filter', 'multiple'];
        $store->put($namespace, 'item1', ['category' => 'A', 'status' => 'active', 'priority' => 1]);
        $store->put($namespace, 'item2', ['category' => 'A', 'status' => 'inactive', 'priority' => 2]);
        $store->put($namespace, 'item3', ['category' => 'B', 'status' => 'active', 'priority' => 1]);

        $filtered = $store->search($namespace, ['filter' => ['category' => 'A', 'status' => 'active']]);

        self::assertCount(1, $filtered);
        self::assertSame('A', $filtered[0]->value['category']);
        self::assertSame('active', $filtered[0]->value['status']);
    }

    public function testShouldHandleEmptySearchResults(): void
    {
        $store = $this->store();
        $namespace = ['empty', 'search'];
        $store->put($namespace, 'item1', ['type' => 'A']);
        $store->put($namespace, 'item2', ['type' => 'B']);

        self::assertSame([], $store->search($namespace, ['filter' => ['type' => 'C']]));
    }

    public function testShouldRespectSearchPagination(): void
    {
        $store = $this->store();
        $namespace = ['pagination', 'search'];
        for ($i = 0; $i < 20; $i++) {
            $store->put($namespace, "item{$i}", ['index' => $i, 'category' => 'test']);
        }

        $page1 = $store->search($namespace, ['filter' => ['category' => 'test'], 'limit' => 5, 'offset' => 0]);
        $page2 = $store->search($namespace, ['filter' => ['category' => 'test'], 'limit' => 5, 'offset' => 5]);

        self::assertCount(5, $page1);
        self::assertCount(5, $page2);
        self::assertNotSame(self::keys($page1), self::keys($page2));
    }

    // ===== Advanced: Search Operators =========================================

    private function productsStore(): RedisStore
    {
        $store = $this->store();
        $store->put(['products', 'electronics'], 'laptop1', ['name' => 'Laptop Pro', 'price' => 1200, 'stock' => 10, 'tags' => ['electronics', 'computers'], 'specs' => ['ram' => 16, 'storage' => 512]]);
        $store->put(['products', 'electronics'], 'laptop2', ['name' => 'Laptop Air', 'price' => 800, 'stock' => 5, 'tags' => ['electronics', 'computers', 'portable'], 'specs' => ['ram' => 8, 'storage' => 256]]);
        $store->put(['products', 'electronics'], 'phone1', ['name' => 'Phone X', 'price' => 600, 'stock' => 20, 'tags' => ['electronics', 'mobile'], 'specs' => ['ram' => 6, 'storage' => 128]]);
        $store->put(['products', 'furniture'], 'chair1', ['name' => 'Office Chair', 'price' => 250, 'stock' => 15, 'tags' => ['furniture', 'office'], 'material' => 'leather']);

        return $store;
    }

    public function testShouldSupportGtOperator(): void
    {
        $results = $this->productsStore()->search(['products'], ['filter' => ['price' => ['$gt' => 700]]]);

        self::assertCount(2, $results);
        self::assertSame(['Laptop Air', 'Laptop Pro'], self::names($results));
    }

    public function testShouldSupportGteOperator(): void
    {
        $results = $this->productsStore()->search(['products'], ['filter' => ['price' => ['$gte' => 800]]]);

        self::assertCount(2, $results);
        self::assertSame(['Laptop Air', 'Laptop Pro'], self::names($results));
    }

    public function testShouldSupportLtOperator(): void
    {
        $results = $this->productsStore()->search(['products'], ['filter' => ['price' => ['$lt' => 600]]]);

        self::assertCount(1, $results);
        self::assertSame('Office Chair', $results[0]->value['name']);
    }

    public function testShouldSupportLteOperator(): void
    {
        $results = $this->productsStore()->search(['products'], ['filter' => ['price' => ['$lte' => 600]]]);

        self::assertCount(2, $results);
        self::assertSame(['Office Chair', 'Phone X'], self::names($results));
    }

    public function testShouldSupportInOperator(): void
    {
        $results = $this->productsStore()->search(['products'], ['filter' => ['name' => ['$in' => ['Laptop Pro', 'Phone X', 'Unknown']]]]);

        self::assertCount(2, $results);
        self::assertSame(['Laptop Pro', 'Phone X'], self::names($results));
    }

    public function testShouldSupportNinOperator(): void
    {
        $results = $this->productsStore()->search(['products'], ['filter' => ['name' => ['$nin' => ['Laptop Pro', 'Phone X']]]]);

        self::assertCount(2, $results);
        self::assertSame(['Laptop Air', 'Office Chair'], self::names($results));
    }

    public function testShouldSupportExistsOperator(): void
    {
        $store = $this->productsStore();

        $with = $store->search(['products'], ['filter' => ['material' => ['$exists' => true]]]);
        self::assertCount(1, $with);
        self::assertSame('Office Chair', $with[0]->value['name']);

        self::assertCount(3, $store->search(['products'], ['filter' => ['material' => ['$exists' => false]]]));
    }

    public function testShouldSupportEqOperator(): void
    {
        $results = $this->productsStore()->search(['products'], ['filter' => ['price' => ['$eq' => 600]]]);

        self::assertCount(1, $results);
        self::assertSame('Phone X', $results[0]->value['name']);
    }

    public function testShouldSupportNeOperator(): void
    {
        $results = $this->productsStore()->search(['products'], ['filter' => ['price' => ['$ne' => 600]]]);

        self::assertCount(3, $results);
        self::assertSame(['Laptop Air', 'Laptop Pro', 'Office Chair'], self::names($results));
    }

    // ===== Advanced: Complex Filter Combinations ==============================

    private function usersStore(): RedisStore
    {
        $store = $this->store();
        $store->put(['users'], 'user1', ['name' => 'Alice', 'age' => 25, 'city' => 'New York', 'active' => true]);
        $store->put(['users'], 'user2', ['name' => 'Bob', 'age' => 30, 'city' => 'San Francisco', 'active' => true]);
        $store->put(['users'], 'user3', ['name' => 'Charlie', 'age' => 35, 'city' => 'New York', 'active' => false]);
        $store->put(['users'], 'user4', ['name' => 'David', 'age' => 28, 'city' => 'Los Angeles', 'active' => true]);

        return $store;
    }

    public function testShouldCombineMultipleFiltersWithAndLogic(): void
    {
        $results = $this->usersStore()->search(['users'], ['filter' => ['age' => ['$gte' => 25, '$lte' => 30], 'active' => true]]);

        self::assertCount(3, $results);
        self::assertSame(['Alice', 'Bob', 'David'], self::names($results));
    }

    public function testShouldHandleNestedObjectQueries(): void
    {
        $store = $this->usersStore();
        $store->put(['products'], 'prod1', ['name' => 'Product 1', 'details' => ['category' => 'electronics', 'subcategory' => 'computers']]);
        $store->put(['products'], 'prod2', ['name' => 'Product 2', 'details' => ['category' => 'electronics', 'subcategory' => 'phones']]);

        $results = $store->search(['products'], ['filter' => ['details.category' => 'electronics', 'details.subcategory' => 'computers']]);

        self::assertCount(1, $results);
        self::assertSame('Product 1', $results[0]->value['name']);
    }

    public function testShouldCombineInWithOtherOperators(): void
    {
        $results = $this->usersStore()->search(['users'], ['filter' => ['city' => ['$in' => ['New York', 'San Francisco']], 'age' => ['$lt' => 30]]]);

        self::assertCount(1, $results);
        self::assertSame('Alice', $results[0]->value['name']);
    }

    // ===== Advanced: Store Statistics =========================================

    public function testShouldReturnAccurateStatistics(): void
    {
        $store = $this->store();
        $store->put(['namespace1'], 'key1', ['value' => 'data1']);
        $store->put(['namespace1'], 'key2', ['value' => 'data2']);
        $store->put(['namespace2'], 'key3', ['value' => 'data3']);

        $stats = $store->stats();

        self::assertSame(3, $stats['totalDocuments']);
        self::assertSame(2, $stats['namespaceCount']);
        self::assertArrayNotHasKey('vectorDocuments', $stats, 'only reported when an index is configured');
        self::assertSame($stats, $store->getStatistics());
    }

    public function testShouldHandleEmptyStore(): void
    {
        $stats = $this->store()->stats();

        self::assertSame(0, $stats['totalDocuments']);
        self::assertSame(0, $stats['namespaceCount']);
    }

    public function testStatisticsReportVectorDocumentsAndIndexInfoWhenAnIndexIsConfigured(): void
    {
        $store = $this->store(new RedisStoreIndexConfig(dims: 4, embeddings: CallbackEmbeddings::characters(), fields: ['text']));
        $store->put(['docs'], 'a', ['text' => 'alpha']);
        $store->put(['docs'], 'b', ['text' => 'beta']);
        $store->put(['docs'], 'c', ['title' => 'nothing to embed']);

        $stats = $store->stats();

        self::assertSame(3, $stats['totalDocuments']);
        self::assertSame(2, $stats['vectorDocuments']);
        self::assertSame('store', $stats['indexInfo']['index_name']);
        self::assertSame(3, (int) $stats['indexInfo']['num_docs']);
    }

    // ===== Vector Search with Distance Metrics ================================

    private function metricStore(string $distanceType, string $field = 'text', ?float $threshold = null, ?array $fields = null): RedisStore
    {
        return $this->store(new RedisStoreIndexConfig(
            dims: 3,
            embeddings: CallbackEmbeddings::hashed(),
            distanceType: $distanceType,
            fields: $fields ?? [$field],
            similarityThreshold: $threshold,
        ));
    }

    public function testShouldSupportCosineDistance(): void
    {
        $store = $this->metricStore('cosine');
        $store->put(['docs'], 'doc1', ['text' => 'hello world']);
        $store->put(['docs'], 'doc2', ['text' => 'hello']);
        $store->put(['docs'], 'doc3', ['text' => 'world']);

        $results = $store->search(['docs'], ['query' => 'hello', 'limit' => 3]);

        self::assertCount(3, $results);
        foreach ($results as $result) {
            self::assertGreaterThanOrEqual(0, $result->score);
            self::assertLessThanOrEqual(1, $result->score);
        }
        self::assertSame('doc2', $results[0]->key, 'the identical text is the nearest');
        self::assertEqualsWithDelta(1.0, $results[0]->score, 1e-6);
    }

    public function testShouldSupportL2EuclideanDistance(): void
    {
        $store = $this->metricStore('l2');
        $store->put(['docs'], 'doc1', ['text' => 'test document']);
        $store->put(['docs'], 'doc2', ['text' => 'another test']);

        $results = $store->search(['docs'], ['query' => 'test', 'limit' => 2]);

        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertGreaterThan(0, $result->score);
            self::assertLessThanOrEqual(1, $result->score);
        }
    }

    public function testShouldSupportInnerProductDistance(): void
    {
        $store = $this->metricStore('ip');
        $store->put(['docs'], 'doc1', ['text' => 'vector search']);
        $store->put(['docs'], 'doc2', ['text' => 'search test']);

        $results = $store->search(['docs'], ['query' => 'search', 'limit' => 2]);

        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertGreaterThan(0, $result->score);
            self::assertLessThan(1, $result->score);
        }
    }

    public function testShouldFilterResultsBySimilarityThreshold(): void
    {
        $store = $this->metricStore('cosine', 'content', 0.5);
        $store->put(['docs'], 'doc1', ['content' => 'exact match']);
        $store->put(['docs'], 'doc2', ['content' => 'similar content']);
        $store->put(['docs'], 'doc3', ['content' => 'very different xyz']);

        $results = $store->search(['docs'], ['query' => 'exact match', 'limit' => 10]);

        self::assertGreaterThan(0, count($results));
        foreach ($results as $result) {
            self::assertGreaterThanOrEqual(0.5, $result->score);
        }
    }

    public function testShouldOverrideThresholdPerQuery(): void
    {
        $store = $this->metricStore('cosine', 'content');
        $store->put(['docs'], 'doc1', ['content' => 'high similarity']);
        $store->put(['docs'], 'doc2', ['content' => 'medium match']);
        $store->put(['docs'], 'doc3', ['content' => 'low relevance xyz']);

        $strict = $store->search(['docs'], ['query' => 'high similarity', 'limit' => 10, 'similarityThreshold' => 0.7]);
        $lenient = $store->search(['docs'], ['query' => 'high similarity', 'limit' => 10, 'similarityThreshold' => 0.3]);

        self::assertGreaterThanOrEqual(count($strict), count($lenient));
        foreach ($strict as $result) {
            self::assertGreaterThanOrEqual(0.7, $result->score);
        }
        self::assertGreaterThanOrEqual(1, count($strict), 'the identical text always clears the bar');
    }

    public function testShouldIndexOnlySpecifiedFields(): void
    {
        $store = $this->metricStore('cosine', fields: ['title', 'summary']);
        $store->put(['articles'], 'art1', [
            'title' => 'Important Article',
            'summary' => 'This is a summary about technology',
            'body' => 'Long body text that should not be indexed',
            'metadata' => ['author' => 'John'],
        ]);
        $store->put(['articles'], 'art2', [
            'title' => 'Another Article',
            'summary' => 'Summary about science',
            'body' => 'technology appears here but won\'t be indexed',
        ]);

        $results = $store->search(['articles'], ['query' => 'technology', 'limit' => 10]);

        self::assertGreaterThan(0, count($results));
        self::assertLessThanOrEqual(2, count($results));
        $embedded = $this->embeddedTexts($store);
        self::assertNotContains('Long body text that should not be indexed', $embedded);
    }

    public function testShouldSupportPerOperationFieldSelection(): void
    {
        $store = $this->store(new RedisStoreIndexConfig(dims: 3, embeddings: CallbackEmbeddings::hashed()));

        $store->put(['products'], 'prod1', ['name' => 'Product One', 'description' => 'Advanced electronic device', 'tags' => ['electronics', 'gadget']], ['description']);
        $store->put(['products'], 'prod2', ['name' => 'Electronic Product Two', 'description' => 'Simple device', 'tags' => ['electronics']], ['name']);
        $store->put(['products'], 'prod3', ['name' => 'Product Three', 'description' => 'Another electronic item'], false);

        $results = $store->search(['products'], ['query' => 'electronic', 'limit' => 10]);

        self::assertCount(2, $results);
        self::assertSame(['Electronic Product Two', 'Product One'], self::names($results));
    }

    public function testShouldCombineVectorSearchWithAdvancedFilters(): void
    {
        $store = $this->metricStore('cosine', 'description', 0.3);
        $store->put(['products'], 'laptop1', ['name' => 'Gaming Laptop', 'description' => 'High performance gaming computer', 'price' => 1500, 'category' => 'electronics']);
        $store->put(['products'], 'laptop2', ['name' => 'Office Laptop', 'description' => 'Business computer for office work', 'price' => 800, 'category' => 'electronics']);
        $store->put(['products'], 'phone1', ['name' => 'Smartphone', 'description' => 'Mobile device with high performance', 'price' => 600, 'category' => 'electronics']);
        $store->put(['products'], 'chair1', ['name' => 'Gaming Chair', 'description' => 'Comfortable chair for gaming', 'price' => 300, 'category' => 'furniture']);

        $results = $store->search(['products'], [
            'query' => 'gaming',
            'filter' => ['price' => ['$lt' => 1000], 'category' => 'electronics'],
            'limit' => 10,
        ]);

        foreach ($results as $result) {
            self::assertLessThan(1000, $result->value['price']);
            self::assertSame('electronics', $result->value['category']);
        }
        self::assertNotContains('laptop1', array_map(static fn (Item $i): string => $i->key, $results));
        self::assertNotContains('chair1', array_map(static fn (Item $i): string => $i->key, $results));
    }

    /** @return list<string> every text the store's embeddings model has been asked to embed */
    private function embeddedTexts(RedisStore $store): array
    {
        $property = new \ReflectionProperty($store, 'index');
        $config = $property->getValue($store);
        $embeddings = $config->embeddings;
        \assert($embeddings instanceof CallbackEmbeddings);

        return $embeddings->embedded;
    }

    // ===== Behaviour the upstream suite does not pin ==========================

    public function testPutDoesNotClobberAnotherNamespaceThatSharesALabelAndKey(): void
    {
        $store = $this->store();
        $store->put(['docs', 'private'], 'k', ['who' => 'private']);

        $store->put(['docs'], 'k', ['who' => 'root']);

        self::assertSame('private', $store->get(['docs', 'private'], 'k')?->value['who']);
        self::assertSame('root', $store->get(['docs'], 'k')?->value['who']);
        self::assertNull($store->get(['private'], 'k'), 'a namespace is not matched by one of its labels');
    }

    public function testGetDoesNotFallForTagCaseFolding(): void
    {
        $store = $this->store();
        $store->put(['ns'], 'Doc1', ['v' => 1]);

        self::assertNull($store->get(['ns'], 'doc1'));
        self::assertSame(1, $store->get(['ns'], 'Doc1')?->value['v']);
    }

    public function testKeysWithRediSearchSyntaxAreStoredAndFoundLiterally(): void
    {
        $store = $this->store();
        $keys = ['a-b', 'a.b', 'x y', 'q}) | (@key:{*', 'back\\slash', 'uni-é', '{braced}', 'a/b?'];
        foreach ($keys as $i => $key) {
            $store->put(['syntax'], $key, ['i' => $i]);
        }

        foreach ($keys as $i => $key) {
            self::assertSame($i, $store->get(['syntax'], $key)?->value['i'], "key {$key}");
        }
        self::assertNull($store->get(['syntax'], '*'));
    }

    public function testAnEmptyKeyIsAnItemLikeAnyOther(): void
    {
        $store = $this->store();

        $store->put(['empties'], '', ['first' => true]);
        $store->put(['empties'], '', ['second' => true]);
        $store->put(['empties'], 'real', ['third' => true]);

        self::assertSame(['second' => true], $store->get(['empties'], '')?->value);
        self::assertCount(2, $store->search(['empties']), 'the second empty-key put replaced the first');
    }

    public function testNamespaceLabelsWithQuerySyntaxCannotRewriteTheQuery(): void
    {
        $store = $this->store();
        $store->put(['safe'], 'k', ['v' => 1]);
        $store->put(['tenant'], 'k', ['v' => 2]);

        $evil = ['x) | (@prefix:*'];
        $store->put($evil, 'k', ['v' => 3]);

        self::assertSame(3, $store->get($evil, 'k')?->value['v']);
        self::assertSame(1, $store->get(['safe'], 'k')?->value['v']);
        self::assertCount(1, $store->search($evil));
    }

    public function testDeletingAnItemAlsoDeletesItsVector(): void
    {
        $store = $this->vectorStore();
        $store->put(['v'], 'k', ['text' => 'some text']);
        self::assertSame(1, $store->stats()['vectorDocuments']);

        $store->delete(['v'], 'k');

        self::assertSame(0, $store->stats()['vectorDocuments']);
        self::assertSame(0, $store->stats()['totalDocuments']);
        self::assertSame([], $store->search(['v'], ['query' => 'some text']));
    }

    public function testExplicitTtlOverridesTheDefaultAndAppliesToTheVectorKey(): void
    {
        $store = $this->store(
            new RedisStoreIndexConfig(dims: 4, embeddings: CallbackEmbeddings::characters(), fields: ['text']),
            new TtlConfig(defaultTtl: 60),
        );

        $store->put(['t'], 'short', ['text' => 'brief'], null, 1 / 60);
        $store->put(['t'], 'long', ['text' => 'lasting']);
        $this->pass(2);

        self::assertNull($store->get(['t'], 'short'));
        self::assertNotNull($store->get(['t'], 'long'));
        self::assertSame(1, $store->stats()['vectorDocuments'], 'the vector key expired with its item');
    }

    public function testSearchRefreshTtlRearmsEveryHit(): void
    {
        $store = $this->ttlStore();
        $store->put(['r'], 'a', ['n' => 1]);
        $store->put(['r'], 'b', ['n' => 2]);

        $this->pass(1);
        self::assertCount(2, $store->search(['r'], ['refreshTtl' => true]));
        $this->pass(1.5);

        self::assertCount(2, $store->search(['r']));
    }

    public function testWithoutAnIndexConfigAQueryFallsBackToAPlainSearch(): void
    {
        $store = $this->store();
        $store->put(['plain'], 'a', ['text' => 'hello']);

        $results = $store->search(['plain'], ['query' => 'anything']);

        self::assertCount(1, $results);
        self::assertNull($results[0]->score);
    }

    public function testASearchWithNoMatchingNamespaceIsEmpty(): void
    {
        $store = $this->store();
        $store->put(['here'], 'a', ['v' => 1]);

        self::assertSame([], $store->search(['elsewhere']));
        self::assertSame([], $store->listNamespaces(['prefix' => ['elsewhere']]));
    }

    public function testStoreOperationsOnAMissingIndexAreEmptyNotErrors(): void
    {
        $store = new RedisStore($this->client);

        self::assertNull($store->get(['x'], 'k'));
        self::assertSame([], $store->search(['x']));
        self::assertSame([], $store->listNamespaces());
        self::assertSame(['totalDocuments' => 0, 'namespaceCount' => 0], $store->stats());
    }

    public function testSetupIsIdempotent(): void
    {
        $store = $this->store();
        $store->put(['i'], 'k', ['v' => 1]);

        $store->setup();
        $store->setup();

        self::assertSame(1, $store->get(['i'], 'k')?->value['v']);
    }

    public function testBatchRejectsAnInvalidNamespaceLikePut(): void
    {
        $store = $this->store();

        $this->expectException(InvalidNamespaceError::class);
        $store->batch([new PutOperation(['langgraph'], 'k', ['v' => 1])]);
    }

    public function testBatchDeleteRemovesAnItem(): void
    {
        $store = $this->store();
        $store->put(['d'], 'k', ['v' => 1]);

        $store->batch([new PutOperation(['d'], 'k', null)]);

        self::assertNull($store->get(['d'], 'k'));
    }

    public function testBatchPutHonoursTheOperationsIndexSetting(): void
    {
        $store = $this->store(new RedisStoreIndexConfig(dims: 4, embeddings: CallbackEmbeddings::characters(), fields: ['text']));

        $store->batch([
            new PutOperation(['b'], 'indexed', ['text' => 'one']),
            new PutOperation(['b'], 'skipped', ['text' => 'two'], false),
        ]);

        self::assertSame(1, $store->stats()['vectorDocuments']);
    }
}
