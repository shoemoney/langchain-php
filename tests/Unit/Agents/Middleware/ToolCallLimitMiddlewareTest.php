<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\ToolCallLimitExceededError;
use LangGraph\Agents\Middleware\ToolCallLimitMiddleware;
use LangGraph\Agents\Middleware\Utils;
use LangGraph\Checkpoint\MemorySaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/middleware/tests/toolCallLimit.test.ts`.
 *
 * The `vi.fn` tool mocks are call counters on the test case, reset for every test.
 */
#[CoversClass(ToolCallLimitMiddleware::class)]
#[CoversClass(ToolCallLimitExceededError::class)]
final class ToolCallLimitMiddlewareTest extends TestCase
{
    private int $searchCalls = 0;

    private int $calculatorCalls = 0;

    private StructuredTool $searchTool;

    private StructuredTool $calculatorTool;

    protected function setUp(): void
    {
        $this->searchCalls = 0;
        $this->calculatorCalls = 0;
        $this->searchTool = tool(
            function (array $in): string {
                ++$this->searchCalls;

                return 'Results for: ' . $in['query'];
            },
            ['name' => 'search', 'description' => 'Search for information', 'schema' => Schema::object(['query' => ['type' => 'string']], ['query'])],
        );
        $this->calculatorTool = tool(
            function (array $in): string {
                ++$this->calculatorCalls;

                return 'Result: ' . $in['expression'];
            },
            ['name' => 'calculator', 'description' => 'Calculate an expression', 'schema' => Schema::object(['expression' => ['type' => 'string']], ['expression'])],
        );
    }

    /** @return array{id: string, name: string, args: array<string, string>} */
    private static function search(string $id, string $query): array
    {
        return ['id' => $id, 'name' => 'search', 'args' => ['query' => $query]];
    }

    /** @return array{id: string, name: string, args: array<string, string>} */
    private static function calc(string $id, string $expression): array
    {
        return ['id' => $id, 'name' => 'calculator', 'args' => ['expression' => $expression]];
    }

    /** @param list<array<string, mixed>> $toolCalls */
    private static function ai(array $toolCalls): AIMessage
    {
        return new AIMessage(['content' => '', 'tool_calls' => $toolCalls]);
    }

    /** @param list<AIMessage> $responses */
    private function agent(array $responses, array $middleware, array $tools = [], ?MemorySaver $checkpointer = null, ?object $model = null): \LangGraph\Agents\ReactAgent
    {
        $options = ['model' => $model ?? AgentAssertions::fakeChat($responses), 'tools' => $tools ?: [$this->searchTool], 'middleware' => $middleware];
        if ($checkpointer !== null) {
            $options['checkpointer'] = $checkpointer;
        }

        return Agent::create($options);
    }

    private static function lastContent(array $result): string
    {
        $content = $result['messages'][array_key_last($result['messages'])]->content;

        return \is_string($content) ? $content : (string) json_encode($content);
    }

    /** @return array<string, mixed>|null */
    private static function runAfterModel(array $middleware, array $state): ?array
    {
        return Utils::getHookFunction($middleware['afterModel'])($state, null);
    }

    private static function toolStatus(ToolMessage $message): mixed
    {
        return $message->additional_kwargs['status'] ?? null;
    }

    // ---- Initialization and validation ----------------------------------------------------------

    public function testShouldThrowErrorIfNoLimitsAreSpecified(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one limit must be specified');

        ToolCallLimitMiddleware::create([]);
    }

    public function testShouldDefaultToContinueExitBehavior(): void
    {
        $middleware = ToolCallLimitMiddleware::create(['threadLimit' => 5]);

        self::assertSame('ToolCallLimitMiddleware', $middleware['name']);
    }

    public function testShouldAcceptDifferentExitBehaviors(): void
    {
        foreach (['continue', 'error', 'end'] as $exitBehavior) {
            $middleware = ToolCallLimitMiddleware::create(['threadLimit' => 5, 'exitBehavior' => $exitBehavior]);

            self::assertSame('ToolCallLimitMiddleware', $middleware['name']);
        }
    }

    public function testShouldGenerateCorrectMiddlewareNameWithoutToolName(): void
    {
        self::assertSame('ToolCallLimitMiddleware', ToolCallLimitMiddleware::create(['threadLimit' => 5])['name']);
    }

    public function testShouldGenerateCorrectMiddlewareNameWithToolName(): void
    {
        $middleware = ToolCallLimitMiddleware::create(['toolName' => 'search', 'threadLimit' => 5]);

        self::assertSame('ToolCallLimitMiddleware[search]', $middleware['name']);
    }

    // ---- Thread-level limits --------------------------------------------------------------------

    public function testShouldAllowToolCallsUnderThreadLimit(): void
    {
        $agent = $this->agent(
            [self::ai([self::search('1', 'test1')]), new AIMessage('Response after tool call')],
            [ToolCallLimitMiddleware::create(['threadLimit' => 5, 'exitBehavior' => 'end'])],
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Search for something')]]);

        // Completes successfully.
        self::assertNotEmpty($result['messages']);
        self::assertStringNotContainsString('thread limit', self::lastContent($result));
        self::assertSame(1, $this->searchCalls);
    }

    public function testShouldTerminateWhenThreadLimitIsExceeded(): void
    {
        $agent = $this->agent(
            [
                self::ai([self::search('1', 'test1'), self::search('2', 'test2')]),
                self::ai([self::search('3', 'test3')]),
                self::ai([self::search('4', 'test4')]),
                new AIMessage('Should not reach here'),
            ],
            [ToolCallLimitMiddleware::create(['threadLimit' => 3, 'exitBehavior' => 'end'])],
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Search for things')]]);

        $lastMessage = $result['messages'][array_key_last($result['messages'])];
        self::assertInstanceOf(AIMessage::class, $lastMessage);
        self::assertStringContainsString('thread limit exceeded', $lastMessage->content);
        self::assertStringContainsString('4/3', $lastMessage->content);
        self::assertSame(3, $this->searchCalls);
    }

    public function testShouldPersistThreadCountAcrossMultipleRuns(): void
    {
        $model = AgentAssertions::fakeChat([
            // First run: 2 tool calls.
            self::ai([self::search('1', 'test1'), self::calc('2', '1+1')]),
            new AIMessage('First run response'),
            // Second run: 2 more tool calls (total: 4).
            self::ai([self::search('3', 'test2'), self::calc('4', '2+2')]),
            new AIMessage('Second run response'),
            // Third run: 1 more tool call (total: 5, exceeds the limit of 4).
            self::ai([self::search('5', 'test3')]),
            new AIMessage('Should be blocked'),
        ]);
        $middleware = ToolCallLimitMiddleware::create(['threadLimit' => 4, 'exitBehavior' => 'end']);
        $checkpointer = new MemorySaver();
        $agent = $this->agent([], [$middleware], [$this->searchTool, $this->calculatorTool], $checkpointer, $model);
        $threadConfig = ['configurable' => ['thread_id' => 'test-thread']];

        $agent->invoke(['messages' => [new HumanMessage('First question')]], $threadConfig);
        $agent->invoke(['messages' => [new HumanMessage('Second question')]], $threadConfig);

        $agent2 = $this->agent([], [$middleware], [$this->searchTool, $this->calculatorTool], $checkpointer, $model);

        // The third run hits the limit.
        $finalResult = $agent2->invoke(['messages' => [new HumanMessage('Third question')]], $threadConfig);

        self::assertStringContainsString('thread limit exceeded', self::lastContent($finalResult));
        self::assertStringContainsString('5/4', self::lastContent($finalResult));
        self::assertSame(2, $this->searchCalls);
        self::assertSame(2, $this->calculatorCalls);
    }

    // ---- Run-level limits -----------------------------------------------------------------------

    public function testShouldAllowToolCallsUnderRunLimit(): void
    {
        $agent = $this->agent(
            [self::ai([self::search('1', 'test')]), self::ai([self::search('2', 'test')]), new AIMessage('Response')],
            [ToolCallLimitMiddleware::create(['runLimit' => 2, 'exitBehavior' => 'end'])],
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Search')]]);

        self::assertStringNotContainsString('run limit', self::lastContent($result));
        self::assertSame(2, $this->searchCalls);
    }

    public function testShouldTerminateWhenRunLimitIsExceeded(): void
    {
        $agent = $this->agent(
            [
                self::ai([self::search('1', 'test1'), self::search('2', 'test2')]),
                self::ai([self::search('3', 'test3')]),
                new AIMessage('Should not reach here'),
            ],
            [ToolCallLimitMiddleware::create(['runLimit' => 2, 'exitBehavior' => 'end'])],
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Search for things')]]);

        self::assertStringContainsString('run limit exceeded', self::lastContent($result));
        self::assertStringContainsString('3/2', self::lastContent($result));
        self::assertSame(2, $this->searchCalls);
    }

    public function testShouldResetRunCountAfterNewHumanMessage(): void
    {
        // One model for both invocations, as upstream's single `createModel()` call.
        $callCount = 0;
        $model = AgentAssertions::fakeChat([
            self::ai([['id' => (string) $callCount++, 'name' => 'search', 'args' => ['query' => 'test1']], ['id' => (string) $callCount++, 'name' => 'search', 'args' => ['query' => 'test2']]]),
            self::ai([['id' => (string) $callCount++, 'name' => 'search', 'args' => ['query' => 'test3']]]),
            new AIMessage("Response {$callCount}"),
        ]);
        $middleware = ToolCallLimitMiddleware::create(['runLimit' => 2, 'exitBehavior' => 'end']);
        $threadConfig = ['configurable' => ['thread_id' => 'test-thread']];
        $checkpointer = new MemorySaver();

        // First run: hits the run limit.
        $agent1 = $this->agent([], [$middleware], [$this->searchTool], $checkpointer, $model);
        $result1 = $agent1->invoke(['messages' => [new HumanMessage('First question')]], $threadConfig);
        self::assertSame(2, $this->searchCalls);
        self::assertStringContainsString('run limit exceeded', self::lastContent($result1));
        self::assertStringContainsString('3/2', self::lastContent($result1));

        // Second run: the run count resets.
        $agent2 = $this->agent([], [$middleware], [$this->searchTool], $checkpointer, $model);
        $result2 = $agent2->invoke(['messages' => [new HumanMessage('Second question')]], $threadConfig);
        self::assertStringContainsString('Response 3', self::lastContent($result2));
        self::assertSame(2, $this->searchCalls);
    }

    // ---- Tool-specific limits -------------------------------------------------------------------

    public function testShouldOnlyCountCallsToSpecificTool(): void
    {
        $agent = $this->agent(
            [self::ai([self::search('1', 'test'), self::calc('2', '1+1'), self::calc('3', '2+2')]), new AIMessage('Response')],
            [ToolCallLimitMiddleware::create(['toolName' => 'search', 'threadLimit' => 2, 'exitBehavior' => 'end'])],
            [$this->searchTool, $this->calculatorTool],
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Do calculations')]]);

        // Completes: only 1 search call, the calculators do not count.
        self::assertStringNotContainsString('thread limit', self::lastContent($result));
    }

    public function testShouldTerminateWhenSpecificToolLimitIsExceeded(): void
    {
        $agent = $this->agent(
            [
                self::ai([self::search('1', 'test1'), self::calc('2', '1+1')]),
                self::ai([self::search('3', 'test2')]),
                self::ai([self::search('4', 'test3')]),
                new AIMessage('Should not reach here'),
            ],
            [ToolCallLimitMiddleware::create(['toolName' => 'search', 'threadLimit' => 2, 'exitBehavior' => 'end'])],
            [$this->searchTool, $this->calculatorTool],
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Search and calculate')]]);

        self::assertStringContainsString("'search' tool", self::lastContent($result));
        self::assertStringContainsString('thread limit exceeded', self::lastContent($result));
        self::assertStringContainsString('3/2', self::lastContent($result));
        self::assertSame(2, $this->searchCalls);
    }

    // ---- Multiple middleware instances ----------------------------------------------------------

    public function testShouldWorkWithBothGlobalAndToolSpecificLimiters(): void
    {
        $globalLimiter = ToolCallLimitMiddleware::create(['threadLimit' => 10, 'exitBehavior' => 'end']); // Won't hit this.
        $searchLimiter = ToolCallLimitMiddleware::create(['toolName' => 'search', 'threadLimit' => 2, 'exitBehavior' => 'end']); // Will hit this.

        $agent = $this->agent(
            [
                self::ai([self::search('1', 'test1'), self::search('2', 'test2'), self::calc('3', '1+1')]),
                self::ai([self::search('4', 'test3')]),
                new AIMessage('Should not reach here'),
            ],
            [$globalLimiter, $searchLimiter],
            [$this->searchTool, $this->calculatorTool],
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Search and calculate')]]);

        self::assertStringContainsString("'search' tool", self::lastContent($result));
        self::assertStringContainsString('thread limit exceeded', self::lastContent($result));
        self::assertStringContainsString('3/2', self::lastContent($result));
        self::assertSame(2, $this->searchCalls);
    }

    // ---- Error behavior -------------------------------------------------------------------------

    public function testShouldThrowAnErrorIfRunLimitExceedsThreadLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('runLimit (3) cannot exceed threadLimit (2). The run limit should be less than or equal to the thread limit.');

        ToolCallLimitMiddleware::create(['threadLimit' => 2, 'runLimit' => 3, 'exitBehavior' => 'error']);
    }

    public function testShouldRaiseIfInvalidExitBehaviorIsProvided(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid enum value. Expected 'continue' | 'error' | 'end', received 'invalid'");

        ToolCallLimitMiddleware::create(['threadLimit' => 2, 'runLimit' => 1, 'exitBehavior' => 'invalid']);
    }

    public function testShouldThrowToolCallLimitExceededErrorWhenExitBehaviorIsError(): void
    {
        $middleware = ToolCallLimitMiddleware::create(['threadLimit' => 2, 'exitBehavior' => 'error']);

        // A state that exceeds the limit after incrementing.
        $state = [
            'messages' => [self::ai([self::search('1', 'test')])],
            'threadToolCallCount' => ['__all__' => 2],
            'runToolCallCount' => ['__all__' => 2],
        ];

        $this->expectException(ToolCallLimitExceededError::class);

        self::runAfterModel($middleware, $state);
    }

    public function testShouldIncludeCorrectInformationInToolCallLimitExceededError(): void
    {
        $middleware = ToolCallLimitMiddleware::create(['threadLimit' => 2, 'runLimit' => 1, 'exitBehavior' => 'error']);

        $state = [
            'messages' => [self::ai([self::search('1', 'test')])],
            'threadToolCallCount' => ['__all__' => 2],
            'runToolCallCount' => ['__all__' => 1],
        ];

        try {
            self::runAfterModel($middleware, $state);
            self::fail('Should have thrown error');
        } catch (ToolCallLimitExceededError $error) {
            self::assertSame(3, $error->threadCount);
            self::assertSame(2, $error->threadLimit);
            self::assertSame(2, $error->runCount);
            self::assertSame(1, $error->runLimit);
            self::assertNull($error->toolName);
            self::assertStringContainsString('thread limit exceeded', $error->getMessage());
            self::assertStringContainsString('run limit exceeded', $error->getMessage());
        }
    }

    public function testShouldIncludeToolNameInErrorForToolSpecificLimits(): void
    {
        $middleware = ToolCallLimitMiddleware::create(['toolName' => 'search', 'threadLimit' => 2, 'exitBehavior' => 'error']);

        $state = [
            'messages' => [self::ai([self::search('1', 'test')])],
            'threadToolCallCount' => ['search' => 2],
            'runToolCallCount' => ['search' => 2],
        ];

        try {
            self::runAfterModel($middleware, $state);
            self::fail('Should have thrown error');
        } catch (ToolCallLimitExceededError $error) {
            self::assertSame('search', $error->toolName);
            self::assertStringContainsString("'search' tool", $error->getMessage());
            self::assertStringContainsString('thread limit exceeded', $error->getMessage());
        }
    }

    public function testShouldRunRemainingToolsUntilLimitIsExceeded(): void
    {
        $middleware = ToolCallLimitMiddleware::create(['threadLimit' => 3, 'runLimit' => 2, 'exitBehavior' => 'continue']);

        $agent = $this->agent(
            [
                self::ai([self::search('1', 'test1'), self::search('2', 'test2'), self::calc('3', '1+1')]),
                self::ai([self::search('4', 'test3')]),
                new AIMessage('Should not reach here'),
            ],
            [$middleware],
            [$this->searchTool, $this->calculatorTool],
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Search and calculate')]]);

        self::assertStringContainsString('Tool call limit exceeded. Do not make additional tool calls.', self::lastContent($result));
        self::assertSame(2, $this->searchCalls);
        self::assertSame(0, $this->calculatorCalls);
    }

    // ---- Combined thread and run limits ---------------------------------------------------------

    public function testShouldCheckBothThreadAndRunLimits(): void
    {
        $agent = $this->agent(
            [
                self::ai([self::search('1', 'test1'), self::search('2', 'test2')]),
                self::ai([self::search('3', 'test3')]),
                new AIMessage('Should not reach here'),
            ],
            [ToolCallLimitMiddleware::create(['threadLimit' => 5, 'runLimit' => 2, 'exitBehavior' => 'end'])], // Hits the run limit.
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Search')]]);

        self::assertStringContainsString('run limit exceeded', self::lastContent($result));
        self::assertStringContainsString('3/2', self::lastContent($result));
        self::assertSame(2, $this->searchCalls);
    }

    public function testShouldReportCorrectLimitTypeWhenThreadLimitIsHitFirst(): void
    {
        $agent = $this->agent(
            [
                self::ai([self::search('1', 'test1')]),
                self::ai([self::search('2', 'test2')]),
                self::ai([self::search('3', 'test3')]),
                new AIMessage('Should not reach here'),
            ],
            [ToolCallLimitMiddleware::create(['threadLimit' => 2, 'runLimit' => 2, 'exitBehavior' => 'end'])],
            [],
            new MemorySaver(),
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Search')]], ['configurable' => ['thread_id' => 'test-thread']]);

        self::assertStringContainsString('thread limit exceeded', self::lastContent($result));
        self::assertStringContainsString('3/2', self::lastContent($result));
        self::assertSame(2, $this->searchCalls);
    }

    // ---- Edge cases -----------------------------------------------------------------------------

    public function testShouldHandleMessagesWithNoToolCalls(): void
    {
        $agent = $this->agent(
            [new AIMessage('Just a response, no tool calls')],
            [ToolCallLimitMiddleware::create(['threadLimit' => 2, 'exitBehavior' => 'end'])],
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Hello')]]);

        // Completes without hitting a limit.
        self::assertStringNotContainsString('thread limit', self::lastContent($result));
        self::assertStringNotContainsString('run limit', self::lastContent($result));
    }

    public function testShouldHandleEmptyMessageHistory(): void
    {
        $middleware = ToolCallLimitMiddleware::create(['threadLimit' => 5, 'exitBehavior' => 'end']);

        self::assertNull(self::runAfterModel($middleware, ['messages' => []]));
    }

    public function testShouldCorrectlyCountMultipleToolCallsInSingleAIMessage(): void
    {
        $middleware = ToolCallLimitMiddleware::create(['threadLimit' => 3, 'exitBehavior' => 'end']);

        $state = [
            'messages' => [
                new HumanMessage('Do multiple things'),
                self::ai([self::search('1', 'test1'), self::search('2', 'test2')]),
            ],
            'threadToolCallCount' => ['__all__' => 3],
            'runToolCallCount' => ['__all__' => 3],
        ];

        $result = self::runAfterModel($middleware, $state);

        // The limit is hit: 3 existing calls plus 2 more is above 3.
        self::assertNotNull($result);

        /** @var list<BaseMessage> $messages */
        $messages = $result['messages'];
        // The first message is a ToolMessage (sent to the model: no thread/run details).
        self::assertInstanceOf(ToolMessage::class, $messages[0]);
        self::assertStringContainsString('Tool call limit exceeded', $messages[0]->content);
        // The last is an AI message (shown to the user: with the thread/run details).
        $aiMessage = $messages[array_key_last($messages)];
        self::assertInstanceOf(AIMessage::class, $aiMessage);
        self::assertStringContainsString('thread limit exceeded', $aiMessage->content);
        self::assertStringContainsString('5/3', $aiMessage->content);
    }

    public function testShouldOnlySupportEndForASingleDuplicateToolCall(): void
    {
        $middleware = ToolCallLimitMiddleware::create(['threadLimit' => 3, 'exitBehavior' => 'end']);

        $state = [
            'messages' => [
                new HumanMessage('Do multiple things'),
                self::ai([self::search('1', 'test1'), self::search('2', 'test2'), self::calc('3', '1+1')]),
            ],
            'threadToolCallCount' => ['__all__' => 3],
            'runToolCallCount' => ['__all__' => 3],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot end execution with other tool calls pending. Found calls to: search, calculator.');

        self::runAfterModel($middleware, $state);
    }

    // ---- Continue behavior ----------------------------------------------------------------------

    public function testShouldBlockExceededToolsButLetOtherToolsContinue(): void
    {
        // Limit search to 2 calls, but let the other tools continue.
        $searchLimiter = ToolCallLimitMiddleware::create(['toolName' => 'search', 'threadLimit' => 2, 'exitBehavior' => 'continue']);

        $agent = $this->agent(
            [
                self::ai([self::search('1', 'q1'), self::calc('2', '1+1')]),
                self::ai([self::search('3', 'q2'), self::calc('4', '2+2')]),
                self::ai([self::search('5', 'q3'), self::calc('6', '3+3')]), // search is blocked, calculator works.
                new AIMessage('Final response'),
            ],
            [$searchLimiter],
            [$this->searchTool, $this->calculatorTool],
            new MemorySaver(),
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Question')]], ['configurable' => ['thread_id' => 'test_thread']]);

        $toolMessages = AgentAssertions::ofType($result['messages'], ToolMessage::class);
        $searchSuccess = array_filter($toolMessages, static fn (ToolMessage $m): bool => $m->name === 'search' && self::toolStatus($m) !== 'error');
        $searchBlocked = array_values(array_filter($toolMessages, static fn (ToolMessage $m): bool => $m->name === 'search' && self::toolStatus($m) === 'error'));
        $calcSuccess = array_filter($toolMessages, static fn (ToolMessage $m): bool => $m->name === 'calculator' && self::toolStatus($m) !== 'error');

        // search: 2 successful + 1 blocked; calculator: all 3 successful.
        self::assertCount(2, $searchSuccess);
        self::assertCount(1, $searchBlocked);
        self::assertCount(3, $calcSuccess);
        self::assertStringContainsString('limit', $searchBlocked[0]->content);
        self::assertStringContainsString('search', $searchBlocked[0]->content);
    }

    // ---- End behavior with multiple tool calls --------------------------------------------------

    public function testShouldRaiseErrorWhenEndBehaviorHasMultipleDifferentToolTypes(): void
    {
        $middleware = ToolCallLimitMiddleware::create(['threadLimit' => 2, 'exitBehavior' => 'end']);

        $state = [
            'messages' => [self::ai([self::search('1', 'test1'), self::calc('2', '1+1')])],
            'threadToolCallCount' => ['__all__' => 1],
            'runToolCallCount' => ['__all__' => 1],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Cannot end execution with other tool calls pending. Found calls to: search. Use 'continue' or 'error' behavior instead.");

        self::runAfterModel($middleware, $state);
    }

    // ---- Limit reached but not exceeded ---------------------------------------------------------

    public function testShouldOnlyTriggerWhenLimitIsExceededNotWhenReached(): void
    {
        $middleware = ToolCallLimitMiddleware::create(['threadLimit' => 3, 'runLimit' => 2, 'exitBehavior' => 'end']);

        // The limit is reached exactly (count = limit): no trigger.
        $state1 = [
            'messages' => [self::ai([self::search('1', 'test')])],
            'threadToolCallCount' => ['__all__' => 2],
            'runToolCallCount' => ['__all__' => 1],
        ];
        $result1 = self::runAfterModel($middleware, $state1);
        self::assertNotNull($result1);
        self::assertArrayNotHasKey('jumpTo', $result1);
        self::assertSame(3, $result1['threadToolCallCount']['__all__']);

        // The limit is exceeded (count > limit): trigger.
        $state2 = [
            'messages' => [self::ai([self::search('1', 'test')])],
            'threadToolCallCount' => ['__all__' => 3],
            'runToolCallCount' => ['__all__' => 1],
        ];
        $result2 = self::runAfterModel($middleware, $state2);
        self::assertNotNull($result2);
        self::assertSame('end', $result2['jumpTo']);
    }

    // ---- Parallel tool call limits --------------------------------------------------------------

    /**
     * With a limit of 1 in `continue` mode and 3 proposed calls, the first runs and the 2nd and 3rd are blocked
     * with error ToolMessages; execution continues (no jump).
     */
    public function testShouldHandleParallelToolCallsWithLimitInContinueMode(): void
    {
        $agent = $this->agent(
            [
                self::ai([self::search('1', 'q1'), self::search('2', 'q2'), self::search('3', 'q3')]),
                new AIMessage('Final response'), // The model stops after seeing the errors.
            ],
            [ToolCallLimitMiddleware::create(['threadLimit' => 1, 'exitBehavior' => 'continue'])],
            [],
            new MemorySaver(),
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Test')]], ['configurable' => ['thread_id' => 'test']]);
        $messages = $result['messages'];

        $toolMessages = AgentAssertions::ofType($messages, ToolMessage::class);
        $successful = array_values(array_filter($toolMessages, static fn (ToolMessage $m): bool => self::toolStatus($m) !== 'error'));
        $errors = array_values(array_filter($toolMessages, static fn (ToolMessage $m): bool => self::toolStatus($m) === 'error'));

        self::assertCount(1, $successful);
        self::assertCount(2, $errors);

        // The successful call is q1.
        self::assertStringContainsString('q1', $successful[0]->content);

        // The error messages explain the limit.
        foreach ($errors as $errorMsg) {
            self::assertStringContainsString('limit', strtolower($errorMsg->content));
        }

        // Execution continued: the initial AI message with 3 tool calls, then the final AI message.
        self::assertGreaterThanOrEqual(2, \count(AgentAssertions::ofType($messages, AIMessage::class)));
        self::assertSame(1, $this->searchCalls);
    }

    /**
     * In `end` mode the first call would be allowed, the 2nd and 3rd are blocked with error ToolMessages and
     * execution stops at once, so NO tool runs; an AI message explains why.
     */
    public function testShouldHandleParallelToolCallsWithLimitInEndMode(): void
    {
        $agent = $this->agent(
            [
                self::ai([self::search('1', 'q1'), self::search('2', 'q2'), self::search('3', 'q3')]),
                new AIMessage('Should not reach here'),
            ],
            [ToolCallLimitMiddleware::create(['threadLimit' => 1, 'exitBehavior' => 'end'])],
            [],
            new MemorySaver(),
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Test')]], ['configurable' => ['thread_id' => 'test']]);
        $messages = $result['messages'];

        // When jumping to the end NO tools execute, not even the allowed one: only the 2 blocked calls get error ToolMessages.
        $toolMessages = AgentAssertions::ofType($messages, ToolMessage::class);
        $successful = array_filter($toolMessages, static fn (ToolMessage $m): bool => self::toolStatus($m) !== 'error');
        $errors = array_values(array_filter($toolMessages, static fn (ToolMessage $m): bool => self::toolStatus($m) === 'error'));

        self::assertCount(0, $successful);
        self::assertCount(2, $errors);

        // The error tool messages (sent to the model) include the "Do not" instruction.
        foreach ($errors as $errorMsg) {
            self::assertStringContainsString('Tool call limit exceeded', $errorMsg->content);
            self::assertStringContainsString('Do not', $errorMsg->content);
        }

        // The AI message explaining why execution stopped (shown to the user, with the thread/run details).
        $aiLimitMessages = array_values(array_filter(
            AgentAssertions::ofType($messages, AIMessage::class),
            static fn (AIMessage $m): bool => $m->toolCalls === [] && str_contains(strtolower((string) $m->content), 'limit'),
        ));
        self::assertGreaterThanOrEqual(1, \count($aiLimitMessages));

        $content = strtolower((string) $aiLimitMessages[0]->content);
        self::assertTrue(str_contains($content, 'thread limit exceeded') || str_contains($content, 'run limit exceeded'));
        self::assertSame(0, $this->searchCalls);
    }

    /**
     * Limiting `search` to 1 call while the model proposes 3 searches and 2 calculations: the first search runs,
     * the other 2 are blocked and every calculation runs.
     */
    public function testShouldHandleParallelMixedToolCallsWithSpecificToolLimit(): void
    {
        $agent = $this->agent(
            [
                self::ai([self::search('1', 'q1'), self::calc('2', '1+1'), self::search('3', 'q2'), self::calc('4', '2+2'), self::search('5', 'q3')]),
                new AIMessage('Final response'),
            ],
            [ToolCallLimitMiddleware::create(['toolName' => 'search', 'threadLimit' => 1, 'exitBehavior' => 'continue'])],
            [$this->searchTool, $this->calculatorTool],
            new MemorySaver(),
        );

        $result = $agent->invoke(['messages' => [new HumanMessage('Test')]], ['configurable' => ['thread_id' => 'test']]);

        $toolMessages = AgentAssertions::ofType($result['messages'], ToolMessage::class);
        $searchSuccess = array_filter($toolMessages, static fn (ToolMessage $m): bool => $m->name === 'search' && self::toolStatus($m) !== 'error');
        $searchBlocked = array_filter(
            $toolMessages,
            static fn (ToolMessage $m): bool => $m->name === 'search' && self::toolStatus($m) === 'error' && str_contains(strtolower((string) $m->content), 'limit'),
        );
        $calcSuccess = array_filter($toolMessages, static fn (ToolMessage $m): bool => $m->name === 'calculator' && self::toolStatus($m) !== 'error');

        self::assertCount(1, $searchSuccess);
        self::assertCount(2, $searchBlocked);
        self::assertCount(2, $calcSuccess);
    }
}
