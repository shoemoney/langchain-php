<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/**
 * Port of `langgraph-core/src/tests/time_travel.test.ts`.
 *
 * Differences from upstream, all forced by this port:
 *  - `invoke()` returns the state, not `{..., __interrupt__: [...]}`, so upstream's
 *    `toHaveInterruptValue(q)` / `toBeInterrupted()` matchers become reads of the pending
 *    interrupts on `getState()` (`interruptValues()`), and `not.toBeInterrupted()` is "none pending";
 *  - `getStateHistory()` returns arrays (see its docblock), indexed `['next']`, `['config']`...;
 *  - `compile(['checkpointer' => true])` on a subgraph is spelled the same, and the parent's saver
 *    still wins at run time.
 */
#[CoversClass(Pregel::class)]
final class TimeTravelTest extends TestCase
{
    use TimeTravelFixtures;

    /**
     * ask_1 and ask_2 both interrupt; step_a does not. Used by most subgraph cases.
     *
     * @param list<string> $called
     */
    private static function executor(array &$called): Pregel
    {
        return (new StateGraph(self::stateSchema()))
            ->addNode('step_a', self::recording($called, 'step_a', ['value' => ['step_a_done']]))
            ->addNode('ask_1', static function () use (&$called): array {
                $called[] = 'ask_1';

                return ['value' => ['ask_1:' . interrupt('Question 1?')]];
            })
            ->addNode('ask_2', static function () use (&$called): array {
                $called[] = 'ask_2';

                return ['value' => ['ask_2:' . interrupt('Question 2?')]];
            })
            ->addEdge(Constants::START, 'step_a')
            ->addEdge('step_a', 'ask_1')
            ->addEdge('ask_1', 'ask_2')
            ->addEdge('ask_2', Constants::END)
            ->compile(['checkpointer' => true]);
    }

    /**
     * @param list<string> $called
     */
    private static function parentOf(Pregel $executor): Pregel
    {
        return (new StateGraph(self::stateSchema()))
            ->addNode('executor', $executor)
            ->addEdge(Constants::START, 'executor')
            ->compile(['checkpointer' => new MemorySaver()]);
    }

    // ---- PR #7038: replay behaviour for parent + subgraphs ------------------------------

    public function testReplayFromBeforeAnInterruptStripsStaleResumeWrites(): void
    {
        $called = [];
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('node_a', self::recording($called, 'node_a', ['value' => ['a']]))
            ->addNode('ask_human', static function () use (&$called): array {
                $called[] = 'ask_human';

                return ['value' => ['human:' . interrupt('What is your input?')]];
            })
            ->addNode('node_b', self::recording($called, 'node_b', ['value' => ['b']]))
            ->addEdge(Constants::START, 'node_a')
            ->addEdge('node_a', 'ask_human')
            ->addEdge('ask_human', 'node_b')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        self::assertSame(['What is your input?'], self::interruptValues($graph, $config));

        $result = $graph->invoke(new Command(resume: 'old_answer'), $config);
        self::assertSame(['a', 'human:old_answer', 'b'], $result['value']);

        $beforeAsk = self::findNext($graph->getStateHistory($config), 'ask_human');

        $called = [];
        $graph->invoke(null, self::configOf($beforeAsk));

        // The old answer was not silently reused: the question is asked again.
        self::assertSame(['What is your input?'], self::interruptValues($graph, $config));
        self::assertContains('ask_human', $called);
        self::assertNotContains('node_a', $called);
    }

    public function testReplayWithASubgraphStripsStaleResumeWrites(): void
    {
        $called = [];
        $graph = self::parentOf(self::executor($called));
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        self::assertSame(['Question 1?'], self::interruptValues($graph, $config));

        $graph->invoke(new Command(resume: 'answer_1'), $config);
        self::assertSame(['Question 2?'], self::interruptValues($graph, $config));

        $graph->invoke(new Command(resume: 'answer_2'), $config);
        self::assertSame([], self::interruptValues($graph, $config));

        $beforeExecutor = self::findNext($graph->getStateHistory($config), 'executor');

        $called = [];
        $graph->invoke(null, self::configOf($beforeExecutor));

        self::assertSame(['Question 1?'], self::interruptValues($graph, $config));
        self::assertContains('step_a', $called);
        self::assertContains('ask_1', $called);
        self::assertNotContains('ask_2', $called);
    }

    public function testItResumesWithAnExplicitHeadCheckpointIdWithoutReplayState(): void
    {
        $called = [];
        $subgraph = (new StateGraph(self::stateSchema()))
            ->addNode('step_a', self::recording($called, 'step_a', ['value' => ['sub_a']]))
            ->addNode('ask_human', static function () use (&$called): array {
                $called[] = 'ask_human';

                return ['value' => ['human:' . interrupt('Provide input:')]];
            })
            ->addNode('step_b', self::recording($called, 'step_b', ['value' => ['sub_b']]))
            ->addEdge(Constants::START, 'step_a')
            ->addEdge('step_a', 'ask_human')
            ->addEdge('ask_human', 'step_b')
            ->addEdge('step_b', Constants::END)
            ->compile(['checkpointer' => true]);
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('subgraph_node', $subgraph)
            ->addEdge(Constants::START, 'subgraph_node')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        self::assertSame(['step_a', 'ask_human'], $called);

        $headCheckpointId = $graph->getState($config)->config['configurable']['checkpoint_id'];
        self::assertNotNull($headCheckpointId);

        $called = [];
        $result = $graph->invoke(new Command(resume: 'answer'), new \LangChain\Runnables\RunnableConfig(configurable: [
            'thread_id' => '1',
            'checkpoint_id' => $headCheckpointId,
            'checkpoint_ns' => '',
        ]));

        self::assertSame(['ask_human', 'step_b'], $called);
        self::assertSame([], self::interruptValues($graph, $config));
        self::assertSame(['sub_a', 'human:answer', 'sub_b'], $result['value']);
    }

    public function testAccumulatedSubgraphStateIsLoadedOnReplayThenResume(): void
    {
        $startedState = [];
        $subSchema = self::stateSchema();
        $subgraph = (new StateGraph($subSchema))
            ->addNode('step_a', static function (array $state) use (&$startedState): array {
                $startedState[] = $state;

                return ['value' => ['a:' . interrupt('question_a')]];
            })
            ->addEdge(Constants::START, 'step_a')
            ->compile(['checkpointer' => true]);

        $parentSchema = \LangGraph\State\Annotation::root([
            'results' => \LangGraph\State\Annotation::withReducer(
                static fn (array $a, array $b): array => array_merge($a, $b),
                static fn (): array => [],
            ),
        ]);
        $graph = (new StateGraph($parentSchema))
            ->addNode('parent_node', static fn (): array => ['results' => ['p']])
            ->addNode('sub_node', $subgraph)
            ->addEdge(Constants::START, 'parent_node')
            ->addEdge('parent_node', 'sub_node')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig();

        $graph->invoke(['results' => []], $config);
        $graph->invoke(new Command(resume: 'a1'), $config);
        self::assertSame(['value' => []], $startedState[0]);

        $startedState = [];
        $graph->invoke(['results' => []], $config);
        $graph->invoke(new Command(resume: 'a2'), $config);
        self::assertSame(['value' => ['a:a1']], $startedState[0]);

        $originalHistory = $graph->getStateHistory($config);
        self::assertSame(
            [[], ['sub_node'], ['parent_node'], ['__start__'], [], ['sub_node'], ['parent_node'], ['__start__']],
            array_column($originalHistory, 'next'),
        );

        $beforeSub2nd = self::findNext($originalHistory, 'sub_node');

        $startedState = [];
        $graph->invoke(null, self::configOf($beforeSub2nd));
        self::assertSame(['question_a'], self::interruptValues($graph, $config));
        self::assertSame(['value' => ['a:a1']], $startedState[0]);

        $postReplay = $graph->getStateHistory($config);
        self::assertSame(
            [['sub_node'], [], ['sub_node'], ['parent_node'], ['__start__'], [], ['sub_node'], ['parent_node'], ['__start__']],
            array_column($postReplay, 'next'),
        );
        self::assertSame(
            ['fork', 'loop', 'loop', 'loop', 'input', 'loop', 'loop', 'loop', 'input'],
            array_map(static fn (array $s): mixed => $s['metadata']['source'] ?? null, $postReplay),
        );

        $startedState = [];
        $final = $graph->invoke(new Command(resume: 'a3'), $config);
        self::assertSame([], self::interruptValues($graph, $config));
        self::assertSame(['p', 'p'], $final['results']);

        $finalHistory = $graph->getStateHistory($config);
        self::assertSame(
            [[], ['sub_node'], [], ['sub_node'], ['parent_node'], ['__start__'], [], ['sub_node'], ['parent_node'], ['__start__']],
            array_column($finalHistory, 'next'),
        );
        self::assertSame(
            ['loop', 'fork', 'loop', 'loop', 'loop', 'input', 'loop', 'loop', 'loop', 'input'],
            array_map(static fn (array $s): mixed => $s['metadata']['source'] ?? null, $finalHistory),
        );
    }

    // ---- PR #7115: direct-to-subgraph time travel ----------------------------------------

    public function testItTimeTravelsToASubgraphCheckpointAtTheFirstInterrupt(): void
    {
        $called = [];
        $graph = self::parentOf(self::executor($called));
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        $subConfigAtFirst = self::configOf(self::subgraphConfig($graph, $config, 'executor'));

        $graph->invoke(new Command(resume: 'answer_1'), $config);
        $graph->invoke(new Command(resume: 'answer_2'), $config);

        // Replay from the subgraph checkpoint at the first interrupt.
        $called = [];
        $graph->invoke(null, $subConfigAtFirst);
        self::assertSame(['Question 1?'], self::interruptValues($graph, $config));
        self::assertNotContains('step_a', $called);
        self::assertContains('ask_1', $called);

        // Fork from it.
        $called = [];
        $forkConfig = $graph->updateState($subConfigAtFirst, ['value' => ['forked']]);
        $graph->invoke(null, $forkConfig);
        self::assertSame(['Question 1?'], self::interruptValues($graph, $config));
        self::assertNotContains('step_a', $called);
        self::assertContains('ask_1', $called);
    }

    public function testItTimeTravelsToASubgraphCheckpointAtTheSecondInterrupt(): void
    {
        $called = [];
        $graph = self::parentOf(self::executor($called));
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'answer_1'), $config);

        $subConfig = self::configOf(self::subgraphConfig($graph, $config, 'executor'));

        $graph->invoke(new Command(resume: 'answer_2'), $config);

        $called = [];
        $graph->invoke(null, $subConfig);
        self::assertSame(['Question 2?'], self::interruptValues($graph, $config));
        self::assertNotContains('step_a', $called);
        self::assertNotContains('ask_1', $called);

        $called = [];
        $forkConfig = $graph->updateState($subConfig, ['value' => ['forked']]);
        $graph->invoke(null, $forkConfig);
        self::assertSame(['Question 2?'], self::interruptValues($graph, $config));
        self::assertNotContains('step_a', $called);
        self::assertNotContains('ask_1', $called);
    }

    public function testItTimeTravelsToASubgraphCheckpointAfterCompletion(): void
    {
        $called = [];
        $graph = self::parentOf(self::executor($called));
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'answer_1'), $config);
        $graph->invoke(new Command(resume: 'answer_2'), $config);

        $finalState = $graph->getState($config);
        self::assertCount(0, $finalState->tasks);

        $called = [];
        $replay = $graph->invoke(null, self::configOf($finalState->config));
        self::assertSame([], self::interruptValues($graph, $config));
        self::assertNotContains('step_a', $called);
        self::assertNotContains('ask_1', $called);
        self::assertNotContains('ask_2', $called);
        self::assertContains('step_a_done', $replay['value']);
        self::assertContains('ask_1:answer_1', $replay['value']);
        self::assertContains('ask_2:answer_2', $replay['value']);
    }

    public function testItTimeTravelsToTheMiddleSubgraphOfAThreeLevelGraph(): void
    {
        $called = [];
        $middle = (new StateGraph(self::stateSchema()))
            ->addNode('inner', self::executor($called))
            ->addEdge(Constants::START, 'inner')
            ->compile(['checkpointer' => true]);
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('outer', $middle)
            ->addEdge(Constants::START, 'outer')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'answer_1'), $config);

        $midConfig = self::configOf(self::subgraphConfig($graph, $config, 'outer'));

        $graph->invoke(new Command(resume: 'answer_2'), $config);

        $graph->invoke(null, $midConfig);
        self::assertNotSame([], self::interruptValues($graph, $config));

        $forkConfig = $graph->updateState($midConfig, ['value' => ['forked']]);
        $graph->invoke(null, $forkConfig);
        self::assertNotSame([], self::interruptValues($graph, $config));
    }

    public function testItTimeTravelsWhenTheMiddleSubgraphHasInterruptsOfItsOwn(): void
    {
        $called = [];
        $inner = (new StateGraph(self::stateSchema()))
            ->addNode('step_a', self::recording($called, 'step_a', ['value' => ['step_a_done']]))
            ->addNode('ask_1', static function () use (&$called): array {
                $called[] = 'ask_1';

                return ['value' => ['ask_1:' . interrupt('Question 1?')]];
            })
            ->addEdge(Constants::START, 'step_a')
            ->addEdge('step_a', 'ask_1')
            ->addEdge('ask_1', Constants::END)
            ->compile(['checkpointer' => true]);
        $middle = (new StateGraph(self::stateSchema()))
            ->addNode('pre', static function () use (&$called): array {
                $called[] = 'pre';

                return ['value' => ['pre:' . interrupt('Pre-question?')]];
            })
            ->addNode('inner', $inner)
            ->addEdge(Constants::START, 'pre')
            ->addEdge('pre', 'inner')
            ->addEdge('inner', Constants::END)
            ->compile(['checkpointer' => true]);
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('outer', $middle)
            ->addEdge(Constants::START, 'outer')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        self::assertSame(['Pre-question?'], self::interruptValues($graph, $config));
        $midConfigAtPre = self::configOf(self::subgraphConfig($graph, $config, 'outer'));

        $graph->invoke(new Command(resume: 'pre_answer'), $config);
        self::assertSame(['Question 1?'], self::interruptValues($graph, $config));
        $midConfigAtAsk1 = self::configOf(self::subgraphConfig($graph, $config, 'outer'));

        $graph->invoke(new Command(resume: 'answer_1'), $config);
        self::assertSame([], self::interruptValues($graph, $config));

        $called = [];
        $graph->invoke(null, $midConfigAtPre);
        self::assertSame(['Pre-question?'], self::interruptValues($graph, $config));
        self::assertContains('pre', $called);
        self::assertNotContains('step_a', $called);
        self::assertNotContains('ask_1', $called);

        $called = [];
        $graph->invoke(null, $graph->updateState($midConfigAtPre, ['value' => ['forked']]));
        self::assertSame(['Pre-question?'], self::interruptValues($graph, $config));
        self::assertContains('pre', $called);
        self::assertNotContains('step_a', $called);

        $called = [];
        $graph->invoke(null, $midConfigAtAsk1);
        self::assertSame(['Question 1?'], self::interruptValues($graph, $config));
        self::assertNotContains('pre', $called);
        self::assertContains('ask_1', $called);

        $called = [];
        $graph->invoke(null, $graph->updateState($midConfigAtAsk1, ['value' => ['forked']]));
        self::assertSame(['Question 1?'], self::interruptValues($graph, $config));
        self::assertNotContains('pre', $called);
        self::assertContains('ask_1', $called);
    }

    // ---- PR #7498: eager fork checkpoint on time travel ---------------------------------

    public function testItCreatesAForkCheckpointOnReplayFromBeforeAnInterruptThenResumes(): void
    {
        $called = [];
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('node_a', self::recording($called, 'node_a', ['value' => ['a']]))
            ->addNode('ask_human', static function () use (&$called): array {
                $called[] = 'ask_human';

                return ['value' => ['human:' . interrupt('What is your input?')]];
            })
            ->addNode('node_b', self::recording($called, 'node_b', ['value' => ['b']]))
            ->addEdge(Constants::START, 'node_a')
            ->addEdge('node_a', 'ask_human')
            ->addEdge('ask_human', 'node_b')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'old_answer'), $config);

        $originalHistory = $graph->getStateHistory($config);
        self::assertSame([
            ['loop', [], ['value' => ['a', 'human:old_answer', 'b']]],
            ['loop', ['node_b'], ['value' => ['a', 'human:old_answer']]],
            ['loop', ['ask_human'], ['value' => ['a']]],
            ['loop', ['node_a'], ['value' => []]],
            ['input', ['__start__'], ['value' => []]],
        ], self::sourceNextValues($originalHistory));

        $beforeAsk = self::findNext($originalHistory, 'ask_human');

        $called = [];
        $graph->invoke(null, self::configOf($beforeAsk));
        self::assertSame(['What is your input?'], self::interruptValues($graph, $config));
        self::assertContains('ask_human', $called);
        self::assertNotContains('node_a', $called);

        self::assertSame([
            ['fork', ['ask_human']],
            ['loop', []],
            ['loop', ['node_b']],
            ['loop', ['ask_human']],
            ['loop', ['node_a']],
            ['input', ['__start__']],
        ], self::sourceAndNext($graph->getStateHistory($config)));

        $called = [];
        $finalResult = $graph->invoke(new Command(resume: 'new_answer'), $config);
        self::assertSame(['a', 'human:new_answer', 'b'], $finalResult['value']);
        self::assertContains('ask_human', $called);
        self::assertContains('node_b', $called);

        self::assertSame([
            ['loop', [], ['value' => ['a', 'human:new_answer', 'b']]],
            ['loop', ['node_b'], ['value' => ['a', 'human:new_answer']]],
            ['fork', ['ask_human'], ['value' => ['a']]],
            ['loop', [], ['value' => ['a', 'human:old_answer', 'b']]],
            ['loop', ['node_b'], ['value' => ['a', 'human:old_answer']]],
            ['loop', ['ask_human'], ['value' => ['a']]],
            ['loop', ['node_a'], ['value' => []]],
            ['input', ['__start__'], ['value' => []]],
        ], self::sourceNextValues($graph->getStateHistory($config)));
    }

    public function testItCreatesAForkOnSubgraphTimeTravelAndResumesFromTheFirstInterrupt(): void
    {
        $called = [];
        $graph = self::parentOf(self::executor($called));
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        $subConfigAtFirst = self::configOf(self::subgraphConfig($graph, $config, 'executor'));
        $graph->invoke(new Command(resume: 'answer_1'), $config);
        $graph->invoke(new Command(resume: 'answer_2'), $config);

        self::assertSame([
            ['loop', [], ['value' => ['step_a_done', 'ask_1:answer_1', 'ask_2:answer_2']]],
            ['loop', ['executor'], ['value' => []]],
            ['input', ['__start__'], ['value' => []]],
        ], self::sourceNextValues($graph->getStateHistory($config)));

        $called = [];
        $graph->invoke(null, $subConfigAtFirst);
        self::assertSame(['Question 1?'], self::interruptValues($graph, $config));
        self::assertNotContains('step_a', $called);

        self::assertSame([
            ['fork', ['executor']],
            ['loop', []],
            ['loop', ['executor']],
            ['input', ['__start__']],
        ], self::sourceAndNext($graph->getStateHistory($config)));

        $called = [];
        $resume1 = $graph->invoke(new Command(resume: 'new_answer_1'), $config);
        self::assertSame(['Question 2?'], self::interruptValues($graph, $config));
        self::assertContains('ask_1', $called);
        unset($resume1);

        $called = [];
        $resume2 = $graph->invoke(new Command(resume: 'new_answer_2'), $config);
        self::assertSame(['step_a_done', 'ask_1:new_answer_1', 'ask_2:new_answer_2'], $resume2['value']);

        self::assertSame([
            ['loop', [], ['value' => ['step_a_done', 'ask_1:new_answer_1', 'ask_2:new_answer_2']]],
            ['fork', ['executor'], ['value' => []]],
            ['loop', [], ['value' => ['step_a_done', 'ask_1:answer_1', 'ask_2:answer_2']]],
            ['loop', ['executor'], ['value' => []]],
            ['input', ['__start__'], ['value' => []]],
        ], self::sourceNextValues($graph->getStateHistory($config)));
    }

    public function testItCreatesAForkOnSubgraphTimeTravelAndResumesFromTheSecondInterrupt(): void
    {
        $called = [];
        $graph = self::parentOf(self::executor($called));
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'answer_1'), $config);
        $subConfigAtSecond = self::configOf(self::subgraphConfig($graph, $config, 'executor'));
        $graph->invoke(new Command(resume: 'answer_2'), $config);

        $called = [];
        $graph->invoke(null, $subConfigAtSecond);
        self::assertSame(['Question 2?'], self::interruptValues($graph, $config));
        self::assertNotContains('step_a', $called);
        self::assertNotContains('ask_1', $called);

        self::assertSame([
            ['fork', ['executor']],
            ['loop', []],
            ['loop', ['executor']],
            ['input', ['__start__']],
        ], self::sourceAndNext($graph->getStateHistory($config)));

        $resume = $graph->invoke(new Command(resume: 'new_answer_2'), $config);
        self::assertSame(['step_a_done', 'ask_1:answer_1', 'ask_2:new_answer_2'], $resume['value']);

        self::assertSame([
            ['loop', [], ['value' => ['step_a_done', 'ask_1:answer_1', 'ask_2:new_answer_2']]],
            ['fork', ['executor'], ['value' => []]],
            ['loop', [], ['value' => ['step_a_done', 'ask_1:answer_1', 'ask_2:answer_2']]],
            ['loop', ['executor'], ['value' => []]],
            ['input', ['__start__'], ['value' => []]],
        ], self::sourceNextValues($graph->getStateHistory($config)));
    }

    public function testItVerifiesTheCheckpointPatternOnSubgraphTimeTravel(): void
    {
        $executor = (new StateGraph(self::stateSchema()))
            ->addNode('ask', static fn (): array => ['value' => ['a:' . interrupt('Q?')]])
            ->addEdge(Constants::START, 'ask')
            ->compile(['checkpointer' => true]);
        $graph = self::parentOf($executor);
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        $subConfig = self::subgraphConfig($graph, $config, 'executor');
        $graph->invoke(new Command(resume: 'first'), $config);

        self::assertSame([
            ['loop', [], ['value' => ['a:first']]],
            ['loop', ['executor'], ['value' => []]],
            ['input', ['__start__'], ['value' => []]],
        ], self::sourceNextValues($graph->getStateHistory($config)));

        $graph->invoke(null, self::configOf($subConfig));

        $postTt = $graph->getStateHistory($config);
        self::assertSame([
            ['fork', ['executor']],
            ['loop', []],
            ['loop', ['executor']],
            ['input', ['__start__']],
        ], self::sourceAndNext($postTt));

        // The fork hangs off the checkpoint the replay started from.
        $replayPointId = $subConfig['configurable']['checkpoint_map'][''];
        self::assertSame($replayPointId, $postTt[0]['parentConfig']['configurable']['checkpoint_id']);

        $result = $graph->invoke(new Command(resume: 'second'), $config);
        self::assertSame(['a:second'], $result['value']);

        self::assertSame([
            ['loop', [], ['value' => ['a:second']]],
            ['fork', ['executor'], ['value' => []]],
            ['loop', [], ['value' => ['a:first']]],
            ['loop', ['executor'], ['value' => []]],
            ['input', ['__start__'], ['value' => []]],
        ], self::sourceNextValues($graph->getStateHistory($config)));
    }

    public function testItReplaysFromAParentCheckpointWithASubgraphInterruptThenResumes(): void
    {
        $called = [];
        $subgraph = (new StateGraph(self::stateSchema()))
            ->addNode('step_a', self::recording($called, 'step_a', ['value' => ['sub_a']]))
            ->addNode('ask_human', static function () use (&$called): array {
                $called[] = 'ask_human';

                return ['value' => ['human:' . interrupt('Provide input:')]];
            })
            ->addNode('step_b', self::recording($called, 'step_b', ['value' => ['sub_b']]))
            ->addEdge(Constants::START, 'step_a')
            ->addEdge('step_a', 'ask_human')
            ->addEdge('ask_human', 'step_b')
            ->compile(['checkpointer' => true]);
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('router', self::recording($called, 'router', ['value' => ['routed']]))
            ->addNode('subgraph_node', $subgraph)
            ->addNode('post_process', self::recording($called, 'post_process', ['value' => ['post']]))
            ->addEdge(Constants::START, 'router')
            ->addEdge('router', 'subgraph_node')
            ->addEdge('subgraph_node', 'post_process')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig();

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'old_answer'), $config);

        $originalHistory = $graph->getStateHistory($config);
        self::assertSame(
            [[], ['post_process'], ['subgraph_node'], ['router'], ['__start__']],
            array_column($originalHistory, 'next'),
        );

        $interruptCheckpoint = self::findNext($originalHistory, 'subgraph_node');

        $called = [];
        $graph->invoke(null, self::configOf($interruptCheckpoint));
        self::assertSame(['Provide input:'], self::interruptValues($graph, $config));
        self::assertContains('step_a', $called);
        self::assertContains('ask_human', $called);
        self::assertNotContains('step_b', $called);

        $postReplay = $graph->getStateHistory($config);
        self::assertSame(
            [['subgraph_node'], [], ['post_process'], ['subgraph_node'], ['router'], ['__start__']],
            array_column($postReplay, 'next'),
        );
        self::assertSame(
            ['fork', 'loop', 'loop', 'loop', 'loop', 'input'],
            array_map(static fn (array $s): mixed => $s['metadata']['source'] ?? null, $postReplay),
        );

        $called = [];
        $finalResult = $graph->invoke(new Command(resume: 'new_answer'), $config);
        self::assertSame([], self::interruptValues($graph, $config));
        self::assertContains('human:new_answer', $finalResult['value']);
        self::assertContains('sub_b', $finalResult['value']);
        self::assertContains('post', $finalResult['value']);
        self::assertContains('ask_human', $called);
        self::assertContains('step_b', $called);
        self::assertContains('post_process', $called);

        $finalHistory = $graph->getStateHistory($config);
        self::assertSame(
            [[], ['post_process'], ['subgraph_node'], [], ['post_process'], ['subgraph_node'], ['router'], ['__start__']],
            array_column($finalHistory, 'next'),
        );
        self::assertSame(
            ['loop', 'loop', 'fork', 'loop', 'loop', 'loop', 'loop', 'input'],
            array_map(static fn (array $s): mixed => $s['metadata']['source'] ?? null, $finalHistory),
        );
    }
}
