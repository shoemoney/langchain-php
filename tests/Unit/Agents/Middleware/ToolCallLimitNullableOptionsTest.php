<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\ReactAgent;
use LangGraph\Agents\Middleware\ToolCallLimitExceededError;
use LangGraph\Agents\Middleware\ToolCallLimitMiddleware;
use LangGraph\Checkpoint\MemorySaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/** Actual toolCallLimit.ts factory behavior, rather than its uninvoked options schema. */
#[CoversClass(ToolCallLimitMiddleware::class)]
#[CoversClass(ToolCallLimitExceededError::class)]
final class ToolCallLimitNullableOptionsTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, int, int}> */
    public static function limits(): iterable
    {
        yield 'run null' => [['runLimit' => null], 0, 3];
        yield 'thread null' => [['threadLimit' => null], 0, 3];
        yield 'both null' => [['threadLimit' => null, 'runLimit' => null], 0, 3];
        yield 'thread two run null' => [['threadLimit' => 2, 'runLimit' => null], 0, 3];
        yield 'thread null run zero' => [['threadLimit' => null, 'runLimit' => 0], 0, 3];
        yield 'thread zero run null' => [['threadLimit' => 0, 'runLimit' => null], 0, 3];
        yield 'thread null run negative' => [['threadLimit' => null, 'runLimit' => -1], 0, 3];
        yield 'zero' => [['runLimit' => 0], 0, 3];
        yield 'negative' => [['runLimit' => -1], 0, 3];
        yield 'fractional' => [['runLimit' => 1.5], 1, 2];
        yield 'run two absent thread' => [['runLimit' => 2], 2, 1];
        yield 'thread two absent run' => [['threadLimit' => 2], 2, 1];
        yield 'infinity' => [['runLimit' => INF], 3, 0];
        yield 'nan' => [['runLimit' => NAN], 3, 0];
    }

    #[DataProvider('limits')]
    public function testPresentNullUsesZeroThresholdAndAbsentLimitDoesNot(array $options, int $allowed, int $blocked): void
    {
        $middleware = ToolCallLimitMiddleware::create($options);
        $result = ($middleware['afterModel']['hook'])(['messages' => [self::calls(3)]]);

        self::assertSame(['__all__' => $allowed], $result['threadToolCallCount']);
        self::assertSame(['__all__' => 3], $result['runToolCallCount']);
        $messages = $result['messages'] ?? [];
        self::assertCount($blocked, $messages);
        foreach ($messages as $index => $message) {
            self::assertInstanceOf(ToolMessage::class, $message);
            self::assertSame((string) ($allowed + $index + 1), $message->toolCallId);
            self::assertSame('test', $message->name);
            self::assertSame('error', $message->additional_kwargs['status']);
            self::assertSame('Tool call limit exceeded. Do not make additional tool calls.', $message->content);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function rejectedOrdering(): iterable
    {
        yield 'present null thread' => [['threadLimit' => null, 'runLimit' => 2], 'runLimit (2) cannot exceed threadLimit (null).'];
        yield 'present null run with negative thread' => [['threadLimit' => -1, 'runLimit' => null], 'runLimit (null) cannot exceed threadLimit (-1).'];
        yield 'fraction' => [['threadLimit' => 1, 'runLimit' => 1.5], 'runLimit (1.5) cannot exceed threadLimit (1).'];
    }

    #[DataProvider('rejectedOrdering')]
    public function testOrderingUsesPresentNullAsZeroButDisplaysOriginalValue(array $options, string $prefix): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($prefix . ' The run limit should be less than or equal to the thread limit.');
        ToolCallLimitMiddleware::create($options);
    }

    public function testBothAbsentLimitsAreStillRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one limit must be specified (threadLimit or runLimit)');
        ToolCallLimitMiddleware::create([]);
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function malformedLimits(): iterable
    {
        foreach (['threadLimit', 'runLimit'] as $field) {
            foreach (['numeric string' => '2', 'text' => 'no', 'true' => true, 'false' => false, 'list' => [], 'object' => new \stdClass()] as $name => $value) {
                yield $field . ' ' . $name => [$field, $value, get_debug_type($value)];
            }
        }
    }

    #[DataProvider('malformedLimits')]
    public function testPhpRejectsMalformedLimitsAtTheFactoryBoundary(string $field, mixed $value, string $type): void
    {
        // Explicit PHP adaptation: JavaScript's runtime coercion is outside int|float|null.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("{$field} must be an int, float or null; received {$type}.");
        ToolCallLimitMiddleware::create([$field => $value]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function nullableDiagnostics(): iterable
    {
        yield 'run' => [['runLimit' => null], 'run limit exceeded (3/null calls)'];
        yield 'thread' => [['threadLimit' => null], 'thread limit exceeded (3/null calls)'];
        yield 'both' => [['threadLimit' => null, 'runLimit' => null], 'thread limit exceeded (3/null calls) and run limit exceeded (3/null calls)'];
        yield 'hypothetical thread' => [['threadLimit' => 2, 'runLimit' => null], 'thread limit exceeded (3/2 calls) and run limit exceeded (3/null calls)'];
    }

    #[DataProvider('nullableDiagnostics')]
    public function testErrorPreservesRawNullAndReportsOnlyPresentExceededLimits(array $options, string $detail): void
    {
        $middleware = ToolCallLimitMiddleware::create([...$options, 'exitBehavior' => 'error']);
        try {
            ($middleware['afterModel']['hook'])(['messages' => [self::calls(3)]]);
            self::fail('The first matching call must exceed a present null limit');
        } catch (ToolCallLimitExceededError $error) {
            self::assertSame('ToolCallLimitExceededError', $error->errorName());
            self::assertSame(3, $error->threadCount);
            self::assertSame(3, $error->runCount);
            self::assertSame($options['threadLimit'] ?? null, $error->threadLimit);
            self::assertSame($options['runLimit'] ?? null, $error->runLimit);
            self::assertSame('Tool call limit reached: ' . $detail . '.', $error->getMessage());
        }
    }

    #[DataProvider('nullableDiagnostics')]
    public function testEndUsesTheSameNullableDiagnosticAndBlockedToolMessages(array $options, string $detail): void
    {
        $middleware = ToolCallLimitMiddleware::create([...$options, 'exitBehavior' => 'end']);
        $result = ($middleware['afterModel']['hook'])(['messages' => [self::calls(3)]]);

        self::assertSame('end', $result['jumpTo']);
        self::assertSame(['__all__' => 0], $result['threadToolCallCount']);
        self::assertSame(['__all__' => 3], $result['runToolCallCount']);
        self::assertCount(4, $result['messages']);
        self::assertInstanceOf(AIMessage::class, $result['messages'][3]);
        self::assertSame('Tool call limit reached: ' . $detail . '.', $result['messages'][3]->content);
    }

    public function testExistingErrorConstructorAndMessageHelperTreatUnflaggedNullAsAbsent(): void
    {
        $error = new ToolCallLimitExceededError(3, 3, null, 2);
        self::assertSame('Tool call limit reached: run limit exceeded (3/2 calls).', $error->getMessage());
        self::assertSame("'test' tool call limit reached: thread limit exceeded (3/2 calls).", ToolCallLimitExceededError::buildFinalAIMessageContent(3, 3, 2, null, 'test'));
    }

    public function testNullableNamedLimiterOnlyCountsMatchingTools(): void
    {
        $middleware = ToolCallLimitMiddleware::create(['toolName' => 'test', 'runLimit' => null, 'exitBehavior' => null]);
        $message = self::calls(1);
        $message->toolCalls[] = ['id' => 'other', 'name' => 'other', 'args' => []];
        $result = ($middleware['afterModel']['hook'])(['messages' => [$message], 'threadToolCallCount' => ['other' => 5], 'runToolCallCount' => ['other' => 6]]);

        self::assertSame(['other' => 5, 'test' => 0], $result['threadToolCallCount']);
        self::assertSame(['other' => 6, 'test' => 1], $result['runToolCallCount']);
        self::assertCount(1, $result['messages']);
        self::assertSame("Tool call limit exceeded. Do not call 'test' again.", $result['messages'][0]->content);
        self::assertSame(['runToolCallCount' => []], ($middleware['afterAgent'])([]));
    }

    public function testNullableEndRejectsOtherPendingToolTypes(): void
    {
        $middleware = ToolCallLimitMiddleware::create(['toolName' => 'test', 'threadLimit' => null, 'exitBehavior' => 'end']);
        $message = self::calls(1);
        $message->toolCalls[] = ['id' => 'other', 'name' => 'other', 'args' => []];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot end execution with other tool calls pending. Found calls to: other.');
        ($middleware['afterModel']['hook'])(['messages' => [$message]]);
    }

    public function testNullRunLimitBlocksRealAgentToolsAndResetsRunCountsAcrossInvocations(): void
    {
        $executed = 0;
        $agent = self::agent(['runLimit' => null, 'exitBehavior' => 'end'], $executed, [self::calls(1), self::calls(1)]);
        for ($run = 1; $run <= 2; ++$run) {
            $result = $agent->invoke(['messages' => [new HumanMessage('run ' . $run)]], ['configurable' => ['thread_id' => 'nullable-limit']]);
            self::assertSame(['__all__' => 0], $result['threadToolCallCount']);
            self::assertSame([], $result['runToolCallCount']);
            self::assertSame('Tool call limit reached: run limit exceeded (1/null calls).', end($result['messages'])->content);
        }
        self::assertSame(0, $executed);
    }

    public function testMemorySaverPersistsThreadCountWhileAfterAgentResetsNumericRunLimit(): void
    {
        $executed = 0;
        $agent = self::agent(['threadLimit' => 2, 'runLimit' => 1, 'exitBehavior' => 'end'], $executed, [self::calls(1), new AIMessage('done 1'), self::calls(1), new AIMessage('done 2'), self::calls(1)]);
        for ($run = 1; $run <= 3; ++$run) {
            $result = $agent->invoke(['messages' => [new HumanMessage('run ' . $run)]], ['configurable' => ['thread_id' => 'numeric-limit']]);
            self::assertSame(['__all__' => min(2, $run)], $result['threadToolCallCount']);
            self::assertSame([], $result['runToolCallCount']);
            self::assertSame($run === 3 ? 'Tool call limit reached: thread limit exceeded (3/2 calls).' : 'done ' . $run, end($result['messages'])->content);
        }
        self::assertSame(2, $executed);
    }

    private static function calls(int $count): AIMessage
    {
        $calls = [];
        for ($index = 1; $index <= $count; ++$index) {
            $calls[] = ['id' => (string) $index, 'name' => 'test', 'args' => []];
        }

        return new AIMessage(['content' => '', 'tool_calls' => $calls]);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<AIMessage>      $responses
     */
    private static function agent(array $options, int &$executed, array $responses): ReactAgent
    {
        $tool = tool(static function (array $input) use (&$executed): string {
            ++$executed;

            return 'ran';
        }, ['name' => 'test', 'description' => 'Deterministic local tool', 'schema' => Schema::object([])]);

        return Agent::create(['model' => AgentAssertions::fakeChat($responses), 'tools' => [$tool], 'middleware' => [ToolCallLimitMiddleware::create($options)], 'checkpointer' => new MemorySaver()]);
    }
}
