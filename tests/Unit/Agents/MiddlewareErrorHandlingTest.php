<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Errors\MiddlewareError;
use LangGraph\Agents\Middleware;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Errors\GraphInterrupt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/tests/middlewareErrorHandling.test.ts`: GraphInterrupt and other GraphBubbleUp errors
 * are not wrapped in a MiddlewareError but bubble up unchanged.
 */
#[CoversClass(MiddlewareError::class)]
#[CoversClass(Middleware::class)]
final class MiddlewareErrorHandlingTest extends TestCase
{
    private static function passThrough(string $name): array
    {
        return Middleware::create([
            'name' => $name,
            'wrapToolCall' => static fn (array $request, callable $handler): mixed => $handler($request),
        ]);
    }

    private static function interruptTool(string $name, string $description, mixed $value, ?\ArrayObject $calls = null): \LangChain\Tools\StructuredTool
    {
        return tool(
            static function () use ($value, $calls): never {
                $calls?->append(1);

                throw new GraphInterrupt([['value' => $value]]);
            },
            ['name' => $name, 'description' => $description, 'schema' => Schema::object([])],
        );
    }

    public function testShouldPreserveGraphInterruptInterruptsPropertyThroughMiddlewareWhenUsingCheckpointer(): void
    {
        $interruptValue = ['action' => 'approve', 'data' => ['id' => 123]];

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'interrupt_tool', 'args' => [], 'id' => 'call_1']]]]),
            'tools' => [self::interruptTool('interrupt_tool', 'A tool that throws a GraphInterrupt', $interruptValue)],
            // This middleware wraps the tool call: GraphInterrupt should pass through.
            'middleware' => [self::passThrough('testMiddleware')],
            'checkpointer' => new MemorySaver(),
        ]);

        // With a checkpointer, a GraphInterrupt is reported by the run rather than thrown.
        $interrupts = AgentAssertions::interrupts(
            $agent,
            ['messages' => [new HumanMessage('test')]],
            ['configurable' => ['thread_id' => 'test-single-middleware']],
        );

        // The interrupt is preserved with all its properties.
        self::assertCount(1, $interrupts);
        self::assertSame($interruptValue, $interrupts[0]['value']);
    }

    public function testShouldPreserveGraphInterruptThroughMultipleMiddlewareLayers(): void
    {
        $interruptValue = ['action' => 'approve', 'data' => ['id' => 456]];

        // Multiple middleware layers: all pass the GraphInterrupt through unchanged.
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'interrupt_tool', 'args' => [], 'id' => 'call_1']]]]),
            'tools' => [self::interruptTool('interrupt_tool', 'A tool that throws a GraphInterrupt', $interruptValue)],
            'middleware' => [self::passThrough('outerMiddleware'), self::passThrough('innerMiddleware')],
            'checkpointer' => new MemorySaver(),
        ]);

        $interrupts = AgentAssertions::interrupts(
            $agent,
            ['messages' => [new HumanMessage('test')]],
            ['configurable' => ['thread_id' => 'test-multi-middleware']],
        );

        self::assertCount(1, $interrupts);
        self::assertSame($interruptValue, $interrupts[0]['value']);
    }

    public function testShouldPreserveGraphInterruptFromNestedSubagentThroughParentMiddleware(): void
    {
        // An inner agent whose tool throws a GraphInterrupt.
        $innerAgent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'inner_interrupt', 'args' => [], 'id' => 'inner_1']]]]),
            'tools' => [self::interruptTool('inner_interrupt', 'Inner tool that interrupts', 'subagent-interrupt')],
            'middleware' => [self::passThrough('innerMiddleware')],
            'checkpointer' => new MemorySaver(),
        ]);

        // An outer agent with a tool that invokes the inner agent.
        $subAgentTool = tool(
            static function () use ($innerAgent): string {
                // The inner agent run reports its interrupts.
                $interrupts = AgentAssertions::interrupts(
                    $innerAgent,
                    ['messages' => [new HumanMessage('trigger interrupt')]],
                    ['configurable' => ['thread_id' => 'inner-thread']],
                );
                // If the inner agent interrupted, propagate it.
                if ($interrupts !== []) {
                    throw new GraphInterrupt($interrupts);
                }

                return 'success';
            },
            ['name' => 'subagent_tool', 'description' => 'A tool that spawns a sub-agent', 'schema' => Schema::object([])],
        );

        $outerAgent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'subagent_tool', 'args' => [], 'id' => 'outer_1']]]]),
            'tools' => [$subAgentTool],
            'middleware' => [self::passThrough('outerMiddleware')],
            'checkpointer' => new MemorySaver(),
        ]);

        $interrupts = AgentAssertions::interrupts(
            $outerAgent,
            ['messages' => [new HumanMessage('test')]],
            ['configurable' => ['thread_id' => 'outer-thread']],
        );

        // The GraphInterrupt from the inner agent bubbles up correctly.
        self::assertCount(1, $interrupts);
        self::assertSame('subagent-interrupt', $interrupts[0]['value']);
    }

    public function testShouldPreserveErrorsFromTheDownstreamHandler(): void
    {
        $originalError = new \Exception('regular error');
        $errorTool = tool(
            static function () use ($originalError): never {
                throw $originalError;
            },
            ['name' => 'error_tool', 'description' => 'A tool that throws a regular error', 'schema' => Schema::object([])],
        );

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'error_tool', 'args' => [], 'id' => 'call_1']]]]),
            'tools' => [$errorTool],
            'middleware' => [self::passThrough('testMiddleware')],
        ]);

        try {
            $agent->invoke(['messages' => [new HumanMessage('test')]]);
            self::fail('The tool error should surface.');
        } catch (\Throwable $thrown) {
            // The very same error, not a wrapper.
            self::assertSame($originalError, $thrown);
        }
    }

    public function testShouldWrapErrorsThrownByMiddleware(): void
    {
        $middlewareError = new \Exception('middleware failed');
        $okTool = tool(static fn (): string => 'ok', ['name' => 'ok_tool', 'description' => 'A tool that succeeds', 'schema' => Schema::object([])]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'ok_tool', 'args' => [], 'id' => 'call_1']]]]),
            'tools' => [$okTool],
            'middleware' => [Middleware::create([
                'name' => 'testMiddleware',
                'wrapToolCall' => static function () use ($middlewareError): never {
                    throw $middlewareError;
                },
            ])],
        ]);

        try {
            $agent->invoke(['messages' => [new HumanMessage('test')]]);
            self::fail('Should have thrown MiddlewareError');
        } catch (\Throwable $error) {
            self::assertTrue(MiddlewareError::isInstance($error));
            self::assertSame($middlewareError, $error->getPrevious());
        }
    }

    public function testShouldHandleGraphInterruptWithCheckpointerForResumeFlow(): void
    {
        $toolCalled = new \ArrayObject();

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'interrupt_tool', 'args' => [], 'id' => 'call_1']], []]]),
            'tools' => [self::interruptTool('interrupt_tool', 'A tool that requires approval', 'needs-approval', $toolCalled)],
            'middleware' => [self::passThrough('testMiddleware')],
            'checkpointer' => new MemorySaver(),
        ]);

        // The first invocation interrupts.
        $interrupts = AgentAssertions::interrupts(
            $agent,
            ['messages' => [new HumanMessage('test')]],
            ['configurable' => ['thread_id' => 'test-resume-thread']],
        );

        // The interrupt is reported (not thrown as an error when using a checkpointer).
        self::assertCount(1, $interrupts);
        self::assertSame('needs-approval', $interrupts[0]['value']);
        self::assertCount(1, $toolCalled);
    }

    public function testMiddlewareErrorWrapShouldReturnGraphInterruptUnchanged(): void
    {
        $interrupt = new GraphInterrupt([['value' => 'test']]);

        $wrapped = MiddlewareError::wrap($interrupt, 'testMiddleware');

        self::assertSame($interrupt, $wrapped);
        self::assertInstanceOf(GraphInterrupt::class, $wrapped);
    }

    public function testMiddlewareErrorWrapShouldWrapRegularErrorsInMiddlewareError(): void
    {
        $error = new \Exception('test error');

        $wrapped = MiddlewareError::wrap($error, 'testMiddleware');

        self::assertInstanceOf(MiddlewareError::class, $wrapped);
        self::assertSame('test error', $wrapped->getMessage());
        self::assertSame($error, $wrapped->getPrevious());
    }

    public function testMiddlewareErrorWrapShouldWrapNonErrorValuesInMiddlewareError(): void
    {
        $wrapped = MiddlewareError::wrap('string error', 'testMiddleware');

        self::assertInstanceOf(MiddlewareError::class, $wrapped);
        self::assertSame('string error', $wrapped->getMessage());
    }
}
