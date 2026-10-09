<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\ModelCallLimitMiddleware;
use LangGraph\Checkpoint\MemorySaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/middleware/tests/modelCallLimit.test.ts`.
 */
#[CoversClass(ModelCallLimitMiddleware::class)]
final class ModelCallLimitMiddlewareTest extends TestCase
{
    private static function toolCallMessage(string $name, string $arg, string $content = '', ?string $id = null): AIMessage
    {
        return new AIMessage(['content' => $content, 'id' => $id, 'tool_calls' => [['id' => 'call_1', 'name' => $name, 'args' => ['arg1' => $arg]]]]);
    }

    /**
     * Upstream replays one message object whose id is assigned the first time the messages reducer sees it, so
     * every replay REPLACES the earlier copy instead of appending. A model here stamps a fresh run id on each
     * reply, so the thread-limit cases give the message that id up front.
     */
    private static function toolCallMessage1(?string $id = null): AIMessage
    {
        return self::toolCallMessage('tool_1', 'arg1', 'foo', $id);
    }

    /** @return list<\LangChain\Tools\StructuredTool> */
    private static function tools(): array
    {
        return array_map(
            static fn (string $name, string $result): \LangChain\Tools\StructuredTool => tool(
                static fn (array $in): string => $result,
                ['name' => $name, 'description' => $name, 'schema' => Schema::object(['arg1' => ['type' => 'string']])],
            ),
            ['tool_1', 'tool_2', 'tool_3'],
            ['foobar', 'barfoo', 'barfoo'],
        );
    }

    /** @return array<string, array{0: string}> */
    public static function exitBehaviors(): array
    {
        return ['error' => ['error'], 'end' => ['end']];
    }

    #[DataProvider('exitBehaviors')]
    public function testShouldNotThrowIfTheRunLimitExceeds(string $exitBehavior): void
    {
        // First invocation: 2 model calls (within limit): the model asks for 3 tools, then answers.
        // Second invocation: 3 model calls (exceeds the limit of 2).
        $model = AgentAssertions::fakeChat([
            // First invocation - call 1: makes 3 tool calls.
            new AIMessage(['content' => '', 'tool_calls' => [
                ['id' => 'call_1', 'name' => 'tool_1', 'args' => ['arg1' => 'arg1']],
                ['id' => 'call_2', 'name' => 'tool_2', 'args' => ['arg1' => 'arg2']],
                ['id' => 'call_3', 'name' => 'tool_3', 'args' => ['arg1' => 'arg3']],
            ]]),
            // First invocation - call 2: the final response after the tools ran.
            new AIMessage(['content' => 'baz']),
            // Second invocation: one tool call each, so the third model call must fail.
            self::toolCallMessage1(),
            self::toolCallMessage('tool_2', 'arg2'),
            self::toolCallMessage('tool_3', 'arg3'),
            new AIMessage(['content' => 'fuzbaz']),
        ]);
        $agent = Agent::create([
            'model' => $model,
            'tools' => self::tools(),
            'middleware' => [ModelCallLimitMiddleware::create(['runLimit' => 2, 'exitBehavior' => $exitBehavior])],
        ]);

        $result = $agent->invoke(['messages' => ['Hello, world!']]);

        // The first invocation does not throw: only 2 model calls are made.
        self::assertSame('baz', $result['messages'][array_key_last($result['messages'])]->content);

        $message = 'Model call limits exceeded: run level call limit reached with 2 model calls';
        if ($exitBehavior === 'error') {
            // The next invocation throws: it needs a third model call.
            try {
                $agent->invoke(['messages' => ['Hello, world!']]);
                self::fail('the second invocation should have thrown');
            } catch (\Throwable $e) {
                self::assertSame($message, $e->getMessage());
            }
        } else {
            $result = $agent->invoke(['messages' => ['Hello, world!']]);
            self::assertSame($message, $result['messages'][array_key_last($result['messages'])]->content);
        }
    }

    /**
     * The describe block shares one checkpointer and thread between its two cases, so the second case replays
     * the first one's invocations.
     *
     * @return array{0: MemorySaver, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private static function threadScenario(string $exitBehavior): array
    {
        $checkpointer = new MemorySaver();
        $config = ['configurable' => ['thread_id' => 'test-123']];
        $middleware = ModelCallLimitMiddleware::create(['threadLimit' => 3, 'exitBehavior' => $exitBehavior]);

        return [$checkpointer, $config, $middleware];
    }

    #[DataProvider('exitBehaviors')]
    public function testShouldNotThrowIfTheThreadLimitIsNotExceeded(string $exitBehavior): void
    {
        [$checkpointer, $config, $middleware] = self::threadScenario($exitBehavior);

        $model = AgentAssertions::fakeChat([self::toolCallMessage1('tool-call-message-1')]);
        $agent = Agent::create(['model' => $model, 'tools' => self::tools(), 'checkpointer' => $checkpointer, 'middleware' => [$middleware]]);
        $agent->invoke(['messages' => ['Hello, world!']], $config);

        $agent2 = Agent::create(['model' => $model, 'tools' => self::tools(), 'middleware' => [$middleware], 'checkpointer' => $checkpointer]);
        $result = $agent2->invoke(['messages' => ['Hello, world!']], $config);

        self::assertSame(0, $result['runModelCallCount']);
        self::assertSame(3, $result['threadModelCallCount']);
        if ($exitBehavior === 'end') {
            self::assertStringNotContainsString('Model call limits exceeded', (string) $result['messages'][array_key_last($result['messages'])]->content);
        }
    }

    #[DataProvider('exitBehaviors')]
    public function testShouldThrowAnErrorIfTheThreadLimitIsExceeded(string $exitBehavior): void
    {
        [$checkpointer, $config, $middleware] = self::threadScenario($exitBehavior);

        $model = AgentAssertions::fakeChat([self::toolCallMessage1('tool-call-message-1')]);
        $agent = Agent::create(['model' => $model, 'tools' => self::tools(), 'checkpointer' => $checkpointer, 'middleware' => [$middleware]]);
        $agent->invoke(['messages' => ['Hello, world!']], $config);
        $agent2 = Agent::create(['model' => $model, 'tools' => self::tools(), 'middleware' => [$middleware], 'checkpointer' => $checkpointer]);
        $agent2->invoke(['messages' => ['Hello, world!']], $config);

        $agent3 = Agent::create(['model' => $model, 'tools' => self::tools(), 'checkpointer' => $checkpointer, 'middleware' => [$middleware]]);
        $message = 'Model call limits exceeded: thread level call limit reached with 3 model calls';
        if ($exitBehavior === 'error') {
            try {
                $agent3->invoke(['messages' => ['Hello, world!']], $config);
                self::fail('the invocation should have thrown');
            } catch (\Throwable $e) {
                self::assertSame($message, $e->getMessage());
            }
        } else {
            $result = $agent3->invoke(['messages' => ['Hello, world!']], $config);
            self::assertSame($message, $result['messages'][array_key_last($result['messages'])]->content);
        }
    }
}
