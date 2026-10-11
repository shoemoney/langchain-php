<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint\Redis;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\Redis\PhpRedisClient;
use LangGraph\Checkpoint\Redis\RedisSaver;
use LangGraph\Checkpoint\Redis\TtlConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/** Pinned index.ts fromCluster lifecycle and checkpoint behavior over the real adapter seam. */
#[CoversClass(RedisSaver::class)]
#[CoversClass(PhpRedisClient::class)]
#[RequiresPhpExtension('redis')]
final class RedisSaverClusterTest extends TestCase
{
    public function testFactorySetsUpAllIndexesAndCheckpointDocumentsRouteByTheirOwnKeys(): void
    {
        $fake = PhpRedisClusterClientTest::fake();
        $fake->documentsEnabled = true;
        $saver = RedisSaver::fromCluster([['url' => 'redis://node-a:7000']], new TtlConfig(2, true), static fn (): \RedisCluster => $fake);
        self::assertSame(['checkpoints', 'checkpoint_blobs', 'checkpoint_writes'], array_column($fake->calls, 2));
        $config = $saver->put(['configurable' => ['thread_id' => 'thread']], new Checkpoint(
            v: 1, id: 'cp', ts: '2024-01-01T00:00:00Z', channelValues: ['answer' => 42], channelVersions: ['answer' => 1],
        ), ['source' => 'update', 'step' => 1], ['answer' => 1]);
        $tuple = $saver->getTuple($config);
        self::assertNotNull($tuple);
        self::assertSame(['answer' => 42], $tuple->checkpoint->channelValues);
        self::assertSame(1, $tuple->metadata['step']);
        foreach ($fake->calls as $call) {
            if (is_string($call[0]) && str_starts_with($call[1] ?? '', 'JSON.')) {
                self::assertSame($call[0], $call[2]);
            }
        }
        self::assertContains(['EXPIRE', 'checkpoint:thread::cp', 120], $fake->calls);
        $saver->end();
        self::assertSame(1, $fake->closes);
    }

    public function testBorrowedClusterAdapterKeepsUpstreamEndClosesClientContract(): void
    {
        $fake = PhpRedisClusterClientTest::fake();
        (new RedisSaver(new PhpRedisClient($fake)))->end();
        self::assertSame(1, $fake->closes);
    }

    public function testAlreadyExistingIndexesAreAcceptedThroughNormalizedClusterErrors(): void
    {
        $fake = PhpRedisClusterClientTest::fake();
        $fake->replyError = 'Index already exists';
        self::assertInstanceOf(RedisSaver::class, RedisSaver::fromCluster([['url' => 'redis://node-a']], connector: static fn (): \RedisCluster => $fake));
        self::assertCount(3, $fake->calls);
        self::assertNull($fake->error);
    }
}
