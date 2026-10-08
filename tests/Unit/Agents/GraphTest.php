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
 * Upstream snapshots the Mermaid drawing of each graph (and writes a markdown matrix of them). This port has no
 * graph drawing yet (WP-06), so each case asserts the same information the drawing carries: the nodes, the
 * plain edges and, for every conditional edge, the set of nodes it can reach. The expectation is derived by
 * the rules below, written out per hook rather than by building a graph:
 *
 *  - the entry is the first beforeAgent node, else the first beforeModel node, else the model node; tools (and
 *    every beforeModel loop) come back to the first beforeModel node, else the model node;
 *  - the exit is the last afterAgent node, else END;
 *  - a hook without `canJumpTo` is a plain edge to the next node; with `canJumpTo` it is a conditional edge to
 *    the next node and the allowed targets (`tools` only if a tool exists);
 *  - `beforeAgent` and the model's own edges send `end` to the exit; `beforeModel` sends it to END;
 *  - afterModel and afterAgent run in reverse, and the first of each (the last to run) leaves the loop.
 */
#[CoversClass(ReactAgent::class)]
final class GraphTest extends TestCase
{
    private const MODEL = 'model_request';
    private const TOOLS = 'tools';

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

        // Expected nodes.
        $expectedNodes = [self::MODEL, ...($hasTool ? [self::TOOLS] : [])];
        foreach ($kinds as $list) {
            foreach ($list as [$node]) {
                $expectedNodes[] = $node;
            }
        }

        // Expected edges.
        $names = static fn (array $list): array => array_map(static fn (array $entry): string => $entry[0], $list);
        $beforeAgent = $kinds['beforeAgent'];
        $beforeModel = $kinds['beforeModel'];
        $afterModel = $kinds['afterModel'];
        $afterAgent = $kinds['afterAgent'];

        $entry = $names($beforeAgent)[0] ?? $names($beforeModel)[0] ?? self::MODEL;
        $loopEntry = $names($beforeModel)[0] ?? self::MODEL;
        $exit = $afterAgent === [] ? Constants::END : $names($afterAgent)[\count($afterAgent) - 1];

        $edges = [[Constants::START, $entry]];
        $conditional = [];

        // A target label as the node it is, for a hook whose `end` goes to $endsAt.
        $targets = static function (array $allowed, string $endsAt) use ($hasTool): array {
            $out = [];
            foreach ($allowed as $label) {
                $node = ['model' => self::MODEL, 'tools' => self::TOOLS, 'end' => $endsAt][$label];
                if ($node === self::TOOLS && !$hasTool) {
                    continue;
                }
                $out[] = $node;
            }

            return $out;
        };
        $destinations = static fn (array $list): array => array_values(array_unique($list));

        // beforeAgent: forward, `end` goes to the exit.
        foreach ($beforeAgent as $i => [$node, $allowed]) {
            $next = $beforeAgent[$i + 1][0] ?? $loopEntry;
            if ($allowed === []) {
                $edges[] = [$node, $next];
            } else {
                $conditional[$node] = $destinations([$next, ...$targets($allowed, $exit)]);
            }
        }
        // beforeModel: forward, `end` goes to END.
        foreach ($beforeModel as $i => [$node, $allowed]) {
            $next = $beforeModel[$i + 1][0] ?? self::MODEL;
            if ($allowed === []) {
                $edges[] = [$node, $next];
            } else {
                $conditional[$node] = $destinations([$next, ...$targets($allowed, Constants::END)]);
            }
        }
        // The model node.
        if ($afterModel !== []) {
            $edges[] = [self::MODEL, $afterModel[\count($afterModel) - 1][0]];
        } else {
            $paths = [...($hasTool ? [self::TOOLS] : []), $exit];
            if (\count($paths) === 1) {
                $edges[] = [self::MODEL, $paths[0]];
            } else {
                $conditional[self::MODEL] = $paths;
            }
        }
        // afterModel: reverse; the first one (the last to run) leaves the loop.
        for ($i = \count($afterModel) - 1; $i > 0; $i--) {
            [$node, $allowed] = $afterModel[$i];
            $next = $afterModel[$i - 1][0];
            if ($allowed === []) {
                $edges[] = [$node, $next];
            } else {
                $conditional[$node] = $destinations([$next, ...$targets($allowed, Constants::END)]);
            }
        }
        if ($afterModel !== []) {
            $conditional[$afterModel[0][0]] = [...($hasTool ? [self::TOOLS] : []), self::MODEL, $exit];
        }
        // afterAgent: reverse; the first one (the last to run) ends the run.
        for ($i = \count($afterAgent) - 1; $i > 0; $i--) {
            [$node, $allowed] = $afterAgent[$i];
            $next = $afterAgent[$i - 1][0];
            if ($allowed === []) {
                $edges[] = [$node, $next];
            } else {
                $conditional[$node] = $destinations([$next, ...$targets($allowed, Constants::END)]);
            }
        }
        if ($afterAgent !== []) {
            [$node, $allowed] = $afterAgent[0];
            if ($allowed === []) {
                $edges[] = [$node, Constants::END];
            } else {
                $conditional[$node] = $destinations([Constants::END, ...$targets($allowed, Constants::END)]);
            }
        }
        // Tools come back to the loop entry.
        if ($hasTool) {
            $edges[] = [self::TOOLS, $loopEntry];
        }

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

        self::assertEqualsCanonicalizing($expectedNodes, $actualNodes);
        self::assertEqualsCanonicalizing($edges, $actualEdges);
        self::assertEqualsCanonicalizing(array_keys($conditional), array_keys($actualConditional));
        foreach ($conditional as $source => $expectedDestinations) {
            self::assertEqualsCanonicalizing($expectedDestinations, $actualConditional[$source], 'destinations of ' . $source);
        }
    }
}
