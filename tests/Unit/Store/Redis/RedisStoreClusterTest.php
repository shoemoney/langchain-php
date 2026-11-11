<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Redis;

use LangChain\Tests\Unit\Checkpoint\Redis\PhpRedisClusterClientTest;
use LangGraph\Checkpoint\Redis\PhpRedisClient;
use LangGraph\Checkpoint\Redis\TtlConfig;
use LangGraph\Store\Redis\RedisStore;
use LangGraph\Store\Redis\RedisStoreIndexConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/** Pinned store.ts fromCluster setup, data operations and close, without sockets. */
#[CoversClass(RedisStore::class)]
#[CoversClass(PhpRedisClient::class)]
#[RequiresPhpExtension('redis')]
final class RedisStoreClusterTest extends TestCase
{
    public function testFactorySetsUpStoreAndVectorIndexesBeforeUsingTheSlotRoutedAdapter(): void
    {
        $fake = PhpRedisClusterClientTest::fake();
        $fake->documentsEnabled = true;
        $store = RedisStore::fromCluster([['url' => 'redis://node-a:7000']], new RedisStoreIndexConfig(2), new TtlConfig(1), static fn (): \RedisCluster => $fake);
        $creates = array_filter($fake->calls, static fn (array $call): bool => $call[1] === 'FT.CREATE');
        self::assertSame(['store', 'store_vectors'], array_column($creates, 2));
        self::assertContains('VECTOR', $fake->calls[1]);
        self::assertContains('DIM', $fake->calls[1]);
        $store->put(['docs'], 'one', ['text' => 'hello']);
        self::assertSame(['text' => 'hello'], $store->get(['docs'], 'one')->value);
        $keys = array_keys($fake->documents);
        self::assertCount(1, $keys);
        self::assertContains(['EXPIRE', $keys[0], 60], $fake->calls);
        foreach ($fake->calls as $call) {
            if (($call[1] ?? '') === 'JSON.SET') {
                self::assertSame($call[0], $call[2]);
            }
            if (($call[1] ?? '') === 'FT.SEARCH') {
                self::assertSame(['node-a', 7000], $call[0]);
            }
        }
        $store->close();
        self::assertSame(1, $fake->closes);
    }

    public function testBorrowedClusterAdapterKeepsUpstreamCloseClosesClientContract(): void
    {
        $fake = PhpRedisClusterClientTest::fake();
        (new RedisStore(new PhpRedisClient($fake)))->close();
        self::assertSame(1, $fake->closes);
    }

    public function testInvalidSeedsFailBeforeStoreSetupOrTransportConnection(): void
    {
        $connections = 0;
        try {
            RedisStore::fromCluster([['url' => 'redis://node-a/1']], connector: static function () use (&$connections): \RedisCluster {
                ++$connections;

                return PhpRedisClusterClientTest::fake();
            });
            self::fail('Expected cluster database rejection.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $connections);
        }
    }

    public function testFactoryPreservesUpstreamSetupLogsAndContinuesOnUnsupportedSearchModule(): void
    {
        $fake = PhpRedisClusterClientTest::fake();
        $fake->replyError = 'ERR unknown command FT.CREATE';
        $log = tempnam(sys_get_temp_dir(), 'langchain-cluster-');
        $previous = ini_set('error_log', $log);
        try {
            $store = RedisStore::fromCluster([['url' => 'redis://node-a']], connector: static fn (): \RedisCluster => $fake);
            self::assertInstanceOf(RedisStore::class, $store);
            self::assertStringContainsString('ERR unknown command FT.CREATE', file_get_contents($log));
            $fake->replyError = null;
            $store->close();
            self::assertSame(1, $fake->closes);
        } finally {
            ini_set('error_log', $previous);
            unlink($log);
        }
    }
}
