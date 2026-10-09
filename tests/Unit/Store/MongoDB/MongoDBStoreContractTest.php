<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use LangGraph\Store\MongoDB\MongoDBIndexConfig;
use LangGraph\Store\MongoDB\MongoDBStore;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The `store.int.test.ts` behaviours over the in-memory fake.
 *
 * Not env-gated. The auto-embedding cases skip here because the fake cannot embed text
 * server-side; {@see MongoDBStoreIntegrationTest} runs the same cases against a live server.
 */
#[CoversClass(MongoDBStore::class)]
final class MongoDBStoreContractTest extends MongoDBStoreContractCase
{
    private FakeMongoStoreClient $client;

    protected function setUp(): void
    {
        $this->client = new FakeMongoStoreClient();
    }

    protected function makeStore(string $collection, ?HashEmbeddings $embeddings = null, ?MongoDBIndexConfig $indexConfig = null): MongoDBStore
    {
        $store = new MongoDBStore($this->client, 'langgraph_test', $collection, embeddings: $embeddings, indexConfig: $indexConfig);
        $store->start();

        return $store;
    }

    protected function rawDocument(string $collection, array $namespace, string $key): ?array
    {
        return $this->client->store($collection)->findOne(['namespace' => $namespace, 'key' => $key]);
    }

    protected function searchIndex(string $collection, string $name): ?array
    {
        foreach ($this->client->store($collection)->searchIndexes as $definition) {
            if ($definition['name'] === $name) {
                return $definition['definition'];
            }
        }

        return null;
    }
}
