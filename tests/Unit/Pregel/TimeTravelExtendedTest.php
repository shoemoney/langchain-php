<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\State\Annotation;
use LangGraph\State\AnnotationRoot;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/**
 * Port of the "Python parity" suite `langgraph-core/src/tests/time_travel_extended.test.ts`
 * (all 33 cases, grouped as upstream groups them).
 *
 * Differences from upstream, all forced by this port, and the same as {@see TimeTravelTest}:
 *  - `toHaveInterruptValue` / `toBeInterrupted` become reads of `getState()` (`interruptValues()`);
 *  - `getStateHistory()` returns arrays;
 *  - `getState(config, {subgraphs: true}).tasks[0].state` has no home on `PregelTaskDescription`,
 *    so the two cases that read it get the subgraph's config from `getState()` of its namespace
 *    instead (`subgraphConfig()`), which is the same config upstream returns there.
 */
#[CoversClass(Pregel::class)]
final class TimeTravelExtendedTest extends TestCase
{
    use TimeTravelFixtures;

    private static function concat(): \Closure
    {
        return static fn (array $a, array $b): array => array_merge($a, $b);
    }

    /** A root schema of list-concat channels. */
    private static function trail(string ...$keys): AnnotationRoot
    {
        $spec = [];
        foreach ($keys as $key) {
            $spec[$key] = Annotation::withReducer(self::concat(), static fn (): array => []);
        }

        return Annotation::root($spec);
    }

    /**
     * @param list<string> $called
     * @return array{0: Pregel, 1: \ArrayObject<int, string>}
     */
    private static function linear(MemorySaver $saver): Pregel
    {
        return (new StateGraph(self::stateSchema()))
            ->addNode('node_a', static fn (): array => ['value' => ['a']])
            ->addNode('node_b', static fn (): array => ['value' => ['b']])
            ->addEdge(Constants::START, 'node_a')
            ->addEdge('node_a', 'node_b')
            ->compile(['checkpointer' => $saver]);
    }

    /** The graph used by the interrupt cases: node_a, ask_human (interrupts), node_b. */
    private static function askGraph(): Pregel
    {
        return (new StateGraph(self::stateSchema()))
            ->addNode('node_a', static fn (): array => ['value' => ['a']])
            ->addNode('ask_human', static fn (): array => ['value' => ['human:' . interrupt('What is your input?')]])
            ->addNode('node_b', static fn (): array => ['value' => ['b']])
            ->addEdge(Constants::START, 'node_a')
            ->addEdge('node_a', 'ask_human')
            ->addEdge('ask_human', 'node_b')
            ->compile(['checkpointer' => new MemorySaver()]);
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    private static function lastWithNext(array $history, string $node): array
    {
        $matching = array_values(array_filter($history, static fn (array $s): bool => in_array($node, $s['next'], true)));
        self::assertNotSame([], $matching, "no history entry has \"{$node}\" in next");

        return $matching[count($matching) - 1];
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>|null
     */
    private static function interruptAtNode(array $history, string $node): ?array
    {
        foreach ($history as $entry) {
            if (!in_array($node, $entry['next'], true)) {
                continue;
            }
            foreach ($entry['tasks'] as $task) {
                if (($task['interrupts'] ?? []) !== []) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return list<string>
     */
    private static function checkpointIds(array $history): array
    {
        return array_values(array_unique(array_map(
            static fn (array $s): string => (string) $s['config']['configurable']['checkpoint_id'],
            $history,
        )));
    }

    // ---- Replay and fork basics ------------------------------------------------------------

    public function testItRerunsNodesAfterTheCheckpointOnReplay(): void
    {
        $called = [];
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('node_a', self::recording($called, 'node_a', ['value' => ['a']]))
            ->addNode('node_b', self::recording($called, 'node_b', ['value' => ['b']]))
            ->addEdge(Constants::START, 'node_a')
            ->addEdge('node_a', 'node_b')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig('tt-basic-1');

        self::assertSame(['a', 'b'], $graph->invoke(['value' => []], $config)['value']);

        $beforeB = self::findNext($graph->getStateHistory($config), 'node_b');

        $called = [];
        $replay = $graph->invoke(null, self::configOf($beforeB));
        self::assertSame(['a', 'b'], $replay['value']);
        self::assertContains('node_b', $called);
        self::assertNotContains('node_a', $called);
    }

    public function testItNoOpsWhenReplayingFromTheFinalCheckpoint(): void
    {
        $called = [];
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('node_a', self::recording($called, 'node_a', ['value' => ['a']]))
            ->addEdge(Constants::START, 'node_a')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig('tt-basic-2');

        $graph->invoke(['value' => []], $config);
        $state = $graph->getState($config);
        self::assertSame([], $state->next);

        $called = [];
        $replay = $graph->invoke(null, self::configOf($state->config));
        self::assertSame(['a'], $replay['value']);
        self::assertSame([], $called);
    }

    public function testItRerunsWithModifiedStateOnFork(): void
    {
        $called = [];
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('node_a', self::recording($called, 'node_a', ['value' => ['a']]))
            ->addNode('node_b', self::recording($called, 'node_b', ['value' => ['b']]))
            ->addEdge(Constants::START, 'node_a')
            ->addEdge('node_a', 'node_b')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig('tt-basic-3');

        $graph->invoke(['value' => []], $config);
        $beforeB = self::findNext($graph->getStateHistory($config), 'node_b');

        $called = [];
        $forkConfig = $graph->updateState(self::configOf($beforeB), ['value' => ['x']]);
        $forkResult = $graph->invoke(null, $forkConfig);

        self::assertContains('node_b', $called);
        self::assertSame(['a', 'x', 'b'], $forkResult['value']);
    }

    public function testItCreatesIndependentBranchesFromMultipleForks(): void
    {
        $graph = self::linear(new MemorySaver());
        $config = self::threadConfig('tt-basic-4');

        $graph->invoke(['value' => []], $config);
        $beforeB = self::findNext($graph->getStateHistory($config), 'node_b');

        $result1 = $graph->invoke(null, $graph->updateState(self::configOf($beforeB), ['value' => ['fork1']]));
        $result2 = $graph->invoke(null, $graph->updateState(self::configOf($beforeB), ['value' => ['fork2']]));

        self::assertContains('fork1', $result1['value']);
        self::assertNotContains('fork2', $result1['value']);
        self::assertContains('fork2', $result2['value']);
        self::assertNotContains('fork1', $result2['value']);
    }

    // ---- Interrupt replay and fork ---------------------------------------------------------

    public function testItProducesStableInterruptResultsAcrossRepeatedReplays(): void
    {
        $graph = self::askGraph();
        $config = self::threadConfig('tt-int-1');

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'cached_answer'), $config);

        $beforeAsk = self::lastWithNext($graph->getStateHistory($config), 'ask_human');

        $values = [];
        for ($i = 0; $i < 3; $i++) {
            $result = $graph->invoke(null, self::configOf($beforeAsk));
            self::assertSame(['What is your input?'], self::interruptValues($graph, $config));
            $values[] = json_encode($result['value']);
        }

        self::assertCount(1, array_unique($values));
    }

    public function testItReFiresTheInterruptOnForkFromBeforeTheInterrupt(): void
    {
        $graph = self::askGraph();
        $config = self::threadConfig('tt-int-2');

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'hello'), $config);

        $beforeAsk = self::lastWithNext($graph->getStateHistory($config), 'ask_human');

        $forkConfig = $graph->updateState(self::configOf($beforeAsk), ['value' => ['forked']]);
        $graph->invoke(null, $forkConfig);
        self::assertSame(['What is your input?'], self::interruptValues($graph, $forkConfig));

        $final = $graph->invoke(new Command(resume: 'world'), $forkConfig);
        self::assertSame(['a', 'forked', 'human:world', 'b'], $final['value']);
    }

    public function testItReFiresTheInterruptOnForkFromTheInterruptCheckpoint(): void
    {
        $graph = self::askGraph();
        $config = self::threadConfig('tt-int-3');

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'hello'), $config);

        $interruptCheckpoint = self::interruptAtNode($graph->getStateHistory($config), 'ask_human');
        self::assertNotNull($interruptCheckpoint);

        $forkConfig = $graph->updateState(self::configOf($interruptCheckpoint), ['value' => ['forked']]);
        $graph->invoke(null, $forkConfig);
        self::assertNotSame([], self::interruptValues($graph, $forkConfig));

        $final = $graph->invoke(new Command(resume: 'different'), $forkConfig);
        self::assertContains('human:different', $final['value']);
    }

    public function testItForksFromBetweenSequentialInterruptsPreservingTheFirstAnswer(): void
    {
        $called = [];
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('node_a', self::recording($called, 'node_a', ['value' => ['a']]))
            ->addNode('interrupt_1', static function () use (&$called): array {
                $called[] = 'interrupt_1';

                return ['value' => ['i1:' . interrupt('First question?')]];
            })
            ->addNode('interrupt_2', static function () use (&$called): array {
                $called[] = 'interrupt_2';

                return ['value' => ['i2:' . interrupt('Second question?')]];
            })
            ->addNode('node_b', self::recording($called, 'node_b', ['value' => ['b']]))
            ->addEdge(Constants::START, 'node_a')
            ->addEdge('node_a', 'interrupt_1')
            ->addEdge('interrupt_1', 'interrupt_2')
            ->addEdge('interrupt_2', 'node_b')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig('tt-int-4');

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'ans1'), $config);
        $graph->invoke(new Command(resume: 'ans2'), $config);

        $history = $graph->getStateHistory($config);
        $between = self::lastWithNext($history, 'interrupt_2');
        $forkConfig = $graph->updateState(self::configOf($between), ['value' => ['mid_fork']]);
        $graph->invoke(null, $forkConfig);
        self::assertSame(['Second question?'], self::interruptValues($graph, $forkConfig));

        $final = $graph->invoke(new Command(resume: 'new_b'), $forkConfig);
        self::assertContains('i2:new_b', $final['value']);
        self::assertContains('i1:ans1', $final['value']);

        $beforeI1 = self::lastWithNext($history, 'interrupt_1');
        $called = [];
        $graph->invoke(null, self::configOf($beforeI1));
        self::assertSame(['First question?'], self::interruptValues($graph, $config));
        self::assertContains('interrupt_1', $called);
        self::assertNotContains('interrupt_2', $called);
    }

    public function testItReFiresTheFirstInterruptWhenReplayingBeforeAMultiInterruptNode(): void
    {
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('ask', static function (): array {
                $answer1 = interrupt('First question?');
                $answer2 = interrupt('Second question?');

                return ['value' => ["a1:{$answer1}", "a2:{$answer2}"]];
            })
            ->addNode('after', static fn (): array => ['value' => ['done']])
            ->addEdge(Constants::START, 'ask')
            ->addEdge('ask', 'after')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig('tt-int-5');

        $graph->invoke(['value' => []], $config);
        self::assertSame(['First question?'], self::interruptValues($graph, $config));

        $graph->invoke(new Command(resume: 'ans1'), self::configOf($graph->getState($config)->config));
        self::assertSame(['Second question?'], self::interruptValues($graph, $config));

        $result = $graph->invoke(new Command(resume: 'ans2'), self::configOf($graph->getState($config)->config));
        self::assertSame(['a1:ans1', 'a2:ans2', 'done'], $result['value']);

        $beforeAsk = self::lastWithNext($graph->getStateHistory($config), 'ask');
        $graph->invoke(null, self::configOf($beforeAsk));
        self::assertSame(['First question?'], self::interruptValues($graph, $config));
    }

    // ---- Subgraph without interrupt ----------------------------------------------------------

    /**
     * @param list<string> $called
     */
    private static function plainSubgraphParent(array &$called): Pregel
    {
        $subgraph = (new StateGraph(self::stateSchema()))
            ->addNode('step_a', self::recording($called, 'step_a', ['value' => ['sub_a']]))
            ->addNode('step_b', self::recording($called, 'step_b', ['value' => ['sub_b']]))
            ->addEdge(Constants::START, 'step_a')
            ->addEdge('step_a', 'step_b')
            ->compile();

        return (new StateGraph(self::stateSchema()))
            ->addNode('parent_node', self::recording($called, 'parent_node', ['value' => ['parent']]))
            ->addNode('subgraph', $subgraph)
            ->addNode('post_process', self::recording($called, 'post_process', ['value' => ['post']]))
            ->addEdge(Constants::START, 'parent_node')
            ->addEdge('parent_node', 'subgraph')
            ->addEdge('subgraph', 'post_process')
            ->compile(['checkpointer' => new MemorySaver()]);
    }

    public function testItReplaysAParentCheckpointBeforeASubgraph(): void
    {
        $called = [];
        $graph = self::plainSubgraphParent($called);
        $config = self::threadConfig('tt-sub-1');

        $result = $graph->invoke(['value' => []], $config);
        self::assertContains('sub_a', $result['value']);
        self::assertContains('sub_b', $result['value']);
        self::assertContains('post', $result['value']);

        $beforeSub = self::findNext($graph->getStateHistory($config), 'subgraph');

        $called = [];
        $replay = $graph->invoke(null, self::configOf($beforeSub));
        self::assertContains('sub_a', $replay['value']);
        self::assertContains('sub_b', $replay['value']);
        self::assertContains('post', $replay['value']);
        self::assertNotContains('parent_node', $called);
    }

    public function testItForksAParentCheckpointBeforeASubgraphWithModifiedState(): void
    {
        $called = [];
        $graph = self::plainSubgraphParent($called);
        $config = self::threadConfig('tt-sub-2');

        $graph->invoke(['value' => []], $config);
        $beforeSub = self::findNext($graph->getStateHistory($config), 'subgraph');

        $called = [];
        $forkConfig = $graph->updateState(self::configOf($beforeSub), ['value' => ['forked']]);
        $forkResult = $graph->invoke(null, $forkConfig);

        self::assertContains('step_a', $called);
        self::assertContains('step_b', $called);
        self::assertContains('post_process', $called);
        self::assertContains('forked', $forkResult['value']);
    }

    // ---- Subgraph with interrupt (additional) -------------------------------------------------

    /**
     * @param list<string> $called
     */
    private static function routerSubgraphGraph(array &$called, bool $subCheckpointer): Pregel
    {
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
            ->compile($subCheckpointer ? ['checkpointer' => true] : []);

        return (new StateGraph(self::stateSchema()))
            ->addNode('router', self::recording($called, 'router', ['value' => ['routed']]))
            ->addNode('subgraph_node', $subgraph)
            ->addNode('post_process', self::recording($called, 'post_process', ['value' => ['post']]))
            ->addEdge(Constants::START, 'router')
            ->addEdge('router', 'subgraph_node')
            ->addEdge('subgraph_node', 'post_process')
            ->compile(['checkpointer' => new MemorySaver()]);
    }

    public function testItReplaysFromAParentCheckpointBeforeASubgraphInterrupt(): void
    {
        $called = [];
        $graph = self::routerSubgraphGraph($called, true);
        $config = self::threadConfig('tt-subint-1');

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'answer'), $config);

        $beforeSub = self::lastWithNext($graph->getStateHistory($config), 'subgraph_node');

        $called = [];
        $graph->invoke(null, self::configOf($beforeSub));
        self::assertSame(['Provide input:'], self::interruptValues($graph, $config));
        self::assertContains('step_a', $called);
        self::assertContains('ask_human', $called);
        self::assertNotContains('step_b', $called);
    }

    public function testItReplaysFromAParentInterruptCheckpoint(): void
    {
        $called = [];
        $graph = self::routerSubgraphGraph($called, true);
        $config = self::threadConfig('tt-subint-2');

        $graph->invoke(['value' => []], $config);
        // Upstream reads `tasks[0].state` here; the pending subgraph task must exist and carry
        // the interrupt, and its own state is reachable through its namespace.
        $parentState = $graph->getState($config, ['subgraphs' => true]);
        self::assertNotEmpty($parentState->tasks);
        self::assertNotEmpty(self::subgraphConfig($graph, $config, 'subgraph_node'));

        $graph->invoke(new Command(resume: 'answer'), $config);

        $interruptCheckpoint = self::interruptAtNode($graph->getStateHistory($config), 'subgraph_node');
        self::assertNotNull($interruptCheckpoint);

        $called = [];
        $graph->invoke(null, self::configOf($interruptCheckpoint));
        self::assertNotSame([], self::interruptValues($graph, $config));
        self::assertContains('step_a', $called);
        self::assertContains('ask_human', $called);
        self::assertNotContains('step_b', $called);
    }

    public function testItForksAndResumesAfterReplayFromAParentInterruptCheckpoint(): void
    {
        $called = [];
        $graph = self::routerSubgraphGraph($called, true);
        $config = self::threadConfig('tt-subint-3');

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'old_answer'), $config);

        $interruptCheckpoint = self::findNext($graph->getStateHistory($config), 'subgraph_node');

        $called = [];
        $graph->invoke(null, self::configOf($interruptCheckpoint));
        self::assertSame(['Provide input:'], self::interruptValues($graph, $config));

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
        $final = $graph->invoke(new Command(resume: 'new_answer'), $config);
        self::assertSame([], self::interruptValues($graph, $config));
        self::assertContains('human:new_answer', $final['value']);
        self::assertContains('ask_human', $called);
        self::assertContains('step_b', $called);
        self::assertContains('post_process', $called);
    }

    public function testItCompletesTheFlowWhenTheSubgraphHasNoCheckpointer(): void
    {
        $called = [];
        $graph = self::routerSubgraphGraph($called, false);
        $config = self::threadConfig('tt-subint-4');

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'original'), $config);

        $beforeSub = self::lastWithNext($graph->getStateHistory($config), 'subgraph_node');

        $called = [];
        $forkConfig = $graph->updateState(self::configOf($beforeSub), ['value' => ['forked']]);
        $graph->invoke(null, $forkConfig);
        self::assertSame(['Provide input:'], self::interruptValues($graph, $forkConfig));

        $called = [];
        $final = $graph->invoke(new Command(resume: 'new_answer'), $forkConfig);
        self::assertContains('human:new_answer', $final['value']);
        self::assertContains('step_b', $called);
        self::assertContains('post_process', $called);
    }

    public function testItReplaysFromTheSubgraphNamespaceOfAPendingTask(): void
    {
        $called = [];
        $graph = self::routerSubgraphGraph($called, true);
        $config = self::threadConfig('tt-subint-5');

        $graph->invoke(['value' => []], $config);

        // Upstream takes `tasks[0].state.config`: with no saved checkpoint under the task's own
        // namespace that config is just `{thread_id, checkpoint_ns: "subgraph_node:<taskId>"}`, so
        // it names a place in the thread rather than a checkpoint. Build the same config.
        $parentState = $graph->getState($config, ['subgraphs' => true]);
        $subConfig = new RunnableConfig(configurable: [
            'thread_id' => 'tt-subint-5',
            'checkpoint_ns' => 'subgraph_node:' . $parentState->tasks[0]->id,
        ]);

        $called = [];
        $graph->invoke(null, $subConfig);
        self::assertSame(['Provide input:'], self::interruptValues($graph, $config));

        $called = [];
        $final = $graph->invoke(new Command(resume: 'replayed_answer'), $subConfig);
        self::assertContains('ask_human', $called);
        self::assertContains('human:replayed_answer', $final['value']);
        self::assertContains('step_b', $called);
        self::assertContains('post_process', $called);
    }

    // ---- Copy fork and update_state ----------------------------------------------------------

    public function testItRetriggersTheInterruptOnACopyFork(): void
    {
        $graph = self::askGraph();
        $config = self::threadConfig('tt-copy-1');

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'hello'), $config);

        $beforeAsk = self::lastWithNext($graph->getStateHistory($config), 'ask_human');

        $forkConfig = $graph->updateState(self::configOf($beforeAsk), null, Constants::COPY);
        $graph->invoke(null, $forkConfig);
        self::assertSame(['What is your input?'], self::interruptValues($graph, $forkConfig));

        $final = $graph->invoke(new Command(resume: 'new_answer'), $forkConfig);
        self::assertSame(['a', 'human:new_answer', 'b'], $final['value']);
    }

    public function testItDistinguishesCopyForkMetadataFromUpdateFork(): void
    {
        $graph = self::linear(new MemorySaver());
        $config = self::threadConfig('tt-copy-2');

        $graph->invoke(['value' => []], $config);
        $beforeB = self::findNext($graph->getStateHistory($config), 'node_b');

        $copyConfig = $graph->updateState(self::configOf($beforeB), null, Constants::COPY);
        self::assertSame('fork', $graph->getState($copyConfig)->metadata['source']);

        $regularConfig = $graph->updateState(self::configOf($beforeB), ['value' => ['x']]);
        self::assertSame('update', $graph->getState($regularConfig)->metadata['source']);
    }

    public function testItRetriggersTheInterruptOnUpdateStateWithNullValues(): void
    {
        $graph = self::askGraph();
        $config = self::threadConfig('tt-copy-3');

        $graph->invoke(['value' => []], $config);
        $graph->invoke(new Command(resume: 'hello'), $config);

        $beforeAsk = self::lastWithNext($graph->getStateHistory($config), 'ask_human');

        $forkConfig = $graph->updateState(self::configOf($beforeAsk), null);
        $graph->invoke(null, $forkConfig);
        self::assertSame(['What is your input?'], self::interruptValues($graph, $forkConfig));

        self::assertSame('update', $graph->getState($forkConfig)->metadata['source']);
    }

    // ---- Stateful subgraph replay ------------------------------------------------------------

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $started
     * @param list<array{0: string, 1: array<string, mixed>}> $observed
     */
    private static function twoQuestionSubgraphParent(array &$started, array &$observed, bool $stateful): Pregel
    {
        $sub = (new StateGraph(self::trail('value')))
            ->addNode('step_a', static function (array $state) use (&$started, &$observed): array {
                $started[] = ['step_a', $state];
                $answer = interrupt('question_a');
                $observed[] = ['step_a', $state];

                return ['value' => ["a:{$answer}"]];
            })
            ->addNode('step_b', static function (array $state) use (&$started, &$observed): array {
                $started[] = ['step_b', $state];
                $answer = interrupt('question_b');
                $observed[] = ['step_b', $state];

                return ['value' => ["b:{$answer}"]];
            })
            ->addEdge(Constants::START, 'step_a')
            ->addEdge('step_a', 'step_b')
            ->compile($stateful ? ['checkpointer' => true] : []);

        return (new StateGraph(self::trail('results')))
            ->addNode('parent_node', static fn (): array => ['results' => ['p']])
            ->addNode('sub_node', $sub)
            ->addEdge(Constants::START, 'parent_node')
            ->addEdge('parent_node', 'sub_node')
            ->compile(['checkpointer' => new MemorySaver()]);
    }

    public function testItRetainsAccumulatedSubgraphStateOnParentReplay(): void
    {
        $started = [];
        $observed = [];
        $graph = self::twoQuestionSubgraphParent($started, $observed, true);
        $config = self::threadConfig('tt-stateful-1');

        $graph->invoke(['results' => []], $config);
        $graph->invoke(new Command(resume: 'a1'), $config);
        $graph->invoke(new Command(resume: 'b1'), $config);
        self::assertSame(['step_a', ['value' => []]], $observed[0]);
        self::assertSame(['step_b', ['value' => ['a:a1']]], $observed[1]);

        $observed = [];
        $graph->invoke(['results' => []], $config);
        $graph->invoke(new Command(resume: 'a2'), $config);
        $graph->invoke(new Command(resume: 'b2'), $config);
        self::assertSame(['step_a', ['value' => ['a:a1', 'b:b1']]], $observed[0]);
        self::assertSame(['step_b', ['value' => ['a:a1', 'b:b1', 'a:a2']]], $observed[1]);

        $history = $graph->getStateHistory($config);
        $beforeSub2nd = array_values(array_filter($history, static fn (array $s): bool => in_array('sub_node', $s['next'], true)))[0];

        $started = [];
        $graph->invoke(null, self::configOf($beforeSub2nd));
        self::assertNotSame([], self::interruptValues($graph, $config));
        self::assertSame(['step_a', ['value' => ['a:a1', 'b:b1']]], $started[0]);
    }

    public function testItStartsAStatelessSubgraphFreshOnParentReplay(): void
    {
        $started = [];
        $observed = [];
        $graph = self::twoQuestionSubgraphParent($started, $observed, false);
        $config = self::threadConfig('tt-stateful-2');

        $graph->invoke(['results' => []], $config);
        $graph->invoke(new Command(resume: 'a1'), $config);
        $graph->invoke(new Command(resume: 'b1'), $config);
        self::assertSame(['step_a', ['value' => []]], $observed[0]);
        self::assertSame(['step_b', ['value' => ['a:a1']]], $observed[1]);

        $observed = [];
        $graph->invoke(['results' => []], $config);
        $graph->invoke(new Command(resume: 'a2'), $config);
        $graph->invoke(new Command(resume: 'b2'), $config);
        self::assertSame(['step_a', ['value' => []]], $observed[0]);
        self::assertSame(['step_b', ['value' => ['a:a2']]], $observed[1]);

        $history = $graph->getStateHistory($config);
        $beforeSub2nd = array_values(array_filter($history, static fn (array $s): bool => in_array('sub_node', $s['next'], true)))[0];

        $started = [];
        $graph->invoke(null, self::configOf($beforeSub2nd));
        self::assertNotSame([], self::interruptValues($graph, $config));
        self::assertSame(['step_a', ['value' => []]], $started[0]);
    }

    // ---- Append-only checkpoint history ------------------------------------------------------

    public function testReplayPreservesTheOriginalCheckpointsWhenItCreatesABranch(): void
    {
        $callCount = 0;
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('node_a', static fn (): array => ['value' => ['a']])
            ->addNode('node_b', static function () use (&$callCount): array {
                $callCount++;

                return ['value' => ["b{$callCount}"]];
            })
            ->addNode('node_c', static fn (): array => ['value' => ['c']])
            ->addEdge(Constants::START, 'node_a')
            ->addEdge('node_a', 'node_b')
            ->addEdge('node_b', 'node_c')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig('tt-branch-1');

        self::assertSame(['a', 'b1', 'c'], $graph->invoke(['value' => []], $config)['value']);

        $originalHistory = $graph->getStateHistory($config);
        self::assertCount(5, $originalHistory);
        self::assertSame(
            [[], ['node_c'], ['node_b'], ['node_a'], ['__start__']],
            array_column($originalHistory, 'next'),
        );

        $originalIds = self::checkpointIds($originalHistory);
        $beforeB = self::findNext($originalHistory, 'node_b');
        $beforeBId = $beforeB['config']['configurable']['checkpoint_id'];

        $replay = $graph->invoke(null, self::configOf($beforeB));
        self::assertSame(['a', 'b2', 'c'], $replay['value']);

        $postHistory = $graph->getStateHistory($config);
        self::assertCount(8, $postHistory);
        self::assertSame(
            [[], ['node_c'], ['node_b'], [], ['node_c'], ['node_b'], ['node_a'], ['__start__']],
            array_column($postHistory, 'next'),
        );

        $postIds = self::checkpointIds($postHistory);
        foreach ($originalIds as $id) {
            self::assertContains($id, $postIds);
        }

        $fork = null;
        foreach ($postHistory as $entry) {
            if (($entry['metadata']['source'] ?? null) === 'fork'
                && !in_array($entry['config']['configurable']['checkpoint_id'], $originalIds, true)) {
                $fork = $entry;
                break;
            }
        }
        self::assertNotNull($fork);
        self::assertSame($beforeBId, $fork['parentConfig']['configurable']['checkpoint_id']);

        $latest = $graph->getState($config);
        self::assertSame(['value' => ['a', 'b2', 'c']], $latest->values);
        self::assertNotContains($latest->config['configurable']['checkpoint_id'], $originalIds);
    }

    /**
     * @return array{0: Pregel, 1: \stdClass}
     */
    private static function branchingSubgraphParent(): array
    {
        $counter = new \stdClass();
        $counter->subCalls = 0;

        $sub = (new StateGraph(self::trail('subValue')))
            ->addNode('sub_step', static function () use ($counter): array {
                $counter->subCalls++;

                return ['subValue' => ["sub{$counter->subCalls}"]];
            })
            ->addEdge(Constants::START, 'sub_step')
            ->compile();

        $graph = (new StateGraph(self::trail('value', 'subValue')))
            ->addNode('parent_start', static fn (): array => ['value' => ['p_start']])
            ->addNode('sub_graph', $sub)
            ->addNode('parent_end', static fn (): array => ['value' => ['p_end']])
            ->addEdge(Constants::START, 'parent_start')
            ->addEdge('parent_start', 'sub_graph')
            ->addEdge('sub_graph', 'parent_end')
            ->compile(['checkpointer' => new MemorySaver()]);

        return [$graph, $counter];
    }

    public function testReplayPreservesTheOriginalCheckpointsWhenItCreatesASubgraphBranch(): void
    {
        [$graph] = self::branchingSubgraphParent();
        $config = self::threadConfig('tt-branch-sub-1');

        self::assertSame(
            ['value' => ['p_start', 'p_end'], 'subValue' => ['sub1']],
            $graph->invoke(['value' => [], 'subValue' => []], $config),
        );

        $originalHistory = $graph->getStateHistory($config);
        $originalIds = self::checkpointIds($originalHistory);
        $beforeSub = self::findNext($originalHistory, 'sub_graph');
        $beforeSubId = $beforeSub['config']['configurable']['checkpoint_id'];

        self::assertSame(
            ['value' => ['p_start', 'p_end'], 'subValue' => ['sub2']],
            $graph->invoke(null, self::configOf($beforeSub)),
        );

        $postHistory = $graph->getStateHistory($config);
        $postIds = self::checkpointIds($postHistory);
        foreach ($originalIds as $id) {
            self::assertContains($id, $postIds);
        }

        $newIds = array_values(array_diff($postIds, $originalIds));
        self::assertGreaterThanOrEqual(2, count($newIds));

        $fork = null;
        foreach ($postHistory as $entry) {
            if (($entry['metadata']['source'] ?? null) === 'fork' && in_array($entry['config']['configurable']['checkpoint_id'], $newIds, true)) {
                $fork = $entry;
                break;
            }
        }
        self::assertNotNull($fork);
        self::assertSame($beforeSubId, $fork['parentConfig']['configurable']['checkpoint_id']);

        $latest = $graph->getState($config);
        self::assertContains($latest->config['configurable']['checkpoint_id'], $newIds);
        self::assertSame(['value' => ['p_start', 'p_end'], 'subValue' => ['sub2']], $latest->values);
    }

    public function testForkPreservesTheOriginalCheckpointsWhenItCreatesABranch(): void
    {
        $callCount = 0;
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('node_a', static fn (): array => ['value' => ['a']])
            ->addNode('node_b', static function () use (&$callCount): array {
                $callCount++;

                return ['value' => ["b{$callCount}"]];
            })
            ->addNode('node_c', static fn (): array => ['value' => ['c']])
            ->addEdge(Constants::START, 'node_a')
            ->addEdge('node_a', 'node_b')
            ->addEdge('node_b', 'node_c')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig('tt-branch-fork-1');

        $graph->invoke(['value' => []], $config);

        $originalHistory = $graph->getStateHistory($config);
        self::assertCount(5, $originalHistory);
        $originalIds = self::checkpointIds($originalHistory);
        $beforeB = self::findNext($originalHistory, 'node_b');
        $beforeBId = $beforeB['config']['configurable']['checkpoint_id'];

        $forkConfig = $graph->updateState(self::configOf($beforeB), ['value' => ['x']]);
        self::assertSame(['a', 'x', 'b2', 'c'], $graph->invoke(null, $forkConfig)['value']);

        $postHistory = $graph->getStateHistory($config);
        self::assertCount(8, $postHistory);
        self::assertSame([
            ['value' => ['a', 'x', 'b2', 'c']],
            ['value' => ['a', 'x', 'b2']],
            ['value' => ['a', 'x']],
            ['value' => ['a', 'b1', 'c']],
            ['value' => ['a', 'b1']],
            ['value' => ['a']],
            ['value' => []],
            ['value' => []],
        ], array_column($postHistory, 'values'));

        foreach ($originalIds as $id) {
            self::assertContains($id, self::checkpointIds($postHistory));
        }

        $update = null;
        foreach ($postHistory as $entry) {
            if (($entry['metadata']['source'] ?? null) === 'update'
                && !in_array($entry['config']['configurable']['checkpoint_id'], $originalIds, true)) {
                $update = $entry;
                break;
            }
        }
        self::assertNotNull($update);
        self::assertSame($beforeBId, $update['parentConfig']['configurable']['checkpoint_id']);

        $latest = $graph->getState($config);
        self::assertSame(['value' => ['a', 'x', 'b2', 'c']], $latest->values);
        self::assertNotContains($latest->config['configurable']['checkpoint_id'], $originalIds);
    }

    public function testForkPreservesTheOriginalCheckpointsWhenItCreatesASubgraphBranch(): void
    {
        [$graph] = self::branchingSubgraphParent();
        $config = self::threadConfig('tt-branch-fork-sub-1');

        $graph->invoke(['value' => [], 'subValue' => []], $config);

        $originalHistory = $graph->getStateHistory($config);
        $originalIds = self::checkpointIds($originalHistory);
        $beforeSub = self::findNext($originalHistory, 'sub_graph');
        $beforeSubId = $beforeSub['config']['configurable']['checkpoint_id'];

        $forkConfig = $graph->updateState(self::configOf($beforeSub), ['value' => ['extra']]);
        self::assertSame(
            ['value' => ['p_start', 'extra', 'p_end'], 'subValue' => ['sub2']],
            $graph->invoke(null, $forkConfig),
        );

        $postHistory = $graph->getStateHistory($config);
        $postIds = self::checkpointIds($postHistory);
        foreach ($originalIds as $id) {
            self::assertContains($id, $postIds);
        }

        $newIds = array_values(array_diff($postIds, $originalIds));
        self::assertGreaterThanOrEqual(3, count($newIds));

        $update = null;
        foreach ($postHistory as $entry) {
            if (($entry['metadata']['source'] ?? null) === 'update' && in_array($entry['config']['configurable']['checkpoint_id'], $newIds, true)) {
                $update = $entry;
                break;
            }
        }
        self::assertNotNull($update);
        self::assertSame($beforeSubId, $update['parentConfig']['configurable']['checkpoint_id']);

        $latest = $graph->getState($config);
        self::assertContains($latest->config['configurable']['checkpoint_id'], $newIds);
        self::assertSame(['value' => ['p_start', 'extra', 'p_end'], 'subValue' => ['sub2']], $latest->values);
    }

    // ---- Observability -----------------------------------------------------------------------

    public function testGetStateReachesAnInterruptedSubgraphThroughItsNamespace(): void
    {
        $subgraph = (new StateGraph(Annotation::root(['data' => Annotation::last()])))
            ->addNode('process', static function (): array {
                interrupt('Continue?');

                return ['data' => 'processed'];
            })
            ->addEdge(Constants::START, 'process')
            ->compile();
        $graph = (new StateGraph(Annotation::root(['data' => Annotation::last()])))
            ->addNode('sub', $subgraph)
            ->addEdge(Constants::START, 'sub')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig('tt-obs-1');

        $graph->invoke(['data' => 'input'], $config);

        $state = $graph->getState($config, ['subgraphs' => true]);
        self::assertGreaterThan(0, count($state->tasks));

        // Upstream reads `tasks[0].state`; the port reaches the same snapshot through the task's
        // namespace (`sub:<taskId>`), which `getState()` delegates to the subgraph.
        $subState = $graph->getState(
            new RunnableConfig(configurable: ['thread_id' => 'tt-obs-1', 'checkpoint_ns' => 'sub:' . $state->tasks[0]->id]),
            ['subgraphs' => true],
        );
        self::assertSame('tt-obs-1', $subState->config['configurable']['thread_id']);
        self::assertSame(['process'], $subState->next);
    }

    public function testItExposesCheckpointNsAndThreadIdInsideSubgraphNodes(): void
    {
        $captured = [];
        $subgraph = (new StateGraph(Annotation::root(['data' => Annotation::last()])))
            ->addNode('inner', static function (array $state, RunnableConfig $config) use (&$captured): array {
                $captured['checkpoint_ns'] = $config->configurable['checkpoint_ns'] ?? null;
                $captured['thread_id'] = $config->configurable['thread_id'] ?? null;

                return ['data' => 'done'];
            })
            ->addEdge(Constants::START, 'inner')
            ->compile();
        $graph = (new StateGraph(Annotation::root(['data' => Annotation::last()])))
            ->addNode('outer', $subgraph)
            ->addEdge(Constants::START, 'outer')
            ->compile(['checkpointer' => new MemorySaver()]);

        $graph->invoke(['data' => 'test'], self::threadConfig('tt-obs-2'));

        self::assertNotEmpty($captured['checkpoint_ns']);
        self::assertSame('tt-obs-2', $captured['thread_id']);
    }

    // ---- Nested and parallel subgraph state on replay ----------------------------------------

    /**
     * A `parent_node` -> `sub_node` graph whose subgraph records the state it starts from.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $observed
     */
    private static function observingSubgraphParent(array &$observed): Pregel
    {
        $sub = (new StateGraph(self::trail('value')))
            ->addNode('sub_step', static function (array $state) use (&$observed): array {
                $observed[] = ['sub_step', ['value' => $state['value']]];

                return ['value' => ['s']];
            })
            ->addEdge(Constants::START, 'sub_step')
            ->compile(['checkpointer' => true]);

        return (new StateGraph(self::trail('results')))
            ->addNode('parent_node', static fn (): array => ['results' => ['p']])
            ->addNode('sub_node', $sub)
            ->addEdge(Constants::START, 'parent_node')
            ->addEdge('parent_node', 'sub_node')
            ->compile(['checkpointer' => new MemorySaver()]);
    }

    public function testItLoadsSubgraphStateFromTheFirstInvocationOnReplay(): void
    {
        $observed = [];
        $graph = self::observingSubgraphParent($observed);
        $config = self::threadConfig('tt-nested-1');

        $graph->invoke(['results' => []], $config);
        $graph->invoke(['results' => []], $config);

        $beforeSub1st = self::lastWithNext($graph->getStateHistory($config), 'sub_node');

        $observed = [];
        $graph->invoke(null, self::configOf($beforeSub1st));
        self::assertSame(['sub_step', ['value' => []]], $observed[0]);
    }

    public function testItLoadsTheLatestSubgraphStateAfterParentReplayOnNextInvoke(): void
    {
        $observed = [];
        $graph = self::observingSubgraphParent($observed);
        $config = self::threadConfig('tt-nested-2');

        $graph->invoke(['results' => []], $config);
        self::assertSame(['sub_step', ['value' => []]], $observed[count($observed) - 1]);

        $graph->invoke(['results' => []], $config);
        self::assertSame(['sub_step', ['value' => ['s']]], $observed[count($observed) - 1]);

        $history = $graph->getStateHistory($config);
        $beforeParent2nd = array_values(array_filter($history, static fn (array $s): bool => in_array('parent_node', $s['next'], true)))[0];

        $observed = [];
        $graph->invoke(null, self::configOf($beforeParent2nd));
        self::assertSame(['sub_step', ['value' => ['s']]], $observed[0]);

        $observed = [];
        $graph->invoke(['results' => []], $config);
        self::assertSame(['sub_step', ['value' => ['s', 's']]], $observed[0]);
    }

    /**
     * parent_step -> mid_node (mid_step -> inner_node (inner_step)), every level stateful.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $observed
     */
    private static function threeLevelParent(array &$observed): Pregel
    {
        $inner = (new StateGraph(self::trail('innerTrail')))
            ->addNode('inner_step', static function (array $state) use (&$observed): array {
                $observed[] = ['inner_step', ['innerTrail' => $state['innerTrail']]];

                return ['innerTrail' => ['inner']];
            })
            ->addEdge(Constants::START, 'inner_step')
            ->compile(['checkpointer' => true]);

        $mid = (new StateGraph(self::trail('midTrail')))
            ->addNode('mid_step', static function (array $state) use (&$observed): array {
                $observed[] = ['mid_step', ['midTrail' => $state['midTrail']]];

                return ['midTrail' => ['mid']];
            })
            ->addNode('inner_node', $inner)
            ->addEdge(Constants::START, 'mid_step')
            ->addEdge('mid_step', 'inner_node')
            ->compile(['checkpointer' => true]);

        return (new StateGraph(self::trail('results')))
            ->addNode('parent_step', static fn (): array => ['results' => ['p']])
            ->addNode('mid_node', $mid)
            ->addEdge(Constants::START, 'parent_step')
            ->addEdge('parent_step', 'mid_node')
            ->compile(['checkpointer' => new MemorySaver()]);
    }

    public function testItLoadsTheCorrectStateAtAllThreeNestingLevelsOnReplay(): void
    {
        $observed = [];
        $graph = self::threeLevelParent($observed);
        $config = self::threadConfig('tt-nested-3');

        $graph->invoke(['results' => []], $config);
        self::assertSame([['mid_step', ['midTrail' => []]], ['inner_step', ['innerTrail' => []]]], $observed);

        $observed = [];
        $graph->invoke(['results' => []], $config);
        self::assertSame([['mid_step', ['midTrail' => ['mid']]], ['inner_step', ['innerTrail' => ['inner']]]], $observed);

        $history = $graph->getStateHistory($config);
        $beforeParent2nd = array_values(array_filter($history, static fn (array $s): bool => in_array('parent_step', $s['next'], true)))[0];

        $observed = [];
        $graph->invoke(null, self::configOf($beforeParent2nd));
        self::assertSame([['mid_step', ['midTrail' => ['mid']]], ['inner_step', ['innerTrail' => ['inner']]]], $observed);

        $observed = [];
        $graph->invoke(['results' => []], $config);
        self::assertSame([
            ['mid_step', ['midTrail' => ['mid', 'mid']]],
            ['inner_step', ['innerTrail' => ['inner', 'inner']]],
        ], $observed);
    }

    public function testItLoadsTheCorrectNestedStateOnFork(): void
    {
        $observed = [];
        $graph = self::threeLevelParent($observed);
        $config = self::threadConfig('tt-nested-4');

        $graph->invoke(['results' => []], $config);
        $graph->invoke(['results' => []], $config);

        $history = $graph->getStateHistory($config);
        $beforeParent2nd = array_values(array_filter($history, static fn (array $s): bool => in_array('parent_step', $s['next'], true)))[0];
        $forkConfig = $graph->updateState(self::configOf($beforeParent2nd), ['results' => ['forked']]);

        $observed = [];
        $graph->invoke(null, $forkConfig);
        self::assertSame([['mid_step', ['midTrail' => ['mid']]], ['inner_step', ['innerTrail' => ['inner']]]], $observed);
    }

    public function testItLoadsTheCorrectStateForParallelSiblingSubgraphsOnReplay(): void
    {
        $observed = [];
        $subA = (new StateGraph(self::trail('aTrail')))
            ->addNode('sub_a_step', static function (array $state) use (&$observed): array {
                $observed[] = ['sub_a', ['aTrail' => $state['aTrail']]];

                return ['aTrail' => ['a']];
            })
            ->addEdge(Constants::START, 'sub_a_step')
            ->compile(['checkpointer' => true]);
        $subB = (new StateGraph(self::trail('bTrail')))
            ->addNode('sub_b_step', static function (array $state) use (&$observed): array {
                $observed[] = ['sub_b', ['bTrail' => $state['bTrail']]];

                return ['bTrail' => ['b']];
            })
            ->addEdge(Constants::START, 'sub_b_step')
            ->compile(['checkpointer' => true]);

        $graph = (new StateGraph(self::trail('results')))
            ->addNode('parent_step', static fn (): array => ['results' => ['p']])
            ->addNode('sub_a_node', $subA)
            ->addNode('sub_b_node', $subB)
            ->addEdge(Constants::START, 'parent_step')
            ->addEdge('parent_step', 'sub_a_node')
            ->addEdge('parent_step', 'sub_b_node')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig('tt-parallel-1');

        $first = static fn (array $observed, string $who): array => array_values(array_filter($observed, static fn (array $o): bool => $o[0] === $who))[0];

        $graph->invoke(['results' => []], $config);
        self::assertSame(['sub_a', ['aTrail' => []]], $first($observed, 'sub_a'));
        self::assertSame(['sub_b', ['bTrail' => []]], $first($observed, 'sub_b'));

        $observed = [];
        $graph->invoke(['results' => []], $config);
        self::assertSame(['sub_a', ['aTrail' => ['a']]], $first($observed, 'sub_a'));
        self::assertSame(['sub_b', ['bTrail' => ['b']]], $first($observed, 'sub_b'));

        $history = $graph->getStateHistory($config);
        $beforeParent2nd = array_values(array_filter($history, static fn (array $s): bool => in_array('parent_step', $s['next'], true)))[0];

        $observed = [];
        $graph->invoke(null, self::configOf($beforeParent2nd));
        self::assertSame(['sub_a', ['aTrail' => ['a']]], $first($observed, 'sub_a'));
        self::assertSame(['sub_b', ['bTrail' => ['b']]], $first($observed, 'sub_b'));

        $observed = [];
        $graph->invoke(['results' => []], $config);
        self::assertSame(['sub_a', ['aTrail' => ['a', 'a']]], $first($observed, 'sub_a'));
        self::assertSame(['sub_b', ['bTrail' => ['b', 'b']]], $first($observed, 'sub_b'));
    }

    public function testItLoadsSubgraphStateCorrectlyWhenTheSubgraphRunsInALoop(): void
    {
        $observed = [];
        $sub = (new StateGraph(self::trail('subTrail')))
            ->addNode('sub_step', static function (array $state) use (&$observed): array {
                $observed[] = ['sub_step', ['subTrail' => $state['subTrail']]];

                return ['subTrail' => ['s']];
            })
            ->addEdge(Constants::START, 'sub_step')
            ->compile(['checkpointer' => true]);

        $parentSchema = Annotation::root([
            'counter' => Annotation::last(static fn (): int => 0),
            'results' => Annotation::withReducer(self::concat(), static fn (): array => []),
        ]);
        $graph = (new StateGraph($parentSchema))
            ->addNode('inc', static fn (array $state): array => [
                'counter' => $state['counter'] + 1,
                'results' => ["inc:{$state['counter']}"],
            ])
            ->addNode('sub_node', $sub)
            // Upstream routes straight off `sub_node`. A conditional edge here receives the
            // node's own output, not the graph state (StateGraph does not wire the state reader
            // into branches yet), so a pass-through node carries `counter` to the router.
            ->addNode('check', static fn (array $state): array => ['counter' => $state['counter']])
            ->addEdge(Constants::START, 'inc')
            ->addEdge('inc', 'sub_node')
            ->addEdge('sub_node', 'check')
            ->addConditionalEdges('check', static fn (array $state): string => $state['counter'] < 2 ? 'inc' : Constants::END)
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::threadConfig('tt-loop-1');

        $graph->invoke(['counter' => 0, 'results' => []], $config);
        self::assertSame([['sub_step', ['subTrail' => []]], ['sub_step', ['subTrail' => ['s']]]], $observed);

        $observed = [];
        $graph->invoke(['counter' => 0, 'results' => []], $config);
        self::assertSame([['sub_step', ['subTrail' => ['s', 's']]], ['sub_step', ['subTrail' => ['s', 's', 's']]]], $observed);

        $history = $graph->getStateHistory($config);
        $startOfLoop2nd = null;
        $midLoop2nd = null;
        foreach ($history as $entry) {
            if (in_array('inc', $entry['next'], true) && ($entry['values']['counter'] ?? null) === 0 && $startOfLoop2nd === null) {
                $startOfLoop2nd = $entry;
            }
            if (in_array('inc', $entry['next'], true) && ($entry['values']['counter'] ?? null) === 1 && $midLoop2nd === null) {
                $midLoop2nd = $entry;
            }
        }
        self::assertNotNull($startOfLoop2nd);
        self::assertNotNull($midLoop2nd);

        $observed = [];
        $graph->invoke(null, self::configOf($startOfLoop2nd));
        self::assertSame([['sub_step', ['subTrail' => ['s', 's']]], ['sub_step', ['subTrail' => ['s', 's', 's']]]], $observed);

        $observed = [];
        $graph->invoke(null, self::configOf($midLoop2nd));
        self::assertSame([['sub_step', ['subTrail' => ['s', 's', 's']]]], $observed);
    }
}
