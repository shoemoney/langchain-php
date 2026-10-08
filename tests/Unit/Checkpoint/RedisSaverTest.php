<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\Redis\RedisSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of the `RedisSaver Basic` block of `tests/checkpoint.int.test.ts`.
 *
 * Upstream drives these with a hand-rolled mock client that supplies only the methods each
 * test touches. Here the recording {@see FakeRedisClient} stands in, so the same assertions
 * are made against the documents the saver actually stored.
 */
#[CoversClass(RedisSaver::class)]
final class RedisSaverTest extends TestCase
{
    public function testShouldCreateAnInstanceWithARedisClient(): void
    {
        self::assertInstanceOf(RedisSaver::class, new RedisSaver(new FakeRedisClient()));
    }

    public function testShouldExtendBaseCheckpointSaver(): void
    {
        self::assertInstanceOf(BaseCheckpointSaver::class, new RedisSaver(new FakeRedisClient()));
    }

    public function testShouldReturnNullForNonExistentCheckpoint(): void
    {
        $saver = new RedisSaver(new FakeRedisClient());

        self::assertNull($saver->getTuple(['configurable' => [
            'thread_id' => 'test-thread',
            'checkpoint_id' => 'non-existent',
        ]]));
    }

    public function testShouldRetrieveAnExistingCheckpoint(): void
    {
        $client = new FakeRedisClient();
        $document = [
            'thread_id' => 'test-thread',
            'checkpoint_ns' => '',
            'checkpoint_id' => 'test-checkpoint',
            'parent_checkpoint_id' => null,
            'checkpoint' => [
                'v' => 1,
                'id' => 'test-checkpoint',
                'ts' => '2024-01-01T00:00:00Z',
                'channel_values' => new \stdClass(),
                'channel_versions' => new \stdClass(),
                'versions_seen' => new \stdClass(),
            ],
            'metadata' => new \stdClass(),
        ];
        $client->seedJson('checkpoint:test-thread::test-checkpoint', $document);

        $result = (new RedisSaver($client))->getTuple(['configurable' => [
            'thread_id' => 'test-thread',
            'checkpoint_id' => 'test-checkpoint',
        ]]);

        self::assertNotNull($result);
        self::assertEquals(new Checkpoint(v: 1, id: 'test-checkpoint', ts: '2024-01-01T00:00:00Z'), $result->checkpoint);
        self::assertSame([], $result->metadata);
        // The lookup is by key, not by search.
        self::assertSame([['checkpoint:test-thread::test-checkpoint']], $client->callsTo('jsonGet'));
    }

    public function testShouldSaveACheckpointWithPutMethod(): void
    {
        $client = new FakeRedisClient();
        $saver = new RedisSaver($client);
        $checkpoint = new Checkpoint(
            v: 1,
            id: 'cp-123',
            ts: '2024-01-01T00:00:00Z',
            channelValues: ['test' => 'value'],
            channelVersions: ['test' => 1],
        );

        $result = $saver->put(
            ['configurable' => ['thread_id' => 'thread-1', 'checkpoint_ns' => '']],
            $checkpoint,
            ['source' => 'update', 'step' => 0, 'parents' => []],
        );

        $stored = $client->callsTo('jsonSet')[0];
        self::assertSame('checkpoint:thread-1::cp-123', $stored[0]);

        $document = json_decode($stored[2], true);
        self::assertSame('cp-123', $document['checkpoint']['id']);
        self::assertSame(['test' => 'value'], $document['checkpoint']['channel_values']);
        self::assertSame(['source' => 'update', 'step' => 0, 'parents' => []], $document['metadata']);
        self::assertSame('cp-123', $result['configurable']['checkpoint_id']);
    }

    public function testShouldListCheckpointsForAThread(): void
    {
        $client = new FakeRedisClient();
        foreach ([['cp-1', '01'], ['cp-2', '02'], ['cp-3', '03']] as [$id, $hour]) {
            $client->seedJson("checkpoint:thread-1::{$id}", [
                'thread_id' => 'thread-1',
                'checkpoint_ns' => '__empty__',
                'checkpoint_id' => $id,
                'parent_checkpoint_id' => null,
                'checkpoint_ts' => strtotime("2024-01-01T{$hour}:00:00Z") * 1000,
                'checkpoint' => [
                    'v' => 1,
                    'id' => $id,
                    'ts' => "2024-01-01T{$hour}:00:00Z",
                    'channel_values' => new \stdClass(),
                    'channel_versions' => new \stdClass(),
                    'versions_seen' => new \stdClass(),
                ],
                'metadata' => new \stdClass(),
                'has_writes' => 'false',
            ]);
        }

        $results = (new RedisSaver($client))->list(['configurable' => ['thread_id' => 'thread-1']]);

        self::assertCount(3, $results);
        self::assertSame('cp-3', $results[0]->checkpoint->id, 'most recent first');
    }

    public function testShouldSavePendingWritesWithPutWrites(): void
    {
        $client = new FakeRedisClient();

        (new RedisSaver($client))->putWrites(
            ['configurable' => ['thread_id' => 'thread-1', 'checkpoint_ns' => '', 'checkpoint_id' => 'cp-1']],
            [['channel1', 'value1'], ['channel2', 'value2']],
            'task-1',
        );

        $saved = array_values(array_filter(
            $client->callsTo('jsonSet'),
            static fn (array $call): bool => str_starts_with($call[0], 'checkpoint_write:'),
        ));
        self::assertCount(2, $saved);

        self::assertSame('checkpoint_write:thread-1::cp-1:task-1:0', $saved[0][0]);
        $first = json_decode($saved[0][2], true);
        self::assertSame('channel1', $first['channel']);
        self::assertSame('value1', $first['value']);

        self::assertSame('checkpoint_write:thread-1::cp-1:task-1:1', $saved[1][0]);
        $second = json_decode($saved[1][2], true);
        self::assertSame('channel2', $second['channel']);
        self::assertSame('value2', $second['value']);
    }

    public function testShouldDeleteAThreadWithDeleteThread(): void
    {
        $client = new FakeRedisClient();
        $client->seedJson('checkpoint:thread-1:cp-1', ['k' => 1]);
        $client->seedJson('checkpoint:thread-1:cp-2', ['k' => 1]);
        $client->seedJson('checkpoint_write:thread-1::cp-1:task-1:0', ['k' => 1]);
        $client->seedZSet('write_keys_zset:thread-1::cp-1', ['checkpoint_write:thread-1::cp-1:task-1:0' => 0]);

        (new RedisSaver($client))->deleteThread('thread-1');

        foreach ([
            'checkpoint:thread-1:cp-1',
            'checkpoint:thread-1:cp-2',
            'checkpoint_write:thread-1::cp-1:task-1:0',
            'write_keys_zset:thread-1::cp-1',
        ] as $key) {
            self::assertContains($key, $client->deletedKeys());
        }
    }

    /** `fromUrl` needs ext-redis and a server; the failure mode without either is a clear exception. */
    public function testFromUrlRejectsAnUnparseableUrl(): void
    {
        if (!extension_loaded('redis')) {
            $this->expectException(\RuntimeException::class);
        } else {
            $this->expectException(\InvalidArgumentException::class);
        }

        RedisSaver::fromUrl('not a url');
    }
}
