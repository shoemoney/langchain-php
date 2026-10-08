<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables\Graph;

use LangChain\OutputParsers\CommaSeparatedListOutputParser;
use LangChain\Prompts\PromptTemplate;
use LangChain\Runnables\Graph\Graph;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableLambda;
use LangChain\Utils\Testing\FakeLLM;
use LangGraph\Graph\Graph as GraphBuilder;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `Pregel::getGraph()` beyond the converted upstream cases: xray depth, the schema-less builder,
 * a bare Pregel, and drawing real, runnable chains and graphs end to end.
 */
#[CoversClass(Pregel::class)]
#[CoversClass(Runnable::class)]
final class PregelGetGraphTest extends TestCase
{
    private const STYLES = "\tclassDef default fill:#f2f0ff,line-height:1.2;\n"
        . "\tclassDef first fill-opacity:0;\n"
        . "\tclassDef last fill:#bfb6fc;\n";

    private static function leaf(): \LangGraph\Pregel\CompiledStateGraph
    {
        return (new StateGraph(Annotation::root([])))
            ->addNode('x', static fn (): array => [])
            ->addNode('y', static fn (): array => [])
            ->addEdge(Constants::START, 'x')
            ->addEdge('x', 'y')
            ->compile();
    }

    private static function middle(): \LangGraph\Pregel\CompiledStateGraph
    {
        return (new StateGraph(Annotation::root([])))
            ->addNode('leaf', self::leaf())
            ->addNode('m', static fn (): array => [])
            ->addEdge(Constants::START, 'leaf')
            ->addEdge('leaf', 'm')
            ->compile();
    }

    private static function outer(): \LangGraph\Pregel\CompiledStateGraph
    {
        return (new StateGraph(Annotation::root([])))
            ->addNode('mid', self::middle())
            ->addEdge(Constants::START, 'mid')
            ->compile();
    }

    public function testWithoutXrayASubgraphIsOneNode(): void
    {
        $mermaid = self::outer()->getGraph()->drawMermaid();

        $this->assertStringContainsString("\tmid(mid)\n", $mermaid);
        $this->assertStringNotContainsString('subgraph', $mermaid);
    }

    public function testXrayTrueExpandsEveryLevel(): void
    {
        $mermaid = self::outer()->getGraph(null, true)->drawMermaid();

        $this->assertStringContainsString("\tsubgraph mid\n", $mermaid);
        $this->assertStringContainsString("\tsubgraph leaf\n", $mermaid);
        $this->assertStringContainsString("\tmid_leaf_x --> mid_leaf_y;\n", $mermaid);
        $this->assertStringContainsString("\tmid_leaf_y --> mid_m;\n", $mermaid);
    }

    public function testAnIntegerXrayStopsAfterThatManyLevels(): void
    {
        $mermaid = self::outer()->getGraph(null, 1)->drawMermaid();

        $this->assertStringContainsString("\tsubgraph mid\n", $mermaid);
        $this->assertStringNotContainsString('subgraph leaf', $mermaid);
        $this->assertStringContainsString("\tmid_leaf(leaf)\n", $mermaid);
    }

    public function testASubgraphWithASingleNodeStaysOneNode(): void
    {
        $single = (new StateGraph(Annotation::root([])))
            ->addNode('only', static fn (): array => [])
            ->addEdge(Constants::START, 'only')
            ->compile();
        $app = (new StateGraph(Annotation::root([])))
            ->addNode('box', $single)
            ->addEdge(Constants::START, 'box')
            ->compile();

        $mermaid = $app->getGraph(null, true)->drawMermaid();

        $this->assertStringContainsString("\tbox(box)\n", $mermaid);
        $this->assertStringNotContainsString('subgraph', $mermaid);
    }

    public function testAPlainGraphDrawsItsEdgesAndCallsBothEndsInAndOut(): void
    {
        $compiled = (new GraphBuilder())
            ->addNode('a', static fn (string $s): string => $s . 'a')
            ->addNode('b', static fn (string $s): string => $s . 'b')
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->addEdge('b', Constants::END)
            ->compile();

        $this->assertSame(
            "%%{init: {'flowchart': {'curve': 'linear'}}}%%\ngraph TD;\n"
            . "\t__start__([<p>__start__</p>]):::first\n\ta(a)\n\tb(b)\n\t__end__([<p>__end__</p>]):::last\n"
            . "\t__start__ --> a;\n\ta --> b;\n\tb --> __end__;\n" . self::STYLES,
            $compiled->getGraph()->drawMermaid(),
        );
    }

    public function testAJoinEdgeDrawsOneEdgePerSource(): void
    {
        $app = (new StateGraph(Annotation::root([])))
            ->addNode('a', static fn (): array => [])
            ->addNode('b', static fn (): array => [])
            ->addNode('c', static fn (): array => [])
            ->addEdge(Constants::START, 'a')
            ->addEdge(Constants::START, 'b')
            ->addEdge(['a', 'b'], 'c')
            ->compile();

        $mermaid = $app->getGraph()->drawMermaid();

        $this->assertStringContainsString("\ta --> c;\n", $mermaid);
        $this->assertStringContainsString("\tb --> c;\n", $mermaid);
        $this->assertStringContainsString("\tc --> __end__;\n", $mermaid, 'the terminal node gets its implicit end edge');
    }

    public function testNodeEndsDrawAsConditionalEdges(): void
    {
        $app = (new StateGraph(Annotation::root([])))
            ->addNode('router', static fn (): array => [], ['ends' => ['left', 'right']])
            ->addNode('left', static fn (): array => [])
            ->addNode('right', static fn (): array => [])
            ->addEdge(Constants::START, 'router')
            ->compile();

        $mermaid = $app->getGraph()->drawMermaid();

        $this->assertStringContainsString("\trouter -.-> left;\n", $mermaid);
        $this->assertStringContainsString("\trouter -.-> right;\n", $mermaid);
    }

    public function testABranchPathMapLabelsItsEdges(): void
    {
        $app = (new StateGraph(Annotation::root([])))
            ->addNode('decide', static fn (): array => [])
            ->addNode('yes', static fn (): array => [])
            ->addNode('no', static fn (): array => [])
            ->addEdge(Constants::START, 'decide')
            ->addConditionalEdges('decide', static fn (): string => 'ok', ['ok' => 'yes', 'bad' => 'no'])
            ->compile();

        $mermaid = $app->getGraph()->drawMermaid();

        $this->assertStringContainsString("\tdecide -. &nbsp;ok&nbsp; .-> yes;\n", $mermaid);
        $this->assertStringContainsString("\tdecide -. &nbsp;bad&nbsp; .-> no;\n", $mermaid);
    }

    public function testInterruptBeforeAndAfterBothShowOnTheNode(): void
    {
        $app = (new StateGraph(Annotation::root([])))
            ->addNode('n', static fn (): array => [])
            ->addEdge(Constants::START, 'n')
            ->compile(['interruptBefore' => ['n'], 'interruptAfter' => ['n']]);

        $this->assertStringContainsString('__interrupt = before,after', $app->getGraph()->drawMermaid());
    }

    public function testANodeNamedLikeTheMermaidKeywordIsQuoted(): void
    {
        $app = (new StateGraph(Annotation::root([])))
            ->addNode('subgraph', static fn (): array => [])
            ->addEdge(Constants::START, 'subgraph')
            ->compile();

        $this->assertStringContainsString("\t_subgraph_(\"subgraph\")\n", $app->getGraph()->drawMermaid());
    }

    public function testABarePregelDrawsLikeAnyRunnable(): void
    {
        $pregel = new Pregel();

        $graph = $pregel->getGraph();

        $this->assertSame(['PregelInput', 'Pregel', 'PregelOutput'], array_map(static fn ($n): string => $n->name, array_values($graph->nodes)));
    }

    public function testASequenceOfRealStepsDrawsAndStillRuns(): void
    {
        $chain = PromptTemplate::fromTemplate('{x}')
            ->pipe(new FakeLLM(['response' => 'a,b']))
            ->pipe(new CommaSeparatedListOutputParser());

        $this->assertSame(['a', 'b'], $chain->invoke(['x' => 'go']));
        $this->assertStringContainsString("\tPromptTemplate --> FakeLLM;\n", $chain->getGraph()->drawMermaid());
    }

    public function testASequenceOfLambdasInlinesEachStepAndChainsThem(): void
    {
        $chain = RunnableLambda::from(static fn (int $n): int => $n + 1)
            ->pipe(RunnableLambda::from(static fn (int $n): int => $n * 2));

        $graph = $chain->getGraph();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertCount(4, $graph->nodes);
        $this->assertCount(3, $graph->edges);
        $this->assertSame(4, $chain->invoke(1));
    }

    public function testACompiledGraphRunsAndDrawsTheSameTopology(): void
    {
        $app = (new StateGraph(Annotation::root(['v' => Annotation::last()])))
            ->addNode('inc', static fn (array $s): array => ['v' => $s['v'] + 1])
            ->addNode('dbl', static fn (array $s): array => ['v' => $s['v'] * 2])
            ->addEdge(Constants::START, 'inc')
            ->addEdge('inc', 'dbl')
            ->addEdge('dbl', Constants::END)
            ->compile();

        $this->assertSame(['v' => 4], $app->invoke(['v' => 1]));

        $json = $app->getGraph()->toJSON();
        $this->assertSame(['__start__', 'inc', 'dbl', '__end__'], array_column($json['nodes'], 'id'));
        $this->assertSame(
            [['__start__', 'inc'], ['dbl', '__end__'], ['inc', 'dbl']],
            array_map(static fn (array $e): array => [$e['source'], $e['target']], $json['edges']),
            'edges sort by source, stably',
        );
    }
}
