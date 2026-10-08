<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Graph;

use LangGraph\Graph\CompiledGraph;
use LangGraph\Graph\Graph;
use LangGraph\Pregel\CompiledStateGraph;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompiledGraph::class)]
#[CoversClass(CompiledStateGraph::class)]
final class CompiledGraphTest extends TestCase
{
    private static function chain(): Graph
    {
        return (new Graph())
            ->addNode('a', static fn (string $s): string => $s . 'a')
            ->addNode('b', static fn (string $s): string => $s . 'b')
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->addEdge('b', Constants::END);
    }

    public function testCompileReturnsAPregelThatRemembersItsBuilder(): void
    {
        $builder = self::chain();
        $compiled = $builder->compile(['name' => 'chain']);

        $this->assertInstanceOf(CompiledGraph::class, $compiled);
        $this->assertInstanceOf(Pregel::class, $compiled);
        $this->assertSame($builder, $compiled->builder);
        $this->assertSame('chain', $compiled->getName());
    }

    public function testEachNodeGetsAnEphemeralChannelAndIsStreamed(): void
    {
        $compiled = self::chain()->compile();

        $this->assertArrayHasKey('a', $compiled->channels);
        $this->assertArrayHasKey('b', $compiled->channels);
        $this->assertArrayHasKey(Constants::START, $compiled->channels);
        $this->assertArrayHasKey(Constants::END, $compiled->channels);
        $this->assertSame(['a', 'b'], $compiled->streamChannels);
        $this->assertSame(Constants::START, $compiled->inputChannels);
        $this->assertSame(Constants::END, $compiled->outputChannels);
    }

    public function testAnEdgeMakesTheEndNodeSubscribeToTheStartNode(): void
    {
        $compiled = self::chain()->compile();

        $this->assertSame([Constants::START], $compiled->nodes['a']->triggers);
        $this->assertSame(['a'], $compiled->nodes['b']->triggers);
    }

    public function testInvokeRunsTheChain(): void
    {
        $this->assertSame('xab', self::chain()->compile()->invoke('x'));
    }

    public function testACompiledGraphIsReusable(): void
    {
        $compiled = self::chain()->compile();

        $this->assertSame('1ab', $compiled->invoke('1'));
        $this->assertSame('2ab', $compiled->invoke('2'));
    }

    public function testAnEdgeFromStartToEndIsRejected(): void
    {
        $nodes = [];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot have an edge from START to END');

        CompiledGraph::attachEdge($nodes, Constants::START, Constants::END);
    }

    public function testCompiledStateGraphIsACompiledGraph(): void
    {
        $builder = (new StateGraph(['n' => 'int']))
            ->addNode('inc', static fn (array $s): array => ['n' => $s['n'] + 1])
            ->addEdge(Constants::START, 'inc');
        $compiled = $builder->compile();

        $this->assertInstanceOf(CompiledStateGraph::class, $compiled);
        $this->assertInstanceOf(CompiledGraph::class, $compiled);
        $this->assertSame($builder, $compiled->builder);
        $this->assertSame(['n' => 2], $compiled->invoke(['n' => 1]));
    }

    public function testStateGraphCompileKeepsStoreCacheAndDisabledCheckpointerOptions(): void
    {
        $store = new \LangGraph\Store\InMemoryStore();
        $cache = new \LangGraph\Cache\InMemoryCache();
        $compiled = (new StateGraph(['n' => 'int']))
            ->addNode('inc', static fn (array $s): array => ['n' => $s['n'] + 1])
            ->addEdge(Constants::START, 'inc')
            ->compile(['checkpointer' => false, 'store' => $store, 'cache' => $cache]);

        $this->assertSame($store, $compiled->store);
        $this->assertSame($cache, $compiled->cache);
        $this->assertNull($compiled->checkpointer);
        $this->assertTrue($compiled->checkpointerDisabled);
    }

    public function testACompiledGraphCanBeANodeOfAnother(): void
    {
        $inner = self::chain()->compile();
        $outer = (new Graph())
            ->addNode('inner', $inner)
            ->addNode('shout', static fn (string $s): string => strtoupper($s))
            ->addEdge(Constants::START, 'inner')
            ->addEdge('inner', 'shout')
            ->addEdge('shout', Constants::END);

        $this->assertSame([$inner], $outer->nodes['inner']->subgraphs);
        $this->assertSame('XAB', $outer->compile()->invoke('x'));
    }
}
