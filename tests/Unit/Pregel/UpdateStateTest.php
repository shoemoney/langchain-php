<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Errors\GraphValueError;
use LangGraph\Errors\InvalidUpdateError;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `updateState`, `bulkUpdateState` and the `getStateHistory` filters.
 *
 * Upstream covers these inside `tests/pregel.test.ts` and `time_travel*.test.ts` (already converted
 * in {@see TimeTravelTest} / {@see TimeTravelExtendedTest}); this suite pins the mechanics those
 * suites rely on without naming: the node-attributed write, the version bump that keeps a fork from
 * sharing history with its origin, the special `asNode` values, and the error taxonomy.
 */
#[CoversClass(Pregel::class)]
final class UpdateStateTest extends TestCase
{
    use TimeTravelFixtures;

    private static function pipeline(?MemorySaver $saver = null, array $options = []): Pregel
    {
        return (new StateGraph(self::stateSchema()))
            ->addNode('node_a', static fn (): array => ['value' => ['a']])
            ->addNode('node_b', static fn (): array => ['value' => ['b']])
            ->addNode('node_c', static fn (): array => ['value' => ['c']])
            ->addEdge(Constants::START, 'node_a')
            ->addEdge('node_a', 'node_b')
            ->addEdge('node_b', 'node_c')
            ->compile(['checkpointer' => $saver ?? new MemorySaver()] + $options);
    }

    /** @return array<string, mixed> */
    private static function head(Pregel $graph, RunnableConfig $config): array
    {
        $state = $graph->getState($config);

        return ['values' => $state->values, 'next' => $state->next, 'metadata' => $state->metadata, 'config' => $state->config];
    }

    // ---- the node-attributed write ----------------------------------------------------------

    public function testAnUpdateAsANodeWritesItsChannelsAndFiresThatNodesEdges(): void
    {
        $graph = self::pipeline(null, ['interruptBefore' => ['node_b']]);
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        self::assertSame(['node_b'], $graph->getState($config)->next);

        // As if node_a had returned ['x']: the value lands, and node_a's edge wakes node_b.
        $updated = $graph->updateState($config, ['value' => ['x']], 'node_a');

        $snapshot = $graph->getState($updated);
        self::assertSame(['value' => ['a', 'x']], $snapshot->values);
        self::assertSame(['node_b'], $snapshot->next);
        self::assertSame('update', $snapshot->metadata['source']);
    }

    public function testResumingAfterAnUpdateRunsTheRestOfTheGraphFromTheEditedState(): void
    {
        $graph = self::pipeline(null, ['interruptBefore' => ['node_b']]);
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        $graph->updateState($config, ['value' => ['edited']], 'node_a');

        // The human-in-the-loop pattern: edit at the breakpoint, then continue the same thread.
        $result = $graph->invoke(null, $config);

        self::assertSame(['a', 'edited', 'b', 'c'], $result['value']);
    }

    public function testTheUpdateCheckpointIsAChildOfTheCheckpointItEditedAndBumpsChannelVersions(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);

        $beforeB = self::findNext($graph->getStateHistory($config), 'node_b');
        $baseVersions = $graph->checkpointer->getTuple(self::configOf($beforeB)->configurable)->checkpoint->channelVersions;

        $forkConfig = $graph->updateState(self::configOf($beforeB), ['value' => ['x']], 'node_a');

        $fork = $graph->checkpointer->getTuple($forkConfig->configurable);
        self::assertSame($beforeB['config']['configurable']['checkpoint_id'], $fork->parentConfig['configurable']['checkpoint_id']);
        self::assertSame(((int) $beforeB['metadata']['step']) + 1, $fork->metadata['step']);

        // `value` was written, and node_a's edge wrote `branch:to:node_b`: both advanced, so the
        // fork schedules node_b rather than sharing the origin's already-seen versions.
        foreach (['value', 'branch:to:node_b'] as $channel) {
            self::assertGreaterThan($baseVersions[$channel], $fork->checkpoint->channelVersions[$channel], $channel);
        }
        self::assertNotSame($beforeB['config']['configurable']['checkpoint_id'], $fork->checkpoint->id);
    }

    public function testTwoForksOfTheSameCheckpointDoNotShareHistory(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);
        $beforeB = self::configOf(self::findNext($graph->getStateHistory($config), 'node_b'));

        $one = $graph->updateState($beforeB, ['value' => ['one']], 'node_a');
        $two = $graph->updateState($beforeB, ['value' => ['two']], 'node_a');

        self::assertNotSame($one->configurable['checkpoint_id'], $two->configurable['checkpoint_id']);
        self::assertSame(['a', 'one'], $graph->getState($one)->values['value']);
        self::assertSame(['a', 'two'], $graph->getState($two)->values['value']);
    }

    public function testASingleNodeGraphNeedsNoAsNode(): void
    {
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('only', static fn (): array => ['value' => ['o']])
            ->addEdge(Constants::START, 'only')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);

        $updated = $graph->updateState($config, ['value' => ['x']]);

        self::assertSame(['o', 'x'], $graph->getState($updated)->values['value']);
    }

    public function testTheLastNodeToRunIsInferredWhenItIsUnambiguous(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);

        // node_c ran last; with no more edges there is nothing to schedule, but the update lands.
        $updated = $graph->updateState($config, ['value' => ['tail']]);

        self::assertSame(['a', 'b', 'c', 'tail'], $graph->getState($updated)->values['value']);
        self::assertSame([], $graph->getState($updated)->next);
    }

    public function testAnAmbiguousUpdateIsRejected(): void
    {
        // Two nodes ran in the same superstep, so "the last node" has no single answer.
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('left', static fn (): array => ['value' => ['l']])
            ->addNode('right', static fn (): array => ['value' => ['r']])
            ->addEdge(Constants::START, 'left')
            ->addEdge(Constants::START, 'right')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);

        $this->expectException(InvalidUpdateError::class);
        $this->expectExceptionMessage('Ambiguous update, specify "asNode"');
        $graph->updateState($config, ['value' => ['x']]);
    }

    public function testAnUpdateOnAFreshThreadIsAttributedToTheInputNode(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig('fresh');

        $updated = $graph->updateState($config, ['value' => ['seed']], Constants::START);
        self::assertSame(['seed'], $graph->getState($updated)->values['value']);
        self::assertSame(['node_a'], $graph->getState($updated)->next);

        self::assertSame(['seed', 'a', 'b', 'c'], $graph->invoke(null, $updated)['value']);
    }

    // ---- errors -------------------------------------------------------------------------------

    public function testAnUnknownNodeIsRejected(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);

        $this->expectException(InvalidUpdateError::class);
        $this->expectExceptionMessage('Node "ghost" does not exist');
        $graph->updateState($config, ['value' => ['x']], 'ghost');
    }

    public function testStateManagementNeedsACheckpointer(): void
    {
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('only', static fn (): array => ['value' => ['o']])
            ->addEdge(Constants::START, 'only')
            ->compile();

        foreach ([
            static fn () => $graph->updateState(self::threadConfig(), ['value' => ['x']], 'only'),
            static fn () => $graph->bulkUpdateState(self::threadConfig(), [['updates' => [['values' => [], 'asNode' => 'only']]]]),
            static fn () => $graph->getState(self::threadConfig()),
            static fn () => $graph->getStateHistory(self::threadConfig()),
        ] as $call) {
            try {
                $call();
                self::fail('expected GraphValueError');
            } catch (GraphValueError $e) {
                self::assertSame('No checkpointer set', $e->getMessage());
                self::assertSame('MISSING_CHECKPOINTER', $e->getLcErrorCode());
            }
        }
    }

    public function testBulkUpdateRejectsEmptyInput(): void
    {
        $graph = self::pipeline();

        try {
            $graph->bulkUpdateState(self::threadConfig(), []);
            self::fail('expected an exception for no supersteps');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('No supersteps provided', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No updates provided');
        $graph->bulkUpdateState(self::threadConfig(), [['updates' => []]]);
    }

    public function testSeveralUpdatesInOneSuperstepEachNeedAnAsNode(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);

        $this->expectException(InvalidUpdateError::class);
        $this->expectExceptionMessage('"asNode" is required when applying multiple updates');
        $graph->bulkUpdateState($config, [['updates' => [['values' => ['value' => ['x']]], ['values' => ['value' => ['y']], 'asNode' => 'node_a']]]]);
    }

    // ---- special asNode values ----------------------------------------------------------------

    public function testNullValuesAsEndClearsThePendingTasks(): void
    {
        $graph = self::pipeline(null, ['interruptBefore' => ['node_b']]);
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);
        self::assertSame(['node_b'], $graph->getState($config)->next);

        $cleared = $graph->updateState($config, null, Constants::END);

        self::assertSame([], $graph->getState($cleared)->next);
        self::assertSame(['a'], $graph->getState($cleared)->values['value']);
        self::assertSame('update', $graph->getState($cleared)->metadata['source']);
    }

    public function testClearingStateTakesASingleUpdate(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);

        $this->expectException(InvalidUpdateError::class);
        $this->expectExceptionMessage('Cannot apply multiple updates when clearing state');
        $graph->bulkUpdateState($config, [['updates' => [
            ['values' => null, 'asNode' => Constants::END],
            ['values' => null, 'asNode' => Constants::END],
        ]]]);
    }

    public function testCopyForksTheCheckpointWithoutChangingState(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);
        $beforeB = self::configOf(self::findNext($graph->getStateHistory($config), 'node_b'));

        $copy = $graph->updateState($beforeB, null, Constants::COPY);

        $copied = $graph->getState($copy);
        self::assertSame('fork', $copied->metadata['source']);
        self::assertSame(['value' => ['a']], $copied->values);
        self::assertSame(['node_b'], $copied->next);
    }

    public function testCopyWithPairsForksAndAppliesThoseUpdatesToTheFork(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);
        $beforeB = self::configOf(self::findNext($graph->getStateHistory($config), 'node_b'));

        $forked = $graph->updateState($beforeB, [[['value' => ['p']], 'node_a'], [['value' => ['q']], 'node_a']], Constants::COPY);

        $snapshot = $graph->getState($forked);
        self::assertSame('update', $snapshot->metadata['source']);
        self::assertSame(['a', 'p', 'q'], $snapshot->values['value']);
        self::assertSame(['node_b'], $snapshot->next);
    }

    public function testCopyOfAThreadThatNeverRanIsRejected(): void
    {
        $graph = self::pipeline();

        $this->expectException(InvalidUpdateError::class);
        $this->expectExceptionMessage('Cannot copy a non-existent checkpoint');
        $graph->updateState(self::threadConfig('empty'), null, Constants::COPY);
    }

    public function testAsInputWritesThroughTheInputChannelsAndRecordsAnInputCheckpoint(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig('as-input');

        $updated = $graph->updateState($config, ['value' => ['seed']], Constants::INPUT);

        $snapshot = $graph->getState($updated);
        self::assertSame('input', $snapshot->metadata['source']);
        self::assertSame(-1, $snapshot->metadata['step']);
        // The write lands in the input channel, exactly as a run's input would: the graph has not
        // seen it yet, so `__start__` is what runs next.
        self::assertSame(['__start__'], $snapshot->next);

        self::assertSame(['seed', 'a', 'b', 'c'], $graph->invoke(null, $updated)['value']);
    }

    public function testNothingToWriteMakesAnEmptyCheckpoint(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);
        $before = self::head($graph, $config);

        $updated = $graph->updateState($config, null);

        $after = self::head($graph, $updated);
        self::assertSame($before['values'], $after['values']);
        self::assertSame('update', $after['metadata']['source']);
        self::assertSame($before['metadata']['step'] + 1, $after['metadata']['step']);
    }

    // ---- bulk updates --------------------------------------------------------------------------

    public function testBulkUpdateAppliesSuperstepsInOrderEachOnTopOfThePrevious(): void
    {
        $graph = self::pipeline(null, ['interruptBefore' => ['node_b']]);
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);
        $stepBefore = $graph->getState($config)->metadata['step'];

        $last = $graph->bulkUpdateState($config, [
            ['updates' => [['values' => ['value' => ['one']], 'asNode' => 'node_a']]],
            ['updates' => [['values' => ['value' => ['two']], 'asNode' => 'node_a']]],
        ]);

        $snapshot = $graph->getState($last);
        self::assertSame(['a', 'one', 'two'], $snapshot->values['value']);
        self::assertSame($stepBefore + 2, $snapshot->metadata['step']);
    }

    public function testSeveralUpdatesInOneSuperstepShareOneCheckpoint(): void
    {
        $graph = self::pipeline(null, ['interruptBefore' => ['node_b']]);
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);
        $stepBefore = $graph->getState($config)->metadata['step'];

        $last = $graph->bulkUpdateState($config, [['updates' => [
            ['values' => ['value' => ['one']], 'asNode' => 'node_a'],
            ['values' => ['value' => ['two']], 'asNode' => 'node_a'],
        ]]]);

        $snapshot = $graph->getState($last);
        self::assertSame(['a', 'one', 'two'], $snapshot->values['value']);
        self::assertSame($stepBefore + 1, $snapshot->metadata['step']);
    }

    // ---- getStateHistory filters ---------------------------------------------------------------

    public function testHistoryFiltersByMetadata(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);
        $graph->updateState($config, ['value' => ['x']], 'node_c');

        $updates = $graph->getStateHistory($config, ['filter' => ['source' => 'update']]);
        $inputs = $graph->getStateHistory($config, new CheckpointListOptions(filter: ['source' => 'input']));

        self::assertCount(1, $updates);
        self::assertSame('update', $updates[0]['metadata']['source']);
        self::assertCount(1, $inputs);
        self::assertSame('input', $inputs[0]['metadata']['source']);
        self::assertCount(6, $graph->getStateHistory($config));
    }

    public function testHistoryLimitKeepsTheNewest(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);

        $all = $graph->getStateHistory($config);
        $limited = $graph->getStateHistory($config, ['limit' => 2]);

        self::assertCount(2, $limited);
        self::assertSame(
            [$all[0]['config']['configurable']['checkpoint_id'], $all[1]['config']['configurable']['checkpoint_id']],
            array_map(static fn (array $s): string => $s['config']['configurable']['checkpoint_id'], $limited),
        );
        self::assertSame(1, count($graph->getStateHistory($config, 1)));
    }

    public function testHistoryBeforePaginatesWithoutRepeatingOrSkipping(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);

        $all = $graph->getStateHistory($config);
        $pageOne = $graph->getStateHistory($config, ['limit' => 2]);
        $pageTwo = $graph->getStateHistory($config, ['limit' => 2, 'before' => self::configOf($pageOne[1])]);
        $rest = $graph->getStateHistory($config, ['before' => self::configOf($pageTwo[1])]);

        $ids = static fn (array $page): array => array_map(
            static fn (array $s): string => $s['config']['configurable']['checkpoint_id'],
            $page,
        );
        self::assertSame($ids($all), array_merge($ids($pageOne), $ids($pageTwo), $ids($rest)));
    }

    public function testHistoryEntriesCarryNextTasksCreatedAtAndParent(): void
    {
        $graph = self::pipeline();
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);

        $history = $graph->getStateHistory($config);

        self::assertSame([], $history[0]['next']);
        self::assertSame(['node_c'], $history[1]['next']);
        self::assertSame('node_c', $history[1]['tasks'][0]['name']);
        self::assertNotNull($history[0]['createdAt']);
        self::assertSame(
            $history[1]['config']['configurable']['checkpoint_id'],
            $history[0]['parentConfig']['configurable']['checkpoint_id'],
        );
        self::assertNull($history[count($history) - 1]['parentConfig']);
    }

    public function testHistoryOfARootGraphExcludesItsSubgraphsCheckpoints(): void
    {
        $sub = (new StateGraph(self::stateSchema()))
            ->addNode('inner', static fn (): array => ['value' => ['i']])
            ->addEdge(Constants::START, 'inner')
            ->compile();
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('outer', $sub)
            ->addEdge(Constants::START, 'outer')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig();
        $graph->invoke(['value' => []], $config);

        $history = $graph->getStateHistory($config);

        self::assertCount(3, $history);
        foreach ($history as $entry) {
            self::assertSame('', $entry['config']['configurable']['checkpoint_ns']);
        }
    }

    // ---- end to end through a real chain -------------------------------------------------------

    public function testAChainRunsAsANodeAndItsOutputCanBeForkedAndReplayed(): void
    {
        // A real LCEL sequence as the node: normalise, then wrap into the state update.
        $chain = RunnableSequence::from([
            new RunnableLambda(static fn (array $state): string => strtoupper(implode('+', $state['value']) ?: 'empty')),
            new RunnableLambda(static fn (string $text): array => ['value' => ["chain:{$text}"]]),
        ]);

        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('seed', static fn (): array => ['value' => ['seed']])
            ->addNode('chain', $chain)
            ->addNode('done', static fn (): array => ['value' => ['done']])
            ->addEdge(Constants::START, 'seed')
            ->addEdge('seed', 'chain')
            ->addEdge('chain', 'done')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig();

        self::assertSame(['seed', 'chain:SEED', 'done'], $graph->invoke(['value' => []], $config)['value']);

        $beforeChain = self::configOf(self::findNext($graph->getStateHistory($config), 'chain'));
        $fork = $graph->updateState($beforeChain, ['value' => ['edited']], 'seed');

        self::assertSame(['seed', 'edited', 'chain:SEED+EDITED', 'done'], $graph->invoke(null, $fork)['value']);
    }
}
