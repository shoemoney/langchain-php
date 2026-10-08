<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\Redis\RedisClientInterface;
use LangGraph\Checkpoint\Redis\ShallowRedisSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of the `ShallowRedisSaver` block of `tests/checkpoint.int.test.ts`.
 *
 * Env-gated on `LANGGRAPH_REDIS_URL` exactly as {@see RedisSaverIntegrationTest} is: a real
 * Redis Stack server when it is set, {@see FakeRedisClient} when it is not. Upstream starts a
 * container per test; here each test starts from an empty saver keyspace instead.
 *
 * It deliberately does NOT run the checkpointer contract. The shallow saver keeps no history and
 * replaces a task's writes on every `putWrites`, so it cannot satisfy a contract that asks for
 * both.
 */
#[CoversClass(ShallowRedisSaver::class)]
final class RedisShallowSaverIntegrationTest extends TestCase
{
    private RedisClientInterface $client;

    private ShallowRedisSaver $saver;

    protected function setUp(): void
    {
        $this->client = RedisLiveServer::freshClient();
        $this->saver = new ShallowRedisSaver($this->client);
    }

    /**
     * @param array<string, mixed> $channelValues
     * @param array<string, int|string> $channelVersions
     */
    private static function checkpoint(int $seq, array $channelValues = [], array $channelVersions = []): Checkpoint
    {
        return new Checkpoint(
            v: 1,
            id: CheckpointId::uuid6($seq),
            ts: gmdate('Y-m-d\TH:i:s.v\Z'),
            channelValues: $channelValues,
            channelVersions: $channelVersions,
        );
    }

    /** @return array{configurable: array<string, string>} */
    private static function config(string $threadId, string $ns = '', ?string $checkpointId = null): array
    {
        $configurable = ['thread_id' => $threadId, 'checkpoint_ns' => $ns];
        if ($checkpointId !== null) {
            $configurable['checkpoint_id'] = $checkpointId;
        }

        return ['configurable' => $configurable];
    }

    /** @return array{source: string, step: int, parents: array<never, never>} */
    private static function meta(string $source, int $step): array
    {
        return ['source' => $source, 'step' => $step, 'parents' => []];
    }

    public function testShouldOnlyKeepTheLatestCheckpointPerThread(): void
    {
        $config = self::config('test-thread');
        $this->saver->put($config, self::checkpoint(0, ['counter' => 1], ['counter' => '1.0']), self::meta('input', 1), ['counter' => '1.0']);
        $checkpoint2 = self::checkpoint(1, ['counter' => 2], ['counter' => '2.0']);
        $this->saver->put($config, $checkpoint2, self::meta('loop', 2), ['counter' => '2.0']);

        // Upstream passes `null` for "no config".
        $results = $this->saver->list([]);

        self::assertCount(1, $results);
        self::assertSame($checkpoint2->id, $results[0]->checkpoint->id);
        self::assertSame(2, $results[0]->checkpoint->channelValues['counter']);
    }

    public function testShouldSupportSearchWithMetadataFilters(): void
    {
        $this->saver->put(
            self::config('thread-1'),
            self::checkpoint(0, ['value' => 'a'], ['value' => '1.0']),
            ['source' => 'input', 'step' => 2, 'parents' => [], 'writes' => [], 'score' => 1],
            ['value' => '1.0'],
        );
        $this->saver->put(
            self::config('thread-2'),
            self::checkpoint(1, ['value' => 'b'], ['value' => '1.0']),
            ['source' => 'loop', 'step' => 1, 'parents' => [], 'writes' => ['foo' => 'bar'], 'score' => null],
            ['value' => '1.0'],
        );

        $count = fn (?array $filter): int => count($this->saver->list([], $filter === null ? null : new CheckpointListOptions(filter: $filter)));

        self::assertSame(1, $count(['source' => 'input', 'step' => 2, 'parents' => []]));
        self::assertSame(1, $count(['source' => 'loop', 'step' => 1, 'parents' => []]));
        self::assertSame(2, $count(null));
        self::assertSame(0, $count(['source' => 'update', 'step' => 1, 'parents' => []]));
    }

    public function testShouldOverwriteWritesNotAppendThem(): void
    {
        $savedConfig = $this->saver->put(
            self::config('test-thread'),
            self::checkpoint(0, ['value' => 'test'], ['value' => '1.0']),
            self::meta('input', 1),
            ['value' => '1.0'],
        );

        $this->saver->putWrites($savedConfig, [['channel1', 'value1']], 'task1');
        // Same task again: replaces, it does not append.
        $this->saver->putWrites($savedConfig, [['channel2', 'value2']], 'task1');

        $result = $this->saver->getTuple($savedConfig);
        self::assertNotNull($result);
        self::assertCount(1, $result->pendingWrites);
        self::assertSame(['task1', 'channel2', 'value2'], $result->pendingWrites[0]);
    }

    public function testShouldHandleNullCharactersInMetadata(): void
    {
        $savedConfig = $this->saver->put(
            self::config('test-thread'),
            self::checkpoint(0, ['value' => 'test'], ['value' => '1.0']),
            ['source' => 'input', 'step' => 0, 'parents' => [], 'my_key' => "\x00abc"],
            ['value' => '1.0'],
        );

        $result = $this->saver->getTuple($savedConfig);

        self::assertNotNull($result);
        // NUL bytes are sanitised away.
        self::assertSame('abc', $result->metadata['my_key']);
    }

    public function testShouldSupportASecondSaverOpenedOnTheSameStore(): void
    {
        $other = new ShallowRedisSaver(RedisLiveServer::sameStore($this->client));
        $config = self::config('factory-thread');

        $other->put($config, self::checkpoint(0, ['test' => 'value'], ['test' => '1.0']), self::meta('input', 0), ['test' => '1.0']);

        self::assertSame('value', $other->getTuple($config)?->checkpoint->channelValues['test']);
        $other->end();
    }

    public function testShouldStoreChannelValuesInlineNotAsBlobs(): void
    {
        $threadId = 'test_thread_' . CheckpointId::uuid6(0);
        $this->saver->put(
            self::config($threadId),
            self::checkpoint(
                0,
                ['test_channel' => ['test_value'], 'another_channel' => ['key' => 'value']],
                ['test_channel' => '1', 'another_channel' => '2'],
            ),
            self::meta('input', 1),
            ['test_channel' => '1', 'another_channel' => '2'],
        );

        $allKeys = $this->client->keys('*');
        self::assertSame([], array_values(array_filter($allKeys, static fn (string $k): bool => str_contains($k, 'checkpoint_blob'))));

        $checkpointKeys = array_values(array_filter(
            $allKeys,
            static fn (string $k): bool => str_starts_with($k, 'checkpoint:') && str_contains($k, $threadId),
        ));
        self::assertCount(1, $checkpointKeys);

        $data = json_decode((string) $this->client->jsonGet($checkpointKeys[0]), true);
        self::assertSame(['test_value'], $data['checkpoint']['channel_values']['test_channel']);
        self::assertSame(['key' => 'value'], $data['checkpoint']['channel_values']['another_channel']);
    }

    public function testShouldCleanUpOldWritesWhenPuttingNewCheckpoint(): void
    {
        $threadId = 'test_thread_' . CheckpointId::uuid6(0);
        $checkpoint1 = self::checkpoint(0);
        $config1 = self::config($threadId, '', $checkpoint1->id);

        $this->saver->put($config1, $checkpoint1, self::meta('input', 1));
        $this->saver->putWrites($config1, [['channel1', 'value1'], ['channel2', 'value2']], 'task1');
        self::assertCount(2, $this->client->keys("checkpoint_write:{$threadId}:*"));

        $checkpoint2 = self::checkpoint(1);
        $config2 = self::config($threadId, '', $checkpoint2->id);
        $this->saver->put($config2, $checkpoint2, self::meta('loop', 2));
        $this->saver->putWrites($config2, [['channel3', 'value3'], ['channel4', 'value4']], 'task2');

        // Only the new writes remain.
        $writeKeys = $this->client->keys("checkpoint_write:{$threadId}:*");
        self::assertCount(2, $writeKeys);
        foreach ($writeKeys as $key) {
            self::assertStringContainsString($checkpoint2->id, $key);
        }
    }

    public function testShouldCleanUpOldCheckpointsWhenPuttingNewOne(): void
    {
        $threadId = 'test_thread_' . CheckpointId::uuid6(0);
        $config = self::config($threadId);

        $checkpoint1 = self::checkpoint(0, ['value' => 1], ['value' => '1.0']);
        $this->saver->put($config, $checkpoint1, self::meta('input', 1), ['value' => '1.0']);

        // Shallow mode: the key does not contain the checkpoint id.
        $keys = $this->client->keys("checkpoint:{$threadId}:*");
        self::assertSame(["checkpoint:{$threadId}::shallow"], $keys);
        self::assertSame($checkpoint1->id, json_decode((string) $this->client->jsonGet($keys[0]), true)['checkpoint_id']);

        $checkpoint2 = self::checkpoint(1, ['value' => 2], ['value' => '2.0']);
        $this->saver->put($config, $checkpoint2, self::meta('loop', 2), ['value' => '2.0']);

        $keys = $this->client->keys("checkpoint:{$threadId}:*");
        self::assertSame(["checkpoint:{$threadId}::shallow"], $keys, 'the same key is reused');
        self::assertSame($checkpoint2->id, json_decode((string) $this->client->jsonGet($keys[0]), true)['checkpoint_id']);

        // A superseded checkpoint id is no longer addressable.
        self::assertNull($this->saver->getTuple(self::config($threadId, '', $checkpoint1->id)));
    }

    public function testShouldPreservePendingWritesWhenPutWritesIsCalledBeforePutInterruptFlow(): void
    {
        $checkpointId = CheckpointId::uuid6(0);
        $config = self::config('shallow-interrupt-flow', '', $checkpointId);

        $this->saver->putWrites($config, [['__interrupt__', ['value' => 'interrupted!', 'resumable' => true]]], 'task-interrupt');
        $this->saver->put(
            self::config('shallow-interrupt-flow'),
            new Checkpoint(v: 1, id: $checkpointId, ts: gmdate('c'), channelValues: ['messages' => ['hello']]),
            self::meta('loop', 1),
        );

        $tuple = $this->saver->getTuple($config);
        self::assertNotNull($tuple);
        self::assertCount(1, $tuple->pendingWrites);
        self::assertSame('task-interrupt', $tuple->pendingWrites[0][0]);
        self::assertSame('__interrupt__', $tuple->pendingWrites[0][1]);
        self::assertEquals(['value' => 'interrupted!', 'resumable' => true], $tuple->pendingWrites[0][2]);
    }

    public function testShouldPreservePendingWritesWhenPutWritesIsCalledAfterPutNormalFlow(): void
    {
        $savedConfig = $this->saver->put(
            self::config('shallow-normal-flow'),
            new Checkpoint(v: 1, id: CheckpointId::uuid6(0), ts: gmdate('c'), channelValues: ['messages' => ['hello']]),
            self::meta('loop', 1),
        );

        $this->saver->putWrites($savedConfig, [['__interrupt__', ['value' => 'after-put', 'resumable' => true]]], 'task-after');

        $tuple = $this->saver->getTuple($savedConfig);
        self::assertNotNull($tuple);
        self::assertCount(1, $tuple->pendingWrites);
        self::assertSame('__interrupt__', $tuple->pendingWrites[0][1]);
    }

    public function testShouldPreservePendingWritesAcrossPutPutWritesPutDoublePut(): void
    {
        $checkpoint = new Checkpoint(v: 1, id: CheckpointId::uuid6(0), ts: gmdate('c'), channelValues: ['messages' => ['hello']]);
        $config = self::config('shallow-double-put');
        $savedConfig = $this->saver->put($config, $checkpoint, self::meta('loop', 1));

        $this->saver->putWrites($savedConfig, [['__interrupt__', ['value' => 'survive-double-put', 'resumable' => true]]], 'task-double');
        $this->saver->put($config, $checkpoint, self::meta('loop', 1));

        $tuple = $this->saver->getTuple($savedConfig);
        self::assertNotNull($tuple);
        self::assertCount(1, $tuple->pendingWrites);
        self::assertSame('__interrupt__', $tuple->pendingWrites[0][1]);
        self::assertEquals(['value' => 'survive-double-put', 'resumable' => true], $tuple->pendingWrites[0][2]);
    }

    public function testDeleteThreadRemovesTheShallowCheckpointAndItsWrites(): void
    {
        $checkpoint = self::checkpoint(0);
        $config = self::config('shallow-delete', '', $checkpoint->id);
        $this->saver->put($config, $checkpoint, self::meta('loop', 1));
        $this->saver->putWrites($config, [['channel', 'value']], 'task');
        $this->saver->put(self::config('shallow-keep'), self::checkpoint(1), self::meta('loop', 1));

        $this->saver->deleteThread('shallow-delete');

        self::assertSame([], array_values(array_filter(
            $this->client->keys('*'),
            static fn (string $key): bool => str_contains($key, 'shallow-delete'),
        )));
        self::assertNotNull($this->saver->getTuple(self::config('shallow-keep')));
    }

    public function testRefusesGlobIdentifiers(): void
    {
        foreach ([
            fn () => $this->saver->deleteThread('*'),
            fn () => $this->saver->getTuple(self::config('t*')),
            fn () => $this->saver->list(['thread_id' => '*']),
            fn () => $this->saver->putWrites(self::config('t', '', 'c'), [['a', 1]], 'task-*'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected the glob identifier to be refused');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Redis pattern meta-character', $e->getMessage());
            }
        }
    }

    /**
     * Without an index the all-threads listing scans `checkpoint:*:*:shallow` keys. Creation is made
     * a no-op on the fake so the missing-index path is reachable; a real server's index is shared
     * state this test must not drop.
     */
    public function testListFallsBackToAKeyScanWhenTheSearchIndexIsMissing(): void
    {
        $client = new FakeRedisClient();
        $client->disableIndexing();
        $saver = new ShallowRedisSaver($client);
        $saver->put(self::config('scan-1'), self::checkpoint(0), self::meta('input', 1));
        $saver->put(self::config('scan-2'), self::checkpoint(1), self::meta('loop', 2));

        self::assertCount(2, $saver->list([]));
        $filtered = $saver->list([], new CheckpointListOptions(filter: ['source' => 'loop']));
        self::assertCount(1, $filtered);
        self::assertSame('scan-2', $filtered[0]->config['configurable']['thread_id']);
        self::assertNotSame([], $client->callsTo('ftSearch'), 'the search was attempted first');
    }
}
