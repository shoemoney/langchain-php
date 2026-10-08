<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables\Graph;

use LangChain\Runnables\Graph\Edge;
use LangChain\Runnables\Graph\Graph;
use LangChain\Runnables\Graph\Mermaid;
use LangChain\Runnables\Graph\Node;
use LangChain\Runnables\Graph\RunnableIOSchema;
use LangChain\Utils\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Graph::class)]
#[CoversClass(Node::class)]
#[CoversClass(Edge::class)]
#[CoversClass(Mermaid::class)]
final class GraphTest extends TestCase
{
    public function testAnAnonymousNodeGetsAUuidIdAndItsRunnableName(): void
    {
        $graph = new Graph();
        $node = $graph->addNode(new NamedRunnable('RunnableLambda'));

        $this->assertTrue(Uuid::validate($node->id));
        $this->assertSame('Lambda', $node->name, 'the Runnable prefix is dropped');
        $this->assertSame($node, $graph->nodes[$node->id]);
    }

    public function testASchemaNodeWithoutANameIsUnknown(): void
    {
        $this->assertSame('UnknownSchema', (new Graph())->addNode(new RunnableIOSchema())->name);
    }

    public function testAddingADuplicateNodeIdThrows(): void
    {
        $graph = new Graph();
        $graph->addNode(new NamedRunnable('a'), 'a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Node with id a already exists');

        $graph->addNode(new NamedRunnable('a'), 'a');
    }

    public function testAnEdgeNeedsBothEndsInTheGraph(): void
    {
        $graph = new Graph();
        $inside = $graph->addNode(new NamedRunnable('a'), 'a');
        $outside = new Node('z', new NamedRunnable('z'), 'z');

        try {
            $graph->addEdge($outside, $inside);
            $this->fail('a source outside the graph must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Source node z not in graph', $e->getMessage());
        }

        $this->expectExceptionMessage('Target node z not in graph');
        $graph->addEdge($inside, $outside);
    }

    public function testRemovingANodeRemovesItsEdges(): void
    {
        $graph = new Graph();
        $a = $graph->addNode(new NamedRunnable('a'), 'a');
        $b = $graph->addNode(new NamedRunnable('b'), 'b');
        $c = $graph->addNode(new NamedRunnable('c'), 'c');
        $graph->addEdge($a, $b);
        $graph->addEdge($b, $c);

        $graph->removeNode($b);

        $this->assertSame(['a', 'c'], array_keys($graph->nodes));
        $this->assertSame([], $graph->edges);
    }

    public function testToJsonKeepsEdgeDataAndConditionalAndNumbersAnonymousNodes(): void
    {
        $graph = new Graph();
        $anon = $graph->addNode(new NamedRunnable('Anon'));
        $named = $graph->addNode(new RunnableIOSchema('Out', ['type' => 'string']), 'out');
        $graph->addEdge($anon, $named, 'label', false);

        $json = $graph->toJSON();

        $this->assertSame(0, $json['nodes'][0]['id']);
        $this->assertSame('out', $json['nodes'][1]['id']);
        $this->assertSame(
            ['$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'string', 'title' => 'Out'],
            $json['nodes'][1]['data'],
        );
        $this->assertSame([['source' => 0, 'target' => 'out', 'data' => 'label', 'conditional' => false]], $json['edges']);
    }

    public function testExtendAddsNodesAndEdgesUnderAPrefixAndReturnsTheEnds(): void
    {
        $inner = new Graph();
        $i = $inner->addNode(new NamedRunnable('i'), 'i');
        $j = $inner->addNode(new NamedRunnable('j'), 'j');
        $inner->addEdge($i, $j);

        $outer = new Graph();
        [$first, $last] = $outer->extend($inner, 'sub');

        $this->assertSame(['sub:i', 'sub:j'], array_keys($outer->nodes));
        $this->assertSame('sub:i', $outer->edges[0]->source);
        $this->assertSame('sub:i', $first?->id);
        $this->assertSame('sub:j', $last?->id);
        $this->assertSame(['i', 'j'], array_keys($inner->nodes), 'the extended graph is not touched');
        $this->assertSame('i', $inner->edges[0]->source);
    }

    public function testDrawMermaidReidsWithoutChangingTheGraph(): void
    {
        $graph = new Graph();
        $a = $graph->addNode(new NamedRunnable('Solo'));
        $b = $graph->addNode(new NamedRunnable('Other'));
        $graph->addEdge($a, $b);
        $before = array_keys($graph->nodes);

        $mermaid = $graph->drawMermaid();

        $this->assertStringContainsString("\tSolo --> Other;\n", $mermaid);
        $this->assertSame($before, array_keys($graph->nodes));
    }

    public function testBase64UrlMatchesBtoaIncludingItsLatin1Limit(): void
    {
        $this->assertSame('Z3JhcGggVEQ-Pz8', Mermaid::toBase64Url('graph TD>??'));
        $this->assertSame('_w', Mermaid::toBase64Url("\u{FF}"), 'U+00FF is one Latin-1 byte');

        $this->expectException(\InvalidArgumentException::class);
        Mermaid::toBase64Url("\u{100}");
    }
}
