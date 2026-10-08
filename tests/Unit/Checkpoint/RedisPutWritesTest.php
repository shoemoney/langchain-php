<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Redis\RedisSaver;
use LangGraph\Checkpoint\Redis\ShallowRedisSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tests/putWrites.test.ts` from `@langchain/langgraph-checkpoint-redis`.
 *
 * `RedisSaver::putWrites` stores one JSON document per write under
 * `checkpoint_write:<thread>:<ns>:<ckpt>:<task>:<idx>`. Two things are asserted:
 *
 *  1. Special channels (error / scheduled / interrupt / resume) are written at the fixed
 *     negative indexes of `WRITES_IDX_MAP`, not at their ordinal in the call — otherwise
 *     they collide with a regular per-step write.
 *  2. The conflict clause matches the contract: overwrite when every write is a special
 *     channel, `NX` (insert-or-ignore) otherwise.
 *
 * Both are observable from the `jsonSet` calls the fake records. Upstream does this with
 * a hand-rolled mock client; here the recording fake is the mock.
 */
#[CoversClass(RedisSaver::class)]
#[CoversClass(ShallowRedisSaver::class)]
final class RedisPutWritesTest extends TestCase
{
    private const CONFIG = ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c']];

    /**
     * The `JSON.SET` calls that stored a write document, dropping the trailing
     * `has_writes` marker on the checkpoint key.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    private static function writeSets(FakeRedisClient $client): array
    {
        return array_values(array_filter(
            $client->callsTo('jsonSet'),
            static fn (array $call): bool => str_starts_with($call[0], 'checkpoint_write:'),
        ));
    }

    public function testUsesFixedNegativeIndicesForSpecialChannelsMixedWithRegularWritesAndNxGuardsTheInserts(): void
    {
        $client = new FakeRedisClient();
        $saver = new RedisSaver($client);

        $saver->putWrites(
            self::CONFIG,
            [
                ['foo', 'v_foo'],
                ['bar', 'v_bar'],
                ['__interrupt__', 'paused'],
            ],
            'task_A',
        );

        $writeSets = self::writeSets($client);
        $tails = array_map(static fn (array $call): string => substr($call[0], (int) strrpos($call[0], ':') + 1), $writeSets);
        sort($tails);

        // foo (idx 0), bar (idx 1), __interrupt__ (idx -3 via WRITES_IDX_MAP)
        self::assertSame(['-3', '0', '1'], $tails);

        // A mixed batch: every JSON.SET carries NX so a peer task's existing row at the
        // same (task_id, idx) is not clobbered.
        foreach ($writeSets as $call) {
            self::assertTrue($call[3], $call[0]);
        }
    }

    public function testUsesUnguardedJsonSetWhenEveryWriteIsASpecialChannel(): void
    {
        $client = new FakeRedisClient();
        $saver = new RedisSaver($client);

        $saver->putWrites(self::CONFIG, [['__resume__', 'carry_on']], 'task_A');

        $writeSets = self::writeSets($client);
        self::assertCount(1, $writeSets);
        // RESUME -> idx -4.
        self::assertStringEndsWith(':-4', $writeSets[0][0]);
        // No NX, so INTERRUPT -> RESUME state transitions overwrite what was there.
        self::assertFalse($writeSets[0][3]);
    }

    /** @return array<string, mixed> */
    private static function checkpointDocumentWithAnUndefinedPendingWrite(string $namespace = ''): array
    {
        return [
            'thread_id' => 't',
            'checkpoint_ns' => $namespace,
            'checkpoint_id' => 'c',
            'parent_checkpoint_id' => null,
            'checkpoint' => [
                'v' => 4,
                'id' => 'c',
                'ts' => '2026-01-01T00:00:00.000Z',
                'channel_values' => new \stdClass(),
                'channel_versions' => new \stdClass(),
                'versions_seen' => new \stdClass(),
            ],
            'metadata' => new \stdClass(),
            'checkpoint_ts' => 1,
            'has_writes' => 'true',
        ];
    }

    /** @return array<string, mixed> a write document with no `value` key at all */
    private static function writeDocumentWithoutValue(): array
    {
        return [
            'thread_id' => 't',
            'checkpoint_ns' => '',
            'checkpoint_id' => 'c',
            'task_id' => 'task_A',
            'idx' => 0,
            'channel' => 'regular',
            'type' => 'json',
            'timestamp' => 1,
            'global_idx' => 1,
        ];
    }

    public function testRedisSaverRestoresAMissingWriteValueAsNullInsteadOfParsingItAsJson(): void
    {
        $client = new FakeRedisClient();
        $client->seedJson('checkpoint:t::c', self::checkpointDocumentWithAnUndefinedPendingWrite('__empty__'));
        $client->seedJson('checkpoint_write:t::c:task_A:0', self::writeDocumentWithoutValue());

        $tuple = (new RedisSaver($client))->getTuple([
            'configurable' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c'],
        ]);

        // `undefined` in the upstream test; PHP's nearest is null.
        self::assertSame([['task_A', 'regular', null]], $tuple?->pendingWrites);
    }

    public function testShallowRedisSaverRestoresAMissingWriteValueAsNullInsteadOfParsingItAsJson(): void
    {
        $client = new FakeRedisClient();
        $client->seedJson('checkpoint:t::shallow', self::checkpointDocumentWithAnUndefinedPendingWrite());
        $client->seedZSet('write_keys_zset:t::c', ['checkpoint_write:t::c:task_A:0' => 0]);
        $client->seedJson('checkpoint_write:t::c:task_A:0', self::writeDocumentWithoutValue());

        $tuple = (new ShallowRedisSaver($client))->getTuple([
            'configurable' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c'],
        ]);

        self::assertSame([['task_A', 'regular', null]], $tuple?->pendingWrites);
    }
}
