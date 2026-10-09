<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use LangGraph\Store\MongoDB\MongoDBIndexConfig;
use LangGraph\Store\MongoDB\MongoDBStore;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Port of `tests/store.int.test.ts`: the store contract run against a live MongoDB.
 *
 * Env-gated on `LANGGRAPH_MONGO_URI` (for example `mongodb://localhost:27017`) and on ext-mongodb
 * with `mongodb/mongodb`; skipped cleanly when either is missing. Vector search needs Atlas (or the
 * atlas-local container with mongot), so those cases also need `TEST_MONGODB_VECTORSEARCH`, and the
 * auto-embedding ones `TEST_MONGODB_AUTOEMBEDDING` as well, exactly as upstream gates them.
 *
 * This spec has never run against a real server: none is available where the port was written.
 */
#[CoversClass(MongoDBStore::class)]
final class MongoDBStoreIntegrationTest extends MongoDBStoreContractCase
{
    private const DATABASE = 'langgraph_php_test';

    private ?MongoDBStoreDriverClient $client = null;

    protected function setUp(): void
    {
        $uri = getenv('LANGGRAPH_MONGO_URI');
        if ($uri === false || $uri === '') {
            self::markTestSkipped('Set LANGGRAPH_MONGO_URI to run the MongoDB integration tests.');
        }
        if (!class_exists(\MongoDB\Client::class)) {
            self::markTestSkipped('ext-mongodb and mongodb/mongodb are required for the MongoDB integration tests.');
        }

        $this->client = new MongoDBStoreDriverClient($uri);
        $this->client->client->dropDatabase(self::DATABASE);
    }

    protected function tearDown(): void
    {
        $this->client?->client->dropDatabase(self::DATABASE);
    }

    protected function vectorCasesEnabled(): bool
    {
        return getenv('TEST_MONGODB_VECTORSEARCH') !== false && getenv('TEST_MONGODB_VECTORSEARCH') !== '';
    }

    protected function autoEmbeddingEnabled(): bool
    {
        return getenv('TEST_MONGODB_AUTOEMBEDDING') !== false && getenv('TEST_MONGODB_AUTOEMBEDDING') !== '';
    }

    protected function makeStore(string $collection, ?HashEmbeddings $embeddings = null, ?MongoDBIndexConfig $indexConfig = null): MongoDBStore
    {
        self::assertNotNull($this->client);
        $store = new MongoDBStore($this->client, self::DATABASE, $collection, embeddings: $embeddings, indexConfig: $indexConfig);
        $store->start();

        return $store;
    }

    protected function rawDocument(string $collection, array $namespace, string $key): ?array
    {
        self::assertNotNull($this->client);

        return (new MongoDBStoreDriverCollection($this->client->client->selectCollection(self::DATABASE, $collection)))
            ->findOne(['namespace' => $namespace, 'key' => $key]);
    }

    protected function searchIndex(string $collection, string $name): ?array
    {
        self::assertNotNull($this->client);

        foreach ($this->client->client->selectCollection(self::DATABASE, $collection)->listSearchIndexes(['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]) as $index) {
            if ($index['name'] === $name) {
                return $index['latestDefinition'] ?? null;
            }
        }

        return null;
    }
}
