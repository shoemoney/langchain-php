<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\ReactAgent;
use LangGraph\Pregel\Constants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/tests/graph.test.ts`: the shape of the graph `createAgent` builds for two middleware
 * with every interesting mix of hooks and `canJumpTo` lists, with and without a tool.
 *
 * Upstream snapshots the Mermaid drawing of each graph. The expectation here is that snapshot itself, parsed
 * into GraphSnapshots.json: the nodes, the plain edges and, for every conditional edge, the nodes it can reach.
 * Each case compares the agent's builder against the snapshot with the matching label.
 */
#[CoversClass(ReactAgent::class)]
final class GraphTest extends TestCase
{
    /**
     * Upstream's strategic matrix. Keys: aBeforeAgent, aAfterAgent, aBefore, aAfter and the same for b.
     * A missing key means the hook is not defined; an empty list means no jump is allowed.
     *
     * @return list<array<string, list<string>>>
     */
    private static function strategicCases(): array
    {
        return [
            // Basic cases - no jumps.
            [],
            ['aBefore' => [], 'aAfter' => [], 'bBefore' => [], 'bAfter' => []],
            // Single middleware patterns.
            ['aBefore' => ['tools']],
            ['aAfter' => ['tools']],
            ['aBefore' => ['model']],
            ['aAfter' => ['model']],
            ['aBefore' => ['end']],
            ['aAfter' => ['end']],
            // Interaction patterns - both middleware active.
            ['aBefore' => ['tools'], 'bBefore' => ['model']],
            ['aBefore' => ['model'], 'bBefore' => ['tools']],
            ['aAfter' => ['tools'], 'bAfter' => ['model']],
            ['aAfter' => ['model'], 'bAfter' => ['tools']],
            // Sequential dependency patterns.
            ['aBefore' => ['tools'], 'aAfter' => ['model']],
            ['bBefore' => ['tools'], 'bAfter' => ['model']],
            ['aBefore' => ['model'], 'aAfter' => ['tools']],
            // Termination patterns.
            ['aBefore' => ['end'], 'bBefore' => ['tools']],
            ['aBefore' => ['tools'], 'aAfter' => ['end'], 'bBefore' => ['model']],
            ['aAfter' => ['end'], 'bAfter' => ['tools']],
            // Multiple option patterns.
            ['aBefore' => ['tools', 'model']],
            ['aAfter' => ['tools', 'model']],
            ['aBefore' => ['tools', 'end'], 'bBefore' => ['model']],
            ['aBefore' => ['model', 'end'], 'bBefore' => ['tools']],
            // Complex interaction patterns.
            ['aBefore' => ['tools', 'model'], 'aAfter' => ['end'], 'bBefore' => ['tools'], 'bAfter' => ['model']],
            ['aBefore' => ['tools'], 'aAfter' => ['model', 'end'], 'bBefore' => ['model'], 'bAfter' => ['tools']],
            ['aBefore' => ['tools', 'model', 'end'], 'bAfter' => ['tools', 'model', 'end']],
            // Edge cases - conflicting or unusual patterns.
            ['aBefore' => ['end'], 'aAfter' => ['tools'], 'bBefore' => ['end'], 'bAfter' => ['model']],
            [
                'aBefore' => ['tools', 'model', 'end'], 'aAfter' => ['tools', 'model', 'end'],
                'bBefore' => ['tools', 'model', 'end'], 'bAfter' => ['tools', 'model', 'end'],
            ],
            // Agent-level hooks - beforeAgent and afterAgent.
            ['aBeforeAgent' => ['tools']],
            ['aAfterAgent' => ['model']],
            ['aBeforeAgent' => ['tools'], 'aAfterAgent' => ['end'], 'aBefore' => ['model'], 'aAfter' => ['tools']],
            ['aBeforeAgent' => ['tools'], 'bBeforeAgent' => ['model']],
            ['aAfterAgent' => ['tools'], 'bAfterAgent' => ['model']],
            [
                'aBeforeAgent' => ['tools', 'model', 'end'], 'aAfterAgent' => ['tools', 'model', 'end'],
                'aBefore' => ['tools'], 'aAfter' => ['model'],
            ],
        ];
    }

    /** @return array<string, array{0: array<string, list<string>>, 1: bool}> */
    public static function matrix(): array
    {
        $rows = [];
        foreach ([false, true] as $hasTool) {
            foreach (self::strategicCases() as $index => $case) {
                $label = json_encode($case) ?: '{}';
                $rows[sprintf('case %02d tools=%s %s', $index, $hasTool ? 'yes' : 'no', $label)] = [$case, $hasTool];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, list<string>> $case
     */
    #[DataProvider('matrix')]
    public function testShouldCreateCorrectGraphStructure(array $case, bool $hasTool): void
    {
        $middlewareList = [];
        // [hook => [node name, allowed]] per kind, in middleware order.
        $kinds = ['beforeAgent' => [], 'beforeModel' => [], 'afterModel' => [], 'afterAgent' => []];
        $keyToHook = ['BeforeAgent' => 'beforeAgent', 'AfterAgent' => 'afterAgent', 'Before' => 'beforeModel', 'After' => 'afterModel'];
        $suffix = ['beforeAgent' => 'before_agent', 'beforeModel' => 'before_model', 'afterModel' => 'after_model', 'afterAgent' => 'after_agent'];

        foreach (['a' => 'MiddlewareA', 'b' => 'MiddlewareB'] as $prefix => $name) {
            $config = ['name' => $name];
            foreach ($keyToHook as $key => $hook) {
                if (isset($case[$prefix . $key])) {
                    $config[$hook] = ['hook' => static function (): void {
                    }, 'canJumpTo' => $case[$prefix . $key]];
                    $kinds[$hook][] = [$name . '.' . $suffix[$hook], $case[$prefix . $key]];
                }
            }
            $middlewareList[] = Middleware::create($config);
        }

        $agent = Agent::create([
            'model' => 'openai:gpt-4o-mini',
            'tools' => $hasTool ? [AgentAssertions::noArgTool('someTool', static fn (): string => 'Hello, world!')] : [],
            'middleware' => $middlewareList,
        ]);

        $expected = self::snapshot($case, $hasTool);

        // What the builder holds.
        $builder = $agent->builder;
        $actualNodes = array_keys($builder->nodes);
        $actualEdges = array_map(static fn (array $edge): array => [$edge[0], $edge[1]], $builder->edges);
        $actualConditional = [];
        foreach ($builder->branches as $source => $branches) {
            self::assertCount(1, $branches, $source . ' has exactly one conditional edge');
            $branch = array_values($branches)[0];
            $actualConditional[$source] = array_values(array_unique(array_values($branch->ends ?? [])));
        }

        $normalise = static fn (string $name): string => str_replace('.', '_', $name);
        $edges = array_map(static fn (array $edge): array => [$normalise($edge[0]), $normalise($edge[1])], $actualEdges);
        $conditional = [];
        foreach ($actualConditional as $source => $destinations) {
            $conditional[$normalise($source)] = array_map($normalise, $destinations);
        }

        self::assertEqualsCanonicalizing($expected['nodes'], array_map($normalise, $actualNodes));
        self::assertEqualsCanonicalizing($expected['edges'], $edges);
        self::assertEqualsCanonicalizing(array_keys($expected['conditional']), array_keys($conditional));
        foreach ($expected['conditional'] as $source => $destinations) {
            self::assertEqualsCanonicalizing($destinations, $conditional[$source], 'destinations of ' . $source);
        }
    }

    /**
     * Upstream's snapshot for a case, from the parsed Mermaid in GraphSnapshots.json (nodes, `-->` edges and
     * `-.->` conditional destinations; `.` already `_`, START/END as `__start__`/`__end__`).
     *
     * @param array<string, list<string>> $case
     * @return array{nodes: list<string>, edges: list<array{0: string, 1: string}>, conditional: array<string, list<string>>}
     */
    private static function snapshot(array $case, bool $hasTool): array
    {
        static $snapshots = null;
        $snapshots ??= json_decode((string) file_get_contents(__DIR__ . '/GraphSnapshots.json'), true, 512, JSON_THROW_ON_ERROR);

        $part = static function (string $side) use ($case): string {
            $out = [];
            foreach (['BeforeAgent' => 'beforeAgent', 'AfterAgent' => 'afterAgent', 'Before' => 'before', 'After' => 'after'] as $key => $title) {
                $value = $case[strtolower($side) . $key] ?? null;
                $rendered = $value === null ? 'undefined' : ($value === [] ? '[]' : "[ '" . implode("', '", $value) . "' ]");
                $out[] = $side . ' ' . $title . ': ' . $rendered;
            }

            return implode(', ', $out);
        };
        $label = $part('A') . ' | ' . $part('B') . ' | tools: ' . ($hasTool ? 'true' : 'false');
        self::assertArrayHasKey($label, $snapshots, 'upstream snapshot exists');

        $snapshot = $snapshots[$label];
        $drop = static fn (array $nodes): array => array_values(array_filter($nodes, static fn (string $node): bool => $node !== Constants::START && $node !== Constants::END));
        $snapshot['nodes'] = $drop($snapshot['nodes']);

        return $snapshot;
    }
}
