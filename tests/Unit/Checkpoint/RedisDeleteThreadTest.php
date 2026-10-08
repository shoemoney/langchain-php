<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Redis\RedisSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tests/deleteThread.test.ts` from `@langchain/langgraph-checkpoint-redis`.
 */
#[CoversClass(RedisSaver::class)]
final class RedisDeleteThreadTest extends TestCase
{
    public function testDeletesCheckpointsWritesZsetAndBlobKeysUsingCorrectPatterns(): void
    {
        $client = new FakeRedisClient();
        $client->seedJson('checkpoint:thread-1:cp-1', ['k' => 1]);
        $client->seedJson('checkpoint:thread-1:cp-2', ['k' => 1]);
        $client->seedJson('checkpoint_write:thread-1::cp-1:task-1:0', ['k' => 1]);
        $client->seedJson('checkpoint_write:thread-1::cp-1:task-1:1', ['k' => 1]);
        $client->seedZSet('write_keys_zset:thread-1::cp-1', ['checkpoint_write:thread-1::cp-1:task-1:0' => 0]);
        $client->seedJson('checkpoint_blob:thread-1::messages:1', ['k' => 1]);
        $client->seedJson('checkpoint_blob:thread-1::status:2', ['k' => 1]);
        // Another thread's data must survive.
        $client->seedJson('checkpoint:thread-2:cp-1', ['k' => 1]);
        $client->seedJson('checkpoint_blob:thread-2::messages:1', ['k' => 1]);

        (new RedisSaver($client))->deleteThread('thread-1');

        $deleted = $client->deletedKeys();
        foreach ([
            'checkpoint:thread-1:cp-1',
            'checkpoint:thread-1:cp-2',
            'checkpoint_write:thread-1::cp-1:task-1:0',
            'checkpoint_write:thread-1::cp-1:task-1:1',
            'write_keys_zset:thread-1::cp-1',
            'checkpoint_blob:thread-1::messages:1',
            'checkpoint_blob:thread-1::status:2',
        ] as $expected) {
            self::assertContains($expected, $deleted);
        }
        self::assertCount(7, $deleted);
        self::assertSame(['checkpoint:thread-2:cp-1', 'checkpoint_blob:thread-2::messages:1'], $client->allKeys());
    }

    public function testHandlesEmptyKeySetsGracefully(): void
    {
        $client = new FakeRedisClient();

        (new RedisSaver($client))->deleteThread('nonexistent-thread');

        self::assertSame([], $client->deletedKeys());
        self::assertSame([], $client->callsTo('del'), 'DEL is not issued with an empty key list');
    }

    /** The reason `deleteThread` validates: `*` would expand to `checkpoint:*:*`. */
    public function testRefusesAGlobThreadIdInsteadOfWipingEveryTenant(): void
    {
        $client = new FakeRedisClient();
        $client->seedJson('checkpoint:thread-2:cp-1', ['k' => 1]);

        try {
            (new RedisSaver($client))->deleteThread('*');
            self::fail('Expected the glob thread id to be refused');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $client->callsTo('keys'));
            self::assertSame(['checkpoint:thread-2:cp-1'], $client->allKeys());
        }
    }
}
