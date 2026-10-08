<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Messages\TracedNode;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\PregelNode;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/**
 * `Pregel::getSubgraphs()` / `findSubgraphPregel()` and the namespace delegation built on them.
 *
 * Upstream has no dedicated file for these (they are exercised through `pregel.test.ts` and the
 * time-travel suites); this file pins the discovery rules directly, including the risk the work
 * package called out: a graph wrapped in a `RunnableSequence` must still be found.
 */
#[CoversClass(Pregel::class)]
final class SubgraphsTest extends TestCase
{
    use TimeTravelFixtures;

    private static function leaf(string $node = 'leaf'): Pregel
    {
        return (new StateGraph(self::stateSchema()))
            ->addNode($node, static fn (): array => ['value' => [$node]])
            ->addEdge(Constants::START, $node)
            ->compile();
    }

    private static function wrapping(string $nodeName, Pregel|RunnableSequence $inner, ?MemorySaver $saver = null): Pregel
    {
        return (new StateGraph(self::stateSchema()))
            ->addNode($nodeName, $inner)
            ->addEdge(Constants::START, $nodeName)
            ->compile($saver === null ? [] : ['checkpointer' => $saver]);
    }

    /**
     * @return array<string, Pregel>
     */
    private static function collect(Pregel $graph, ?string $namespace = null, bool $recurse = false): array
    {
        $out = [];
        foreach ($graph->getSubgraphs($namespace, $recurse) as [$name, $subgraph]) {
            $out[$name] = $subgraph;
        }

        return $out;
    }

    public function testAGraphWithoutSubgraphsYieldsNothing(): void
    {
        self::assertSame([], self::collect(self::leaf()));
    }

    public function testADirectSubgraphIsYieldedByNodeName(): void
    {
        $leaf = self::leaf();
        $parent = self::wrapping('child', $leaf);

        self::assertSame(['child' => $leaf], self::collect($parent));
    }

    public function testRecursionNamesNestedGraphsWithTheNamespaceSeparator(): void
    {
        $leaf = self::leaf();
        $middle = self::wrapping('inner', $leaf);
        $outer = self::wrapping('mid', $middle);

        self::assertSame(['mid' => $middle], self::collect($outer));
        self::assertSame(
            ['mid' => $middle, 'mid|inner' => $leaf],
            self::collect($outer, null, true),
        );
    }

    public function testANamespaceSelectsTheMatchingNodeAndStopsAtAnExactMatch(): void
    {
        $leaf = self::leaf();
        $middle = self::wrapping('inner', $leaf);
        $outer = self::wrapping('mid', $middle);

        self::assertSame(['mid' => $middle], self::collect($outer, 'mid', true));
        self::assertSame(['mid|inner' => $leaf], self::collect($outer, 'mid|inner', true));
        self::assertSame([], self::collect($outer, 'unrelated', true));
    }

    public function testAGraphWrappedInARunnableSequenceIsStillFound(): void
    {
        $leaf = self::leaf();
        $sequence = RunnableSequence::from([
            new RunnableLambda(static fn (array $state): array => $state),
            $leaf,
        ]);
        $parent = self::wrapping('piped', $sequence);

        self::assertSame(['piped' => $leaf], self::collect($parent));
    }

    public function testASequenceNestedInASequenceIsSearchedBreadthFirst(): void
    {
        $leaf = self::leaf();
        $inner = RunnableSequence::from([new RunnableLambda(static fn (array $s): array => $s), $leaf]);
        $outer = RunnableSequence::from([new RunnableLambda(static fn (array $s): array => $s), $inner]);

        self::assertSame($leaf, Pregel::findSubgraphPregel($outer));
        self::assertSame($leaf, Pregel::findSubgraphPregel($leaf));
        self::assertSame($leaf, Pregel::findSubgraphPregel(new TracedNode($leaf)));
        self::assertNull(Pregel::findSubgraphPregel(new RunnableLambda(static fn (): int => 1)));
        self::assertNull(Pregel::findSubgraphPregel(null));
    }

    public function testRecursionReachesAGraphInsideASequence(): void
    {
        $leaf = self::leaf();
        $middle = self::wrapping('piped', RunnableSequence::from([new RunnableLambda(static fn (array $s): array => $s), $leaf]));
        $outer = self::wrapping('mid', $middle);

        self::assertSame(['mid' => $middle, 'mid|piped' => $leaf], self::collect($outer, null, true));
    }

    public function testSubgraphsDeclaredOnTheNodeAreHonoured(): void
    {
        // A node that calls a graph from inside its own body can say so; the declaration is what
        // makes it discoverable, because nothing can see through a plain function. Built by hand:
        // `StateGraph::compile()` does not copy a node's `subgraphs` onto the compiled node (a gap
        // in a file this work package does not own), so the declaration is only visible on a
        // `Pregel` assembled directly.
        $leaf = self::leaf();
        $parent = new Pregel(
            nodes: ['caller' => new PregelNode(
                bound: new RunnableLambda(static fn (): array => []),
                subgraphs: [$leaf],
            )],
        );

        self::assertSame(['caller' => $leaf], self::collect($parent));
    }

    // ---- namespace delegation -------------------------------------------------------------------

    /**
     * A parent whose child interrupts, paused so the child has a checkpoint of its own.
     */
    private static function pausedParent(): array
    {
        $child = (new StateGraph(self::stateSchema()))
            ->addNode('ask', static fn (): array => ['value' => ['got:' . interrupt('Q?')]])
            ->addEdge(Constants::START, 'ask')
            ->compile();
        $parent = self::wrapping('child', $child, new MemorySaver());
        $config = self::threadConfig('sub-1');
        $parent->invoke(['value' => []], $config);

        return [$parent, $config, $child];
    }

    public function testGetStateOfATaskNamespaceIsAnsweredByTheSubgraph(): void
    {
        [$parent, $config] = self::pausedParent();
        $taskId = $parent->getState($config)->tasks[0]->id;

        $childState = $parent->getState(new RunnableConfig(configurable: [
            'thread_id' => 'sub-1',
            'checkpoint_ns' => "child:{$taskId}",
        ]));

        self::assertSame(['ask'], $childState->next);
        self::assertSame("child:{$taskId}", $childState->config['configurable']['checkpoint_ns']);
        self::assertSame(['Q?'], array_map(static fn (array $i): mixed => $i['value'], $childState->tasks[0]->interrupts));
    }

    public function testGetStateHistoryOfATaskNamespaceIsTheSubgraphsHistory(): void
    {
        [$parent, $config] = self::pausedParent();
        $taskId = $parent->getState($config)->tasks[0]->id;

        $history = $parent->getStateHistory(new RunnableConfig(configurable: [
            'thread_id' => 'sub-1',
            'checkpoint_ns' => "child:{$taskId}",
        ]));

        self::assertSame([['ask'], ['__start__']], array_column($history, 'next'));
        foreach ($history as $entry) {
            self::assertSame("child:{$taskId}", $entry['config']['configurable']['checkpoint_ns']);
        }
    }

    public function testAnUnknownNamespaceFallsBackToReadingTheSaverDirectly(): void
    {
        [$parent] = self::pausedParent();

        // No static subgraph is called `dynamic`, as for a graph created inside a tool call: the
        // read goes to the saver with the full namespace and finds nothing, rather than failing.
        $state = $parent->getState(new RunnableConfig(configurable: [
            'thread_id' => 'sub-1',
            'checkpoint_ns' => 'dynamic:abc',
        ]));

        self::assertSame([], $state->values);
        self::assertSame([], $state->next);
    }

    public function testUpdateStateOfATaskNamespaceEditsTheSubgraph(): void
    {
        [$parent, $config] = self::pausedParent();
        $taskId = $parent->getState($config)->tasks[0]->id;
        $childConfig = new RunnableConfig(configurable: [
            'thread_id' => 'sub-1',
            'checkpoint_ns' => "child:{$taskId}",
        ]);

        $updated = $parent->updateState($childConfig, ['value' => ['edited']], Constants::START);

        self::assertSame(['edited'], $parent->getState($updated)->values['value']);
        self::assertSame("child:{$taskId}", $updated->configurable['checkpoint_ns']);
    }

    public function testUpdateStateOfAnUnknownSubgraphNamespaceNamesTheMissingGraph(): void
    {
        [$parent] = self::pausedParent();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Subgraph "ghost" not found');
        $parent->updateState(
            new RunnableConfig(configurable: ['thread_id' => 'sub-1', 'checkpoint_ns' => 'ghost:abc']),
            ['value' => ['x']],
            'whatever',
        );
    }

    public function testASubgraphCompiledWithCheckpointerTrueKeepsOnePersistentNamespace(): void
    {
        $child = (new StateGraph(self::stateSchema()))
            ->addNode('step', static fn (): array => ['value' => ['s']])
            ->addEdge(Constants::START, 'step')
            ->compile(['checkpointer' => true]);
        $parent = self::wrapping('child', $child, new MemorySaver());
        $config = self::threadConfig('persist');

        $parent->invoke(['value' => []], $config);
        $parent->invoke(['value' => []], $config);

        // Both runs of the child wrote under the SAME namespace (`child`, no task id), so its
        // history grows across the parent's runs instead of starting over each time.
        $history = $parent->getStateHistory(new RunnableConfig(configurable: ['thread_id' => 'persist', 'checkpoint_ns' => 'child']));
        self::assertGreaterThanOrEqual(4, count($history));
        foreach ($history as $entry) {
            self::assertSame('child', $entry['config']['configurable']['checkpoint_ns']);
        }
    }
}
