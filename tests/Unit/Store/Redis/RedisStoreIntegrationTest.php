<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Redis;

use LangGraph\Checkpoint\Redis\PhpRedisClient;
use LangGraph\Checkpoint\Redis\RedisClientInterface;
use LangGraph\Checkpoint\Redis\TtlConfig;
use LangGraph\Store\Redis\RedisStore;
use LangGraph\Store\Redis\RedisStoreIndexConfig;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `tests/store.int.test.ts` against a real Redis Stack server (RedisJSON + RediSearch).
 *
 * Env-gated: set `LANGGRAPH_REDIS_URL` (for example `redis://127.0.0.1:6379`) to run it; without
 * it every test skips. WARNING: each test starts by dropping the `store` and `store_vectors`
 * indexes and deleting every `store:*` and `store_vectors:*` key, so point the URL at a
 * dedicated server or database. Needs ext-redis.
 */
#[CoversClass(RedisStore::class)]
#[CoversClass(PhpRedisClient::class)]
final class RedisStoreIntegrationTest extends RedisStoreSpecCase
{
    protected function setUp(): void
    {
        if (self::url() === null) {
            self::markTestSkipped('Set LANGGRAPH_REDIS_URL to a Redis Stack server to run the RedisStore integration tests.');
        }
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is required for the RedisStore integration tests.');
        }
        parent::setUp();
    }

    private static function url(): ?string
    {
        $url = getenv('LANGGRAPH_REDIS_URL');

        return is_string($url) && $url !== '' ? $url : null;
    }

    protected function newClient(): RedisClientInterface
    {
        $redis = $this->raw();
        foreach (['store', 'store_vectors'] as $index) {
            try {
                $redis->rawCommand('FT.DROPINDEX', $index);
            } catch (\RedisException) {
                // The index was not there.
            }
        }
        foreach (['store:*', 'store_vectors:*'] as $pattern) {
            $keys = $redis->keys($pattern);
            if (is_array($keys) && $keys !== []) {
                $redis->del($keys);
            }
        }
        $redis->close();

        return PhpRedisClient::fromUrl((string) self::url());
    }

    protected function pass(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }

    protected function connect(?RedisStoreIndexConfig $index = null, ?TtlConfig $ttl = null): RedisStore
    {
        return RedisStore::fromConnString((string) self::url(), $index, $ttl);
    }

    private function raw(): \Redis
    {
        $parts = parse_url((string) self::url());
        $redis = new \Redis();
        $redis->connect((string) ($parts['host'] ?? '127.0.0.1'), (int) ($parts['port'] ?? 6379));
        if (isset($parts['pass'])) {
            $redis->auth(rawurldecode($parts['pass']));
        }
        $database = trim((string) ($parts['path'] ?? ''), '/');
        if ($database !== '' && ctype_digit($database)) {
            $redis->select((int) $database);
        }

        return $redis;
    }
}
