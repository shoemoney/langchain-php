<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Checkpoint\MemorySaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/middleware/tests/dynamicTools.test.ts`: middleware registering and handling tools that
 * were not declared when the agent was created.
 */
#[CoversClass(Middleware::class)]
final class MiddlewareDynamicToolsTest extends TestCase
{
    /** A static tool that is always available. */
    private static function staticTool(): StructuredTool
    {
        return tool(
            static fn (array $in): string => 'Static result: ' . $in['value'],
            ['name' => 'static_tool', 'description' => 'A static tool that is always available', 'schema' => Schema::object(['value' => ['type' => 'string']], ['value'])],
        );
    }

    /** A dynamic tool, registered at runtime via middleware. */
    private static function dynamicTool(): StructuredTool
    {
        return tool(
            static fn (array $in): string => 'Dynamic result: ' . $in['value'],
            ['name' => 'dynamic_tool', 'description' => 'A dynamically registered tool', 'schema' => Schema::object(['value' => ['type' => 'string']], ['value'])],
        );
    }

    private static function sumTool(): StructuredTool
    {
        return tool(
            static fn (array $in): string => 'Sum: ' . ($in['x'] + $in['y']),
            ['name' => 'calculate_sum', 'description' => 'Another dynamically registered tool for calculations', 'schema' => Schema::object(['x' => ['type' => 'number'], 'y' => ['type' => 'number']], ['x', 'y'])],
        );
    }

    /**
     * @param list<list<array<string, mixed>>> $toolCalls
     * @param list<array<string, mixed>>       $middleware
     * @param list<StructuredTool>|null        $tools
     */
    private static function agent(array $toolCalls, array $middleware, ?array $tools = null, array $extra = []): \LangGraph\Agents\ReactAgent
    {
        return Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => $toolCalls]),
            'tools' => $tools ?? [self::staticTool()],
            'middleware' => $middleware,
            ...$extra,
        ]);
    }

    /** Supplies the implementation of `dynamic_tool` (and optionally `calculate_sum`). */
    private static function provider(string $name = 'dynamicToolMiddleware'): array
    {
        return Middleware::create([
            'name' => $name,
            'wrapToolCall' => static function (array $request, callable $handler): mixed {
                if ($request['toolCall']['name'] === 'dynamic_tool') {
                    return $handler([...$request, 'tool' => self::dynamicTool()]);
                }

                return $handler($request);
            },
        ]);
    }

    /** @return list<ToolMessage> */
    private static function toolMessages(array $result): array
    {
        return AgentAssertions::ofType($result['messages'], ToolMessage::class);
    }

    public function testShouldAllowMiddlewareToHandleDynamicallyRegisteredToolsViaWrapToolCall(): void
    {
        $seenTool = 'not-set';
        $middleware = Middleware::create([
            'name' => 'dynamicToolMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler) use (&$seenTool): mixed {
                if ($request['toolCall']['name'] === 'dynamic_tool') {
                    // request.tool is undefined for unregistered tools.
                    $seenTool = $request['tool'];

                    // Provide the tool implementation by spreading the request.
                    return $handler([...$request, 'tool' => self::dynamicTool()]);
                }

                return $handler($request);
            },
        ]);
        $agent = self::agent([[['name' => 'dynamic_tool', 'args' => ['value' => 'test'], 'id' => 'call_1']], []], [$middleware]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the dynamic tool')]]);

        self::assertNull($seenTool);
        $toolMessages = self::toolMessages($result);
        self::assertCount(1, $toolMessages);
        self::assertSame('Dynamic result: test', $toolMessages[0]->content);
        self::assertSame('dynamic_tool', $toolMessages[0]->name);
    }

    public function testShouldHandleBothStaticAndDynamicToolsInTheSameExecution(): void
    {
        $agent = self::agent([[
            ['name' => 'static_tool', 'args' => ['value' => 'static'], 'id' => 'call_1'],
            ['name' => 'dynamic_tool', 'args' => ['value' => 'dynamic'], 'id' => 'call_2'],
        ], []], [self::provider()]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use both tools')]]);

        $toolMessages = self::toolMessages($result);
        self::assertCount(2, $toolMessages);
        $byName = array_column(array_map(static fn (ToolMessage $m): array => ['name' => $m->name, 'content' => $m->content], $toolMessages), 'content', 'name');
        self::assertSame('Static result: static', $byName['static_tool']);
        self::assertSame('Dynamic result: dynamic', $byName['dynamic_tool']);
    }

    public function testShouldGracefullyReturnErrorWhenMiddlewareDoesNotHandleUnregisteredTool(): void
    {
        // A middleware that doesn't handle the dynamic tool (passthrough).
        $passthrough = Middleware::create([
            'name' => 'passthroughMiddleware',
            'wrapToolCall' => static fn (array $request, callable $handler): mixed => $handler($request),
        ]);
        $agent = self::agent([[['name' => 'nonexistent_tool', 'args' => [], 'id' => 'call_1']], []], [$passthrough]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use nonexistent tool')]]);

        $toolMessages = self::toolMessages($result);
        self::assertCount(1, $toolMessages);
        self::assertSame('error', $toolMessages[0]->additional_kwargs['status'] ?? null);
        self::assertStringContainsString('nonexistent_tool is not a valid tool', $toolMessages[0]->content);
        self::assertStringContainsString('static_tool', $toolMessages[0]->content);
    }

    public function testShouldAllowOverrideOfToolCallParameters(): void
    {
        $modifying = Middleware::create([
            'name' => 'modifyingMiddleware',
            // Modify the tool call args using a spread.
            'wrapToolCall' => static fn (array $request, callable $handler): mixed => $handler([
                ...$request,
                'toolCall' => [...$request['toolCall'], 'args' => ['value' => 'modified']],
            ]),
        ]);
        $agent = self::agent([[['name' => 'static_tool', 'args' => ['value' => 'original'], 'id' => 'call_1']], []], [$modifying]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the tool')]]);

        $toolMessages = self::toolMessages($result);
        self::assertCount(1, $toolMessages);
        self::assertSame('Static result: modified', $toolMessages[0]->content);
    }

    public function testShouldAllowChainedSpreadModifications(): void
    {
        $middleware = Middleware::create([
            'name' => 'chainingMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler): mixed {
                // Chain multiple modifications using spreads.
                $step1 = [...$request, 'toolCall' => [...$request['toolCall'], 'args' => ['value' => 'step1']]];
                $step2 = [...$step1, 'toolCall' => [...$step1['toolCall'], 'args' => ['value' => 'step2']]];

                return $handler($step2);
            },
        ]);
        $agent = self::agent([[['name' => 'static_tool', 'args' => ['value' => 'original'], 'id' => 'call_1']], []], [$middleware]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the tool')]]);

        self::assertSame('Static result: step2', self::toolMessages($result)[0]->content);
    }

    public function testShouldWorkWithOnlyDynamicToolsViaMiddleware(): void
    {
        $dynamicOnly = Middleware::create([
            'name' => 'dynamicOnlyMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler): mixed {
                if ($request['toolCall']['name'] === 'dynamic_tool') {
                    return $handler([...$request, 'tool' => self::dynamicTool()]);
                }
                if ($request['toolCall']['name'] === 'calculate_sum') {
                    return $handler([...$request, 'tool' => self::sumTool()]);
                }

                return $handler($request);
            },
        ]);

        // An agent with NO tools, only middleware.
        $agent = self::agent([[
            ['name' => 'dynamic_tool', 'args' => ['value' => 'hello'], 'id' => 'call_1'],
            ['name' => 'calculate_sum', 'args' => ['x' => 5, 'y' => 3], 'id' => 'call_2'],
        ], []], [$dynamicOnly], []);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the dynamic tools')]]);

        $toolMessages = self::toolMessages($result);
        self::assertCount(2, $toolMessages);
        $byName = array_column(array_map(static fn (ToolMessage $m): array => ['name' => $m->name, 'content' => $m->content], $toolMessages), 'content', 'name');
        self::assertSame('Dynamic result: hello', $byName['dynamic_tool']);
        self::assertSame('Sum: 8', $byName['calculate_sum']);
    }

    public function testShouldPreserveMiddlewareChainOrderWithDynamicTools(): void
    {
        $callLog = [];

        $logging = Middleware::create([
            'name' => 'loggingMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler) use (&$callLog): mixed {
                $callLog[] = 'logging_before';
                $result = $handler($request);
                $callLog[] = 'logging_after';

                return $result;
            },
        ]);
        $dynamic = Middleware::create([
            'name' => 'dynamicToolMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler) use (&$callLog): mixed {
                $callLog[] = 'dynamic_before';
                $result = $request['toolCall']['name'] === 'dynamic_tool'
                    ? $handler([...$request, 'tool' => self::dynamicTool()])
                    : $handler($request);
                $callLog[] = 'dynamic_after';

                return $result;
            },
        ]);
        $agent = self::agent([[['name' => 'dynamic_tool', 'args' => ['value' => 'test'], 'id' => 'call_1']], []], [$logging, $dynamic]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the dynamic tool')]]);

        // The middleware chain ran in the correct order.
        self::assertSame(['logging_before', 'dynamic_before', 'dynamic_after', 'logging_after'], $callLog);
        $toolMessages = self::toolMessages($result);
        self::assertCount(1, $toolMessages);
        self::assertSame('Dynamic result: test', $toolMessages[0]->content);
    }

    private static function errorTool(): StructuredTool
    {
        return tool(
            static function (): never {
                throw new \Exception('Dynamic tool error');
            },
            ['name' => 'error_tool', 'description' => 'A tool that throws an error', 'schema' => Schema::object([])],
        );
    }

    public function testShouldHandleErrorsFromDynamicToolsWhenMiddlewareCatchesThem(): void
    {
        // The middleware has to catch the errors of dynamic tools itself: by default middleware errors re-throw.
        $errorMiddleware = Middleware::create([
            'name' => 'errorMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler): mixed {
                if ($request['toolCall']['name'] === 'error_tool') {
                    try {
                        return $handler([...$request, 'tool' => self::errorTool()]);
                    } catch (\Throwable $e) {
                        // Convert the error to a ToolMessage so the LLM can see it.
                        return new ToolMessage([
                            'content' => $e->getMessage() . "\n Please fix your mistakes.",
                            'tool_call_id' => $request['toolCall']['id'],
                            'name' => 'error_tool',
                        ]);
                    }
                }

                return $handler($request);
            },
        ]);
        $agent = self::agent([[['name' => 'error_tool', 'args' => [], 'id' => 'call_1']], []], [$errorMiddleware]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the error tool')]]);

        $toolMessages = self::toolMessages($result);
        self::assertCount(1, $toolMessages);
        self::assertStringContainsString('Dynamic tool error', $toolMessages[0]->content);
    }

    public function testShouldLetMiddlewareHandleErrorsFromDynamicTools(): void
    {
        $errorHandling = Middleware::create([
            'name' => 'errorHandlingMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler): mixed {
                if ($request['toolCall']['name'] === 'error_tool') {
                    try {
                        return $handler([...$request, 'tool' => self::errorTool()]);
                    } catch (\Throwable) {
                        // Handle the error and return a custom message.
                        return new ToolMessage(['content' => 'Error handled by middleware', 'tool_call_id' => $request['toolCall']['id'], 'name' => 'error_tool']);
                    }
                }

                return $handler($request);
            },
        ]);
        $agent = self::agent([[['name' => 'error_tool', 'args' => [], 'id' => 'call_1']], []], [$errorHandling]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the error tool')]]);

        $toolMessages = self::toolMessages($result);
        self::assertCount(1, $toolMessages);
        self::assertSame('Error handled by middleware', $toolMessages[0]->content);
    }

    public function testShouldHaveToolDefinedForRegisteredTools(): void
    {
        $capturedTool = null;
        $inspecting = Middleware::create([
            'name' => 'inspectingMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler) use (&$capturedTool): mixed {
                $capturedTool = $request['tool'];

                return $handler($request);
            },
        ]);
        $agent = self::agent([[['name' => 'static_tool', 'args' => ['value' => 'test'], 'id' => 'call_1']], []], [$inspecting]);

        $agent->invoke(['messages' => [new HumanMessage('Use the static tool')]]);

        self::assertNotNull($capturedTool);
        self::assertSame('static_tool', $capturedTool->name);
    }

    public function testShouldHaveToolUndefinedForUnregisteredTools(): void
    {
        $capturedTool = 'not-set';
        $inspecting = Middleware::create([
            'name' => 'inspectingMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler) use (&$capturedTool): mixed {
                $capturedTool = $request['tool'];
                if ($request['toolCall']['name'] === 'dynamic_tool') {
                    return $handler([...$request, 'tool' => self::dynamicTool()]);
                }

                return $handler($request);
            },
        ]);
        $agent = self::agent([[['name' => 'dynamic_tool', 'args' => ['value' => 'test'], 'id' => 'call_1']], []], [$inspecting]);

        $agent->invoke(['messages' => [new HumanMessage('Use the dynamic tool')]]);

        self::assertNull($capturedTool);
    }

    public function testShouldAllowAddingNewToolsInWrapModelCallWhenWrapToolCallIsProvided(): void
    {
        $dynamicToolMiddleware = Middleware::create([
            'name' => 'dynamicToolMiddleware',
            // Add the dynamic tool to the request: the documented pattern.
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([...$request, 'tools' => [...$request['tools'], self::dynamicTool()]]),
            'wrapToolCall' => static function (array $request, callable $handler): mixed {
                if ($request['toolCall']['name'] === 'dynamic_tool') {
                    return $handler([...$request, 'tool' => self::dynamicTool()]);
                }

                return $handler($request);
            },
        ]);
        $agent = self::agent([[['name' => 'dynamic_tool', 'args' => ['value' => 'test'], 'id' => 'call_1']], []], [$dynamicToolMiddleware]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the dynamic tool')]]);

        $toolMessages = self::toolMessages($result);
        self::assertCount(1, $toolMessages);
        self::assertSame('Dynamic result: test', $toolMessages[0]->content);
    }

    public function testShouldRejectAddingNewToolsInWrapModelCallWhenNoWrapToolCallExists(): void
    {
        $noToolCall = Middleware::create([
            'name' => 'noToolCallMiddleware',
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([...$request, 'tools' => [...$request['tools'], self::dynamicTool()]]),
        ]);
        $agent = self::agent([[], []], [$noToolCall]);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/You have added a new tool in "wrapModelCall".*wrapToolCall/');

        $agent->invoke(['messages' => [new HumanMessage('Use the dynamic tool')]]);
    }

    public function testShouldRejectReplacingAnExistingToolWithADifferentInstance(): void
    {
        // A different tool instance with the same name as the static tool.
        $replacement = tool(
            static fn (array $in): string => 'Replaced result: ' . $in['value'],
            ['name' => 'static_tool', 'description' => 'A replacement tool', 'schema' => Schema::object(['value' => ['type' => 'string']], ['value'])],
        );
        $replacing = Middleware::create([
            'name' => 'replacingMiddleware',
            // Replace the existing tool with a different instance.
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([
                ...$request,
                'tools' => array_map(static fn (mixed $t): mixed => $t->name === 'static_tool' ? $replacement : $t, $request['tools']),
            ]),
            'wrapToolCall' => static fn (array $request, callable $handler): mixed => $handler($request),
        ]);
        $agent = self::agent([[], []], [$replacing]);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/You have modified a tool in "wrapModelCall"/');

        $agent->invoke(['messages' => [new HumanMessage('Use the tool')]]);
    }

    public function testShouldAllowAddingToolsWhenWrapToolCallIsOnASeparateMiddleware(): void
    {
        // Middleware A adds tools (wrapModelCall only).
        $adder = Middleware::create([
            'name' => 'adderMiddleware',
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([...$request, 'tools' => [...$request['tools'], self::dynamicTool()]]),
        ]);
        // Middleware B handles execution (wrapToolCall only).
        $executor = self::provider('executorMiddleware');
        $agent = self::agent([[['name' => 'dynamic_tool', 'args' => ['value' => 'split'], 'id' => 'call_1']], []], [$adder, $executor]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the dynamic tool')]]);

        $toolMessages = self::toolMessages($result);
        self::assertCount(1, $toolMessages);
        self::assertSame('Dynamic result: split', $toolMessages[0]->content);
    }

    public function testShouldWorkWithDynamicToolsAcrossMultipleInvocationsWithACheckpointer(): void
    {
        $agent = self::agent(
            [
                [['name' => 'dynamic_tool', 'args' => ['value' => 'first'], 'id' => 'call_1']],
                [],
                [['name' => 'dynamic_tool', 'args' => ['value' => 'second'], 'id' => 'call_2']],
                [],
            ],
            [self::provider()],
            null,
            ['checkpointer' => new MemorySaver()],
        );

        $config = ['configurable' => ['thread_id' => 'test-thread']];

        // The first invocation.
        $result1 = $agent->invoke(['messages' => [new HumanMessage('First call')]], $config);
        $toolMessages1 = self::toolMessages($result1);
        self::assertCount(1, $toolMessages1);
        self::assertSame('Dynamic result: first', $toolMessages1[0]->content);

        // The second invocation.
        $result2 = $agent->invoke(['messages' => [new HumanMessage('Second call')]], $config);
        $toolMessages2 = self::toolMessages($result2);
        self::assertCount(2, $toolMessages2);
        self::assertSame('Dynamic result: second', $toolMessages2[1]->content);
    }
}
