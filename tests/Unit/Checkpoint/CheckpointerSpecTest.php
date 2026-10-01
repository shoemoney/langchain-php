<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointConstants;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\CheckpointTuple;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Checkpoint\SqliteSaver;
use LangGraph\Pregel\Checkpoint\CheckpointTuple as PregelCheckpointTuple;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The checkpointer contract, run against every saver.
 *
 * Port of `checkpoint-validation/src/spec/{put,put_writes,get_tuple,list,delete_thread}.ts`
 * plus the `MemorySaver` block of `libs/checkpoint/src/tests/checkpoints.test.ts`.
 *
 * The suite is written **once** and executed against both savers, which is the
 * whole point: a checkpointer's job is to be interchangeable, and a contract that
 * only one implementation satisfies is not a contract. The upstream
 * `checkpoint-sqlite` package runs the identical spec.
 */
#[CoversClass(BaseCheckpointSaver::class)]
#[CoversClass(MemorySaver::class)]
#[CoversClass(SqliteSaver::class)]
#[CoversClass(Checkpoint::class)]
#[CoversClass(CheckpointListOptions::class)]
final class CheckpointerSpecTest extends TestCase
{
    /** @var list<array{0: string, 1: callable(): BaseCheckpointSaver}> */
    private const SAVERS = [
        ['MemorySaver', self::class . '::newMemorySaver'],
        ['SqliteSaver', self::class . '::newSqliteSaver'],
    ];

    public static function newMemorySaver(): BaseCheckpointSaver
    {
        return new MemorySaver();
    }

    public static function newSqliteSaver(): BaseCheckpointSaver
    {
        return SqliteSaver::fromConnString(':memory:');
    }

    /**
     * @return list<array{0: string, 1: callable(): BaseCheckpointSaver}>
     */
    public static function savers(): array
    {
        return self::SAVERS;
    }

    // ---- put --------------------------------------------------------------

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testPutReturnsAConfigWithOnlyTheThreeAddressingKeys(
        string $name,
        callable $make,
    ): void {
        $saver = $make();
        $threadId = CheckpointId::uuid6(3);
        $checkpointId = CheckpointId::uuid6(3);

        $tuple = CheckpointerFixture::initialCheckpointTuple($threadId, 'root-ns', $checkpointId);

        // `canary` is here to prove extra configurable fields are not stored
        // into the returned config: only the three addressing keys may appear.
        $returned = $saver->put(
            ['thread_id' => $threadId, 'checkpoint_ns' => 'root-ns', 'canary' => 'tweet'],
            $tuple->checkpoint,
            $tuple->metadata,
        );

        self::assertArrayHasKey('configurable', $returned, $name);
        self::assertSame(
            ['thread_id', 'checkpoint_ns', 'checkpoint_id'],
            array_keys($returned['configurable']),
            $name,
        );
        self::assertSame($threadId, $returned['configurable']['thread_id'], $name);
        self::assertSame('root-ns', $returned['configurable']['checkpoint_ns'], $name);
        self::assertSame($checkpointId, $returned['configurable']['checkpoint_id'], $name);
    }

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testPutStoresTheCheckpointAndMetadataWithoutAlteration(
        string $name,
        callable $make,
    ): void {
        $saver = $make();
        $threadId = CheckpointId::uuid6(3);
        $checkpointId = CheckpointId::uuid6(3);

        $tuple = CheckpointerFixture::initialCheckpointTuple(
            $threadId,
            'root-ns',
            $checkpointId,
            ['animals' => ['dog'], 'count' => 3],
        );

        self::assertNull($saver->get(['thread_id' => $threadId, 'checkpoint_ns' => 'root-ns', 'checkpoint_id' => $checkpointId]), $name);

        $returned = $saver->put(
            ['thread_id' => $threadId, 'checkpoint_ns' => 'root-ns'],
            $tuple->checkpoint,
            $tuple->metadata,
        );

        $roundTripped = $saver->getTuple($returned);

        self::assertNotNull($roundTripped, $name);
        self::assertEquals($tuple->checkpoint->toArray(), $roundTripped->checkpoint->toArray(), $name);
        self::assertEquals($tuple->metadata, $roundTripped->metadata, $name);
        self::assertEquals(
            ['thread_id' => $threadId, 'checkpoint_ns' => 'root-ns', 'checkpoint_id' => $checkpointId],
            $roundTripped->config['configurable'],
            $name,
        );
    }

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testPutDefaultsAnAbsentNamespaceToTheEmptyString(
        string $name,
        callable $make,
    ): void {
        $saver = $make();
        $threadId = CheckpointId::uuid6(3);
        $checkpointId = CheckpointId::uuid6(3);
        $tuple = CheckpointerFixture::initialCheckpointTuple($threadId, '', $checkpointId);

        $returned = $saver->put(['thread_id' => $threadId], $tuple->checkpoint, $tuple->metadata);

        self::assertArrayHasKey('checkpoint_ns', $returned['configurable'], $name);
        self::assertSame('', $returned['configurable']['checkpoint_ns'], $name);
        self::assertNotNull($saver->getTuple(['thread_id' => $threadId, 'checkpoint_ns' => '']), $name);
    }

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testPutFailsWithoutAConfigurable(string $name, callable $make): void
    {
        $saver = $make();
        $tuple = CheckpointerFixture::initialCheckpointTuple(CheckpointId::uuid6(3), '', CheckpointId::uuid6(3));

        $this->expectException(\InvalidArgumentException::class);
        $saver->put([], $tuple->checkpoint, $tuple->metadata);
    }

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testPutFailsWithoutAThreadId(string $name, callable $make): void
    {
        $saver = $make();
        $tuple = CheckpointerFixture::initialCheckpointTuple(CheckpointId::uuid6(3), 'ns', CheckpointId::uuid6(3));

        $this->expectException(\InvalidArgumentException::class);
        $saver->put(['checkpoint_ns' => 'ns'], $tuple->checkpoint, $tuple->metadata);
    }

    // ---- putWrites --------------------------------------------------------

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testPutWritesStoresWritesAgainstTheCheckpoint(
        string $name,
        callable $make,
    ): void {
        foreach (['', 'child'] as $namespace) {
            $saver = $make();
            $threadId = CheckpointId::uuid6(3);
            $checkpointId = CheckpointId::uuid6(3);
            $tuple = CheckpointerFixture::initialCheckpointTuple($threadId, $namespace, $checkpointId);

            $returned = $saver->put(
                ['thread_id' => $threadId, 'checkpoint_ns' => $namespace],
                $tuple->checkpoint,
                $tuple->metadata,
            );
            $saver->putWrites($returned, [['animals', 'dog']], 'pet_task');

            $saved = $saver->getTuple($returned);

            self::assertNotNull($saved, $name);
            self::assertSame([['pet_task', 'animals', 'dog']], $saved->pendingWrites, $name . '/' . $namespace);
        }
    }

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testPutWritesFailsWithoutAThreadId(string $name, callable $make): void
    {
        $saver = $make();

        $this->expectException(\InvalidArgumentException::class);
        $saver->putWrites(['checkpoint_ns' => '', 'checkpoint_id' => 'x'], [['animals', 'dog']], 'pet_task');
    }

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testPutWritesFailsWithoutACheckpointId(string $name, callable $make): void
    {
        $saver = $make();

        $this->expectException(\InvalidArgumentException::class);
        $saver->putWrites(['thread_id' => 't', 'checkpoint_ns' => ''], [['animals', 'dog']], 'pet_task');
    }

    /**
     * A regular write is stored once. A second call at the same `(task, index)`
     * must not replace it, or a retried task's first attempt would be lost.
     *
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testARegularWriteIsNeverReplaced(string $name, callable $make): void
    {
        $saver = $make();
        $threadId = CheckpointId::uuid6(3);
        $tuple = CheckpointerFixture::initialCheckpointTuple($threadId, '', CheckpointId::uuid6(3));
        $returned = $saver->put(['thread_id' => $threadId], $tuple->checkpoint, $tuple->metadata);

        $saver->putWrites($returned, [['animals', 'first']], 'task');
        $saver->putWrites($returned, [['animals', 'second']], 'task');

        self::assertSame(
            [['task', 'animals', 'first']],
            $saver->getTuple($returned)?->pendingWrites,
            $name,
        );
    }

    /**
     * A special-channel write always replaces, which is what lets a resume
     * overwrite the interrupt it is answering.
     *
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testASpecialChannelWriteAlwaysReplaces(string $name, callable $make): void
    {
        $saver = $make();
        $threadId = CheckpointId::uuid6(3);
        $tuple = CheckpointerFixture::initialCheckpointTuple($threadId, '', CheckpointId::uuid6(3));
        $returned = $saver->put(['thread_id' => $threadId], $tuple->checkpoint, $tuple->metadata);

        $saver->putWrites($returned, [[CheckpointConstants::INTERRUPT, 'paused']], 'task');
        $saver->putWrites($returned, [[CheckpointConstants::INTERRUPT, 'still paused']], 'task');

        self::assertSame(
            [['task', CheckpointConstants::INTERRUPT, 'still paused']],
            $saver->getTuple($returned)?->pendingWrites,
            $name,
        );
    }

    /**
     * One task's special write must not collide with another's regular write at
     * the same ordinal position.
     *
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testSpecialAndRegularWritesDoNotCollide(string $name, callable $make): void
    {
        $saver = $make();
        $threadId = CheckpointId::uuid6(3);
        $tuple = CheckpointerFixture::initialCheckpointTuple($threadId, '', CheckpointId::uuid6(3));
        $returned = $saver->put(['thread_id' => $threadId], $tuple->checkpoint, $tuple->metadata);

        $saver->putWrites($returned, [['foo', 'val_foo'], ['bar', 'val_bar']], 'task_A');
        $saver->putWrites($returned, [[CheckpointConstants::INTERRUPT, 'paused']], 'task_A');
        $saver->putWrites($returned, [[CheckpointConstants::RESUME, 'carry_on']], 'task_A');
        $saver->putWrites($returned, [['baz', 'val_baz']], 'task_B');

        $writes = $saver->getTuple($returned)?->pendingWrites ?? [];
        $seen = [];
        foreach ($writes as [$taskId, $channel]) {
            $seen["{$taskId}:{$channel}"] = true;
        }

        self::assertEquals([
            'task_A:foo' => true,
            'task_A:bar' => true,
            'task_A:' . CheckpointConstants::INTERRUPT => true,
            'task_A:' . CheckpointConstants::RESUME => true,
            'task_B:baz' => true,
        ], $seen, $name);
    }

    // ---- getTuple ---------------------------------------------------------

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testGetTupleReturnsTheRequestedCheckpointAndItsAncestry(
        string $name,
        callable $make,
    ): void {
        $saver = $make();

        foreach (['', 'child'] as $namespace) {
            $threadId = CheckpointId::uuid6(3);
            $parentId = CheckpointId::uuid6(3);
            $childId = CheckpointId::uuid6(3);

            $writesToParent = [[
                'writes' => [[CheckpointConstants::TASKS, ['add_fish']]],
                'taskId' => 'pending_sends_task',
            ]];
            $writesToChild = [[
                'writes' => [['animals', ['dog', 'fish']]],
                'taskId' => 'add_fish',
            ]];

            ['parent' => $parent, 'child' => $child] = CheckpointerFixture::parentAndChildTuples(
                threadId: $threadId,
                parentCheckpointId: $parentId,
                childCheckpointId: $childId,
                checkpointNs: $namespace,
                initialChannelValues: ['animals' => ['dog']],
                writesToParent: $writesToParent,
                writesToChild: $writesToChild,
            );

            $stored = iterator_to_array(CheckpointerFixture::putTuples($saver, [
                ['tuple' => $parent, 'writes' => $writesToParent, 'newVersions' => ['animals' => 1]],
                ['tuple' => $child, 'writes' => $writesToChild, 'newVersions' => ['animals' => 2]],
            ]), false);

            [$parentTuple, $childTuple] = $stored;
            $label = $name . '/' . ($namespace === '' ? 'root' : $namespace);

            // The first checkpoint: no parent, and its own writes.
            self::assertEquals($parent->checkpoint->toArray(), $parentTuple->checkpoint->toArray(), $label);
            self::assertEquals($parent->metadata, $parentTuple->metadata, $label);
            self::assertEquals(
                ['thread_id' => $threadId, 'checkpoint_ns' => $namespace, 'checkpoint_id' => $parentId],
                $parentTuple->config['configurable'],
                $label,
            );
            self::assertNull($parentTuple->parentConfig, $label);
            self::assertSame(
                [['pending_sends_task', CheckpointConstants::TASKS, ['add_fish']]],
                $parentTuple->pendingWrites,
                $label,
            );

            // The subsequent checkpoint: parented, and its own writes.
            self::assertEquals($child->checkpoint->toArray(), $childTuple->checkpoint->toArray(), $label);
            self::assertEquals($child->metadata, $childTuple->metadata, $label);
            self::assertEquals(
                ['thread_id' => $threadId, 'checkpoint_ns' => $namespace, 'checkpoint_id' => $childId],
                $childTuple->config['configurable'],
                $label,
            );
            self::assertEquals(
                ['thread_id' => $threadId, 'checkpoint_ns' => $namespace, 'checkpoint_id' => $parentId],
                $childTuple->parentConfig['configurable'],
                $label,
            );
            self::assertSame(
                [['add_fish', 'animals', ['dog', 'fish']]],
                $childTuple->pendingWrites,
                $label,
            );

            // No `checkpoint_id`: the latest.
            $latest = $saver->getTuple(['thread_id' => $threadId, 'checkpoint_ns' => $namespace]);
            self::assertNotNull($latest, $label);
            self::assertEquals($child->checkpoint->toArray(), $latest->checkpoint->toArray(), $label);
            self::assertEquals($child->metadata, $latest->metadata, $label);
            self::assertEquals(
                ['thread_id' => $threadId, 'checkpoint_ns' => $namespace, 'checkpoint_id' => $childId],
                $latest->config['configurable'],
                $label,
            );
            self::assertEquals(
                ['thread_id' => $threadId, 'checkpoint_ns' => $namespace, 'checkpoint_id' => $parentId],
                $latest->parentConfig['configurable'],
                $label,
            );
            self::assertSame(
                [['add_fish', 'animals', ['dog', 'fish']]],
                $latest->pendingWrites,
                $label,
            );
        }
    }

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testGetTupleReturnsNullForAnUnknownCheckpointId(
        string $name,
        callable $make,
    ): void {
        $saver = $make();

        self::assertNull($saver->getTuple([
            'thread_id' => CheckpointId::uuid6(3),
            'checkpoint_ns' => 'ns',
            'checkpoint_id' => CheckpointId::uuid6(3),
        ]), $name);
    }

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testGetTupleReturnsNullWithoutAThreadId(string $name, callable $make): void
    {
        $saver = $make();

        self::assertNull($saver->getTuple(['checkpoint_ns' => 'ns']), $name);
    }

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testGetReturnsOnlyTheCheckpoint(string $name, callable $make): void
    {
        $saver = $make();
        $threadId = CheckpointId::uuid6(3);
        $tuple = CheckpointerFixture::initialCheckpointTuple($threadId, '', CheckpointId::uuid6(3), ['animals' => ['dog']]);
        $saver->put(['thread_id' => $threadId], $tuple->checkpoint, $tuple->metadata);

        self::assertEquals(
            $tuple->checkpoint->toArray(),
            $saver->get(['thread_id' => $threadId])?->toArray(),
            $name,
        );
        self::assertNull($saver->get(['thread_id' => 'never-used']), $name);
    }

    /**
     * A channel written by an ancestor but not by the latest node still has to
     * read back from the latest checkpoint — otherwise resuming at that
     * checkpoint silently forgets the channel.
     *
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testChannelsCarriedOverFromAnAncestorArePreserved(
        string $name,
        callable $make,
    ): void {
        $saver = $make();
        $threadId = CheckpointId::uuid6(3);
        $parentId = CheckpointId::uuid6(3);
        $childId = CheckpointId::uuid6(3);

        $parent = new CheckpointTuple(
            config: CheckpointerFixture::config($threadId, '', $parentId),
            checkpoint: new Checkpoint(
                v: 4,
                id: $parentId,
                ts: gmdate('Y-m-d\TH:i:s.v\Z'),
                channelValues: ['messages' => ['hi'], 'stepCount' => 1],
                channelVersions: ['messages' => 1, 'stepCount' => 1],
                versionsSeen: ['' => ['someChannel' => 1]],
            ),
            metadata: ['source' => 'loop', 'step' => 0, 'parents' => []],
        );

        $child = new CheckpointTuple(
            config: CheckpointerFixture::config($threadId, '', $childId),
            checkpoint: new Checkpoint(
                v: 4,
                id: $childId,
                ts: gmdate('Y-m-d\TH:i:s.v\Z'),
                // `messages` carries over from the parent and is intentionally
                // absent from the child's `newVersions` — the shape a node that
                // never touches the channel produces.
                channelValues: ['messages' => ['hi'], 'stepCount' => 3],
                channelVersions: ['messages' => 1, 'stepCount' => 2],
                versionsSeen: ['' => ['someChannel' => 1]],
            ),
            metadata: ['source' => 'loop', 'step' => 1, 'parents' => []],
            parentConfig: CheckpointerFixture::config($threadId, '', $parentId),
        );

        iterator_to_array(CheckpointerFixture::putTuples($saver, [
            ['tuple' => $parent, 'writes' => [], 'newVersions' => ['messages' => 1, 'stepCount' => 1]],
            ['tuple' => $child, 'writes' => [], 'newVersions' => ['stepCount' => 2]],
        ]), false);

        $latest = $saver->getTuple(['thread_id' => $threadId, 'checkpoint_ns' => '']);

        self::assertNotNull($latest, $name);
        self::assertSame(
            ['messages' => ['hi'], 'stepCount' => 3],
            $latest->checkpoint->channelValues,
            $name,
        );
    }

    // ---- the pre-format-4 pending-sends migration -------------------------

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testMigratesPendingSendsOnRead(string $name, callable $make): void
    {
        $saver = $make();
        $config = ['thread_id' => 'thread-1', 'checkpoint_ns' => ''];

        $checkpoint0 = new Checkpoint(v: 1, id: CheckpointId::uuid6(0), ts: '2024-04-19T17:19:07.952Z');
        $config = $saver->put($config, $checkpoint0, ['source' => 'loop', 'parents' => [], 'step' => 0]);

        $saver->putWrites($config, [
            [CheckpointConstants::TASKS, 'send-1'],
            [CheckpointConstants::TASKS, 'send-2'],
        ], 'task-1');
        $saver->putWrites($config, [[CheckpointConstants::TASKS, 'send-3']], 'task-2');

        // The sends belong to the *next* checkpoint, not to the one they were
        // scheduled from.
        $tuple0 = $saver->getTuple($config);
        self::assertSame([], $tuple0?->checkpoint->channelValues, $name);
        self::assertSame([], $tuple0?->checkpoint->channelVersions, $name);

        $checkpoint1 = new Checkpoint(v: 1, id: CheckpointId::uuid6(1), ts: '2024-04-20T17:19:07.952Z');
        $config = $saver->put($config, $checkpoint1, ['source' => 'loop', 'parents' => [], 'step' => 1]);

        $tuple1 = $saver->getTuple($config);
        self::assertSame(
            [CheckpointConstants::TASKS => ['send-1', 'send-2', 'send-3']],
            $tuple1?->checkpoint->channelValues,
            $name,
        );
        self::assertArrayHasKey(
            CheckpointConstants::TASKS,
            $tuple1?->checkpoint->channelVersions ?? [],
            $name,
        );
    }

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testListAlsoMigratesOldCheckpoints(string $name, callable $make): void
    {
        $saver = $make();
        $config = ['thread_id' => 'thread-1', 'checkpoint_ns' => ''];

        $config = $saver->put(
            $config,
            new Checkpoint(v: 1, id: CheckpointId::uuid6(0), ts: '2024-04-19T17:19:07.952Z'),
            ['source' => 'loop', 'parents' => [], 'step' => 0],
        );
        $saver->putWrites($config, [
            [CheckpointConstants::TASKS, 'send-1'],
            [CheckpointConstants::TASKS, 'send-2'],
        ], 'task-1');
        $saver->putWrites($config, [[CheckpointConstants::TASKS, 'send-3']], 'task-2');

        $saver->put(
            $config,
            new Checkpoint(v: 1, id: CheckpointId::uuid6(1), ts: '2024-04-20T17:19:07.952Z'),
            ['source' => 'loop', 'parents' => [], 'step' => 1],
        );

        $tuples = $saver->list(['thread_id' => 'thread-1']);

        self::assertCount(2, $tuples, $name);
        self::assertSame(
            [CheckpointConstants::TASKS => ['send-1', 'send-2', 'send-3']],
            $tuples[0]->checkpoint->channelValues,
            $name,
        );
        self::assertArrayHasKey(CheckpointConstants::TASKS, $tuples[0]->checkpoint->channelVersions, $name);
    }

    // ---- list -------------------------------------------------------------

    /**
     * The argument matrix, built exactly as the upstream spec builds it.
     *
     * @return list<array{0: string, 1: callable(): BaseCheckpointSaver, 2: array<string, mixed>, 3: list<string>}>
     */
    public static function listCases(): array
    {
        $generated = CheckpointerFixture::generatedTuples();
        $all = array_map(static fn (array $entry): CheckpointTuple => $entry['tuple'], $generated);

        $invalidThreadId = CheckpointId::uuid6(3);
        $threadIds = [null];
        foreach ($all as $tuple) {
            $threadIds[] = $tuple->config['configurable']['thread_id'];
        }
        $threadIds = array_values(array_unique($threadIds, SORT_REGULAR));
        $threadIds[] = $invalidThreadId;

        $namespaces = [null, '', 'child'];
        $limits = [null, 1, 2];
        $befores = [null, $all[0]->config, $all[1]->config];
        $filters = [null, [], ['source' => 'input'], ['source' => 'loop']];

        $cases = [];
        foreach ($threadIds as $threadId) {
            foreach ($namespaces as $namespace) {
                foreach ($limits as $limit) {
                    foreach ($befores as $before) {
                        foreach ($filters as $filter) {
                            $expected = [];
                            foreach ($all as $tuple) {
                                $configurable = $tuple->config['configurable'];
                                if ($threadId !== null && $configurable['thread_id'] !== $threadId) {
                                    continue;
                                }
                                if ($namespace !== null && $configurable['checkpoint_ns'] !== $namespace) {
                                    continue;
                                }
                                if ($before !== null
                                    && !($tuple->checkpoint->id < $before['configurable']['checkpoint_id'])) {
                                    continue;
                                }
                                if ($filter !== null) {
                                    $matches = true;
                                    foreach ($filter as $key => $value) {
                                        if (($tuple->metadata[$key] ?? null) !== $value) {
                                            $matches = false;
                                            break;
                                        }
                                    }
                                    if (!$matches) {
                                        continue;
                                    }
                                }
                                $expected[] = $tuple->checkpoint->id;
                            }

                            $cases[] = [
                                self::describeListCase($threadId, $namespace, $limit, $before, $filter, $expected),
                                self::class . '::newMemorySaver',
                                [
                                    'thread_id' => $threadId,
                                    'checkpoint_ns' => $namespace,
                                    'thread_ns' => $namespace !== null,
                                    'limit' => $limit,
                                    'before' => $before,
                                    'filter' => $filter,
                                ],
                                $expected,
                            ];
                            $cases[] = [
                                self::describeListCase($threadId, $namespace, $limit, $before, $filter, $expected)
                                    . ' [sqlite]',
                                self::class . '::newSqliteSaver',
                                [
                                    'thread_id' => $threadId,
                                    'checkpoint_ns' => $namespace,
                                    'thread_ns' => $namespace !== null,
                                    'limit' => $limit,
                                    'before' => $before,
                                    'filter' => $filter,
                                ],
                                $expected,
                            ];
                        }
                    }
                }
            }
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $expectedIds
     */
    #[DataProvider('listCases')]
    public function testListFiltersByThreadNamespaceLimitBeforeAndMetadata(
        string $description,
        callable $make,
        array $options,
        array $expectedIds,
    ): void {
        $saver = $make();
        $stored = CheckpointerFixture::toMap(
            iterator_to_array(CheckpointerFixture::putTuples($saver, CheckpointerFixture::generatedTuples()), false),
        );

        $config = ['thread_id' => $options['thread_id']];
        if ($options['thread_ns'] ?? false) {
            $config['checkpoint_ns'] = $options['checkpoint_ns'];
        }

        $listOptions = new CheckpointListOptions(
            before: $options['before'],
            limit: $options['limit'],
            filter: $options['filter'],
        );

        $actual = $saver->list($config, $listOptions);

        $limitEnforced = $options['limit'] !== null && $options['limit'] < count($expectedIds);
        self::assertCount(
            $limitEnforced ? $options['limit'] : count($expectedIds),
            $actual,
            $description,
        );

        // Newest first within a thread and namespace — a `before` cursor is only
        // meaningful if the result is ordered. Across threads the savers group
        // differently (a database can sort globally, an in-process map cannot),
        // which is why the upstream spec compares this list as a set.
        self::assertNewestFirstPerGroup($actual, $description);

        $actualMap = CheckpointerFixture::toMap($actual);
        foreach ($actual as $tuple) {
            $id = $tuple->checkpoint->id;
            self::assertArrayHasKey($id, $stored, $description);
            // `list` must return exactly what `getTuple` returns for that
            // checkpoint — same values, same metadata, same parent, same writes.
            self::assertEquals($stored[$id], $tuple, $description);
        }

        if (!$limitEnforced) {
            $expectedMap = [];
            foreach ($expectedIds as $id) {
                $expectedMap[$id] = $stored[$id];
            }
            self::assertEquals($expectedMap, $actualMap, $description);
        }
    }

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testListReturnsNewestFirstAndAcceptsABareLimit(
        string $name,
        callable $make,
    ): void {
        $saver = $make();
        iterator_to_array(CheckpointerFixture::putTuples($saver, CheckpointerFixture::generatedTuples()), false);

        // Within one thread *and* namespace the order is a single descending run.
        $threadId = CheckpointerFixture::generatedTuples()[0]['tuple']->config['configurable']['thread_id'];
        $tuples = $saver->list(['thread_id' => $threadId, 'checkpoint_ns' => '']);

        $ids = array_map(static fn (PregelCheckpointTuple $t): string => $t->checkpoint->id, $tuples);
        self::assertNotEmpty($ids, $name);
        $sorted = $ids;
        rsort($sorted, SORT_STRING);
        self::assertSame($sorted, $ids, $name);

        // A thread spans two namespaces here, and across every thread the result
        // is still newest-first within each thread and namespace.
        self::assertNewestFirstPerGroup($saver->list(['thread_id' => $threadId]), $name);
        self::assertNewestFirstPerGroup($saver->list([]), $name);

        // The engine passes a bare integer; the options object is the richer form.
        self::assertCount(1, $saver->list([], 1), $name);
        self::assertSame([], $saver->list([], 0), $name);
    }

    /**
     * Assert the ids descend within each contiguous `(thread, namespace)` run.
     *
     * @param list<PregelCheckpointTuple> $tuples
     */
    private static function assertNewestFirstPerGroup(array $tuples, string $message): void
    {
        $group = null;
        $previous = null;
        foreach ($tuples as $tuple) {
            $configurable = $tuple->config['configurable'];
            $key = $configurable['thread_id'] . '/' . ($configurable['checkpoint_ns'] ?? '');
            if ($key !== $group) {
                $group = $key;
                $previous = null;
            }
            if ($previous !== null) {
                self::assertLessThan(
                    $previous,
                    $tuple->checkpoint->id,
                    $message . ' (group ' . $key . ')',
                );
            }
            $previous = $tuple->checkpoint->id;
        }
    }

    // ---- deleteThread -----------------------------------------------------

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testDeleteThreadRemovesOnlyThatThread(string $name, callable $make): void
    {
        $saver = $make();
        $meta = ['source' => 'update', 'step' => -1, 'parents' => []];

        $saver->put(['thread_id' => '1', 'checkpoint_ns' => ''], Checkpoint::empty(), $meta);
        $saver->put(['thread_id' => '2', 'checkpoint_ns' => ''], Checkpoint::empty(), $meta);
        self::assertNotNull($saver->getTuple(['thread_id' => '1', 'checkpoint_ns' => '']), $name);

        $saver->deleteThread('1');

        self::assertNull($saver->getTuple(['thread_id' => '1', 'checkpoint_ns' => '']), $name);
        self::assertNotNull($saver->getTuple(['thread_id' => '2', 'checkpoint_ns' => '']), $name);
    }

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testDeleteThreadAlsoRemovesItsWrites(string $name, callable $make): void
    {
        $saver = $make();
        $config = $saver->put(
            ['thread_id' => '1'],
            Checkpoint::empty(),
            ['source' => 'update', 'step' => -1, 'parents' => []],
        );
        $saver->putWrites($config, [['animals', 'dog']], 'task');

        $saver->deleteThread('1');

        self::assertSame([], $saver->list(['thread_id' => '1']), $name);
    }

    // ---- getDeltaChannelHistory -------------------------------------------

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testGetDeltaChannelHistoryCollectsWritesAndSeed(
        string $name,
        callable $make,
    ): void {
        $saver = $make();
        $threadId = CheckpointId::uuid6(3);
        $parentId = CheckpointId::uuid6(3);
        $childId = CheckpointId::uuid6(3);

        $parent = new CheckpointTuple(
            config: CheckpointerFixture::config($threadId, '', $parentId),
            checkpoint: new Checkpoint(
                v: 4,
                id: $parentId,
                ts: '2024-04-19T17:19:07.952Z',
                channelValues: ['log' => ['seed']],
                channelVersions: ['log' => 1],
                versionsSeen: [],
            ),
            metadata: ['source' => 'loop', 'step' => 0, 'parents' => []],
        );
        $child = new CheckpointTuple(
            config: CheckpointerFixture::config($threadId, '', $childId),
            checkpoint: new Checkpoint(
                v: 4,
                id: $childId,
                ts: '2024-04-20T17:19:07.952Z',
                channelValues: ['log' => ['seed']],
                channelVersions: ['log' => 2],
                versionsSeen: [],
            ),
            metadata: ['source' => 'loop', 'step' => 1, 'parents' => []],
            parentConfig: CheckpointerFixture::config($threadId, '', $parentId),
        );

        iterator_to_array(CheckpointerFixture::putTuples($saver, [
            ['tuple' => $parent, 'writes' => [], 'newVersions' => ['log' => 1]],
            ['tuple' => $child, 'writes' => [], 'newVersions' => ['log' => 2]],
        ]), false);

        // A checkpoint's own writes are pending for the *next* superstep, so the
        // walk starts at the child's parent: it replays the parent's write and
        // terminates on the parent's stored value.
        $saver->putWrites(
            CheckpointerFixture::config($threadId, '', $parentId),
            [['log', 'from-parent']],
            'task',
        );

        $history = $saver->getDeltaChannelHistory(
            ['thread_id' => $threadId, 'checkpoint_ns' => '', 'checkpoint_id' => $childId],
            ['log', 'never-written'],
        );

        self::assertSame([['task', 'log', 'from-parent']], $history['log']->writes, $name);
        self::assertTrue($history['log']->hasSeed, $name);
        self::assertSame(['seed'], $history['log']->seed, $name);
        self::assertFalse($history['never-written']->hasSeed, $name);
        self::assertSame([], $history['never-written']->writes, $name);
    }

    /**
     * @param callable(): BaseCheckpointSaver $make
     */
    #[DataProvider('savers')]
    public function testGetDeltaChannelHistoryWithNoChannelsIsEmpty(
        string $name,
        callable $make,
    ): void {
        self::assertSame([], $make()->getDeltaChannelHistory(['thread_id' => 'x'], []), $name);
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * @param string|null               $threadId
     * @param string|null               $namespace
     * @param int|null                  $limit
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $filter
     * @param list<string>              $expected
     */
    private static function describeListCase(
        ?string $threadId,
        ?string $namespace,
        ?int $limit,
        ?array $before,
        ?array $filter,
        array $expected,
    ): string {
        $count = $limit !== null && $limit < count($expected)
            ? "{$limit} tuples"
            : (count($expected) === 0 ? 'no tuples' : count($expected) . ' tuples');

        $parts = [];
        $parts[] = $threadId === null ? 'thread_id is unspecified' : "thread_id={$threadId}";
        $parts[] = $namespace === null
            ? 'checkpoint_ns is unspecified'
            : ($namespace === '' ? 'checkpoint_ns is root' : "checkpoint_ns={$namespace}");
        $parts[] = $limit === null ? 'limit is unspecified' : "limit={$limit}";
        $parts[] = $before === null
            ? 'before is unspecified'
            : 'before=' . $before['configurable']['checkpoint_id'];
        $parts[] = $filter === null
            ? 'filter is unspecified'
            : ($filter === [] ? 'filter is empty' : 'filter=' . json_encode($filter, JSON_THROW_ON_ERROR));

        return "should return {$count} when " . implode(', ', $parts);
    }
}
