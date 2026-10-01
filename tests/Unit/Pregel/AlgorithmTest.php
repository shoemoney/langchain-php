<?php

declare(strict_types=1);

namespace LangGraph\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Channels\BinaryOperatorAggregate;
use LangGraph\Channels\EphemeralValue;
use LangGraph\Channels\LastValue;
use LangGraph\Channels\Topic;
use LangGraph\Errors\InvalidUpdateError;
use LangGraph\Pregel\Algorithm;
use LangGraph\Pregel\Checkpoint\Checkpoint;
use LangGraph\Pregel\Checkpoint\CheckpointFunctions;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\NextTaskExtraFields;
use LangGraph\Pregel\PendingWritesIndex;
use LangGraph\Pregel\PregelExecutableTask;
use LangGraph\Pregel\PregelInputWrites;
use LangGraph\Pregel\PregelNode;
use LangGraph\Pregel\TaskPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scheduling algorithm.
 *
 * Port of `src/pregel/algo.test.ts` plus the algorithmic assertions from
 * `src/tests/pregel.test.ts`. These are the tests that would catch a
 * correctness regression in the engine: task selection, the write fold, the
 * barrier, and the reserved-channel rules.
 */
#[CoversClass(Algorithm::class)]
#[CoversClass(PendingWritesIndex::class)]
#[CoversClass(NextTaskExtraFields::class)]
#[CoversClass(PregelExecutableTask::class)]
#[CoversClass(PregelInputWrites::class)]
#[CoversClass(TaskPath::class)]
final class AlgorithmTest extends TestCase
{
    // ---- pending writes index -------------------------------------------

    /**
     * Build a pending-write list with the same channel mix the upstream test
     * uses: roughly a quarter resumes, a sprinkle of errors, the rest returns.
     *
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private static function buildPendingWrites(int $count): array
    {
        $writes = [];
        for ($i = 0; $i < $count; $i++) {
            $taskId = 'task-' . ($i % 500);
            if ($i % 4 === 0) {
                $writes[] = [$taskId, Constants::RESUME, $i];
            } elseif ($i % 11 === 0) {
                $writes[] = [$taskId, Constants::ERROR, 'err'];
            } else {
                $writes[] = [$taskId, Constants::RETURN, $i];
            }
        }

        return $writes;
    }

    public function testIndexSuccessfulWriteTaskIdsMatchesLinearScan(): void
    {
        $pendingWrites = self::buildPendingWrites(12000);
        $index = PendingWritesIndex::build($pendingWrites);

        for ($i = 0; $i < 500; $i++) {
            $taskId = 'task-' . $i;

            $expected = false;
            foreach ($pendingWrites as $w) {
                if ($w[0] === $taskId && $w[1] !== Constants::ERROR) {
                    $expected = true;
                    break;
                }
            }

            $this->assertSame(
                $expected,
                $index->hasSuccessfulWrite($taskId),
                "task {$taskId}"
            );
        }
    }

    public function testIndexResumeValuesMatchFilter(): void
    {
        $pendingWrites = self::buildPendingWrites(8000);
        $index = PendingWritesIndex::build($pendingWrites);

        for ($i = 0; $i < 200; $i++) {
            $taskId = 'task-' . $i;

            $expected = [];
            foreach ($pendingWrites as $w) {
                if ($w[0] === $taskId && $w[1] === Constants::RESUME) {
                    $expected[] = $w[2];
                }
            }

            $this->assertSame($expected, $index->resumeFor($taskId), "task {$taskId}");
        }
    }

    public function testPrepareNextTasksIsUnchangedByAPrebuiltIndex(): void
    {
        [$checkpoint, $processes, $channels] = self::buildThirtyNodeFixture();

        $pendingWrites = self::buildPendingWrites(6000);
        $config = new RunnableConfig();
        $config->configurable = ['thread_id' => 'algo-test'];
        $extra = new NextTaskExtraFields(step: 2, channels: $channels, processes: $processes);

        $withoutPrebuilt = Algorithm::prepareNextTasks(
            $checkpoint,
            $pendingWrites,
            $processes,
            $channels,
            $config,
            false,
            $extra,
        );

        $withPrebuilt = Algorithm::prepareNextTasks(
            $checkpoint,
            $pendingWrites,
            $processes,
            $channels,
            $config,
            false,
            new NextTaskExtraFields(
                step: 2,
                channels: $channels,
                processes: $processes,
                pendingWritesIndex: PendingWritesIndex::build($pendingWrites),
            ),
        );

        $a = array_keys($withoutPrebuilt);
        $b = array_keys($withPrebuilt);
        sort($a);
        sort($b);
        $this->assertSame($a, $b);

        foreach ($withoutPrebuilt as $id => $task) {
            $this->assertSame($task->id, $withPrebuilt[$id]->id);
            $this->assertSame($task->name, $withPrebuilt[$id]->name);
            $this->assertSame($task->triggers, $withPrebuilt[$id]->triggers);
            $this->assertSame($task->path?->toArray(), $withPrebuilt[$id]->path?->toArray());
        }
    }

    /**
     * Thirty nodes, each triggered by its own channel, each channel one version
     * ahead of what the node has seen — so every node is live.
     *
     * @return array{0: Checkpoint, 1: array<string, PregelNode>, 2: array<string, LastValue>}
     */
    private static function buildThirtyNodeFixture(): array
    {
        $channelVersions = [];
        $versionsSeen = [];
        $processes = [];
        $channels = [];

        for ($i = 0; $i < 30; $i++) {
            $name = 'node' . $i;
            $chan = 'channel' . $i;
            $channelVersions[$chan] = 2;
            $versionsSeen[$name] = [$chan => 1];
            $processes[$name] = new PregelNode(channels: [$chan], triggers: [$chan]);
            $channel = new LastValue();
            $channel->update([$i]);
            $channels[$chan] = $channel;
        }

        $checkpoint = new Checkpoint(
            v: 1,
            id: '00000000-0000-0000-0000-000000000002',
            ts: '2026-01-01T00:00:00.000Z',
            channelValues: array_map(static fn (LastValue $c): mixed => $c->get(), $channels),
            channelVersions: $channelVersions,
            versionsSeen: $versionsSeen,
        );

        return [$checkpoint, $processes, $channels];
    }

    // ---- applyWrites -----------------------------------------------------

    public function testApplyWritesFoldsUpdatesIntoTheChannel(): void
    {
        $channels = ['items' => new BinaryOperatorAggregate(
            static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
            static fn (): array => [],
        )];
        $checkpoint = new Checkpoint();

        $updated = Algorithm::applyWrites(
            $checkpoint,
            $channels,
            [new PregelInputWrites([['items', ['a']], ['items', ['b']]])],
            static fn ($v) => Algorithm::increment($v),
        );

        $this->assertSame(['items'], $updated);
        $this->assertSame(['a', 'b'], $channels['items']->get());
    }

    public function testApplyWritesRecordsVersionsSeenPerNode(): void
    {
        $channels = ['x' => new LastValue(), 'y' => new LastValue()];
        $channels['x']->update([1]);
        $channels['y']->update([2]);

        $checkpoint = new Checkpoint(channelVersions: ['x' => 3, 'y' => 4]);

        $task = new PregelExecutableTask(
            id: 't1',
            name: 'consumer',
            triggers: ['x', 'y'],
            path: TaskPath::pull('consumer'),
        );

        Algorithm::applyWrites($checkpoint, $channels, [$task], static fn ($v) => Algorithm::increment($v));

        // Recording what a node *saw* is what stops it being rescheduled for
        // the same channel version.
        $this->assertSame(['x' => 3, 'y' => 4], $checkpoint->versionsSeen['consumer']);
    }

    public function testApplyWritesSortsByPathSoOrderIsIndependentOfCompletion(): void
    {
        // Two LastValue channels; which one "wins" depends purely on the order
        // writes are applied. The sort by task path is what makes that order a
        // property of the graph rather than of the runtime's scheduling.
        $channels = [
            'alpha' => new LastValue(),
            'beta' => new LastValue(),
        ];

        $makeTask = static fn (string $node, string $channel, array $tasks): PregelExecutableTask
            => new PregelExecutableTask(
                id: $node,
                name: $node,
                writes: [[$channel, $node]],
                path: TaskPath::pull($node),
            );

        $b = $makeTask('b', 'beta', []);
        $a = $makeTask('a', 'alpha', []);

        // Deliberately passed in reverse.
        $checkpoint = new Checkpoint();
        $updated = Algorithm::applyWrites($checkpoint, $channels, [$b, $a], static fn ($v) => Algorithm::increment($v));

        $this->assertSame('a', $channels['alpha']->get());
        $this->assertSame('b', $channels['beta']->get());

        // The observable consequence of the sort is `updatedChannels` order:
        // channels are visited in task-path order regardless of the order the
        // tasks were handed in. (Versions come from the *global* max, so two
        // channels written in one pass legitimately share a version.)
        $this->assertSame(['alpha', 'beta'], $updated);
    }

    public function testApplyWritesIgnoresReservedChannels(): void
    {
        $channels = ['x' => new LastValue()];
        $checkpoint = new Checkpoint();

        $updated = Algorithm::applyWrites($checkpoint, $channels, [new PregelInputWrites([
            [Constants::ERROR, 'boom'],
            [Constants::INTERRUPT, ['id' => null, 'value' => 'q']],
            [Constants::NO_WRITES, null],
            [Constants::RETURN, 'result'],
        ])], static fn ($v) => Algorithm::increment($v));

        // None of the reserved channels is a channel, so none becomes state.
        $this->assertSame([], $updated);
        $this->assertFalse($channels['x']->isAvailable());
    }

    public function testApplyWritesWrapsInvalidUpdateWithTheChannelName(): void
    {
        $channels = ['x' => new LastValue()];

        $this->expectException(InvalidUpdateError::class);
        $this->expectExceptionMessage('Invalid update for channel "x"');

        // Two writes to a single-value channel in one step is ambiguous.
        Algorithm::applyWrites(
            new Checkpoint(),
            $channels,
            [new PregelInputWrites([['x', 1], ['x', 2]])],
            static fn ($v) => Algorithm::increment($v),
        );
    }

    public function testApplyWritesNotifiesUntouchedChannelsOfANewStep(): void
    {
        // EphemeralValue is how a channel learns a step happened with no write
        // to it — that is what clears it. Without the `update([])` sweep an
        // edge's value would persist forever and re-trigger its node.
        $channels = [
            'a' => new EphemeralValue(),
            'b' => new EphemeralValue(),
        ];
        $channels['a']->update(['fresh']);

        $task = new PregelExecutableTask(
            id: 't',
            name: 'a',
            triggers: ['a'],
            path: TaskPath::pull('a'),
            writes: [],
        );

        Algorithm::applyWrites(new Checkpoint(), $channels, [$task], static fn ($v) => Algorithm::increment($v));

        $this->assertFalse($channels['a']->isAvailable(), 'an unwritten ephemeral channel must be cleared');
    }

    public function testApplyWritesFinishesChannelsOnTheLastStep(): void
    {
        // `finish()` is how LastValueAfterFinish publishes its value. It must
        // only run when nothing else will trigger, or a value intended for the
        // end would appear mid-run.
        $channels = ['a' => new \LangGraph\Channels\LastValueAfterFinish()];
        $channels['a']->update(['v']);

        $task = new PregelExecutableTask(
            id: 't',
            name: 'a',
            triggers: ['a'],
            path: TaskPath::pull('a'),
        );

        // With no trigger map, nothing will trigger a next step.
        Algorithm::applyWrites(new Checkpoint(), $channels, [$task], static fn ($v) => Algorithm::increment($v));

        $this->assertTrue($channels['a']->isAvailable());
    }

    public function testApplyWritesBumpsAConsumedChannel(): void
    {
        // A channel whose value is read exactly once must re-version when it is
        // consumed, or the task that read it would be rescheduled for the same
        // version on every subsequent step — a livelock the engine has to
        // prevent at the version layer, not the channel layer.
        $channel = new class extends \LangGraph\Channels\LastValue {
            public bool $consumed = false;

            public function consume(): bool
            {
                $this->consumed = true;

                return true;
            }
        };
        $channel->update([1]);
        $channels = ['a' => $channel];

        $checkpoint = new Checkpoint(channelVersions: ['a' => 1]);

        $task = new PregelExecutableTask(
            id: 't',
            name: 'reader',
            triggers: ['a'],
            path: TaskPath::pull('reader'),
        );

        Algorithm::applyWrites($checkpoint, $channels, [$task], static fn ($v) => Algorithm::increment($v));

        $this->assertTrue($channel->consumed);
        $this->assertSame(2, $checkpoint->channelVersions['a']);
    }

    public function testApplyWritesLeavesANonConsumingChannelAtItsVersion(): void
    {
        // `LastValue` does not consume: its value stays available until it is
        // overwritten, which is what lets several nodes read the same state in
        // one step. Its version must therefore not move.
        $channels = ['a' => new LastValue()];
        $channels['a']->update([1]);

        $checkpoint = new Checkpoint(channelVersions: ['a' => 1]);
        $task = new PregelExecutableTask(id: 't', name: 'r', triggers: ['a'], path: TaskPath::pull('r'));

        Algorithm::applyWrites($checkpoint, $channels, [$task], static fn ($v) => Algorithm::increment($v));

        $this->assertSame(1, $checkpoint->channelVersions['a']);
    }

    // ---- prepareNextTasks / prepareSingleTask ----------------------------

    public function testPullTaskIsPreparedWhenATriggerAdvanced(): void
    {
        $channels = ['x' => new LastValue()];
        $channels['x']->update(['now']);

        $processes = ['consumer' => new PregelNode(
            channels: ['x'],
            triggers: ['x'],
            bound: new \LangChain\Runnables\RunnableLambda(static fn (mixed $i): mixed => $i),
        )];

        $checkpoint = new Checkpoint(
            id: '00000000-0000-0000-0000-000000000001',
            channelVersions: ['x' => 5],
            versionsSeen: ['consumer' => ['x' => 4]],
        );

        $tasks = Algorithm::prepareNextTasks(
            $checkpoint,
            null,
            $processes,
            $channels,
            new RunnableConfig(),
            true,
            new NextTaskExtraFields(step: 1, channels: $channels, processes: $processes),
        );

        $this->assertCount(1, $tasks);
        $task = array_values($tasks)[0];
        $this->assertSame('consumer', $task->name);
        // A list-form `channels` yields the channel's value directly, not a map.
        $this->assertSame('now', $task->input);
        $this->assertTrue($task->isExecutable());
    }

    public function testPullTaskIsNotPreparedWhenTheTriggerHasNotAdvanced(): void
    {
        $channels = ['x' => new LastValue()];
        $channels['x']->update(['same']);

        $processes = ['consumer' => new PregelNode(
            channels: ['x'],
            triggers: ['x'],
            bound: new \LangChain\Runnables\RunnableLambda(static fn (mixed $i): mixed => $i),
        )];

        // The node has already seen version 5, and `x` is still at 5.
        $checkpoint = new Checkpoint(
            id: '00000000-0000-0000-0000-000000000001',
            channelVersions: ['x' => 5],
            versionsSeen: ['consumer' => ['x' => 5]],
        );

        $tasks = Algorithm::prepareNextTasks(
            $checkpoint,
            null,
            $processes,
            $channels,
            new RunnableConfig(),
            true,
            new NextTaskExtraFields(step: 1, channels: $channels, processes: $processes),
        );

        $this->assertSame([], $tasks, 'a node must not be rescheduled for a version it has seen');
    }

    public function testPushTaskIsPreparedFromTheTasksChannel(): void
    {
        $channels = [
            Constants::TASKS => new Topic(),
            'x' => new LastValue(),
        ];
        $channels[Constants::TASKS]->update([new \LangGraph\Pregel\Send('worker', ['n' => 1])]);

        $processes = ['worker' => new PregelNode(
            channels: [],
            triggers: [],
            bound: new \LangChain\Runnables\RunnableLambda(static fn (mixed $i): mixed => $i),
        )];

        $checkpoint = new Checkpoint(
            id: '00000000-0000-0000-0000-000000000001',
            channelVersions: [Constants::TASKS => 1],
        );

        $tasks = Algorithm::prepareNextTasks(
            $checkpoint,
            null,
            $processes,
            $channels,
            new RunnableConfig(),
            true,
            new NextTaskExtraFields(step: 1, channels: $channels, processes: $processes),
        );

        $this->assertCount(1, $tasks);
        $task = array_values($tasks)[0];
        $this->assertSame('worker', $task->name);
        $this->assertSame(['n' => 1], $task->input);
        $this->assertSame([Constants::PUSH], $task->triggers);
    }

    public function testPushTaskForAnUnknownNodeIsDroppedNotFatal(): void
    {
        $channels = [Constants::TASKS => new Topic()];
        $channels[Constants::TASKS]->update([new \LangGraph\Pregel\Send('ghost', null)]);

        $checkpoint = new Checkpoint(
            id: '00000000-0000-0000-0000-000000000001',
            channelVersions: [Constants::TASKS => 1],
        );

        // A `Send` naming a node that does not exist is a user error, but it
        // must not take the whole run down with it — the packet is dropped and
        // the run continues. The engine records rather than throwing, matching
        // upstream's `console.warn` (algo.ts:866-867).
        //
        // This used to install a `set_error_handler` purely to catch the
        // E_USER_WARNING the engine raised on purpose, because phpunit.xml sets
        // `failOnWarning="true"` and the notice would otherwise fail the suite.
        // A test that has to trap the thing under test to let the suite pass is
        // a test documenting a defect; the notice is now readable instead.
        \LangChain\Utils\Notice::clear();

        $tasks = Algorithm::prepareNextTasks(
            $checkpoint,
            null,
            [],
            $channels,
            new RunnableConfig(),
            true,
            new NextTaskExtraFields(step: 1, channels: $channels, processes: []),
        );

        $this->assertSame([], $tasks);
        $this->assertCount(1, \LangChain\Utils\Notice::notices());
        $this->assertStringContainsString('ghost', \LangChain\Utils\Notice::notices()[0]);
    }

    public function testTaskIdIsDeterministicForTheSameCheckpointAndStep(): void
    {
        [$checkpoint, $processes, $channels] = self::buildThirtyNodeFixture();

        // `forExecution: false`, matching the upstream `algo.test.ts`: a
        // description is what the scheduler can produce for a node with no
        // runnable attached, and the id derivation is identical either way.
        $first = Algorithm::prepareNextTasks(
            $checkpoint,
            null,
            $processes,
            $channels,
            new RunnableConfig(),
            false,
            new NextTaskExtraFields(step: 7, channels: $channels, processes: $processes),
        );

        $second = Algorithm::prepareNextTasks(
            $checkpoint,
            null,
            $processes,
            $channels,
            new RunnableConfig(),
            false,
            new NextTaskExtraFields(step: 7, channels: $channels, processes: $processes),
        );

        $this->assertSame(array_keys($first), array_keys($second));

        // A different step must produce different ids, or a resumed run would
        // mistake a stale task for its own.
        $third = Algorithm::prepareNextTasks(
            $checkpoint,
            null,
            $processes,
            $channels,
            new RunnableConfig(),
            false,
            new NextTaskExtraFields(step: 8, channels: $channels, processes: $processes),
        );

        $this->assertNotSame(array_keys($first), array_keys($third));
        $this->assertSame(count($first), count($third), 'the same nodes are live at both steps');
    }

    public function testTaskWithASuccessfulPendingWriteIsNotReprepared(): void
    {
        $channels = ['x' => new LastValue()];
        $channels['x']->update(['v']);

        // The node's trigger is its own name — the shape `addEdge` produces.
        $processes = ['consumer' => new PregelNode(
            channels: ['x'],
            triggers: ['consumer'],
            bound: new \LangChain\Runnables\RunnableLambda(static fn (mixed $i): mixed => $i),
        )];
        $channels['consumer'] = new LastValue();
        $channels['consumer']->update(['v']);

        $checkpoint = new Checkpoint(
            id: '00000000-0000-0000-0000-000000000001',
            channelVersions: ['consumer' => 5],
        );

        $config = new RunnableConfig();
        $extra = new NextTaskExtraFields(step: 1, channels: $channels, processes: $processes);

        $initial = Algorithm::prepareNextTasks($checkpoint, null, $processes, $channels, $config, true, $extra);
        $taskId = array_key_first($initial);

        // A non-error write already exists for this task: it ran, and re-running
        // it would apply its output twice.
        $pendingWrites = [[$taskId, 'x', 'already done']];

        $again = Algorithm::prepareNextTasks(
            $checkpoint,
            $pendingWrites,
            $processes,
            $channels,
            $config,
            true,
            $extra,
        );

        $this->assertArrayNotHasKey($taskId, $again);
    }

    public function testTaskWithOnlyAnErrorPendingWriteIsStillReprepared(): void
    {
        $channels = ['x' => new LastValue()];
        $channels['x']->update(['v']);

        $processes = ['consumer' => new PregelNode(
            channels: ['x'],
            triggers: ['consumer'],
            bound: new \LangChain\Runnables\RunnableLambda(static fn (mixed $i): mixed => $i),
        )];
        $channels['consumer'] = new LastValue();
        $channels['consumer']->update(['v']);

        $checkpoint = new Checkpoint(
            id: '00000000-0000-0000-0000-000000000001',
            channelVersions: ['consumer' => 5],
        );

        $config = new RunnableConfig();
        $extra = new NextTaskExtraFields(step: 1, channels: $channels, processes: $processes);

        $initial = Algorithm::prepareNextTasks($checkpoint, null, $processes, $channels, $config, true, $extra);
        $taskId = array_key_first($initial);

        // An ERROR write means the task failed, not that it succeeded. It must
        // be re-prepared so the retry can happen.
        $again = Algorithm::prepareNextTasks(
            $checkpoint,
            [[$taskId, Constants::ERROR, ['message' => 'boom']]],
            $processes,
            $channels,
            $config,
            true,
            $extra,
        );

        $this->assertArrayHasKey($taskId, $again);
    }

    // ---- candidateNodes --------------------------------------------------

    public function testCandidateNodesUsesTheTriggerMapWhenAvailable(): void
    {
        $channels = [];
        $processes = [
            'a' => new PregelNode(),
            'b' => new PregelNode(),
            'c' => new PregelNode(),
        ];
        $checkpoint = new Checkpoint(channelVersions: ['ch' => 1]);

        $candidates = Algorithm::candidateNodes($checkpoint, $processes, new NextTaskExtraFields(
            updatedChannels: ['ch'],
            triggerToNodes: ['ch' => ['c', 'a']],
        ));

        // Sorted, so the order does not depend on the trigger map's insertion
        // order — which is a property of how the graph was built, not of what
        // happened to run.
        $this->assertSame(['a', 'c'], $candidates);
    }

    public function testCandidateNodesFallsBackToEveryNodeWithNoTriggerMap(): void
    {
        $processes = ['b' => new PregelNode(), 'a' => new PregelNode()];
        $checkpoint = new Checkpoint(channelVersions: ['x' => 1]);

        $candidates = Algorithm::candidateNodes($checkpoint, $processes, new NextTaskExtraFields());

        $this->assertSame(['b', 'a'], $candidates);
    }

    public function testCandidateNodesAreEmptyForAFreshCheckpoint(): void
    {
        $processes = ['a' => new PregelNode()];

        // No versions at all: nothing has happened, so nothing can be triggered.
        $this->assertSame([], Algorithm::candidateNodes(new Checkpoint(), $processes, new NextTaskExtraFields()));
    }

    // ---- procInput -------------------------------------------------------

    public function testProcInputReadsAMapOfChannels(): void
    {
        $channels = ['a' => new LastValue(), 'b' => new LastValue()];
        $channels['a']->update(['A']);
        $channels['b']->update(['B']);

        $node = new PregelNode(channels: ['first' => 'a', 'second' => 'b'], triggers: []);

        $this->assertSame(
            ['first' => 'A', 'second' => 'B'],
            Algorithm::procInput($node, $channels, true),
        );
    }

    public function testProcInputReturnsMissingWhenATriggerChannelIsEmpty(): void
    {
        $channels = ['a' => new LastValue(), 'b' => new LastValue()];
        $channels['b']->update(['B']);

        // `a` is a trigger and has no value. That means the node cannot run —
        // it was woken by something that no longer holds a value.
        $node = new PregelNode(channels: ['first' => 'a', 'second' => 'b'], triggers: ['a']);

        $this->assertInstanceOf(
            \LangGraph\Channels\Missing::class,
            Algorithm::procInput($node, $channels, true),
        );
    }

    public function testProcInputSkipsAnEmptyNonTriggerChannel(): void
    {
        $channels = ['a' => new LastValue(), 'b' => new LastValue()];
        $channels['b']->update(['B']);

        // `a` is not a trigger, so being empty just means "absent from this
        // input" — the node still runs with what it has.
        $node = new PregelNode(channels: ['first' => 'a', 'second' => 'b'], triggers: ['b']);

        $this->assertSame(
            ['second' => 'B'],
            Algorithm::procInput($node, $channels, true),
        );
    }

    public function testProcInputTakesTheFirstAvailableChannelFromAList(): void
    {
        $channels = ['a' => new LastValue(), 'b' => new LastValue()];
        $channels['b']->update(['B']);

        $node = new PregelNode(channels: ['a', 'b'], triggers: ['b']);

        $this->assertSame('B', Algorithm::procInput($node, $channels, true));
    }

    public function testProcInputAppliesTheMapperWhenPreparingForExecution(): void
    {
        $channels = ['a' => new LastValue()];
        $channels['a']->update(['A']);

        $node = new PregelNode(
            channels: ['a'],
            triggers: ['a'],
            mapper: static fn (mixed $v): string => 'mapped:' . $v,
        );

        $this->assertSame('mapped:A', Algorithm::procInput($node, $channels, true));
        // The mapper is an execution-time concern; a description sees the raw value.
        $this->assertSame('A', Algorithm::procInput($node, $channels, false));
    }

    // ---- localWrite ------------------------------------------------------

    public function testLocalWriteRejectsANonSendOnTheTasksChannel(): void
    {
        $this->expectException(InvalidUpdateError::class);
        $this->expectExceptionMessage('Invalid packet type');

        Algorithm::localWrite(static function (array $w): void {}, [], [
            [Constants::TASKS, ['not' => 'a send']],
        ]);
    }

    public function testLocalWriteRejectsASendNamingAnUnknownNode(): void
    {
        $this->expectException(InvalidUpdateError::class);
        $this->expectExceptionMessage('Invalid node name "ghost"');

        Algorithm::localWrite(static function (array $w): void {}, [], [
            [Constants::TASKS, new \LangGraph\Pregel\Send('ghost', null)],
        ]);
    }

    public function testLocalWriteAcceptsASendNamingAKnownNode(): void
    {
        $committed = null;

        Algorithm::localWrite(
            static function (array $w) use (&$committed): void { $committed = $w; },
            ['worker' => new PregelNode()],
            [[Constants::TASKS, new \LangGraph\Pregel\Send('worker', ['n' => 1])]],
        );

        $this->assertNotNull($committed);
        $this->assertSame(Constants::TASKS, $committed[0][0]);
    }

    // ---- shouldInterrupt -------------------------------------------------

    public function testShouldInterruptNeedsBothAChangeAndATarget(): void
    {
        $channels = ['x' => 1, 'y' => 1];
        $checkpoint = new Checkpoint(channelVersions: $channels);
        $task = new PregelExecutableTask(id: 't', name: 'a', path: TaskPath::pull('a'));

        // Changed and targeted: interrupt.
        $this->assertTrue(Algorithm::shouldInterrupt($checkpoint, ['a'], [$task]));

        // Changed but not targeted: the node is not one we were asked to stop at.
        $this->assertFalse(Algorithm::shouldInterrupt($checkpoint, ['b'], [$task]));

        // Targeted but unchanged: stopping here would be a no-op pause that
        // costs a resume round trip to learn nothing.
        $unchanged = new Checkpoint(
            channelVersions: $channels,
            versionsSeen: [Constants::INTERRUPT => $channels],
        );
        $this->assertFalse(Algorithm::shouldInterrupt($unchanged, ['a'], [$task]));
    }

    public function testShouldInterruptMatchesTheStartChannelFirst(): void
    {
        $checkpoint = new Checkpoint(
            channelVersions: [Constants::START => 4, 'x' => 1],
            versionsSeen: [Constants::INTERRUPT => [Constants::START => 3, 'x' => 1]],
        );
        $task = new PregelExecutableTask(id: 't', name: 'a', path: TaskPath::pull('a'));

        $this->assertTrue(Algorithm::shouldInterrupt($checkpoint, ['a'], [$task]));
    }

    public function testShouldInterruptWithWildcardSkipsHiddenTasks(): void
    {
        $checkpoint = new Checkpoint(channelVersions: [Constants::START => 2, 'x' => 2]);
        $config = new RunnableConfig();
        $config->tags = [Constants::TAG_HIDDEN];
        $hidden = new PregelExecutableTask(id: 'h', name: 'a', path: TaskPath::pull('a'), config: $config);

        $this->assertFalse(Algorithm::shouldInterrupt($checkpoint, ['*'], [$hidden]));
    }

    // ---- helpers ---------------------------------------------------------

    #[DataProvider('incrementCases')]
    public function testIncrement(mixed $current, mixed $expected): void
    {
        $this->assertSame($expected, Algorithm::increment($current));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function incrementCases(): iterable
    {
        yield 'null becomes 1' => [null, 1];
        yield 'one becomes two' => [1, 2];
        yield 'zero becomes one' => [0, 1];
        yield 'a string appends' => ['a', 'a1'];
    }

    public function testTriggersNextStep(): void
    {
        $this->assertFalse(Algorithm::triggersNextStep(['a'], null));
        $this->assertFalse(Algorithm::triggersNextStep(['a'], []));
        $this->assertFalse(Algorithm::triggersNextStep(['a'], ['b' => ['x']]));
        $this->assertTrue(Algorithm::triggersNextStep(['a'], ['a' => ['x']]));
    }

    public function testIsIgnoredChannel(): void
    {
        foreach ([
            Constants::NO_WRITES,
            Constants::PUSH,
            Constants::RESUME,
            Constants::INTERRUPT,
            Constants::RETURN,
            Constants::ERROR,
            Constants::ERROR_SOURCE_NODE,
        ] as $channel) {
            $this->assertTrue(Algorithm::isIgnoredChannel($channel), $channel);
        }

        $this->assertFalse(Algorithm::isIgnoredChannel('items'));
        $this->assertFalse(Algorithm::isIgnoredChannel(Constants::TASKS));
    }

    public function testTaskPathOrderingKeyIsTheFirstThreeSegments(): void
    {
        $path = new TaskPath([Constants::PUSH, 3, 'x', 99, 'parent']);
        $this->assertSame([Constants::PUSH, 3, 'x'], $path->orderingKey());
    }

    public function testTaskPathDetectsACallPath(): void
    {
        $this->assertTrue((new TaskPath([Constants::PUSH, 0, true]))->isCallPath());
        $this->assertFalse((new TaskPath([Constants::PULL, 'a']))->isCallPath());
        $this->assertFalse((new TaskPath([]))->isCallPath());
    }

    public function testNullChannelVersionFollowsTheMapType(): void
    {
        $this->assertSame(0, CheckpointFunctions::getNullChannelVersion([Constants::START => 3]));
        $this->assertSame('', CheckpointFunctions::getNullChannelVersion(['x' => 'a']));
        $this->assertNull(CheckpointFunctions::getNullChannelVersion([]));
    }

    /**
     * Two unencodable inputs must not share one cache key.
     *
     * `json_encode()` returns `false` on failure — NAN/INF, malformed UTF-8, a
     * resource, recursion — and the old code cast it: `(string) json_encode($input)`.
     * `(string) false` is `""`, so every unencodable input produced the SAME key:
     *
     *     input 0: json_encode => false   (string) => ''
     *     input 1: json_encode => false   (string) => ''
     *
     * Two different inputs, one cache entry, and the second read returns the
     * first's result. That is a silent correctness failure in a cache — worse
     * than no cache, because the cache looks like it is working.
     *
     * Upstream has no equivalent hole: `JSON.stringify` THROWS on input it cannot
     * represent, so a bad key is loud there. `JSON_THROW_ON_ERROR` is the PHP
     * spelling of that, and the second case asserts the failure stays loud
     * rather than becoming a key.
     */
    public function testAnUnencodableCacheKeyFailsLoudlyRatherThanCollapsing(): void
    {
        $method = new \ReflectionMethod(\LangGraph\Pregel\Algorithm::class, 'buildCacheKey');
        $policy = ['ttl' => 60];
        $good = $method->invoke(null, $policy, 'node', ['value' => 'fine'], 'n');
        $this->assertIsArray($good, 'an encodable input still produces a key');

        $this->expectException(\JsonException::class);
        $method->invoke(null, $policy, 'node', ['value' => NAN], 'n');
    }
}
