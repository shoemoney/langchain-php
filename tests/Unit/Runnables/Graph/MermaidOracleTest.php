<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables\Graph;

use LangChain\Runnables\Graph\Graph;
use LangChain\Runnables\Graph\Mermaid;
use LangChain\Runnables\Graph\RunnableIOSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Byte-for-byte parity with the upstream TypeScript.
 *
 * `mermaid-oracle.json` holds each case's graph description and the text that upstream's own
 * `Graph.drawMermaid()` (`graph.ts` + `graph_mermaid.ts`, run under Node) produced for it, along with
 * the node ids, first/last node and edges left after any `reid`/`trim*`/`extend` operations. The PHP
 * port must reproduce every one exactly.
 */
#[CoversClass(Graph::class)]
#[CoversClass(Mermaid::class)]
final class MermaidOracleTest extends TestCase
{
    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function cases(): iterable
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/mermaid-oracle.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            yield $case['name'] => [$case];
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function build(array $spec): Graph
    {
        $graph = new Graph();
        $nodes = [];
        foreach ($spec['nodes'] as $n) {
            $kind = $n['kind'] ?? null;
            $data = match ($kind) {
                'runnable' => new NamedRunnable($n['rname']),
                'schema' => new RunnableIOSchema($n['sname'] ?? null),
                default => new NamedRunnable($n['id']),
            };
            $metadata = isset($n['metadata']) ? (array) $n['metadata'] : null;
            $nodes[$n['id']] = $graph->addNode($data, (string) $n['id'], $metadata);
        }
        foreach ($spec['edges'] as $e) {
            $graph->addEdge($nodes[$e[0]], $nodes[$e[1]], $e[2] ?? null, $e[3] ?? null);
        }

        return $graph;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testMatchesUpstreamByteForByte(array $case): void
    {
        $graph = self::build($case);
        foreach ($case['ops'] ?? [] as $op) {
            switch ($op[0]) {
                case 'reid':
                    $graph = $graph->reid();
                    break;
                case 'trimFirstNode':
                    $graph->trimFirstNode();
                    break;
                case 'trimLastNode':
                    $graph->trimLastNode();
                    break;
                case 'extend':
                    $result = $graph->extend(self::build($op[2]), $op[1]);
                    $this->assertSame(
                        $case['extendResult'],
                        array_map(static fn ($n): ?array => $n === null ? null : ['id' => $n->id], $result),
                    );
                    break;
            }
        }

        $this->assertSame($case['first'], $graph->firstNode()?->id, 'first node');
        $this->assertSame($case['last'], $graph->lastNode()?->id, 'last node');
        $this->assertSame($case['nodeIds'], array_map(strval(...), array_keys($graph->nodes)), 'node ids');
        $this->assertSame($case['nodeNames'], array_map(static fn ($n): string => $n->name, array_values($graph->nodes)), 'node names');
        $this->assertSame(
            $case['edgeList'],
            array_map(static fn ($e): array => [$e->source, $e->target], $graph->edges),
            'edges',
        );

        if ($case['direct'] ?? false) {
            $mermaid = Mermaid::drawMermaid($graph->nodes, $graph->edges, $case['directConfig']);
        } else {
            $p = $case['params'] ?? [];
            $mermaid = $graph->drawMermaid(
                withStyles: $p['withStyles'] ?? null,
                curveStyle: $p['curveStyle'] ?? null,
                nodeColors: $p['nodeColors'] ?? null,
                wrapLabelNWords: $p['wrapLabelNWords'] ?? null,
            );
        }
        $this->assertSame($case['mermaid'], $mermaid);
    }
}
