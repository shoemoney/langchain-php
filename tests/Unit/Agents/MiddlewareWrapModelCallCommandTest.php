<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Runtime;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The "wrapModelCall Command support" describe block of `langchain/src/agents/tests/middleware.test.ts`.
 *
 * Not converted: "should propagate structured output retry Command through wrapModelCall middleware", which
 * needs a `toolStrategy` response format (WP-21c). The `StateSchema`s of the upstream cases are JSON Schemas
 * with a `default`.
 */
#[CoversClass(Middleware::class)]
final class MiddlewareWrapModelCallCommandTest extends TestCase
{
    /** @return list<BaseMessage> */
    private static function aiMessages(array $messages): array
    {
        return AgentAssertions::ofType($messages, AIMessage::class);
    }

    public function testShouldNotLeakWrapModelCallMiddlewareStateAcrossThreadId(): void
    {
        $seenByThread = [];

        $middleware = Middleware::create([
            'name' => 'WrapLeakCheck',
            'stateSchema' => ['type' => 'object', 'properties' => ['contentStrategy' => ['type' => 'string']]],
            'beforeAgent' => static function (array $state, Runtime $runtime): ?array {
                if ($runtime->configurable['thread_id'] === 'thread-a') {
                    return ['contentStrategy' => 'thread-a-only-value'];
                }

                return null;
            },
            'wrapModelCall' => static function (array $request, callable $handler) use (&$seenByThread): mixed {
                $seenByThread[$request['runtime']->configurable['thread_id']] = $request['state']['contentStrategy'] ?? null;

                return $handler($request);
            },
        ]);

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('one'), new AIMessage('two')]),
            'tools' => [],
            'middleware' => [$middleware],
            'checkpointer' => new MemorySaver(),
        ]);

        $agent->invoke(['messages' => [new HumanMessage('first')]], ['configurable' => ['thread_id' => 'thread-a']]);
        $agent->invoke(['messages' => [new HumanMessage('second')]], ['configurable' => ['thread_id' => 'thread-b']]);

        self::assertSame('thread-a-only-value', $seenByThread['thread-a']);
        self::assertNull($seenByThread['thread-b']);
    }

    public function testShouldSupportReturningCommandFromWrapModelCallShortCircuit(): void
    {
        // wrapModelCall returns a Command without calling the handler: the model is never invoked.
        $model = AgentAssertions::fakeChat([new AIMessage('Should not be called')]);

        $commandMiddleware = Middleware::create([
            'name' => 'CommandMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['customState' => ['type' => 'string', 'default' => '']]],
            'wrapModelCall' => static fn (): Command => new Command(update: ['customState' => 'routed']),
        ]);

        $agent = Agent::create(['model' => $model, 'tools' => [], 'middleware' => [$commandMiddleware]]);

        $result = $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

        // The model was not called: no AIMessage in the output. The Command's state update is applied.
        self::assertSame([], self::aiMessages($result['messages']));
        self::assertSame('routed', $result['customState']);
    }

    public function testShouldSupportReturningCommandAfterCallingHandler(): void
    {
        // wrapModelCall calls the handler (the model), then returns a Command with more state updates; the
        // tracked AIMessage still appears in the messages.
        $commandMiddleware = Middleware::create([
            'name' => 'CommandMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['customCounter' => ['type' => 'number', 'default' => 0]]],
            'wrapModelCall' => static function (array $request, callable $handler): Command {
                // Call the model normally.
                $handler($request);

                // Return a Command with additional state updates.
                return new Command(update: ['customCounter' => 42]);
            },
        ]);

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Hello from model')]),
            'tools' => [],
            'middleware' => [$commandMiddleware],
        ]);

        $result = $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

        // The model's AIMessage is tracked and in the output.
        $aiMessages = self::aiMessages($result['messages']);
        self::assertGreaterThanOrEqual(1, \count($aiMessages));
        self::assertStringContainsString('Hello from model', $aiMessages[array_key_last($aiMessages)]->content);

        // The Command's state update is applied too.
        self::assertSame(42, $result['customCounter']);
    }

    public function testShouldTrackAiMessageFromCommandUpdateMessages(): void
    {
        // When a Command has an AIMessage in its update.messages, that is the effective message.
        $commandMiddleware = Middleware::create([
            'name' => 'CommandMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler): Command {
                // Call the handler to get the model response.
                $handler($request);

                // Return a Command with a different AIMessage in update.messages.
                return new Command(update: ['messages' => [new AIMessage('Replaced response')]]);
            },
        ]);

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Original model response')]),
            'tools' => [],
            'middleware' => [$commandMiddleware],
        ]);

        $result = $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

        // The AIMessage from the Command's update.messages is tracked.
        $contents = array_map(static fn (BaseMessage $m): mixed => $m->content, self::aiMessages($result['messages']));
        self::assertContains('Replaced response', $contents);
    }

    public function testShouldNotDoubleCollectCommandsPassedThroughFromInnerHandler(): void
    {
        // An inner middleware returns a Command and the outer one passes it through: collected only once.
        $innerMiddleware = Middleware::create([
            'name' => 'InnerMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['innerState' => ['type' => 'string', 'default' => '']]],
            'wrapModelCall' => static function (array $request, callable $handler): Command {
                $handler($request);

                return new Command(update: ['innerState' => 'inner-value']);
            },
        ]);
        $outerMiddleware = Middleware::create([
            'name' => 'OuterMiddleware',
            // Pass through whatever the inner handler returns.
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler($request),
        ]);

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Hello')]),
            'tools' => [],
            'middleware' => [$outerMiddleware, $innerMiddleware],
        ]);

        $result = $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

        // The inner Command's state is applied (once), and the model's AIMessage is still tracked.
        self::assertSame('inner-value', $result['innerState']);
        self::assertGreaterThanOrEqual(1, \count(self::aiMessages($result['messages'])));
    }

    public function testShouldPassModifiedAiMessageToOuterMiddlewareWhenInnerMiddlewareModifiesAndReturnsCommand(): void
    {
        // Three middleware: the inner one modifies the AIMessage content, the middle one returns a Command
        // (no message update), and the outer one calls handler() and receives the AIMessage modified by the
        // inner one: not the raw model response, and not the Command.
        $outerReceivedFromHandler = null;

        $innerMiddleware = Middleware::create([
            'name' => 'InnerMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler): AIMessage {
                $aiMessage = $handler($request);

                return new AIMessage([...$aiMessage->kwargs(), 'content' => 'Modified by inner']);
            },
        ]);
        $middleMiddleware = Middleware::create([
            'name' => 'MiddleMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['middleFlag' => ['type' => 'boolean', 'default' => false]]],
            'wrapModelCall' => static function (array $request, callable $handler): Command {
                // Call the handler (gets inner's modified AIMessage), then return a Command.
                $handler($request);

                return new Command(update: ['middleFlag' => true]);
            },
        ]);
        $outerMiddleware = Middleware::create([
            'name' => 'OuterMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use (&$outerReceivedFromHandler): mixed {
                $result = $handler($request);
                $outerReceivedFromHandler = $result;

                return $result;
            },
        ]);

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Original')]),
            'tools' => [],
            'middleware' => [$outerMiddleware, $middleMiddleware, $innerMiddleware],
        ]);

        $result = $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

        // The outer one received the AIMessage modified by the inner one, NOT "Original" and NOT a Command.
        self::assertInstanceOf(AIMessage::class, $outerReceivedFromHandler);
        self::assertSame('Modified by inner', $outerReceivedFromHandler->content);

        // The middle one's Command state update is still applied.
        self::assertTrue($result['middleFlag']);
    }

    public function testShouldValidateThatWrapModelCallReturnsAiMessageOrCommand(): void
    {
        $invalidMiddleware = Middleware::create([
            'name' => 'InvalidMiddleware',
            'wrapModelCall' => static fn (): string => 'invalid return value',
        ]);

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Hello')]),
            'tools' => [],
            'middleware' => [$invalidMiddleware],
        ]);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage(
            'Invalid response from "wrapModelCall" in middleware "InvalidMiddleware": expected AIMessage or Command, got string'
        );

        $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);
    }

    public function testShouldSupportMultipleMiddlewareEachReturningCommands(): void
    {
        // All the Commands are collected and returned alongside the AIMessage.
        $makeMiddleware = static fn (string $letter): array => Middleware::create([
            'name' => 'Middleware' . $letter,
            'stateSchema' => ['type' => 'object', 'properties' => ['state' . $letter => ['type' => 'string', 'default' => '']]],
            'wrapModelCall' => static function (array $request, callable $handler) use ($letter): Command {
                $handler($request);

                return new Command(update: ['state' . $letter => 'from-' . $letter]);
            },
        ]);

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Hello')]),
            'tools' => [],
            'middleware' => [$makeMiddleware('A'), $makeMiddleware('B')],
        ]);

        $result = $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

        // Both Commands' state updates are applied.
        self::assertSame('from-A', $result['stateA']);
        self::assertSame('from-B', $result['stateB']);

        // The model's AIMessage is still tracked.
        self::assertGreaterThanOrEqual(1, \count(self::aiMessages($result['messages'])));
    }
}
