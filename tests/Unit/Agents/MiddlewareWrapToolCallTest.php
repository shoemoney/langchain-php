<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Pregel\Command;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The `wrapToolCall` describe block of `langchain/src/agents/tests/middleware.test.ts`.
 */
#[CoversClass(Middleware::class)]
final class MiddlewareWrapToolCallTest extends TestCase
{
    public function testShouldAllowMiddlewareToWrapToolExecutionAndModifyArgsAndResponse(): void
    {
        $toolExecutions = [];
        $weatherToolCalls = 0;
        $weatherTool = tool(
            static function (array $in) use (&$weatherToolCalls): string {
                $weatherToolCalls++;

                return 'Weather in ' . $in['location'] . ': Sunny';
            },
            ['name' => 'get_weather', 'description' => 'Get weather for a location', 'schema' => Schema::object(['location' => ['type' => 'string']], ['location'])],
        );

        // Middleware that logs tool executions and modifies the result.
        $loggingMiddleware = Middleware::create([
            'name' => 'LoggingMiddleware',
            'contextSchema' => ['type' => 'object', 'properties' => ['foo' => ['type' => 'number']], 'required' => ['foo']],
            'stateSchema' => ['type' => 'object', 'properties' => ['bar' => ['type' => 'boolean']], 'required' => ['bar']],
            'wrapToolCall' => function (array $request, callable $handler) use (&$toolExecutions): ToolMessage {
                $toolExecutions[] = 'before:' . $request['toolCall']['name'];
                self::assertSame('get_weather', $request['tool']->name);
                self::assertSame('Get weather for a location', $request['tool']->description);
                self::assertEquals(['args' => ['location' => 'SF'], 'id' => '1', 'name' => 'get_weather', 'type' => 'tool_call'], $request['toolCall']);
                self::assertSame(['foo' => 123], $request['runtime']->context);
                self::assertTrue($request['state']['bar']);

                // Tool args can be modified.
                $request['toolCall']['args']['location'] .= 'O';

                $result = $handler($request);
                $toolExecutions[] = 'after:' . $request['toolCall']['name'];

                // A new ToolMessage with modified content.
                return new ToolMessage([
                    'content' => $result->content . ' (modified)',
                    'tool_call_id' => $result->toolCallId,
                    'name' => $result->name,
                ]);
            },
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'get_weather', 'args' => ['location' => 'SF'], 'id' => '1']]]]),
            'tools' => [$weatherTool],
            'middleware' => [$loggingMiddleware],
        ]);

        $result = $agent->invoke(
            ['messages' => [new HumanMessage("What's the weather in SF?")], 'bar' => true],
            ['context' => ['foo' => 123]],
        );

        self::assertSame(1, $weatherToolCalls);
        self::assertSame(['before:get_weather', 'after:get_weather'], $toolExecutions);
        self::assertCount(3, $result['messages']);
        // The middleware modified the content.
        self::assertSame('Weather in SFO: Sunny (modified)', $result['messages'][2]->content);
    }

    public function testShouldChainMultipleWrapToolCallHandlersCorrectly(): void
    {
        // The order is outer -> inner -> tool -> inner -> outer.
        $executionOrder = [];

        $calculatorTool = tool(
            static function (array $in) use (&$executionOrder): string {
                $executionOrder[] = 'tool_execute';

                return 'Result: ' . $in['expression'];
            },
            ['name' => 'calculator', 'description' => 'Calculate an expression', 'schema' => Schema::object(['expression' => ['type' => 'string']], ['expression'])],
        );

        $layer = static function (string $name, string $prefix) use (&$executionOrder): array {
            return Middleware::create([
                'name' => $name,
                'wrapToolCall' => static function (array $request, callable $handler) use (&$executionOrder, $prefix): mixed {
                    $executionOrder[] = $prefix . '_before';
                    $result = $handler($request);
                    $executionOrder[] = $prefix . '_after';

                    return $result;
                },
            ]);
        };

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'calculator', 'args' => ['expression' => '2+2'], 'id' => '1']]]]),
            'tools' => [$calculatorTool],
            'middleware' => [$layer('AuthMiddleware', 'auth'), $layer('CacheMiddleware', 'cache')],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('Calculate 2+2')]]);

        // auth wraps cache wraps the tool.
        self::assertSame(['auth_before', 'cache_before', 'tool_execute', 'cache_after', 'auth_after'], $executionOrder);
    }

    public function testShouldAllowMiddlewareToHandleToolErrors(): void
    {
        $failingTool = tool(
            static function (): void {
                throw new \Exception('Tool execution failed');
            },
            ['name' => 'failing_tool', 'description' => 'A tool that always fails', 'schema' => Schema::object([])],
        );

        // Middleware that catches errors and returns a custom message.
        $errorHandlerMiddleware = Middleware::create([
            'name' => 'ErrorHandlerMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler): ToolMessage {
                try {
                    return $handler($request);
                } catch (\Throwable $error) {
                    return new ToolMessage([
                        'content' => 'Error handled by middleware: ' . $error->getMessage(),
                        'tool_call_id' => $request['toolCall']['id'],
                    ]);
                }
            },
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'failing_tool', 'args' => [], 'id' => '1']]]]),
            'tools' => [$failingTool],
            'middleware' => [$errorHandlerMiddleware],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Call the failing tool')]]);

        self::assertStringContainsString('Error handled by middleware', $result['messages'][2]->content);
        self::assertStringContainsString('Tool execution failed', $result['messages'][2]->content);
    }

    public function testShouldProvideAccessToStateInWrapToolCall(): void
    {
        $capturedState = null;

        $echoTool = tool(
            static fn (array $in): string => 'Echo: ' . $in['message'],
            ['name' => 'echo', 'description' => 'Echo a message', 'schema' => Schema::object(['message' => ['type' => 'string']], ['message'])],
        );

        // Middleware that captures the state.
        $stateCaptureMiddleware = Middleware::create([
            'name' => 'StateCaptureMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['customField' => ['type' => 'string']], 'required' => ['customField']],
            'wrapToolCall' => static function (array $request, callable $handler) use (&$capturedState): mixed {
                $capturedState = $request['state'];

                return $handler($request);
            },
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'echo', 'args' => ['message' => 'hello'], 'id' => '1']]]]),
            'tools' => [$echoTool],
            'middleware' => [$stateCaptureMiddleware],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('Echo hello')], 'customField' => 'test_value']);

        self::assertNotNull($capturedState);
        self::assertSame('test_value', $capturedState['customField']);
        self::assertArrayHasKey('messages', $capturedState);
    }

    public function testShouldValidateThatWrapToolCallReturnsToolMessageOrCommand(): void
    {
        $validTool = tool(static fn (): string => 'Success', ['name' => 'valid_tool', 'description' => 'A valid tool', 'schema' => Schema::object([])]);

        // Middleware that returns an invalid type.
        $invalidMiddleware = Middleware::create([
            'name' => 'InvalidReturnMiddleware',
            'wrapToolCall' => static fn (): string => 'invalid return value',
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'valid_tool', 'args' => [], 'id' => '1']]]]),
            'tools' => [$validTool],
            'middleware' => [$invalidMiddleware],
        ]);

        // With the default error handling (as in Python), validation errors bubble up.
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage(
            'Invalid response from "wrapToolCall" in middleware "InvalidReturnMiddleware": expected ToolMessage or Command, got string'
        );

        $agent->invoke(['messages' => [new HumanMessage('Call the valid tool')]]);
    }

    public function testShouldSupportReturningCommandFromWrapToolCall(): void
    {
        $commandTool = tool(static fn (): string => 'Tool result', ['name' => 'command_tool', 'description' => 'A tool that can return commands', 'schema' => Schema::object([])]);

        $commandMiddleware = Middleware::create([
            'name' => 'CommandMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler): Command {
                // Execute the tool normally.
                $handler($request);

                // Return a Command instead of a ToolMessage.
                return new Command(update: [
                    'messages' => [new ToolMessage(['content' => 'Command-based response', 'tool_call_id' => $request['toolCall']['id']])],
                ]);
            },
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'command_tool', 'args' => [], 'id' => '1']]]]),
            'tools' => [$commandTool],
            'middleware' => [$commandMiddleware],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Call command tool')]]);

        self::assertSame('Command-based response', $result['messages'][2]->content);
    }

    public function testShouldWorkWithMultipleToolsBeingCalled(): void
    {
        $toolCalls = [];
        $tool1 = tool(static fn (): string => 'Result 1', ['name' => 'tool1', 'description' => 'First tool', 'schema' => Schema::object([])]);
        $tool2 = tool(static fn (): string => 'Result 2', ['name' => 'tool2', 'description' => 'Second tool', 'schema' => Schema::object([])]);

        $trackingMiddleware = Middleware::create([
            'name' => 'TrackingMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler) use (&$toolCalls): mixed {
                $toolCalls[] = $request['tool']->name;

                return $handler($request);
            },
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[
                ['name' => 'tool1', 'args' => [], 'id' => '1'],
                ['name' => 'tool2', 'args' => [], 'id' => '2'],
            ]]]),
            'tools' => [$tool1, $tool2],
            'middleware' => [$trackingMiddleware],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('Call both tools')]]);

        self::assertSame(['tool1', 'tool2'], $toolCalls);
    }

    public function testShouldWorkAlongsideWrapModelCallMiddleware(): void
    {
        // wrapToolCall and wrapModelCall coexist, and tool_calls modified in wrapModelCall reach wrapToolCall.
        $events = [];
        $capturedToolCallInWrapTool = null;

        $calculatorTool = tool(
            static fn (array $in): int|float => $in['x'] * 2,
            ['name' => 'multiply', 'description' => 'Multiply by 2', 'schema' => Schema::object(['x' => ['type' => 'number']], ['x'])],
        );

        $combinedMiddleware = Middleware::create([
            'name' => 'CombinedMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use (&$events): AIMessage {
                $events[] = 'before_model';
                $result = $handler($request);
                $events[] = 'after_model';

                // Modify the AIMessage tool_calls.
                if ($result->toolCalls !== []) {
                    return new AIMessage([
                        ...$result->kwargs(),
                        'tool_calls' => array_map(
                            static fn (array $tc): array => [...$tc, 'args' => [...$tc['args'], 'x' => $tc['args']['x'] * 10]],
                            $result->toolCalls,
                        ),
                    ]);
                }

                return $result;
            },
            'wrapToolCall' => static function (array $request, callable $handler) use (&$events, &$capturedToolCallInWrapTool): mixed {
                $events[] = 'before_tool';
                // Capture the tool call to verify that it was modified.
                $capturedToolCallInWrapTool = $request['toolCall'];
                $result = $handler($request);
                $events[] = 'after_tool';

                return $result;
            },
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'multiply', 'args' => ['x' => 5], 'id' => '1']]]]),
            'tools' => [$calculatorTool],
            'middleware' => [$combinedMiddleware],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('Multiply 5 by 2')]]);

        // 1st model call -> tool execution -> 2nd model call (to finish).
        self::assertSame(['before_model', 'after_model', 'before_tool', 'after_tool', 'before_model', 'after_model'], $events);

        // The modified tool call was propagated to wrapToolCall.
        self::assertSame('multiply', $capturedToolCallInWrapTool['name']);
        self::assertSame(50, $capturedToolCallInWrapTool['args']['x']);
    }

    public function testShouldAllowConditionalToolExecutionBasedOnState(): void
    {
        $adminTool = tool(static fn (): string => 'Admin action executed', ['name' => 'admin_action', 'description' => 'An admin-only action', 'schema' => Schema::object([])]);

        $authMiddleware = Middleware::create([
            'name' => 'AuthMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['isAdmin' => ['type' => 'boolean', 'default' => false]]],
            'wrapToolCall' => static function (array $request, callable $handler): mixed {
                // Check if the user is an admin.
                if ($request['tool']->name === 'admin_action' && !$request['state']['isAdmin']) {
                    return new ToolMessage(['content' => 'Access denied: admin privileges required', 'tool_call_id' => $request['toolCall']['id']]);
                }

                return $handler($request);
            },
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'admin_action', 'args' => [], 'id' => '1']]]]),
            'tools' => [$adminTool],
            'middleware' => [$authMiddleware],
        ]);

        // A non-admin user.
        $result1 = $agent->invoke(['messages' => [new HumanMessage('Perform admin action')], 'isAdmin' => false]);
        self::assertSame('Access denied: admin privileges required', $result1['messages'][2]->content);

        // An admin user.
        $result2 = $agent->invoke(['messages' => [new HumanMessage('Perform admin action')], 'isAdmin' => true]);
        self::assertSame('Admin action executed', $result2['messages'][2]->content);
    }

    public function testShouldSupportToolExecutionTimingAndMetrics(): void
    {
        $metrics = [];

        $slowTool = tool(
            static function (): string {
                usleep(15_000);

                return 'Slow result';
            },
            ['name' => 'slow_operation', 'description' => 'A slow operation', 'schema' => Schema::object([])],
        );

        $metricsMiddleware = Middleware::create([
            'name' => 'MetricsMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler) use (&$metrics): mixed {
                $startTime = microtime(true);
                $result = $handler($request);
                $metrics[] = ['tool' => $request['tool']->name, 'duration' => (microtime(true) - $startTime) * 1000];

                return $result;
            },
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'slow_operation', 'args' => [], 'id' => '1']]]]),
            'tools' => [$slowTool],
            'middleware' => [$metricsMiddleware],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('Run slow operation')]]);

        self::assertCount(1, $metrics);
        self::assertSame('slow_operation', $metrics[0]['tool']);
        self::assertGreaterThanOrEqual(10, $metrics[0]['duration']);
    }

    public function testShouldSupportRetryLogicWithExponentialBackoff(): void
    {
        $attemptCount = 0;
        $maxRetries = 3;

        $flakyTool = tool(
            static function () use (&$attemptCount): string {
                $attemptCount++;
                if ($attemptCount < 3) {
                    throw new \Exception('Attempt ' . $attemptCount . ' failed');
                }

                return 'Success on attempt 3';
            },
            ['name' => 'flaky_tool', 'description' => 'A flaky tool that fails twice', 'schema' => Schema::object([])],
        );

        $retryMiddleware = Middleware::create([
            'name' => 'RetryMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler) use ($maxRetries): mixed {
                for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
                    try {
                        return $handler($request);
                    } catch (\Throwable $error) {
                        if ($attempt === $maxRetries - 1) {
                            throw $error;
                        }
                        // A simple backoff for testing.
                        usleep(1000);
                    }
                }

                throw new \Exception('Unreachable');
            },
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'flaky_tool', 'args' => [], 'id' => '1']]]]),
            'tools' => [$flakyTool],
            'middleware' => [$retryMiddleware],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Call flaky tool')]]);

        // The tool succeeds after retries.
        self::assertSame(3, $attemptCount);
        self::assertSame('Success on attempt 3', $result['messages'][2]->content);
    }

    public function testShouldSupportCachingToolResults(): void
    {
        $cache = [];
        $executionCount = 0;

        $expensiveTool = tool(
            static function (array $in) use (&$executionCount): string {
                $executionCount++;

                return 'Expensive result for: ' . $in['input'];
            },
            ['name' => 'expensive_operation', 'description' => 'An expensive operation', 'schema' => Schema::object(['input' => ['type' => 'string']], ['input'])],
        );

        $cacheMiddleware = Middleware::create([
            'name' => 'CacheMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler) use (&$cache): mixed {
                $cacheKey = $request['tool']->name . ':' . json_encode($request['toolCall']['args']);

                // Check the cache first.
                if (isset($cache[$cacheKey])) {
                    return $cache[$cacheKey];
                }

                // Execute and cache.
                return $cache[$cacheKey] = $handler($request);
            },
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [
                [['name' => 'expensive_operation', 'args' => ['input' => 'test'], 'id' => '1']],
                [['name' => 'expensive_operation', 'args' => ['input' => 'test'], 'id' => '2']],
                [], // No more tool calls: the agent stops.
            ]]),
            'tools' => [$expensiveTool],
            'middleware' => [$cacheMiddleware],
        ]);

        // The first invocation executes the tool.
        $agent->invoke(['messages' => [new HumanMessage('Run expensive operation')]], ['recursionLimit' => 100]);
        self::assertSame(1, $executionCount);

        // The second invocation with the same args uses the cache.
        $agent->invoke(['messages' => [new HumanMessage('Run expensive operation again')]], ['recursionLimit' => 100]);
        self::assertSame(1, $executionCount);
    }

    public function testShouldAllowModifyingToolResultsBasedOnToolProperties(): void
    {
        $publicTool = tool(static fn (): string => 'Public data', ['name' => 'get_public_data', 'description' => 'Get public data', 'schema' => Schema::object([])]);
        $privateTool = tool(static fn (): string => 'Sensitive data', ['name' => 'get_private_data', 'description' => 'Get private data', 'schema' => Schema::object([])]);

        $redactionMiddleware = Middleware::create([
            'name' => 'RedactionMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler): ToolMessage {
                $result = $handler($request);

                // Redact private tool results.
                if (str_contains($request['tool']->name, 'private')) {
                    return new ToolMessage(['content' => '[REDACTED]', 'tool_call_id' => $result->toolCallId, 'name' => $result->name]);
                }

                return $result;
            },
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[
                ['name' => 'get_public_data', 'args' => [], 'id' => '1'],
                ['name' => 'get_private_data', 'args' => [], 'id' => '2'],
            ]]]),
            'tools' => [$publicTool, $privateTool],
            'middleware' => [$redactionMiddleware],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Get both data types')]]);

        self::assertSame('Public data', $result['messages'][2]->content);
        self::assertSame('[REDACTED]', $result['messages'][3]->content);
    }

    public function testShouldWorkWithThreeLayersOfMiddlewareChaining(): void
    {
        $executionFlow = [];

        $testTool = tool(
            static function () use (&$executionFlow): string {
                $executionFlow[] = 'tool';

                return 'result';
            },
            ['name' => 'test', 'description' => 'Test', 'schema' => Schema::object([])],
        );

        $layer = static function (string $name, string $prefix) use (&$executionFlow): array {
            return Middleware::create([
                'name' => $name,
                'wrapToolCall' => static function (array $request, callable $handler) use (&$executionFlow, $prefix): mixed {
                    $executionFlow[] = $prefix . '_before';
                    $result = $handler($request);
                    $executionFlow[] = $prefix . '_after';

                    return $result;
                },
            ]);
        };

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'test', 'args' => [], 'id' => '1']]]]),
            'tools' => [$testTool],
            'middleware' => [$layer('Layer1', 'layer1'), $layer('Layer2', 'layer2'), $layer('Layer3', 'layer3')],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('Test')]]);

        // The nesting: layer1 -> layer2 -> layer3 -> tool.
        self::assertSame(
            ['layer1_before', 'layer2_before', 'layer3_before', 'tool', 'layer3_after', 'layer2_after', 'layer1_after'],
            $executionFlow,
        );
    }
}
