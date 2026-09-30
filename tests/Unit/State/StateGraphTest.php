<?php

declare(strict_types=1);

namespace LangGraph\Tests\Unit\State;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Channels\BinaryOperatorAggregate;
use LangGraph\Channels\EphemeralValue;
use LangGraph\Channels\LastValue;
use LangGraph\Errors\InvalidUpdateError;
use LangGraph\Pregel\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\CompiledStateGraph;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Send;
use LangGraph\State\Annotation;
use LangGraph\State\AnnotationRoot;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The state-graph builder: schema declaration, wiring, and the validation that
 * happens at compile time rather than at run time.
 */
#[CoversClass(StateGraph::class)]
#[CoversClass(Annotation::class)]
#[CoversClass(AnnotationRoot::class)]
#[CoversClass(CompiledStateGraph::class)]
final class StateGraphTest extends TestCase
{
    private static function listSchema(): AnnotationRoot
    {
        return Annotation::root([
            'items' => Annotation::withReducer(
                static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
                static fn (): array => [],
            ),
        ]);
    }

    // ---- Annotation ------------------------------------------------------

    public function testAnnotationWithNoReducerMakesALastValueChannel(): void
    {
        $channel = Annotation::last();

        $this->assertInstanceOf(LastValue::class, $channel);
        $this->assertSame('LastValue', $channel->lcGraphName);
    }

    public function testAnnotationWithAReducerMakesAnAggregateChannel(): void
    {
        $channel = Annotation::withReducer(
            static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
            static fn (): array => [],
        );

        $this->assertInstanceOf(BinaryOperatorAggregate::class, $channel);
        $this->assertTrue($channel->update([['a']]));
        $this->assertTrue($channel->update([['b']]));
        $this->assertSame(['a', 'b'], $channel->get());
    }

    public function testAnnotationAcceptsTheLegacyValueKey(): void
    {
        $channel = Annotation::fromSpec([
            'value' => static fn ($a, $b) => $b,
            'default' => static fn (): int => 0,
        ]);

        $this->assertInstanceOf(BinaryOperatorAggregate::class, $channel);
    }

    public function testAnnotationRootBuildsOneChannelPerKey(): void
    {
        $root = Annotation::root([
            'a' => Annotation::last(),
            'b' => Annotation::withReducer(static fn ($x, $y) => $y),
        ]);

        $this->assertSame(['a', 'b'], $root->keys());
        $this->assertInstanceOf(LastValue::class, $root->spec['a']);
        $this->assertInstanceOf(BinaryOperatorAggregate::class, $root->spec['b']);
    }

    public function testAnnotationRootIsDetectedStructurally(): void
    {
        $this->assertTrue(AnnotationRoot::isInstance(Annotation::root(['a' => Annotation::last()])));
        $this->assertFalse(AnnotationRoot::isInstance(['a' => 1]));
        $this->assertFalse(AnnotationRoot::isInstance(null));
    }

    // ---- build-time validation -------------------------------------------

    public function testANodeNameCollidingWithAStateKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already being used as a state attribute');

        (new StateGraph(self::listSchema()))
            ->addNode('items', static fn (array $s): array => ['items' => []])
            ->compile();
    }

    public function testADuplicateNodeNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already present');

        (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => []])
            ->addNode('a', static fn (array $s): array => ['items' => []])
            ->compile();
    }

    public function testAReservedNodeNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is reserved');

        (new StateGraph(self::listSchema()))
            ->addNode(Constants::START, static fn (array $s): array => ['items' => []])
            ->compile();
    }

    public function testANodeNameContainingANamespaceSeparatorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is a reserved character');

        (new StateGraph(self::listSchema()))
            ->addNode('a|b', static fn (array $s): array => ['items' => []])
            ->compile();
    }

    public function testAnEdgeToAnUnknownNodeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`ghost` not found');

        (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => []])
            ->addEdge('a', 'ghost')
            ->compile();
    }

    public function testAnEdgeFromStartDirectlyToEndIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot have an edge from START to END');

        (new StateGraph(self::listSchema()))
            ->addEdge(Constants::START, Constants::END);
    }

    // ---- wiring ----------------------------------------------------------

    public function testEveryNodeGetsARoutingChannel(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => []])
            ->addNode('b', static fn (array $s): array => ['items' => []])
            ->addEdge(Constants::START, 'a')
            ->compile();

        // Routing is a write to a channel named after the destination, and the
        // destination subscribes to exactly that channel. One mechanism for
        // every way of reaching a node.
        $this->assertArrayHasKey('branch:to:a', $graph->channels);
        $this->assertArrayHasKey('branch:to:b', $graph->channels);
        $this->assertSame(['branch:to:a'], $graph->nodes['a']->triggers);
        $this->assertSame(['a'], $graph->triggerToNodes['branch:to:a']);
    }

    public function testEachNodeRoutingChannelIsAnUnguardedEphemeralValue(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => []])
            ->addEdge(Constants::START, 'a')
            ->compile();

        $this->assertInstanceOf(EphemeralValue::class, $graph->channels['branch:to:a']);

        // Unguarded, because two `Send`s to the same node in one superstep is
        // the map-reduce pattern. The guard exists to catch ambiguous *state*
        // writes, and a routing channel holds no state. Proved by writing
        // twice in one step, which a guarded channel would reject.
        $this->assertTrue($graph->channels['branch:to:a']->update(['a']));
        $this->assertTrue($graph->channels['branch:to:a']->update(['b']));
    }

    public function testTheGraphRegistersTheTasksChannel(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => []])
            ->addEdge(Constants::START, 'a')
            ->compile();

        // `Send` needs a queue to write into, and every graph gets one.
        $this->assertArrayHasKey(Constants::TASKS, $graph->channels);
    }

    public function testANodeSubscribesToEveryStateKey(): void
    {
        $graph = (new StateGraph(Annotation::root([
            'a' => Annotation::last(),
            'b' => Annotation::last(),
        ])))
            ->addNode('worker', static fn (array $s): array => ['a' => 1, 'b' => 2])
            ->addEdge(Constants::START, 'worker')
            ->compile();

        // A map, not a list: the shape is what lets the engine tell a trigger
        // channel (empty => cannot run) from an ordinary one (empty => absent).
        $this->assertSame(['a' => 'a', 'b' => 'b'], $graph->nodes['worker']->channels);
    }

    public function testSetEntryPointAndFinishPoint(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addNode('b', static fn (array $s): array => ['items' => ['b']])
            ->setEntryPoint('a')
            ->addEdge('a', 'b')
            ->setFinishPoint('b')
            ->compile();

        $this->assertSame(['items' => ['seed', 'a', 'b']], $graph->invoke(['items' => ['seed']]));
    }

    public function testFanOutFromASingleEntryNode(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('left', static fn (array $s): array => ['items' => ['L']])
            ->addNode('right', static fn (array $s): array => ['items' => ['R']])
            ->addNode('join', static fn (array $s): array => ['items' => ['J']])
            ->addEdge(Constants::START, 'left')
            ->addEdge(Constants::START, 'right')
            ->addEdge('left', 'join')
            ->addEdge('right', 'join')
            ->compile();

        $result = $graph->invoke(['items' => ['seed']]);

        sort($result['items']);
        $this->assertSame(['J', 'L', 'R', 'seed'], $result['items']);
    }

    // ---- node return shapes ----------------------------------------------

    public function testANodeReturningACommandUpdatesState(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s) => new Command(update: ['items' => ['from-command']]))
            ->addEdge(Constants::START, 'a')
            ->compile();

        $this->assertSame(
            ['items' => ['from-command']],
            $graph->invoke(['items' => []]),
        );
    }

    public function testACommandUpdateToAnUnknownChannelIsDropped(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s) => new Command(update: ['nonsense' => 1]))
            ->addEdge(Constants::START, 'a')
            ->compile();

        // The schema is the state. A `Command` does not widen it.
        $this->assertSame(['items' => []], $graph->invoke(['items' => []]));
    }

    public function testANodeReturningNullProducesNoWrite(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('noop', static fn (array $s) => null)
            ->addEdge(Constants::START, 'noop')
            ->compile();

        $this->assertSame(['items' => ['seed']], $graph->invoke(['items' => ['seed']]));
    }

    public function testANodeReturningAnEmptyArrayProducesNoWrite(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('noop', static fn (array $s): array => [])
            ->addEdge(Constants::START, 'noop')
            ->compile();

        $this->assertSame(['items' => ['seed']], $graph->invoke(['items' => ['seed']]));
    }

    public function testANodeReturningAListContainingCommandsFoldsThem(): void
    {
        // The fan-in shape: several tasks return independently and the graph
        // merges them into one write-back.
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => [
                new Command(update: ['items' => ['x']]),
                new Command(update: ['items' => ['y']]),
            ])
            ->addEdge(Constants::START, 'a')
            ->compile();

        $result = $graph->invoke(['items' => []]);

        sort($result['items']);
        $this->assertSame(['x', 'y'], $result['items']);
    }

    public function testANodeReturningAScalarIsRejected(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('bad', static fn (array $s): int => 42)
            ->addEdge(Constants::START, 'bad')
            ->compile();

        try {
            $graph->invoke(['items' => []]);
            $this->fail('expected a scalar return to be rejected');
        } catch (InvalidUpdateError $e) {
            $this->assertStringContainsString('Expected node "bad"', $e->getMessage());
            $this->assertSame(
                'INVALID_GRAPH_NODE_RETURN_VALUE',
                $e->getLcErrorCode(),
                'the error code is what a caller branches on',
            );
        }
    }

    // ---- output ----------------------------------------------------------

    public function testOutputIsTheStateSchema(): void
    {
        $graph = (new StateGraph(Annotation::root([
            'a' => Annotation::last(static fn (): int => 0),
            'b' => Annotation::last(static fn (): string => ''),
        ])))
            ->addNode('n', static fn (array $s): array => ['a' => 1, 'b' => 'x'])
            ->addEdge(Constants::START, 'n')
            ->compile();

        $this->assertSame(['a' => 1, 'b' => 'x'], $graph->invoke([]));
    }

    public function testAGraphWithNoNodesCompilesAndReturnsEmptyState(): void
    {
        $graph = (new StateGraph(self::listSchema()))->compile();

        $this->assertSame(['items' => []], $graph->invoke(['items' => []]));
    }

    // ---- nested graphs ---------------------------------------------------

    public function testACompiledGraphRunsAsANodeInsideAnother(): void
    {
        $inner = (new StateGraph(self::listSchema()))
            ->addNode('double', static fn (array $s): array => [
                'items' => array_map(static fn (string $x): string => $x . $x, $s['items']),
            ])
            ->addEdge(Constants::START, 'double')
            ->compile();

        $outer = (new StateGraph(self::listSchema()))
            ->addNode('nested', $inner)
            ->addEdge(Constants::START, 'nested')
            ->compile();

        // A compiled graph is just a runnable, which is what makes composition
        // work without a separate concept.
        $result = $outer->invoke(['items' => ['a', 'b']]);

        // The inner graph consumes `a` and `b`, runs, and returns `aa`/`bb`.
        // The outer state channel keeps whatever the inner graph wrote plus
        // what the outer node wrote back — so the doubled values are present.
        $this->assertContains('aa', $result['items']);
        $this->assertContains('bb', $result['items']);
    }

    public function testCheckpointerTrueGivesAMemorySaver(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addEdge(Constants::START, 'a')
            ->compile(['checkpointer' => true]);

        $this->assertInstanceOf(MemorySaver::class, $graph->checkpointer);
    }

    public function testCheckpointerFalseMeansNoSaver(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addEdge(Constants::START, 'a')
            ->compile(['checkpointer' => false]);

        $this->assertNull($graph->checkpointer);
    }

    /**
     * START is a legal source for conditional edges, exactly as it is for addEdge().
     *
     * The guard consulted `$this->nodes` alone, so START — which is a constant,
     * not a node — was rejected while `addEdge(START, ...)` was accepted. Measured
     * before the fix:
     *
     *     addConditionalEdges('a')          -> OK
     *     addConditionalEdges('__start__')  -> InvalidArgumentException: Node `__start__` not found
     *     addEdge(START)                    -> OK
     *
     * Upstream exercises the rejected pattern in its own fixtures —
     * `addConditionalEdges(START, fanOut, ["review"])` in langgraph-js'
     * multi-interrupt-graph.ts:48 and mock-server.ts:540 — so this was not a
     * stricter-than-upstream choice, it was a call pattern upstream supports and
     * this port refused.
     *
     * The third assertion is the one that keeps the fix honest: an UNKNOWN node
     * must still throw, so the guard cannot be made to pass by being dropped.
     */
    public function testConditionalEdgesAcceptStartAsASource(): void
    {
        $graph = new StateGraph(['value' => ['type' => 'string']]);
        $graph->addNode('a', static fn ($s) => $s);

        $graph->addConditionalEdges(Constants::START, static fn ($s) => 'a');
        $graph->addConditionalEdges('a', static fn ($s) => 'a');

        $this->addToAssertionCount(2);

        $this->expectException(\InvalidArgumentException::class);
        $graph->addConditionalEdges('nope', static fn ($s) => 'a');
    }

    /**
     * A duplicate condition name is refused, as upstream refuses it.
     *
     * Upstream (langgraph-core/src/graph/graph.ts:488-495) throws
     * `Condition \`${name}\` already present for node \`${source}\`` rather than
     * overwriting. The keyed-by-name storage was already faithful here; the GUARD
     * was missing, so two conditional edges from one node with no `pathMap` both
     * resolved to SELF and the second silently replaced the first — a branch a
     * caller registered and saw accepted simply disappearing, with nothing thrown.
     *
     * Two assertions because the guard is easy to over-apply: a duplicate must
     * throw, and DISTINCT names from the same source must both still register. A
     * guard that refused everything would pass the first.
     */
    public function testADuplicateConditionalEdgeNameIsRefused(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => []])
            ->addNode('b', static fn (array $s): array => ['items' => []]);
        $graph->addConditionalEdges('a', static fn ($s) => 'b');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already present for node `a`');
        $graph->addConditionalEdges('a', static fn ($s) => 'b');
    }

    public function testDistinctConditionalEdgeNamesFromOneNodeBothRegister(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => []])
            ->addNode('b', static fn (array $s): array => ['items' => []])
            ->addNode('c', static fn (array $s): array => ['items' => []]);
        $graph->addConditionalEdges('a', static fn ($s) => 'b', ['to_b' => 'b']);
        $graph->addConditionalEdges('a', static fn ($s) => 'c', ['to_c' => 'c']);

        $this->addToAssertionCount(1);
    }
}
