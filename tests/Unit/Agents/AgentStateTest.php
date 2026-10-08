<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangGraph\Agents\AgentState;
use LangGraph\Agents\RunnableCallable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for `StateManager` (`agents/state.ts`), which has no upstream unit test of its own: upstream's
 * `tests/state.test.ts` exercises it through `createAgent` (deferred to WP-21b, see the report).
 */
#[CoversClass(AgentState::class)]
final class AgentStateTest extends TestCase
{
    private static function node(array $returns): RunnableCallable
    {
        $node = new RunnableCallable(static fn (): array => $returns);
        $node->invoke([]);

        return $node;
    }

    public function testStateOfAnUnknownMiddlewareIsEmpty(): void
    {
        self::assertSame([], (new AgentState())->getState('nobody'));
    }

    public function testNodesOfOneMiddlewareAreMergedInOrder(): void
    {
        $manager = new AgentState();
        $middleware = ['name' => 'summarizer'];
        $manager->addNode($middleware, self::node(['count' => 1, 'keep' => 'a']));
        $manager->addNode($middleware, self::node(['count' => 2]));

        self::assertSame(['count' => 2, 'keep' => 'a'], $manager->getState('summarizer'));
    }

    public function testGroupsAreKeptApartAndObjectsWork(): void
    {
        $manager = new AgentState();
        $manager->addNode(['name' => 'a'], self::node(['x' => 1]));
        $manager->addNode((object) ['name' => 'b'], self::node(['y' => 2]));

        self::assertSame(['x' => 1], $manager->getState('a'));
        self::assertSame(['y' => 2], $manager->getState('b'));
    }

    public function testJumpToIsNeverPropagated(): void
    {
        $manager = new AgentState();
        $manager->addNode(['name' => 'a'], self::node(['jumpTo' => 'tools', 'x' => 1]));

        self::assertSame(['x' => 1], $manager->getState('a'));
    }

    public function testNodesThatHaveNotRunOrReturnedNonArraysContributeNothing(): void
    {
        $manager = new AgentState();
        $manager->addNode(['name' => 'a'], new RunnableCallable(static fn (): array => ['never' => 'run']));
        $manager->addNode(['name' => 'a'], self::node([]));
        $returnsNull = new RunnableCallable(static fn (): mixed => null);
        $returnsNull->invoke([]);
        $manager->addNode(['name' => 'a'], $returnsNull);

        self::assertSame([], $manager->getState('a'));
    }
}
