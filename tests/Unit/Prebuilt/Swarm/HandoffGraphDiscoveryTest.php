<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt\Swarm;

use LangChain\Messages\AIMessage;
use LangChain\Runnables\Graph\Graph;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangChain\Tests\Unit\Prebuilt\Supervisor\SupervisorFixtures;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Pregel\CompiledStateGraph;
use LangGraph\Pregel\Constants;
use LangGraph\Prebuilt\ReactAgent;
use LangGraph\Prebuilt\Swarm\Handoff;
use LangGraph\Prebuilt\Swarm\Swarm;
use LangGraph\Prebuilt\ToolNode;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of the public getGraph/data lookup in langgraph-swarm/src/handoff.ts:119-137.
 * Swarm consumes it at swarm.ts:145-149 (call147); metadata/nominal-guard adaptations remain explicit.
 */
#[CoversClass(Handoff::class)]
final class HandoffGraphDiscoveryTest extends TestCase
{
    private static function agent(string $node = 'tools', string $name = 'Alice', ?array $tools = null): CompiledStateGraph
    {
        return (new StateGraph(MessagesAnnotation::root()))
            ->addNode($node, new ToolNode($tools ?? [Handoff::createHandoffTool(['agentName' => 'Bob'])]))
            ->addEdge(Constants::START, $node)
            ->addEdge($node, Constants::END)
            ->compile(['name' => $name]);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function escapedQueries(): iterable
    {
        yield 'raw name is absent from drawable keys' => ['subgraph', []];
        yield 'literal quoted drawable key is present' => ['"subgraph"', ['Bob']];
    }

    #[DataProvider('escapedQueries')]
    public function testActualEscapedDrawableKeysChooseTheSourceLiteralDestination(string $query, array $expected): void
    {
        $agent = self::agent('subgraph');
        $drawable = $agent->getGraph();
        self::assertSame(['__start__', '"subgraph"', '__end__'], array_keys($drawable->nodes));
        self::assertSame($agent->nodes['subgraph']->bound, $drawable->nodes['"subgraph"']->data);
        self::assertSame($expected, Handoff::getHandoffDestinations($agent, $query));
    }

    public function testOrdinaryDefaultMissingAndPlainToolControls(): void
    {
        $bob = Handoff::createHandoffTool(['agentName' => 'Bob']);
        $carol = Handoff::createHandoffTool(['agentName' => 'Carol']);
        $plain = SupervisorFixtures::add();
        $agent = self::agent(tools: [$plain, $bob, $carol]);
        $before = serialize([$plain->metadata, $bob->metadata, $carol->metadata]);
        self::assertSame(['Bob', 'Carol'], Handoff::getHandoffDestinations($agent));
        self::assertSame([], Handoff::getHandoffDestinations($agent, 'missing'));
        self::assertSame([], Handoff::getHandoffDestinations(self::agent(tools: [$plain])));
        self::assertSame([$plain, $bob, $carol], $agent->nodes['tools']->bound->tools);
        self::assertSame($before, serialize([$plain->metadata, $bob->metadata, $carol->metadata]));
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function publicViews(): iterable
    {
        yield 'replacement' => ['replacement', 'tools', ['Carol', 'Dave']];
        yield 'absent' => ['absent', 'tools', []];
        yield 'empty' => ['empty', 'tools', []];
        yield 'custom name' => ['replacement', 'SYNTH custom tools', ['Carol', 'Dave']];
    }

    private static function publicView(string $shape, string $node = 'tools', ?\Throwable $failure = null): CompiledStateGraph
    {
        $actual = self::agent();
        $view = new Graph();
        if ($shape !== 'absent') {
            $view->addNode(new ToolNode($shape === 'empty' ? [] : [
                Handoff::createHandoffTool(['agentName' => 'Carol']),
                Handoff::createHandoffTool(['agentName' => 'Dave']),
            ]), $node);
        }

        return new class($actual, $view, $failure) extends CompiledStateGraph {
            public int $calls = 0;
            public array $arguments = [];

            public function __construct(public CompiledStateGraph $actual, public Graph $view, public ?\Throwable $failure)
            {
                parent::__construct(nodes: $actual->nodes, name: $actual->name);
            }

            public function getGraph(?RunnableConfig $config = null, bool|int|null $xray = null): Graph
            {
                $this->calls++;
                $this->arguments[] = func_get_args();
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return $this->view;
            }
        };
    }

    #[DataProvider('publicViews')]
    public function testThePublicViewIsAuthoritativeAndCalledOnceWithoutArguments(string $shape, string $node, array $expected): void
    {
        $agent = self::publicView($shape, $node);
        $runtime = $agent->nodes['tools']->bound;
        $tools = $runtime->tools;
        $metadata = serialize(array_map(static fn ($tool) => $tool->metadata, $tools));
        self::assertSame($expected, Handoff::getHandoffDestinations($agent, $node));
        self::assertSame(1, $agent->calls);
        self::assertSame([[]], $agent->arguments);
        self::assertSame($runtime, $agent->nodes['tools']->bound);
        self::assertSame($tools, $runtime->tools);
        self::assertSame($metadata, serialize(array_map(static fn ($tool) => $tool->metadata, $tools)));
        self::assertSame(['transfer_to_bob'], array_map(static fn ($tool) => $tool->name, $tools));
    }

    public function testTheActualSwarmBuilderUsesPublicViewEndsAndOriginalSubgraphIdentity(): void
    {
        $agent = self::publicView('replacement');
        $builder = Swarm::create(['agents' => [$agent], 'defaultActiveAgent' => 'Alice']);
        self::assertInstanceOf(StateGraph::class, $builder);
        self::assertSame(['Carol', 'Dave'], $builder->nodes['Alice']->ends);
        self::assertSame([$agent], $builder->nodes['Alice']->subgraphs);
        self::assertSame(1, $agent->calls);
        self::assertSame([[]], $agent->arguments);
        self::assertSame('Bob', $agent->nodes['tools']->bound->tools[0]->metadata[Handoff::METADATA_KEY_HANDOFF_DESTINATION]);
    }

    /** @return iterable<string, array{bool}> */
    public static function errorCallers(): iterable
    {
        yield 'direct actual helper' => [false];
        yield 'actual Swarm builder' => [true];
    }

    #[DataProvider('errorCallers')]
    public function testGetterFailurePropagatesTheSameOriginalThrowableWithoutAResult(bool $swarm): void
    {
        $failure = new \RuntimeException('SYNTH original getter failure');
        $agent = self::publicView('replacement', failure: $failure);
        $result = null;
        $caught = null;
        try {
            $result = $swarm
                ? Swarm::create(['agents' => [$agent], 'defaultActiveAgent' => 'Alice'])
                : Handoff::getHandoffDestinations($agent);
        } catch (\Throwable $error) {
            $caught = $error;
        }
        self::assertSame($failure, $caught);
        self::assertNull($result);
        self::assertSame(1, $agent->calls);
        self::assertSame([[]], $agent->arguments);
    }

    public function testNestedCompiledChildIsNeitherExpandedNorInvoked(): void
    {
        $actual = self::agent(name: 'Child');
        $child = new class($actual) extends CompiledStateGraph {
            public int $calls = 0;

            public function __construct(public CompiledStateGraph $actual)
            {
                parent::__construct(nodes: $actual->nodes, name: $actual->name);
            }

            public function getGraph(?RunnableConfig $config = null, bool|int|null $xray = null): Graph
            {
                $this->calls++;

                return $this->actual->getGraph($config, $xray);
            }
        };
        $parent = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('tools', $child)
            ->addEdge(Constants::START, 'tools')
            ->addEdge('tools', Constants::END)
            ->compile(['name' => 'Parent']);
        self::assertSame($child, $parent->getGraph()->nodes['tools']->data);
        self::assertSame([], Handoff::getHandoffDestinations($parent));
        self::assertSame(0, $child->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function ordinaryAgentVariants(): iterable
    {
        foreach (['plain', 'pre', 'post', 'both', 'toolnode', 'interrupt'] as $variant) {
            yield $variant => [$variant];
        }
    }

    #[DataProvider('ordinaryAgentVariants')]
    public function testActualOrdinaryReactAgentVariantsKeepNamesIdentityAndMetadata(string $variant): void
    {
        $handoff = Handoff::createHandoffTool(['agentName' => 'Bob']);
        $plain = SupervisorFixtures::add();
        $tools = [$handoff, $plain];
        $params = ['name' => 'Alice', 'llm' => ReactAgentFixtures::spy([new AIMessage('SYNTH unused')]), 'tools' => $tools];
        if (in_array($variant, ['pre', 'both'], true)) {
            $params['preModelHook'] = static fn (array $state): array => ['messages' => []];
        }
        if (in_array($variant, ['post', 'both'], true)) {
            $params['postModelHook'] = static fn (array $state): array => [];
        }
        if ($variant === 'toolnode') {
            $params['tools'] = new ToolNode($tools);
        }
        if ($variant === 'interrupt') {
            $params['interruptBefore'] = ['tools'];
        }
        $agent = ReactAgent::create($params);
        $runtime = $agent->nodes['tools']->bound;
        $before = serialize(array_map(static fn ($tool) => $tool->metadata, $runtime->tools));
        self::assertSame($runtime, $agent->getGraph()->nodes['tools']->data);
        self::assertSame(['Bob'], Handoff::getHandoffDestinations($agent));
        self::assertSame([], Handoff::getHandoffDestinations($agent, 'missing'));
        self::assertSame('Alice', $agent->name);
        self::assertSame(['transfer_to_bob', 'add'], array_map(static fn ($tool) => $tool->name, $runtime->tools));
        self::assertSame($tools, $runtime->tools);
        self::assertSame($before, serialize(array_map(static fn ($tool) => $tool->metadata, $runtime->tools)));
    }

    /** @return iterable<string, array{mixed, list<string>}> */
    public static function retainedMetadataContracts(): iterable
    {
        yield 'key-present null remains omitted in PHP, source is null' => [null, []];
        yield 'numeric zero remains string in PHP, source is numeric' => [0, ['0']];
        yield 'empty string agrees with source' => ['', ['']];
        yield 'string Carol agrees with source' => ['Carol', ['Carol']];
    }

    #[DataProvider('retainedMetadataContracts')]
    public function testHeldMetadataAdaptationsAreUnchangedAndExplicit(mixed $value, array $expected): void
    {
        $tool = Handoff::createHandoffTool(['agentName' => 'Bob']);
        $tool->metadata = [Handoff::METADATA_KEY_HANDOFF_DESTINATION => $value];
        $before = serialize($tool->metadata);
        $agent = self::agent(tools: [$tool, SupervisorFixtures::add()]);
        self::assertSame($expected, Handoff::getHandoffDestinations($agent));
        self::assertSame($before, serialize($tool->metadata));
        self::assertSame($tool, $agent->nodes['tools']->bound->tools[0]);
        self::assertSame('transfer_to_bob', $tool->name);
    }
}
