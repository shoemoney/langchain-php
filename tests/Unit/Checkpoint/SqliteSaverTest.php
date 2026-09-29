<?php

declare(strict_types=1);

namespace LangGraph\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\SqliteSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The SQLite saver, on the things only a database can get wrong.
 *
 * Port of `libs/checkpoint-sqlite/src/tests/checkpoints.test.ts`.
 *
 * The shared contract lives in {@see CheckpointerSpecTest}; what is left here is
 * the SQL itself — the parts where a parent id, a metadata filter or a write
 * conflict can be expressed in a way an in-process map cannot accidentally get
 * right.
 */
#[CoversClass(SqliteSaver::class)]
final class SqliteSaverTest extends TestCase
{
    private function saver(): SqliteSaver
    {
        return SqliteSaver::fromConnString(':memory:');
    }

    public function testSavesAndRetrievesCheckpointsCorrectly(): void
    {
        $saver = $this->saver();

        self::assertNull($saver->getTuple(['thread_id' => '1']));

        $checkpoint1 = new Checkpoint(
            v: 1,
            id: CheckpointId::uuid6(0),
            ts: '2024-04-19T17:19:07.952Z',
            channelValues: ['someKey1' => 'someValue1'],
            channelVersions: ['someKey2' => 1],
            versionsSeen: ['someKey3' => ['someKey4' => 1]],
        );

        $config = $saver->put(['thread_id' => '1'], $checkpoint1, [
            'source' => 'update',
            'step' => -1,
            'parents' => [],
        ]);

        self::assertSame([
            'configurable' => [
                'thread_id' => '1',
                'checkpoint_ns' => '',
                'checkpoint_id' => $checkpoint1->id,
            ],
        ], $config);

        $saver->putWrites($config, [['bar', 'baz']], 'foo');

        $first = $saver->getTuple(['thread_id' => '1']);
        self::assertNotNull($first);
        self::assertSame($config, $first->config);
        self::assertEquals($checkpoint1->toArray(), $first->checkpoint->toArray());
        self::assertNull($first->parentConfig);
        self::assertSame([['foo', 'bar', 'baz']], $first->pendingWrites);

        $checkpoint2 = new Checkpoint(
            v: 1,
            id: CheckpointId::uuid6(1),
            ts: '2024-04-20T17:19:07.952Z',
            channelValues: ['someKey1' => 'someValue2'],
            channelVersions: ['someKey2' => 2],
            versionsSeen: ['someKey3' => ['someKey4' => 2]],
        );

        $saver->put(
            [
                'thread_id' => '1',
                'checkpoint_id' => '2024-04-18T17:19:07.952Z',
            ],
            $checkpoint2,
            ['source' => 'update', 'step' => -1, 'parents' => ['' => $checkpoint1->id]],
        );

        $second = $saver->getTuple(['thread_id' => '1']);
        self::assertNotNull($second);
        self::assertSame([
            'configurable' => [
                'thread_id' => '1',
                'checkpoint_ns' => '',
                'checkpoint_id' => '2024-04-18T17:19:07.952Z',
            ],
        ], $second->parentConfig);

        $listed = $saver->list(['thread_id' => '1'], new CheckpointListOptions(filter: [
            'source' => 'update',
            'step' => -1,
            'parents' => ['' => $checkpoint1->id],
        ]));

        self::assertCount(1, $listed);
        self::assertSame('2024-04-20T17:19:07.952Z', $listed[0]->checkpoint->ts);
    }

    public function testPreservesInterruptAndResumeWritesAtFixedNegativeIndices(): void
    {
        $saver = $this->saver();
        $checkpoint = Checkpoint::empty();
        $checkpoint->ts = '2024-04-19T17:19:07.952Z';

        $saved = $saver->put(['thread_id' => 't1'], $checkpoint, [
            'source' => 'input',
            'step' => -1,
            'parents' => [],
        ]);
        $config = [
            'thread_id' => 't1',
            'checkpoint_ns' => $saved['configurable']['checkpoint_ns'],
            'checkpoint_id' => $checkpoint->id,
        ];

        // Two regular writes at idx 0 and 1, then a special-channel write that
        // would have landed on idx 0 and replaced the first without the map.
        $saver->putWrites($config, [['foo', 'val_foo'], ['bar', 'val_bar']], 'task_A');
        $saver->putWrites($config, [['__interrupt__', 'paused']], 'task_A');
        $saver->putWrites($config, [['__resume__', 'carry_on']], 'task_A');

        // A concurrent task's regular write must not be ignored because another
        // task happened to use idx 0.
        $saver->putWrites($config, [['baz', 'val_baz']], 'task_B');

        $writes = $saver->getTuple($config)?->pendingWrites ?? [];
        $seen = [];
        foreach ($writes as [$taskId, $channel]) {
            $seen["{$taskId}:{$channel}"] = true;
        }

        self::assertEquals([
            'task_A:foo' => true,
            'task_A:bar' => true,
            'task_A:__interrupt__' => true,
            'task_A:__resume__' => true,
            'task_B:baz' => true,
        ], $seen);
    }

    public function testFiltersOnArbitraryMetadataKeys(): void
    {
        $saver = $this->saver();

        $put = static function (string $id, string $tenant, string $env) use ($saver): void {
            $checkpoint = Checkpoint::empty();
            $checkpoint->id = CheckpointId::uuid6((int) $id);
            $checkpoint->ts = sprintf('2024-06-08T0%s:00:00.000Z', $id);
            $saver->put(['thread_id' => $id], $checkpoint, [
                'source' => 'update',
                'step' => 0,
                'parents' => [],
                'tenant_id' => $tenant,
                'env' => $env,
            ]);
        };

        $put('1', 'acme', 'prod');
        $put('2', 'acme', 'dev');
        $put('3', 'globex', 'prod');

        $collect = static fn (array $filter): array => $saver->list([], new CheckpointListOptions(filter: $filter));
        $threadIds = static function (array $tuples): array {
            $ids = array_map(
                static fn ($tuple): string => (string) $tuple->config['configurable']['thread_id'],
                $tuples,
            );
            sort($ids);

            return $ids;
        };

        self::assertSame(['1', '2'], $threadIds($collect(['tenant_id' => 'acme'])));
        self::assertCount(2, $collect(['env' => 'prod']));
        self::assertCount(1, $collect(['tenant_id' => 'acme', 'env' => 'prod']));
        self::assertCount(0, $collect(['tenant_id' => 'missing']));
    }

    public function testFiltersOnANumericMetadataValue(): void
    {
        // The comparison runs against the *JSON* form of the value, so a numeric
        // `step` has to match the number that was stored rather than the string
        // "0" that `json_extract` would otherwise hand back.
        $saver = $this->saver();
        $checkpoint = Checkpoint::empty();
        $saver->put(['thread_id' => '1'], $checkpoint, ['source' => 'loop', 'step' => 0, 'parents' => []]);

        self::assertCount(1, $saver->list([], new CheckpointListOptions(filter: ['step' => 0])));
        self::assertCount(0, $saver->list([], new CheckpointListOptions(filter: ['step' => 1])));
    }

    public function testDeletingAThreadRemovesBothTables(): void
    {
        $saver = $this->saver();

        $configA = $saver->put(['thread_id' => '1'], Checkpoint::empty(), [
            'source' => 'update',
            'step' => -1,
            'parents' => [],
        ]);
        $saver->putWrites($configA, [['animals', 'dog']], 'task');
        $saver->put(['thread_id' => '2'], Checkpoint::empty(), [
            'source' => 'update',
            'step' => -1,
            'parents' => [],
        ]);

        $saver->deleteThread('1');

        self::assertNull($saver->getTuple(['thread_id' => '1']));
        self::assertSame([], $saver->list(['thread_id' => '1']));
        self::assertNotNull($saver->getTuple(['thread_id' => '2']));

        $rows = $saver->db()->query("SELECT COUNT(*) FROM writes WHERE thread_id = '1'")?->fetchColumn();
        self::assertSame(0, (int) $rows);
    }

    public function testASecondSaverOnTheSameFileSeesWhatTheFirstWrote(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'checkpoint') . '.sqlite';
        try {
            $writer = SqliteSaver::fromConnString($path);
            $checkpoint = Checkpoint::empty();
            $config = $writer->put(['thread_id' => 'durable'], $checkpoint, [
                'source' => 'update',
                'step' => -1,
                'parents' => [],
            ]);
            $writer->putWrites($config, [['animals', 'dog']], 'task');

            // A new process, a new connection, a new saver: this is what
            // "durable" means for a checkpointer.
            $reader = SqliteSaver::fromConnString($path);
            $tuple = $reader->getTuple(['thread_id' => 'durable']);

            self::assertNotNull($tuple);
            self::assertSame($checkpoint->id, $tuple->checkpoint->id);
            self::assertSame([['task', 'animals', 'dog']], $tuple->pendingWrites);
        } finally {
            @unlink($path);
        }
    }
}
