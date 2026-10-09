<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\ToolErrorMiddleware;
use LangGraph\Agents\Middleware\ToolRetryMiddleware;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Errors\GraphInterrupt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/middleware/tests/toolError.test.ts`.
 *
 * `error.name` is the short class name here. The async handler case returns its content blocks directly,
 * as there are no promises.
 */
#[CoversClass(ToolErrorMiddleware::class)]
final class ToolErrorMiddlewareTest extends TestCase
{
    private static function failingTool(): StructuredTool
    {
        return self::throwing(static fn (array $in): \Throwable => new SecretToolError($in['value']), 'Tool that always fails');
    }

    /** @param \Closure(array<string, mixed>): \Throwable $makeError */
    private static function throwing(\Closure $makeError, string $description): StructuredTool
    {
        return tool(
            static function (array $in) use ($makeError): never {
                throw $makeError($in);
            },
            ['name' => 'failing_tool', 'description' => $description, 'schema' => Schema::object(['value' => ['type' => 'string']], ['value'])],
        );
    }

    private static function model(string $toolName = 'failing_tool'): FakeToolCallingModel
    {
        return new FakeToolCallingModel(['toolCalls' => [[['name' => $toolName, 'args' => ['value' => 'x'], 'id' => 'call_1']], []]]);
    }

    /** @return list<ToolMessage> */
    private static function toolMessages(array $result): array
    {
        return AgentAssertions::ofType($result['messages'], ToolMessage::class);
    }

    public function testReturnsHandlerContentAsAnErrorToolMessage(): void
    {
        $calls = [];
        $onError = static function (mixed $error, array $request) use (&$calls): string {
            $calls[] = [$error, $request];
            $errorName = $error instanceof \Throwable ? (new \ReflectionClass($error))->getShortName() : 'UnknownError';

            return "Tool failed with {$errorName}.";
        };
        $agent = Agent::create(['model' => self::model(), 'tools' => [self::failingTool()], 'middleware' => [ToolErrorMiddleware::create(['onError' => $onError])]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the failing tool')]]);

        $toolMessages = self::toolMessages($result);
        self::assertCount(1, $toolMessages);
        self::assertSame('Tool failed with SecretToolError.', $toolMessages[0]->content);
        self::assertSame('failing_tool', $toolMessages[0]->name);
        self::assertSame('error', $toolMessages[0]->additional_kwargs['status']);
        self::assertSame('call_1', $toolMessages[0]->toolCallId);
        self::assertStringNotContainsString('secret detail', $toolMessages[0]->content);
        self::assertCount(1, $calls);
        self::assertSame('failing_tool', $calls[0][1]['toolCall']['name']);
    }

    public function testPropagatesTheOriginalErrorWhenTheHandlerReturnsNothing(): void
    {
        $originalError = new SecretToolError('x');
        $identityTool = self::throwing(static fn (): \Throwable => $originalError, 'Tool that throws a fixed error');
        $seen = [];
        $onError = static function (mixed $error) use (&$seen): null {
            $seen[] = $error;

            return null;
        };
        $agent = Agent::create(['model' => self::model(), 'tools' => [$identityTool], 'middleware' => [ToolErrorMiddleware::create(['onError' => $onError])]]);

        try {
            $agent->invoke(['messages' => [new HumanMessage('Use the failing tool')]]);
            self::fail('the original error should have propagated');
        } catch (\Throwable $thrown) {
            self::assertSame($originalError, $thrown);
        }

        self::assertSame($originalError, $seen[0]);
        self::assertInstanceOf(SecretToolError::class, $originalError);
    }

    public function testHandlesTheOriginalErrorAfterRetriesAreExhausted(): void
    {
        $originalError = new SecretToolError('retry');
        $attempts = 0;
        $retryingTool = tool(
            static function () use (&$attempts, $originalError): never {
                ++$attempts;

                throw $originalError;
            },
            ['name' => 'failing_tool', 'description' => 'Tool that fails until retries are exhausted', 'schema' => Schema::object(['value' => ['type' => 'string']], ['value'])],
        );
        $seen = [];
        $onError = static function (mixed $error) use (&$seen): ?string {
            $seen[] = $error;

            return $error instanceof SecretToolError ? 'handled after retries' : null;
        };
        $agent = Agent::create([
            'model' => self::model(),
            'tools' => [$retryingTool],
            'middleware' => [
                ToolErrorMiddleware::create(['onError' => $onError]),
                ToolRetryMiddleware::create(['maxRetries' => 2, 'initialDelayMs' => 0, 'jitter' => false, 'onFailure' => 'error']),
            ],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the failing tool')]]);

        self::assertSame(3, $attempts);
        self::assertCount(1, $seen);
        self::assertSame($originalError, $seen[0]);
        $toolMessages = self::toolMessages($result);
        self::assertSame('handled after retries', $toolMessages[0]->content);
        self::assertSame('error', $toolMessages[0]->additional_kwargs['status']);
    }

    public function testSupportsHandlersThatReturnContentBlocks(): void
    {
        $agent = Agent::create([
            'model' => self::model(),
            'tools' => [self::failingTool()],
            'middleware' => [ToolErrorMiddleware::create(['onError' => static fn (): array => [['type' => 'text', 'text' => 'The tool failed safely.']]])],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the failing tool')]]);

        $toolMessages = self::toolMessages($result);
        self::assertEquals([['type' => 'text', 'text' => 'The tool failed safely.']], $toolMessages[0]->content);
        self::assertSame('error', $toolMessages[0]->additional_kwargs['status']);
    }

    public function testAcceptsToolInstancesInTheFilter(): void
    {
        $failingTool = self::failingTool();
        $agent = Agent::create([
            'model' => self::model(),
            'tools' => [$failingTool],
            'middleware' => [ToolErrorMiddleware::create(['tools' => [$failingTool], 'onError' => static fn (): string => 'handled'])],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the failing tool')]]);

        self::assertSame('handled', self::toolMessages($result)[0]->content);
    }

    public function testBypassesToolsThatAreNotInTheFilter(): void
    {
        $calls = 0;
        $onError = static function () use (&$calls): string {
            ++$calls;

            return 'handled';
        };
        $agent = Agent::create([
            'model' => self::model(),
            'tools' => [self::failingTool()],
            'middleware' => [ToolErrorMiddleware::create(['tools' => ['other_tool'], 'onError' => $onError])],
        ]);

        try {
            $agent->invoke(['messages' => [new HumanMessage('Use the failing tool')]]);
            self::fail('the tool error should have propagated');
        } catch (\Throwable $thrown) {
            self::assertStringContainsString('secret detail: x', $thrown->getMessage());
        }

        self::assertSame(0, $calls);
    }

    public function testDoesNotPassLangGraphControlFlowErrorsToTheHandler(): void
    {
        $interruptValue = ['action' => 'approve'];
        $interruptTool = tool(
            static function () use ($interruptValue): never {
                throw new GraphInterrupt([['value' => $interruptValue]]);
            },
            ['name' => 'interrupt_tool', 'description' => 'Tool that interrupts', 'schema' => Schema::object([])],
        );
        $calls = 0;
        $onError = static function () use (&$calls): string {
            ++$calls;

            return 'handled';
        };
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'interrupt_tool', 'args' => [], 'id' => 'call_1']]]]),
            'tools' => [$interruptTool],
            'middleware' => [ToolErrorMiddleware::create(['onError' => $onError])],
            'checkpointer' => new MemorySaver(),
        ]);

        $interrupts = AgentAssertions::interrupts(
            $agent,
            ['messages' => [new HumanMessage('Use the interrupt tool')]],
            ['configurable' => ['thread_id' => 'tool-error-interrupt']],
        );

        self::assertSame($interruptValue, $interrupts[0]['value']);
        self::assertSame(0, $calls);
    }
}

class SecretToolError extends \Exception
{
    public function __construct(string $value)
    {
        parent::__construct("secret detail: {$value}");
    }
}
