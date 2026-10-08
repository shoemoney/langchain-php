<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\Redis\RedisClientInterface;
use LangGraph\Checkpoint\Redis\RedisSaver;
use LangGraph\Checkpoint\Redis\TtlConfig;
use LangGraph\Pregel\Constants;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Port of the integration blocks of `tests/checkpoint.int.test.ts` and
 * `tests/checkpoint-search.int.test.ts`, plus the checkpointer contract.
 *
 * Env-gated on `LANGGRAPH_REDIS_URL`: set it and every test here runs against that real Redis
 * Stack server through ext-redis; leave it unset and the same tests run against
 * {@see FakeRedisClient}. See {@see RedisLiveServer} for why the unset case runs instead of
 * skipping, and for the warning about pointing it at a dedicated database.
 *
 * Extending {@see CheckpointerSpecCase} is what makes this the guard for the fake: the contract
 * that passes against the fake in `RedisSaverSpecTest` must also pass against Redis.
 *
 * Converted: the integration workflow, `fromUrl`, `test_from_conn_string`,
 * `test_sync_redis_checkpointer` (including the three pending-writes flows, blob
 * reconstruction, blob deletion and the three blob-TTL cases) and `test_search`.
 * Not converted: the `RediSearch Index Creation` block, which asserts on `FT.INFO` output
 * and so tests the server, not the saver. `ShallowRedisSaverIntegrationTest` covers the rest.
 */
#[CoversClass(RedisSaver::class)]
#[CoversClass(TtlConfig::class)]
final class RedisSaverIntegrationTest extends CheckpointerSpecCase
{
    private RedisClientInterface $client;

    protected function setUp(): void
    {
        $this->client = RedisLiveServer::freshClient();
    }

    protected function makeSaver(): BaseCheckpointSaver
    {
        return new RedisSaver(RedisLiveServer::freshClient());
    }

    private function saver(?TtlConfig $ttl = null): RedisSaver
    {
        return new RedisSaver($this->client, $ttl);
    }

    /**
     * @param array<string, mixed> $channelValues
     * @param array<string, int|string> $channelVersions
     */
    private static function checkpoint(int $seq, array $channelValues = [], array $channelVersions = [], int $v = 1): Checkpoint
    {
        return new Checkpoint(
            v: $v,
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

    private static function meta(string $source = 'update', int $step = 0): array
    {
        return ['source' => $source, 'step' => $step, 'parents' => []];
    }

    // ---- RedisSaver Integration Tests -------------------------------------

    public function testShouldHandleBasicIntegrationWorkflow(): void
    {
        $saver = $this->saver();
        $checkpoint = self::checkpoint(0, ['test' => 'integration']);

        $saved = $saver->put(self::config('integration-test'), $checkpoint, self::meta());
        self::assertSame($checkpoint->id, $saved['configurable']['checkpoint_id']);

        $retrieved = $saver->getTuple($saved);
        self::assertSame($checkpoint->id, $retrieved?->checkpoint->id);
        self::assertSame(['test' => 'integration'], $retrieved?->checkpoint->channelValues);
    }

    public function testShouldWorkWithASecondSaverOpenedOnTheSameStore(): void
    {
        $other = new RedisSaver(RedisLiveServer::sameStore($this->client));
        $checkpoint = self::checkpoint(0, ['test' => 'fromUrl']);

        $other->put(self::config('fromurl-test'), $checkpoint, self::meta());

        $tuple = $other->getTuple(self::config('fromurl-test', '', $checkpoint->id));
        self::assertSame(['test' => 'fromUrl'], $tuple?->checkpoint->channelValues);
        $other->end();
    }

    // ---- test_from_conn_string --------------------------------------------

    public function testShouldCreateASaverWithAConnectionAndRoundTrip(): void
    {
        $saver = $this->saver();
        $checkpoint = self::checkpoint(0);

        $saved = $saver->put(self::config('test-thread'), $checkpoint, self::meta());
        self::assertSame($checkpoint->id, $saved['configurable']['checkpoint_id']);

        self::assertSame($checkpoint->id, $saver->getTuple($saved)?->checkpoint->id);
    }

    public function testShouldHandleMultipleSaversFromTheSameStore(): void
    {
        $saver1 = $this->saver();
        $saver2 = new RedisSaver(RedisLiveServer::sameStore($this->client));
        $checkpoint1 = self::checkpoint(0);
        $checkpoint2 = self::checkpoint(0);

        $saver1->put(self::config('test-thread-1'), $checkpoint1, self::meta('update', 0));
        $saver2->put(self::config('test-thread-2'), $checkpoint2, self::meta('update', 1));

        self::assertSame(
            $checkpoint1->id,
            $saver1->getTuple(self::config('test-thread-1', '', $checkpoint1->id))?->checkpoint->id,
        );
        self::assertSame(
            $checkpoint2->id,
            $saver2->getTuple(self::config('test-thread-2', '', $checkpoint2->id))?->checkpoint->id,
        );
    }

    public function testShouldHandleCrossSaverRetrieval(): void
    {
        $saver1 = $this->saver();
        $saver2 = new RedisSaver(RedisLiveServer::sameStore($this->client));
        $checkpoint = self::checkpoint(0);

        $saver1->put(self::config('shared-thread'), $checkpoint, self::meta());

        $retrieved = $saver2->getTuple(self::config('shared-thread', '', $checkpoint->id));
        self::assertSame($checkpoint->id, $retrieved?->checkpoint->id);
        self::assertSame(self::meta(), $retrieved?->metadata);
    }

    // ---- test_sync_redis_checkpointer -------------------------------------

    public function testShouldHandleBasicCheckpointOperations(): void
    {
        $saver = $this->saver();
        $config = self::config('test-thread-1');
        $messages = [
            ['type' => 'human', 'content' => "what's the weather in sf?"],
            ['type' => 'ai', 'content' => "I'll check the weather for you"],
            ['type' => 'tool', 'content' => "get_weather(city='sf')"],
            ['type' => 'ai', 'content' => "It's always sunny in sf"],
        ];
        $checkpoint = self::checkpoint(0, ['messages' => $messages], ['messages' => '1']);

        $next = $saver->put($config, $checkpoint, self::meta('update', 1), ['messages' => '1']);

        self::assertSame('test-thread-1', $next['configurable']['thread_id']);
        self::assertSame($checkpoint->id, $next['configurable']['checkpoint_id']);

        $latest = $saver->get($config);
        self::assertSame($checkpoint->id, $latest?->id);
        self::assertCount(4, $latest->channelValues['messages']);

        $tuple = $saver->getTuple($config);
        self::assertSame($checkpoint->id, $tuple?->checkpoint->id);
        self::assertSame('update', $tuple->metadata['source']);
        self::assertSame(1, $tuple->metadata['step']);
        self::assertSame([], $tuple->metadata['parents']);

        $checkpoints = $saver->list($config);
        self::assertCount(1, $checkpoints);
        self::assertSame($checkpoint->id, $checkpoints[0]->checkpoint->id);
    }

    public function testShouldHandleMultipleCheckpointsInSequence(): void
    {
        $saver = $this->saver();
        $config = self::config('sequence-thread');

        $checkpoint1 = self::checkpoint(0);
        $saver->put($config, $checkpoint1, self::meta('input', 0));

        $checkpoint2 = self::checkpoint(1, ['count' => 1], ['count' => '1']);
        $saver->put(self::config('sequence-thread', '', $checkpoint1->id), $checkpoint2, self::meta('loop', 1));

        $checkpoint3 = self::checkpoint(2, ['count' => 1, 'output' => 'result'], ['count' => '1', 'output' => '1']);
        $saver->put(self::config('sequence-thread', '', $checkpoint2->id), $checkpoint3, self::meta('loop', 2));

        self::assertSame($checkpoint3->id, $saver->get($config)?->id);

        $checkpoints = $saver->list($config);
        self::assertCount(3, $checkpoints);
        self::assertSame($checkpoint3->id, $checkpoints[0]->checkpoint->id);
        self::assertSame($checkpoint2->id, $checkpoints[1]->checkpoint->id);
        self::assertSame($checkpoint1->id, $checkpoints[2]->checkpoint->id);

        self::assertSame($checkpoint2->id, $checkpoints[0]->parentConfig['configurable']['checkpoint_id']);
        self::assertSame($checkpoint1->id, $checkpoints[1]->parentConfig['configurable']['checkpoint_id']);
        self::assertNull($checkpoints[2]->parentConfig);
    }

    public function testShouldHandleNamespaceIsolation(): void
    {
        $saver = $this->saver();
        $config1 = self::config('namespace-thread', '');
        $config2 = self::config('namespace-thread', 'inner');
        $config3 = self::config('other-thread', '');
        $checkpoint1 = self::checkpoint(0);
        $checkpoint2 = self::checkpoint(1);
        $checkpoint3 = self::checkpoint(2);

        $saver->put($config1, $checkpoint1, self::meta());
        $saver->put($config2, $checkpoint2, self::meta());
        $saver->put($config3, $checkpoint3, self::meta());

        self::assertSame($checkpoint1->id, $saver->get($config1)?->id);
        self::assertSame($checkpoint2->id, $saver->get($config2)?->id);
        self::assertSame($checkpoint3->id, $saver->get($config3)?->id);

        $list1 = $saver->list($config1);
        self::assertCount(1, $list1);
        self::assertSame($checkpoint1->id, $list1[0]->checkpoint->id);

        $list2 = $saver->list($config2);
        self::assertCount(1, $list2);
        self::assertSame($checkpoint2->id, $list2[0]->checkpoint->id);
    }

    public function testShouldHandleCheckpointRetrievalById(): void
    {
        $saver = $this->saver();
        $config = self::config('id-retrieval-thread');
        $checkpoint1 = self::checkpoint(0);
        $checkpoint2 = self::checkpoint(1, ['count' => 42]);

        $saver->put($config, $checkpoint1, self::meta('update', 1));
        $saver->put($config, $checkpoint2, self::meta('update', 2));

        self::assertSame($checkpoint2->id, $saver->get($config)?->id);

        $specific = $saver->get(self::config('id-retrieval-thread', '', $checkpoint1->id));
        self::assertSame($checkpoint1->id, $specific?->id);
        self::assertArrayNotHasKey('count', $specific->channelValues);

        $tuple = $saver->getTuple(self::config('id-retrieval-thread', '', $checkpoint2->id));
        self::assertSame($checkpoint2->id, $tuple?->checkpoint->id);
        self::assertSame(42, $tuple->checkpoint->channelValues['count']);
        self::assertSame(2, $tuple->metadata['step']);
    }

    public function testShouldHandleBeforeParameterInList(): void
    {
        $saver = $this->saver();
        $config = self::config('before-test-thread');
        $checkpoints = [];
        for ($i = 0; $i < 5; $i++) {
            $checkpoints[] = $checkpoint = self::checkpoint($i);
            $saver->put($config, $checkpoint, self::meta('update', $i));
        }

        self::assertCount(5, $saver->list($config));

        $before = $saver->list($config, new CheckpointListOptions(
            before: self::config('before-test-thread', '', $checkpoints[2]->id),
        ));

        self::assertCount(2, $before);
        self::assertSame($checkpoints[1]->id, $before[0]->checkpoint->id);
        self::assertSame($checkpoints[0]->id, $before[1]->checkpoint->id);
    }

    public function testShouldPreservePendingWritesWhenPutWritesIsCalledBeforePutInterruptFlow(): void
    {
        $saver = $this->saver();
        $checkpointId = CheckpointId::uuid6(0);
        $config = self::config('interrupt-flow-test', '', $checkpointId);

        // putWrites BEFORE put: the interrupt flow.
        $saver->putWrites(
            $config,
            [['__interrupt__', ['value' => 'interrupted!', 'resumable' => true]]],
            'task-interrupt',
        );

        // Putting the checkpoint used to clobber has_writes back to "false".
        $checkpoint = new Checkpoint(v: 4, id: $checkpointId, ts: gmdate('c'), channelValues: ['messages' => ['hello']]);
        $saver->put(self::config('interrupt-flow-test'), $checkpoint, self::meta('loop', 1));

        $tuple = $saver->getTuple($config);
        self::assertNotNull($tuple);
        self::assertCount(1, $tuple->pendingWrites);
        self::assertSame('task-interrupt', $tuple->pendingWrites[0][0]);
        self::assertSame('__interrupt__', $tuple->pendingWrites[0][1]);
        self::assertEquals(['value' => 'interrupted!', 'resumable' => true], $tuple->pendingWrites[0][2]);
    }

    public function testShouldPreservePendingWritesWhenPutWritesIsCalledAfterPutNormalFlow(): void
    {
        $saver = $this->saver();
        $checkpoint = new Checkpoint(v: 4, id: CheckpointId::uuid6(0), ts: gmdate('c'), channelValues: ['messages' => ['hello']]);
        $savedConfig = $saver->put(self::config('normal-flow-test'), $checkpoint, self::meta('loop', 1));

        $saver->putWrites($savedConfig, [['__interrupt__', ['value' => 'after-put', 'resumable' => true]]], 'task-after');

        $tuple = $saver->getTuple($savedConfig);
        self::assertNotNull($tuple);
        self::assertCount(1, $tuple->pendingWrites);
        self::assertSame('__interrupt__', $tuple->pendingWrites[0][1]);
    }

    public function testShouldPreservePendingWritesAcrossPutPutWritesPutDoublePut(): void
    {
        $saver = $this->saver();
        $config = self::config('double-put-test');
        $checkpoint = new Checkpoint(v: 4, id: CheckpointId::uuid6(0), ts: gmdate('c'), channelValues: ['messages' => ['hello']]);
        $savedConfig = $saver->put($config, $checkpoint, self::meta('loop', 1));

        $saver->putWrites($savedConfig, [['__interrupt__', ['value' => 'survive-double-put', 'resumable' => true]]], 'task-double');

        // A second put for the same checkpoint id must not clobber has_writes.
        $saver->put($config, $checkpoint, self::meta('loop', 1));

        $tuple = $saver->getTuple($savedConfig);
        self::assertNotNull($tuple);
        self::assertCount(1, $tuple->pendingWrites);
        self::assertSame('__interrupt__', $tuple->pendingWrites[0][1]);
        self::assertEquals(['value' => 'survive-double-put', 'resumable' => true], $tuple->pendingWrites[0][2]);
    }

    /** @return array{0: Checkpoint, 1: Checkpoint} */
    private function putBlobbedPair(RedisSaver $saver, string $threadId, bool $withEmptyCategory = false): array
    {
        $config = self::config($threadId);
        $messages = [['type' => 'human', 'content' => 'Hi there'], ['type' => 'ai', 'content' => 'Hello!']];

        $first = self::checkpoint(
            0,
            ['messages' => $messages, 'status' => 'idle'] + ($withEmptyCategory ? ['category' => ''] : []),
            ['messages' => '1', 'status' => '1'] + ($withEmptyCategory ? ['category' => '1'] : []),
        );
        $saver->put($config, $first, self::meta('loop', 1), $first->channelVersions);

        // A second node writes only `status` (and `category`): `messages` is carried over in
        // the checkpoint object but absent from newVersions, so it lives only in its blob.
        $second = self::checkpoint(
            1,
            ['messages' => $messages, 'status' => 'completed'] + ($withEmptyCategory ? ['category' => 'greeting'] : []),
            ['messages' => '1', 'status' => '2'] + ($withEmptyCategory ? ['category' => '2'] : []),
        );
        $saver->put(
            self::config($threadId, '', $first->id),
            $second,
            self::meta('loop', 2),
            ['status' => '2'] + ($withEmptyCategory ? ['category' => '2'] : []),
        );

        return [$first, $second];
    }

    /** @return list<string> */
    private function blobKeys(string $threadId, string $channel): array
    {
        return array_values(array_filter(
            $this->client->keys('*'),
            static fn (string $key): bool => str_starts_with($key, "checkpoint_blob:{$threadId}:") && str_contains($key, ":{$channel}:"),
        ));
    }

    public function testShouldReconstructChannelValuesFromBlobStorageWhenNewVersionsIsASubset(): void
    {
        $saver = $this->saver();
        $this->putBlobbedPair($saver, 'blob-recon-thread', withEmptyCategory: true);

        $latest = $saver->getTuple(self::config('blob-recon-thread'));

        self::assertNotNull($latest);
        self::assertSame(
            [['type' => 'human', 'content' => 'Hi there'], ['type' => 'ai', 'content' => 'Hello!']],
            $latest->checkpoint->channelValues['messages'],
        );
        self::assertSame('completed', $latest->checkpoint->channelValues['status']);
        self::assertSame('greeting', $latest->checkpoint->channelValues['category']);
    }

    public function testShouldDeleteChannelBlobsWhenDeletingAThread(): void
    {
        $saver = $this->saver();
        $this->putBlobbedPair($saver, 'blob-delete-thread');

        self::assertNotEmpty($this->blobKeys('blob-delete-thread', 'messages'), 'blob keys were created');

        $saver->deleteThread('blob-delete-thread');

        $remaining = array_filter(
            $this->client->keys('*'),
            static fn (string $key): bool => str_contains($key, 'blob-delete-thread'),
        );
        self::assertSame([], array_values($remaining));
    }

    public function testShouldRefreshChannelBlobTtlOnReadWhenRefreshOnReadIsEnabled(): void
    {
        // A long TTL so nothing expires mid-test: assert on the remaining TTL, not on wall-clock expiry.
        $ttlMinutes = 60;
        $saver = $this->saver(new TtlConfig(defaultTtl: $ttlMinutes, refreshOnRead: true));
        $this->putBlobbedPair($saver, 'blob-ttl-refresh-thread');

        $blobKeys = $this->blobKeys('blob-ttl-refresh-thread', 'messages');
        self::assertNotEmpty($blobKeys);
        $blobKey = $blobKeys[0];

        // Erode the blob's TTL so a refresh is observable.
        $this->client->expire($blobKey, 5);
        $before = RedisLiveServer::ttl($this->client, $blobKey);
        self::assertLessThanOrEqual(5, $before);

        $tuple = $saver->getTuple(self::config('blob-ttl-refresh-thread'));
        self::assertSame(
            [['type' => 'human', 'content' => 'Hi there'], ['type' => 'ai', 'content' => 'Hello!']],
            $tuple?->checkpoint->channelValues['messages'],
        );

        $after = RedisLiveServer::ttl($this->client, $blobKey);
        self::assertGreaterThan($before, $after);
        self::assertGreaterThan($ttlMinutes * 60 - 60, $after);
    }

    public function testShouldRefreshCarriedOverChannelBlobTtlOnWrite(): void
    {
        // A new checkpoint must keep EVERY blob it depends on alive, not just the channels its
        // node changed, or a carried-over blob could expire while the fresh checkpoint lives on.
        $ttlMinutes = 60;
        $saver = $this->saver(new TtlConfig(defaultTtl: $ttlMinutes, refreshOnRead: false));
        $config = self::config('blob-ttl-write-thread');
        $messages = [['type' => 'human', 'content' => 'Hi there']];

        $first = self::checkpoint(0, ['messages' => $messages, 'status' => 'idle'], ['messages' => '1', 'status' => '1']);
        $saver->put($config, $first, self::meta('loop', 1), ['messages' => '1', 'status' => '1']);

        $blobKeys = $this->blobKeys('blob-ttl-write-thread', 'messages');
        self::assertNotEmpty($blobKeys);
        $messagesBlob = $blobKeys[0];
        $this->client->expire($messagesBlob, 5);
        self::assertLessThanOrEqual(5, RedisLiveServer::ttl($this->client, $messagesBlob));

        $second = self::checkpoint(1, ['messages' => $messages, 'status' => 'completed'], ['messages' => '1', 'status' => '2']);
        $saver->put(self::config('blob-ttl-write-thread', '', $first->id), $second, self::meta('loop', 2), ['status' => '2']);

        $after = RedisLiveServer::ttl($this->client, $messagesBlob);
        self::assertGreaterThan(5, $after);
        self::assertGreaterThan($ttlMinutes * 60 - 60, $after);
    }

    public function testShouldDropAChannelCleanlyWhenItsBlobHasExpired(): void
    {
        // If a carried-over blob expires during an idle gap, reconstruction skips the channel
        // cleanly: the read succeeds and the channel is absent, not corrupt.
        $saver = $this->saver(new TtlConfig(defaultTtl: 60, refreshOnRead: true));
        $this->putBlobbedPair($saver, 'blob-expired-thread');

        $blobKeys = $this->blobKeys('blob-expired-thread', 'messages');
        self::assertNotEmpty($blobKeys);
        $this->client->del($blobKeys);

        $tuple = $saver->getTuple(self::config('blob-expired-thread'));

        self::assertNotNull($tuple);
        self::assertSame('completed', $tuple->checkpoint->channelValues['status']);
        self::assertArrayNotHasKey('messages', $tuple->checkpoint->channelValues);
    }

    // ---- test_search ------------------------------------------------------

    public function testShouldSearchCheckpointsByMetadata(): void
    {
        $saver = $this->saver();
        $checkpoint1 = self::checkpoint(0);
        $checkpoint2 = self::checkpoint(1, ['count' => 1]);
        $checkpoint3 = self::checkpoint(2);

        $saver->put(self::config('search-thread-1'), $checkpoint1, ['source' => 'input', 'step' => 2, 'parents' => [], 'score' => 1], []);
        $saver->put(self::config('search-thread-2'), $checkpoint2, ['source' => 'loop', 'step' => 1, 'parents' => [], 'score' => null], []);
        $saver->put(self::config('search-thread-2', 'inner'), $checkpoint3, ['source' => 'update', 'step' => 0, 'parents' => []], []);

        $search = fn (array $filter): array => $saver->list([], new CheckpointListOptions(filter: $filter));

        $bySource = $search(['source' => 'input', 'step' => 2, 'parents' => []]);
        self::assertCount(1, $bySource);
        self::assertSame($checkpoint1->id, $bySource[0]->checkpoint->id);
        self::assertSame('input', $bySource[0]->metadata['source']);

        $byStep = $search(['source' => 'loop', 'step' => 1, 'parents' => []]);
        self::assertCount(1, $byStep);
        self::assertSame($checkpoint2->id, $byStep[0]->checkpoint->id);
        self::assertSame(1, $byStep[0]->metadata['step']);

        self::assertCount(0, $search(['source' => 'fork', 'step' => 99, 'parents' => []]));

        $byNull = $search(['source' => 'loop', 'step' => 1, 'parents' => [], 'score' => null]);
        self::assertCount(1, $byNull);
        self::assertSame($checkpoint2->id, $byNull[0]->checkpoint->id);
    }

    public function testShouldSearchCheckpointsWithLimitAndBefore(): void
    {
        $saver = $this->saver();
        $config = self::config('limit-search-thread');
        for ($i = 0; $i < 10; $i++) {
            // `i % 3` makes groups of checkpoints with the same step.
            $saver->put($config, self::checkpoint($i), ['source' => 'update', 'step' => $i % 3, 'parents' => []], []);
        }
        $filter = ['source' => 'update', 'step' => 0, 'parents' => []];

        self::assertCount(3, $saver->list([], new CheckpointListOptions(filter: $filter, limit: 3)));

        $all = $saver->list([], new CheckpointListOptions(filter: $filter));
        self::assertGreaterThan(1, count($all));
        foreach ($all as $result) {
            self::assertSame(0, $result->metadata['step']);
        }
    }

    public function testShouldHandleSearchWithEmptyMetadata(): void
    {
        $saver = $this->saver();
        $config = self::config('empty-metadata-thread', 'test-ns');
        $checkpoint1 = self::checkpoint(0);
        $checkpoint2 = self::checkpoint(1);

        $saver->put($config, $checkpoint1, ['source' => 'input', 'step' => 0, 'parents' => []], []);
        $saver->put($config, $checkpoint2, ['source' => 'loop', 'step' => 1, 'parents' => []], []);

        self::assertCount(2, $saver->list($config));

        $specific = $saver->list($config, new CheckpointListOptions(filter: ['source' => 'input', 'step' => 0, 'parents' => []]));
        self::assertCount(1, $specific);
        self::assertSame($checkpoint1->id, $specific[0]->checkpoint->id);
    }

    // ---- a real graph -----------------------------------------------------

    public function testAGraphRunIsPersistedAndResumable(): void
    {
        $saver = $this->saver();
        $runs = [];

        $builder = new StateGraph(['value' => 'int', 'log' => 'list']);
        $builder->addNode('seed', static function (array $state) use (&$runs): array {
            $runs[] = 'seed';

            return ['value' => $state['value'] * 2, 'log' => ['seeded']];
        });
        $builder->addNode('finish', static function (array $state) use (&$runs): array {
            $runs[] = 'finish';

            return ['value' => $state['value'] + 100];
        });
        $builder->addEdge(Constants::START, 'seed');
        $builder->addEdge('seed', 'finish');
        $builder->addEdge('finish', Constants::END);
        $graph = $builder->compile(['checkpointer' => $saver]);

        $config = new RunnableConfig();
        $config->configurable = ['thread_id' => 'graph-run'];

        $result = $graph->invoke(['value' => 5], $config);
        self::assertSame(110, $result['value']);
        self::assertSame(['seeded'], $result['log']);

        $head = $saver->getTuple(['thread_id' => 'graph-run']);
        self::assertSame(110, $head?->checkpoint->channelValues['value']);
        self::assertSame(['seeded'], $head->checkpoint->channelValues['log']);
        self::assertGreaterThanOrEqual(2, count($saver->list(['thread_id' => 'graph-run'])));
    }
}
