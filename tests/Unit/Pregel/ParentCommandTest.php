<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Errors\ParentCommand;
use LangGraph\Pregel\CallScheduler;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\PregelRunner;
use LangGraph\Pregel\Send;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A `Command(graph: PARENT)` raised inside a subgraph is delivered to the parent by the engine.
 *
 * Ports the parent-command cases of `pregel.test.ts`, `graph_structure.test.ts` and `prebuilt.test.ts`.
 */
#[CoversClass(PregelRunner::class)]
#[CoversClass(CallScheduler::class)]
final class ParentCommandTest extends TestCase
{
    private static function lastValue(): \Closure
    {
        return static fn ($a, $b) => $b;
    }

    private static function concat(): \Closure
    {
        // Order-preserving union: stands in for the messages reducer, which de-duplicates by id.
        return static fn ($a, $b) => array_values(array_unique(array_merge($a, $b)));
    }

    private static function userSchema(): mixed
    {
        return Annotation::root([
            'messages' => Annotation::withReducer(self::concat(), static fn () => []),
            'user_name' => Annotation::withReducer(self::lastValue(), static fn () => ''),
        ]);
    }

    private static function thread(): RunnableConfig
    {
        return new RunnableConfig(configurable: ['thread_id' => '1']);
    }

    public function testParentCommandUpdatesTheParentAndStopsTheSubgraph(): void
    {
        $sub = (new StateGraph(self::userSchema()))
            ->addNode('tool', static fn () => new Command(update: ['user_name' => 'Meow'], graph: Command::PARENT))
            ->addEdge(Constants::START, 'tool')
            ->compile();

        $graph = (new StateGraph(self::userSchema()))
            ->addNode('alice', $sub)
            ->addEdge(Constants::START, 'alice')
            ->compile(['checkpointer' => new MemorySaver()]);

        $result = $graph->invoke(['messages' => ['get user name']], self::thread());

        self::assertSame(['messages' => ['get user name'], 'user_name' => 'Meow'], $result);

        $state = $graph->getState(self::thread());
        self::assertSame(['messages' => ['get user name'], 'user_name' => 'Meow'], $state->values);
        self::assertSame([], $state->next);
        self::assertSame(1, $state->metadata['step']);
    }

    public function testParentCommandWithGotoAndDoubleUpdates(): void
    {
        $schema = self::userSchema();
        $sub = (new StateGraph($schema))
            ->addNode('init', static fn () => ['user_name' => 'Woof'])
            ->addNode('tool', static fn () => [new Command(update: ['user_name' => 'Meow'], goto: 'bob', graph: Command::PARENT)], ['ends' => [Constants::END]])
            ->addEdge(Constants::START, 'init')
            ->addEdge('init', 'tool')
            ->compile();

        $graph = (new StateGraph($schema))
            ->addNode('init', static fn () => [])
            ->addNode('alice', $sub)
            ->addNode('bob', static function (array $state): array {
                if ($state['user_name'] !== 'Meow') {
                    throw new \RuntimeException('failed to update state from child');
                }

                return ['messages' => ['bob']];
            })
            ->addEdge(Constants::START, 'init')
            ->addConditionalEdges('init', static fn () => 'alice')
            ->addEdge('alice', 'bob')
            ->compile(['checkpointer' => new MemorySaver()]);

        $result = $graph->invoke(['messages' => ['get user name']], self::thread());

        self::assertSame(['messages' => ['get user name', 'bob'], 'user_name' => 'Meow'], $result);
        $state = $graph->getState(self::thread());
        self::assertSame([], $state->next);
        self::assertSame(3, $state->metadata['step']);
    }

    public function testParentCommandFromGrandchildGraph(): void
    {
        $schema = self::userSchema();
        $grandchild = (new StateGraph($schema))
            ->addNode('tool', static fn () => new Command(
                update: ['messages' => ['grandkid'], 'user_name' => 'jeffrey'],
                goto: 'robert',
                graph: Command::PARENT,
            ))
            ->addEdge(Constants::START, 'tool')
            ->compile();

        $child = (new StateGraph($schema))
            ->addNode('bob', $grandchild, ['ends' => ['robert']])
            ->addNode('robert', static function (array $state): array {
                if ($state['user_name'] !== 'jeffrey') {
                    throw new \RuntimeException('failed to update state from grandchild');
                }

                return ['messages' => ['robert']];
            })
            ->addEdge(Constants::START, 'bob')
            ->addEdge('bob', 'robert')
            ->compile();

        $graph = (new StateGraph($schema))
            ->addNode('alice', $child)
            ->addEdge(Constants::START, 'alice')
            ->compile(['checkpointer' => new MemorySaver()]);

        $result = $graph->invoke(['messages' => ['get user name']], self::thread());

        self::assertSame(['messages' => ['get user name', 'grandkid', 'robert'], 'user_name' => 'jeffrey'], $result);
        $state = $graph->getState(self::thread());
        self::assertSame([], $state->next);
        self::assertSame(1, $state->metadata['step']);
    }

    public function testCommandParentAsDescribedInTheDocs(): void
    {
        $schema = Annotation::root(['foo' => Annotation::withReducer(self::lastValue(), static fn () => '')]);
        $log = [];
        $goto = '';

        $subgraph = (new StateGraph($schema))
            ->addNode('nodeA', static function () use (&$log, &$goto) {
                $log[] = 'Called A';

                return new Command(update: ['foo' => 'a'], goto: $goto, graph: Command::PARENT);
            })
            ->addEdge(Constants::START, 'nodeA')
            ->compile();

        $parent = (new StateGraph($schema))
            ->addNode('subgraph', $subgraph, ['ends' => ['nodeB', 'nodeC']])
            ->addNode('nodeB', static function (array $s) use (&$log): array {
                $log[] = 'Called B';

                return ['foo' => $s['foo'] . '|b'];
            })
            ->addNode('nodeC', static function (array $s) use (&$log): array {
                $log[] = 'Called C';

                return ['foo' => $s['foo'] . '|c'];
            })
            ->addEdge(Constants::START, 'subgraph')
            ->compile();

        $goto = 'nodeB';
        self::assertSame(['foo' => 'a|b'], $parent->invoke([]));
        self::assertSame(['Called A', 'Called B'], $log);

        $log = [];
        $goto = 'nodeC';
        self::assertSame(['foo' => 'a|c'], $parent->invoke([]));
        self::assertSame(['Called A', 'Called C'], $log);
    }

    public function testShouldHandleParentCommands(): void
    {
        $schema = self::userSchema();
        $sub = (new StateGraph($schema))
            ->addNode('tool', static fn () => new Command(update: ['user_name' => 'Meow'], graph: Command::PARENT))
            ->addEdge(Constants::START, 'tool')
            ->compile();

        $graph = (new StateGraph($schema))
            ->addNode('alice', $sub)
            ->addEdge(Constants::START, 'alice')
            ->compile(['checkpointer' => new MemorySaver()]);

        $result = $graph->invoke(['messages' => ['hi']], self::thread());

        self::assertSame(['messages' => ['hi'], 'user_name' => 'Meow'], $result);
        $state = $graph->getState(self::thread());
        self::assertSame([], $state->next);
    }

    public function testParentCommandWithSendRoutesThroughTheParent(): void
    {
        $schema = Annotation::root(['log' => Annotation::withReducer(self::concat(), static fn () => [])]);
        $sub = (new StateGraph($schema))
            ->addNode('tool', static fn () => new Command(
                goto: [new Send('alice', ['log' => ['to-alice']]), new Send('bob', ['log' => ['to-bob']])],
                graph: Command::PARENT,
            ))
            ->addEdge(Constants::START, 'tool')
            ->compile();

        $graph = (new StateGraph($schema))
            ->addNode('sub', $sub, ['ends' => ['alice', 'bob']])
            ->addNode('alice', static fn (array $s): array => ['log' => ['alice:' . implode(',', $s['log'])]])
            ->addNode('bob', static fn (array $s): array => ['log' => ['bob:' . implode(',', $s['log'])]])
            ->addEdge(Constants::START, 'sub')
            ->compile();

        $result = $graph->invoke(['log' => []]);

        self::assertEqualsCanonicalizing(['alice:to-alice', 'bob:to-bob'], $result['log']);
    }

    public function testARootGraphStillSurfacesTheParentCommand(): void
    {
        $sub = (new StateGraph(self::userSchema()))
            ->addNode('tool', static fn () => new Command(update: ['user_name' => 'Meow'], graph: Command::PARENT))
            ->addEdge(Constants::START, 'tool')
            ->compile();

        try {
            $sub->invoke(['messages' => []]);
            self::fail('a root graph has no parent to take the command');
        } catch (ParentCommand $e) {
            self::assertSame('', $e->command->graph);
            self::assertSame(['user_name' => 'Meow'], $e->command->update);
        }
    }
}
