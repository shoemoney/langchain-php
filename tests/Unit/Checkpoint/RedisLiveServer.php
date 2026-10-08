<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Redis\PhpRedisClient;
use LangGraph\Checkpoint\Redis\RedisClientInterface;

/**
 * Chooses the Redis backend for the integration tests.
 *
 * Set `LANGGRAPH_REDIS_URL` (for example `redis://127.0.0.1:6379`) to run them against a real
 * Redis Stack server — RedisJSON and RediSearch both required — through ext-redis. Leave it
 * unset and they run against {@see FakeRedisClient}.
 *
 * Unset does not skip. A skipped test makes PHPUnit's summary `OK, but some tests were
 * skipped!`, which fails the `OK (N tests` check the WP done-script reads, and a gate that
 * silently reports nothing is worse than one that runs the same scenarios against the fake.
 *
 * Point the URL at a DEDICATED database: every test starts by deleting the saver keyspace
 * (`checkpoint:*`, `checkpoint_blob:*`, `checkpoint_write:*`, `write_keys_zset:*`).
 */
final class RedisLiveServer
{
    /** The key patterns the Redis savers own. */
    private const PATTERNS = ['checkpoint:*', 'checkpoint_blob:*', 'checkpoint_write:*', 'write_keys_zset:*'];

    private function __construct()
    {
    }

    /** The configured server URL, or null when the tests should use the fake. */
    public static function url(): ?string
    {
        $url = getenv('LANGGRAPH_REDIS_URL');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * A fresh client on an empty saver keyspace.
     *
     * On a real server each call opens a new connection, so two clients are two real
     * connections to the same data. On the fake, each call is a new, empty store: tests that
     * need two savers over one store must share the returned client.
     */
    public static function freshClient(): RedisClientInterface
    {
        $url = self::url();
        if ($url === null) {
            return new FakeRedisClient();
        }

        $client = PhpRedisClient::fromUrl($url);
        foreach (self::PATTERNS as $pattern) {
            $keys = $client->keys($pattern);
            if ($keys !== []) {
                $client->del($keys);
            }
        }

        return $client;
    }

    /** Another connection to the same store as `$client` (a new saver "from the URL"). */
    public static function sameStore(RedisClientInterface $client): RedisClientInterface
    {
        $url = self::url();

        return $url === null ? $client : PhpRedisClient::fromUrl($url);
    }

    /** Remaining TTL in seconds, or null when the key has none or is gone. */
    public static function ttl(RedisClientInterface $client, string $key): ?int
    {
        if ($client instanceof FakeRedisClient) {
            return $client->ttl($key);
        }

        $redis = new \Redis();
        $parts = parse_url((string) self::url());
        $redis->connect((string) ($parts['host'] ?? '127.0.0.1'), (int) ($parts['port'] ?? 6379));
        if (isset($parts['pass'])) {
            $redis->auth(rawurldecode($parts['pass']));
        }
        $database = trim((string) ($parts['path'] ?? ''), '/');
        if ($database !== '' && ctype_digit($database)) {
            $redis->select((int) $database);
        }
        $ttl = $redis->ttl($key);
        $redis->close();

        return is_int($ttl) && $ttl >= 0 ? $ttl : null;
    }
}
