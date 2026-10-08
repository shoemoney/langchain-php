<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Graph;

use LangGraph\Graph\Graph;
use LangGraph\Pregel\Constants;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langgraph-core/src/tests/graph.test.ts` (describe "State", 8 of 9 its) plus the
 * `Graph` behaviours of `graph.ts` that the port's `StateGraph` now inherits.
 *
 * Not ported: "should support addSequence" — `addSequence` is WP-05 (`state.ts`), not part of the
 * `Graph` base.
 */
#[CoversClass(Graph::class)]
#[CoversClass(StateGraph::class)]
final class GraphTest extends TestCase
{
    private static function appendMessages(): \LangGraph\Channels\BaseChannel
    {
        return Annotation::withReducer(
            static fn (array $left, array $right): array => [...$left, ...$right],
            static fn (): array => [],
        );
    }

    public function testShouldValidateANewNodeKeyCorrectly(): void
    {
        $stateGraph = new StateGraph(['existingStateAttributeKey' => null]);

        try {
            $stateGraph->addNode('existingStateAttributeKey', static fn ($_) => []);
            $this->fail('A node named like a state key must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('existingStateAttributeKey', $e->getMessage());
        }

        $stateGraph->addNode('newNodeKey', static fn ($_) => []);
        $this->assertArrayHasKey('newNodeKey', $stateGraph->nodes);
    }

    public function testShouldAllowReducersWithDifferentArgumentTypes(): void
    {
        $reducer = static function (array $left, string|array $right): array {
            if (is_string($right)) {
                return $right !== '' ? [...$left, $right] : $left;
            }

            return $right !== [] ? [...$left, ...$right] : $left;
        };

        $graph = (new StateGraph([
            'val' => Annotation::last(),
            'testval' => Annotation::withReducer($reducer, static fn (): array => []),
        ]))
            ->addNode('testnode', static fn (array $state): array => ['testval' => 'hi!', 'val' => 3])
            ->addEdge(Constants::START, 'testnode')
            ->addEdge('testnode', Constants::END)
            ->compile();

        $this->assertEquals(['testval' => ['hello', 'hi!'], 'val' => 3], $graph->invoke(['testval' => ['hello']]));
    }

    public function testShouldAllowReducersWithDifferentArgumentTypesOnAChannelSpec(): void
    {
        $graph = (new StateGraph([
            'testval' => ['reducer' => static fn (array $left, string|array $right): array => [...$left, ...(array) $right]],
        ]))
            ->addNode('testnode', static fn (): array => ['testval' => 'hi!'])
            ->addEdge(Constants::START, 'testnode')
            ->addEdge('testnode', Constants::END)
            ->compile();

        $this->assertEquals(['testval' => ['hello', 'hi!']], $graph->invoke(['testval' => ['hello']]));
    }

    public function testShouldAddMultipleNodesUsingArraySyntax(): void
    {
        $graph = (new StateGraph(['messages' => self::appendMessages()]))
            ->addNode([
                ['node1', static fn (): array => ['messages' => ['from node1']]],
                ['node2', static fn (): array => ['messages' => ['from node2']]],
                ['node3', static fn (): array => ['messages' => ['from node3']]],
            ])
            ->addEdge(Constants::START, 'node1')
            ->addEdge('node1', 'node2')
            ->addEdge('node2', 'node3')
            ->addEdge('node3', Constants::END)
            ->compile();

        $this->assertSame(['from node1', 'from node2', 'from node3'], $graph->invoke(['messages' => []])['messages']);
    }

    public function testShouldAddMultipleNodesUsingRecordSyntax(): void
    {
        $graph = (new StateGraph(['messages' => self::appendMessages()]))
            ->addNode([
                'node1' => static fn (): array => ['messages' => ['from node1']],
                'node2' => static fn (): array => ['messages' => ['from node2']],
                'node3' => static fn (): array => ['messages' => ['from node3']],
            ])
            ->addEdge(Constants::START, 'node1')
            ->addEdge('node1', 'node2')
            ->addEdge('node2', 'node3')
            ->addEdge('node3', Constants::END)
            ->compile();

        $this->assertSame(['from node1', 'from node2', 'from node3'], $graph->invoke(['messages' => []])['messages']);
    }

    public function testShouldThrowWhenAddingDuplicateNodesInParallel(): void
    {
        $stateGraph = new StateGraph(['messages' => self::appendMessages()]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Node `duplicate` already present.');

        $stateGraph->addNode([
            ['duplicate', static fn (): array => ['messages' => ['from node1']]],
            ['duplicate', static fn (): array => ['messages' => ['from node2']]],
        ]);
    }

    public function testShouldThrowWhenAddingAnEmptyNodeList(): void
    {
        $stateGraph = new StateGraph(['messages' => self::appendMessages()]);

        // Upstream checks `[]` and `{}`; both are the empty PHP array.
        try {
            $stateGraph->addNode([]);
            $this->fail('An empty node list must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('No nodes provided in `addNode`', $e->getMessage());
        }
    }

    public function testShouldSupportMetadataAndSubgraphsInParallelNodeAddition(): void
    {
        $subgraph = (new StateGraph(['messages' => null]))
            ->addNode('subnode', static fn (): array => ['messages' => ['from subgraph']])
            ->addEdge(Constants::START, 'subnode')
            ->addEdge('subnode', Constants::END)
            ->compile();

        $stateGraph = (new StateGraph(['messages' => self::appendMessages()]))
            ->addNode([
                ['node1', static fn (): array => ['messages' => ['from node1']], ['metadata' => ['description' => 'node1'], 'subgraphs' => [$subgraph]]],
                ['node2', static fn (): array => ['messages' => ['from node2']], ['metadata' => ['description' => 'node2']]],
            ]);

        $this->assertSame(['description' => 'node1'], $stateGraph->nodes['node1']->metadata);
        $this->assertSame([$subgraph], $stateGraph->nodes['node1']->subgraphs);
        $this->assertSame([], $stateGraph->nodes['node2']->subgraphs);

        $graph = $stateGraph
            ->addEdge(Constants::START, 'node1')
            ->addEdge('node1', 'node2')
            ->addEdge('node2', Constants::END)
            ->compile();

        $this->assertSame(['from node1', 'from node2'], $graph->invoke(['messages' => []])['messages']);
    }

    // ---- Graph itself ------------------------------------------------------------------------

    public function testStateGraphIsAGraph(): void
    {
        $this->assertInstanceOf(Graph::class, new StateGraph(['x' => null]));
    }

    public function testAPlainGraphRunsALinearChain(): void
    {
        $graph = (new Graph())
            ->addNode('double', static fn (int $n): int => $n * 2)
            ->addNode('inc', static fn (int $n): int => $n + 1)
            ->addEdge(Constants::START, 'double')
            ->addEdge('double', 'inc')
            ->addEdge('inc', Constants::END)
            ->compile();

        $this->assertSame(11, $graph->invoke(5));
    }

    public function testAPlainGraphRefusesASecondOutgoingEdge(): void
    {
        $graph = (new Graph())
            ->addNode('a', static fn ($x) => $x)
            ->addNode('b', static fn ($x) => $x)
            ->addEdge(Constants::START, 'a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Already found path for __start__. For multiple edges, use StateGraph.');

        $graph->addEdge(Constants::START, 'b');
    }

    public function testEndCannotStartAnEdgeAndStartCannotEndOne(): void
    {
        $graph = (new Graph())->addNode('a', static fn ($x) => $x);

        foreach ([[Constants::END, 'a', 'END cannot be a start node'], ['a', Constants::START, 'START cannot be an end node']] as [$from, $to, $message]) {
            try {
                $graph->addEdge($from, $to);
                $this->fail($message);
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function testNodeNamesAreValidated(): void
    {
        $graph = new Graph();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Node `__end__` is reserved.');

        $graph->addNode(Constants::END, static fn ($x) => $x);
    }

    public function testCompileRejectsAnEdgeFromAnUnknownNode(): void
    {
        $graph = (new Graph())->addNode('a', static fn ($x) => $x)->addEdge('ghost', 'a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Found edge starting at unknown node `ghost`');

        $graph->compile();
    }

    public function testCompileRejectsAnUnreachableNode(): void
    {
        $graph = (new Graph())
            ->addNode('a', static fn ($x) => $x)
            ->addNode('orphan', static fn ($x) => $x)
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', Constants::END);

        try {
            $graph->compile();
            $this->fail('An unreachable node must be rejected.');
        } catch (\LangGraph\Errors\GraphValueError $e) {
            $this->assertStringContainsString('Node `orphan` is not reachable.', $e->getMessage());
            $this->assertSame('UNREACHABLE_NODE', $e->getLcErrorCode());
        }
    }

    public function testCompileRejectsAnUnknownInterruptNode(): void
    {
        $graph = (new Graph())
            ->addNode('a', static fn ($x) => $x)
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', Constants::END);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Interrupt node `nope` is not present');

        $graph->compile(['interruptBefore' => ['nope']]);
    }

    public function testCompilingMarksTheBuilderCompiledAndLaterEditsAreRecorded(): void
    {
        $graph = (new Graph())->addNode('a', static fn ($x) => $x)->addEdge(Constants::START, 'a')->addEdge('a', Constants::END);
        $this->assertFalse($graph->compiled);

        $graph->compile();
        $this->assertTrue($graph->compiled);

        $before = count(\LangChain\Utils\Notice::notices());
        $graph->addNode('late', static fn ($x) => $x);
        $this->assertCount($before + 1, \LangChain\Utils\Notice::notices());
    }

    public function testSetEntryPointAndSetFinishPointAreEdgeShorthands(): void
    {
        $graph = (new Graph())
            ->addNode('a', static fn (string $s): string => $s . '!')
            ->setEntryPoint('a')
            ->setFinishPoint('a');

        $this->assertSame([[Constants::START, 'a'], ['a', Constants::END]], $graph->allEdges());
        $this->assertSame('hi!', $graph->compile()->invoke('hi'));
    }
}
