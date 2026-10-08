<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Nodes;

use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Nodes\BeforeAgentNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The node that runs a middleware's `beforeAgent` hook. What every middleware node does with a hook's result is
 * covered by `BeforeModelNodeTest`; this asserts the parts that belong to this node: its name, the hook it
 * runs, and the name of its `canJumpTo` constraint in the error for a jump it does not allow.
 */
#[CoversClass(BeforeAgentNode::class)]
final class BeforeAgentNodeTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function state(): array
    {
        return ['messages' => [new HumanMessage('hi')]];
    }

    public function testItIsNamedAfterItsKindAndMiddleware(): void
    {
        $node = new BeforeAgentNode(Middleware::create(['name' => 'audit', 'beforeAgent' => static fn (): null => null]));

        self::assertSame('BeforeAgentNode_audit', $node->getName());
    }

    public function testItRunsOnlyItsOwnHook(): void
    {
        $ran = [];
        $record = static function (string $name) use (&$ran): \Closure {
            return static function () use (&$ran, $name): array {
                $ran[] = $name;

                return ['from' => $name];
            };
        };
        $middleware = Middleware::create([
            'name' => 'audit',
            'beforeAgent' => $record('beforeAgent'),
            'beforeModel' => $record('beforeModel'),
            'afterModel' => $record('afterModel'),
            'afterAgent' => $record('afterAgent'),
        ]);

        $result = (new BeforeAgentNode($middleware))->invoke(self::state(), new RunnableConfig());

        self::assertSame(['beforeAgent'], $ran);
        self::assertSame('beforeAgent', $result['from']);
    }

    public function testItNamesItsOwnConstraintWhenAJumpIsNotAllowed(): void
    {
        $node = new BeforeAgentNode(Middleware::create(['name' => 'audit', 'beforeAgent' => static fn (): array => ['jumpTo' => 'model']]));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid jump target: model, no beforeAgent.canJumpTo defined in middleware audit.');

        $node->invoke(self::state(), new RunnableConfig());
    }

    public function testADeclaredJumpIsAccepted(): void
    {
        $node = new BeforeAgentNode(Middleware::create(['name' => 'audit', 'beforeAgent' => ['hook' => static fn (): array => ['jumpTo' => 'end'], 'canJumpTo' => ['end']]]));

        self::assertSame('end', $node->invoke(self::state(), new RunnableConfig())['jumpTo']);
    }
}
