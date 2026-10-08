<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Nodes;

use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Nodes\BeforeModelNode;
use LangGraph\Agents\Nodes\MiddlewareNode;
use LangGraph\Agents\Runtime;
use LangGraph\Pregel\Command;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The node that runs a middleware's `beforeModel` hook, and with it the behaviour every middleware node shares
 * (`MiddlewareNode::invokeMiddleware()` from `nodes/middleware.ts`): the hook's inputs, the `jumpTo` check, control
 * actions and the update that is returned. Upstream covers these only through whole agents.
 */
#[CoversClass(BeforeModelNode::class)]
#[CoversClass(MiddlewareNode::class)]
final class BeforeModelNodeTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $state = [];

    protected function setUp(): void
    {
        $this->state = ['messages' => [new HumanMessage('hi')], 'counter' => 1];
    }

    /** @param array<string, mixed> $options */
    private static function node(callable|array $hook, array $options = []): BeforeModelNode
    {
        return new BeforeModelNode(Middleware::create(['name' => 'm', 'beforeModel' => $hook, ...$options]));
    }

    public function testItIsNamedAfterItsKindAndMiddleware(): void
    {
        self::assertSame('BeforeModelNode_m', self::node(static fn (): null => null)->getName());
    }

    public function testAHookThatReturnsNothingYieldsOnlyTheJumpToSentinel(): void
    {
        $result = self::node(static fn (): null => null)->invoke($this->state, new RunnableConfig());

        self::assertSame(['jumpTo' => null], $result);
    }

    public function testAnEmptyUpdateEchoesTheStateWithoutAJump(): void
    {
        $result = self::node(static fn (): array => [])->invoke($this->state, new RunnableConfig());

        self::assertSame(['messages' => $this->state['messages'], 'counter' => 1, 'jumpTo' => null], $result);
    }

    public function testAnUpdateIsMergedOverTheState(): void
    {
        $result = self::node(static fn (): array => ['counter' => 2, 'extra' => true])->invoke($this->state, new RunnableConfig());

        self::assertSame(2, $result['counter']);
        self::assertTrue($result['extra']);
        self::assertNull($result['jumpTo']);
        self::assertCount(1, $result['messages']);
    }

    public function testTheHookReceivesTheStateAndAReadOnlyRuntimeWithTheFilteredContext(): void
    {
        $seen = [];
        $node = self::node(
            static function (array $state, Runtime $runtime) use (&$seen): null {
                $seen = ['state' => $state, 'runtime' => $runtime];

                return null;
            },
            ['contextSchema' => ['type' => 'object', 'properties' => ['tenant' => ['type' => 'string'], 'region' => ['type' => 'string', 'default' => 'eu']], 'required' => ['tenant']]],
        );

        $node->invoke($this->state, new RunnableConfig(context: ['tenant' => 'acme', 'unrelated' => 1], configurable: ['thread_id' => 't']));

        self::assertSame($this->state['counter'], $seen['state']['counter']);
        self::assertSame(['tenant' => 'acme', 'region' => 'eu'], $seen['runtime']->context);
        self::assertSame('t', $seen['runtime']->configurable['thread_id']);
    }

    public function testAMissingRequiredContextFieldRaises(): void
    {
        $node = self::node(
            static fn (): null => null,
            ['contextSchema' => ['type' => 'object', 'properties' => ['tenant' => ['type' => 'string']], 'required' => ['tenant']]],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tenant: Required');

        $node->invoke($this->state, new RunnableConfig(context: []));
    }

    public function testAJumpTheHookDoesNotDeclareIsRejected(): void
    {
        $node = self::node(static fn (): array => ['jumpTo' => 'tools']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid jump target: tools, no beforeModel.canJumpTo defined in middleware m.');

        $node->invoke($this->state, new RunnableConfig());
    }

    public function testAJumpOutsideTheDeclaredListIsRejectedAndNamesTheAllowedOnes(): void
    {
        $node = self::node(['hook' => static fn (): array => ['jumpTo' => 'tools'], 'canJumpTo' => ['end', 'model']]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid jump target: tools, must be one of: end, model.');

        $node->invoke($this->state, new RunnableConfig());
    }

    public function testADeclaredJumpIsReturnedAlongsideTheState(): void
    {
        $node = self::node(['hook' => static fn (): array => ['jumpTo' => 'end'], 'canJumpTo' => ['end']]);

        $result = $node->invoke($this->state, new RunnableConfig());

        self::assertSame('end', $result['jumpTo']);
        self::assertSame(1, $result['counter']);
    }

    public function testATerminateControlActionMergesItsResultOrRaisesItsError(): void
    {
        $merged = self::node(static fn (): array => ['type' => 'terminate', 'result' => ['done' => true]])
            ->invoke($this->state, new RunnableConfig());
        self::assertTrue($merged['done']);
        self::assertSame(1, $merged['counter']);

        $failing = self::node(static fn (): array => ['type' => 'terminate', 'error' => new \LogicException('stop here')]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('stop here');
        $failing->invoke($this->state, new RunnableConfig());
    }

    public function testAnUnknownControlActionIsRejected(): void
    {
        $node = self::node(static fn (): array => ['type' => 'explode']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid control action: {"type":"explode"}');

        $node->invoke($this->state, new RunnableConfig());
    }

    public function testACommandIsPassedThrough(): void
    {
        $command = new Command(update: ['counter' => 9]);

        self::assertSame($command, self::node(static fn (): Command => $command)->invoke($this->state, new RunnableConfig()));
    }

    public function testItsInputIsThePrivateStateOfTheMiddlewareSchema(): void
    {
        $node = self::node(
            static fn (): null => null,
            ['stateSchema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], '_b' => ['type' => 'string']], 'required' => ['a', '_b']]],
        );

        $input = $node->nodeOptions()['input'];

        self::assertSame(['messages', 'structuredResponse', 'a', '_b'], array_keys($input['properties']));
        // A private field is never required of the caller.
        self::assertSame(['messages', 'a'], $input['required']);
    }
}
