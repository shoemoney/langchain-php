<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Redis;

use LangChain\Tests\Unit\Checkpoint\FakeRedisClient;
use LangGraph\Checkpoint\Redis\RedisClientInterface;
use LangGraph\Checkpoint\Redis\TtlConfig;
use LangGraph\Store\Redis\FilterBuilder;
use LangGraph\Store\Redis\RedisStore;
use LangGraph\Store\Redis\RedisStoreIndexConfig;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `tests/store.int.test.ts` over {@see FakeRedisClient}, so the whole store runs without a server.
 *
 * `RedisStoreIntegrationTest` runs the same scenarios against a real Redis Stack server.
 */
#[CoversClass(RedisStore::class)]
#[CoversClass(RedisStoreIndexConfig::class)]
#[CoversClass(FilterBuilder::class)]
final class RedisStoreTest extends RedisStoreSpecCase
{
    protected function newClient(): RedisClientInterface
    {
        return new FakeRedisClient();
    }

    protected function pass(float $seconds): void
    {
        \assert($this->client instanceof FakeRedisClient);
        $this->client->advance($seconds);
    }

    protected function connect(?RedisStoreIndexConfig $index = null, ?TtlConfig $ttl = null): RedisStore
    {
        // `fromConnString` needs a socket; the same store, over the same client, is built here.
        return $this->store($index, $ttl);
    }
}
