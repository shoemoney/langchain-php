<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Graph;

use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Prebuilt\ReactAgent;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langgraph-core/src/tests/diagrams.test.ts`.
 *
 * Differences from upstream: `xray` is an argument of `getGraph()` rather than a config field;
 * `StateSchema` and Zod have no PHP form, so the second case builds its state from an
 * `Annotation::root`.
 */
#[CoversClass(Pregel::class)]
final class DiagramsTest extends TestCase
{
    private const STYLES = "\tclassDef default fill:#f2f0ff,line-height:1.2;\n"
        . "\tclassDef first fill-opacity:0;\n"
        . "\tclassDef last fill:#bfb6fc;\n";

    private const HEADER = "%%{init: {'flowchart': {'curve': 'linear'}}}%%\ngraph TD;\n";

    public function testPrebuiltAgent(): void
    {
        $agent = ReactAgent::create([
            'llm' => ReactAgentFixtures::fake([]),
            'tools' => [ReactAgentFixtures::searchApi()],
        ]);

        $mermaid = $agent->getGraph()->drawMermaid();

        $this->assertSame(
            self::HEADER
            . "\t__start__([<p>__start__</p>]):::first\n"
            . "\ttools(tools)\n"
            . "\tagent(agent)\n"
            . "\t__end__([<p>__end__</p>]):::last\n"
            . "\t__start__ --> agent;\n"
            . "\ttools --> agent;\n"
            . "\tagent -.-> tools;\n"
            . "\tagent -.-> __end__;\n"
            . self::STYLES,
            $mermaid,
        );
    }

    public function testImplicitEndEdgeForTerminalNodes(): void
    {
        $graph = (new StateGraph(Annotation::root(['status' => Annotation::last()])))
            ->addNode(
                'riskyNode',
                static function (array $state): array {
                    if ($state['status'] === 'fail') {
                        throw new \RuntimeException('fail');
                    }

                    return ['status' => 'ok'];
                },
                [
                    'errorHandler' => static fn (): Command => new Command(update: ['status' => 'recovered'], goto: 'recover'),
                ],
            )
            ->addNode('recover', static fn (array $state): array => ['status' => $state['status'] . '_final'])
            ->addEdge(Constants::START, 'riskyNode')
            ->addEdge('recover', Constants::END)
            ->compile();

        $mermaid = $graph->getGraph()->drawMermaid();

        $this->assertStringContainsString('__start__ --> riskyNode;', $mermaid);
        $this->assertStringContainsString('riskyNode --> __end__;', $mermaid);
        $this->assertStringContainsString('recover --> __end__;', $mermaid);
        $this->assertStringNotContainsString('__error_handler__riskyNode --> __end__;', $mermaid);
    }

    public function testGraphWithMultipleSinks(): void
    {
        $app = (new StateGraph(Annotation::root([])))
            ->addNode('inner1', static fn (): array => [])
            ->addNode('inner2', static fn (): array => [])
            ->addNode('inner3', static fn (): array => [])
            ->addEdge('__start__', 'inner1')
            ->addConditionalEdges('inner1', static fn (): string => 'inner2', ['inner2', 'inner3'])
            ->compile();

        $this->assertSame(
            self::HEADER
            . "\t__start__([<p>__start__</p>]):::first\n"
            . "\tinner1(inner1)\n"
            . "\tinner2(inner2)\n"
            . "\tinner3(inner3)\n"
            . "\t__start__ --> inner1;\n"
            . "\tinner1 -.-> inner2;\n"
            . "\tinner1 -.-> inner3;\n"
            . self::STYLES,
            $app->getGraph()->drawMermaid(),
        );
    }

    public function testGraphWithSubgraphs(): void
    {
        $subgraph = (new StateGraph(Annotation::root([])))
            ->addNode('inner1', static fn (): array => [])
            ->addNode('inner2', static fn (): array => [])
            ->addNode('inner3', static fn (): array => [])
            ->addEdge('__start__', 'inner1')
            ->addConditionalEdges('inner1', static fn (): string => 'inner2', ['inner2', 'inner3'])
            ->compile();

        $app = (new StateGraph(Annotation::root([])))
            ->addNode('starter', static fn (): array => [])
            ->addNode('inner', $subgraph)
            ->addNode('final', static fn (): array => [])
            ->addEdge('__start__', 'starter')
            ->addConditionalEdges('starter', static fn (): string => 'final', ['inner', 'final'])
            ->compile(['interruptBefore' => ['starter']]);

        $mermaid = $app->getGraph(null, true)->drawMermaid();

        $this->assertSame(
            self::HEADER
            . "\t__start__([<p>__start__</p>]):::first\n"
            . "\tstarter(starter<hr/><small><em>__interrupt = before</em></small>)\n"
            . "\tinner_inner1(inner1)\n"
            . "\tinner_inner2(inner2)\n"
            . "\tinner_inner3(inner3)\n"
            . "\tfinal(final)\n"
            . "\t__start__ --> starter;\n"
            . "\tstarter -.-> inner_inner1;\n"
            . "\tstarter -.-> final;\n"
            . "\tsubgraph inner\n"
            . "\tinner_inner1 -.-> inner_inner2;\n"
            . "\tinner_inner1 -.-> inner_inner3;\n"
            . "\tend\n"
            . self::STYLES,
            $mermaid,
        );
    }
}
