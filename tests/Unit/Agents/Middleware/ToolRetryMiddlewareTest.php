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
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Middleware\InvalidRetryConfigError;
use LangGraph\Agents\Middleware\ToolRetryMiddleware;
use LangGraph\Agents\Middleware\Utils;
use LangGraph\Agents\ReactAgent;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;
use function LangGraph\Pregel\interrupt;

/**
 * `langchain/src/agents/middleware/tests/toolRetry.test.ts`.
 *
 * Upstream's `error.constructor.name` is the short class name here (`Exception` where upstream has `Error`).
 */
#[CoversClass(ToolRetryMiddleware::class)]
#[CoversClass(InvalidRetryConfigError::class)]
final class ToolRetryMiddlewareTest extends TestCase
{
    private static function workingTool(): StructuredTool
    {
        return tool(
            static fn (array $in): string => 'Success: ' . $in['input'],
            ['name' => 'working_tool', 'description' => 'Tool that always succeeds', 'schema' => Schema::object(['input' => ['type' => 'string']], ['input'])],
        );
    }

    private static function failingTool(): StructuredTool
    {
        return tool(
            static function (array $in): never {
                throw new \Exception('Failed: ' . $in['input']);
            },
            ['name' => 'failing_tool', 'description' => 'Tool that always fails', 'schema' => Schema::object(['input' => ['type' => 'string']], ['input'])],
        );
    }

    /** A tool that fails `$failCount` times before succeeding, tracking attempts in a closure. */
    private static function temporaryFailureTool(int $failCount): StructuredTool
    {
        $attempt = 0;

        return tool(
            static function (array $in) use (&$attempt, $failCount): string {
                ++$attempt;
                if ($attempt <= $failCount) {
                    throw new \Exception("Temporary failure {$attempt}");
                }

                return "Success after {$attempt} attempts: {$in['input']}";
            },
            ['name' => 'temp_failing_tool', 'description' => 'Tool that fails temporarily', 'schema' => Schema::object(['input' => ['type' => 'string']], ['input'])],
        );
    }

    /** @param list<array<string, mixed>> $calls */
    private static function model(array $calls): FakeToolCallingModel
    {
        return new FakeToolCallingModel(['toolCalls' => [$calls, []]]);
    }

    /** @param list<mixed> $tools */
    private static function agent(object $model, array $tools, array $middleware): ReactAgent
    {
        return Agent::create(['model' => $model, 'tools' => $tools, 'middleware' => $middleware, 'checkpointer' => new MemorySaver()]);
    }

    /** @return list<ToolMessage> */
    private static function toolMessages(ReactAgent $agent, string $prompt): array
    {
        $result = $agent->invoke(['messages' => [new HumanMessage($prompt)]], ['configurable' => ['thread_id' => 'test']]);

        return AgentAssertions::ofType($result['messages'], ToolMessage::class);
    }

    private static function toolStatus(ToolMessage $message): mixed
    {
        return $message->additional_kwargs['status'] ?? null;
    }

    // ---- Initialization -------------------------------------------------------------------------

    public function testShouldInitializeWithDefaultValues(): void
    {
        self::assertSame('toolRetryMiddleware', ToolRetryMiddleware::create()['name']);
    }

    public function testShouldInitializeWithCustomValues(): void
    {
        $retry = ToolRetryMiddleware::create([
            'maxRetries' => 5,
            'tools' => ['tool1', 'tool2'],
            'retryOn' => [ToolRetryTimeoutError::class, ToolRetryNetworkError::class],
            'onFailure' => 'error',
            'backoffFactor' => 1.5,
            'initialDelayMs' => 500,
            'maxDelayMs' => 30000,
            'jitter' => false,
        ]);

        self::assertSame('toolRetryMiddleware', $retry['name']);
    }

    public function testShouldInitializeWithToolInstances(): void
    {
        $retry = ToolRetryMiddleware::create(['maxRetries' => 3, 'tools' => [self::workingTool(), self::failingTool()]]);

        self::assertSame('toolRetryMiddleware', $retry['name']);
    }

    public function testShouldInitializeWithMixedToolTypes(): void
    {
        $retry = ToolRetryMiddleware::create(['maxRetries' => 2, 'tools' => [self::workingTool(), 'failing_tool']]);

        self::assertSame('toolRetryMiddleware', $retry['name']);
    }

    // ---- Validation -----------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function invalidFields(): array
    {
        return ['maxRetries' => ['maxRetries'], 'initialDelayMs' => ['initialDelayMs'], 'maxDelayMs' => ['maxDelayMs'], 'backoffFactor' => ['backoffFactor']];
    }

    #[DataProvider('invalidFields')]
    public function testShouldThrowInvalidRetryConfigErrorForNegativeValues(string $field): void
    {
        try {
            ToolRetryMiddleware::create([$field => -1]);
            self::fail('Should have thrown an error');
        } catch (InvalidRetryConfigError $error) {
            self::assertStringContainsString('Number must be greater than or equal to 0', $error->getMessage());
            self::assertSame([$field], $error->issues()[0]['path']);
            self::assertSame('too_small', $error->issues()[0]['code']);
        }
    }

    public function testShouldThrowInvalidRetryConfigErrorForInvalidTypeStringInsteadOfNumber(): void
    {
        try {
            ToolRetryMiddleware::create(['maxRetries' => 'not a number']);
            self::fail('Should have thrown an error');
        } catch (InvalidRetryConfigError $error) {
            self::assertSame(['maxRetries'], $error->issues()[0]['path']);
            self::assertSame('invalid_type', $error->issues()[0]['code']);
        }
    }

    // ---- Basic functionality --------------------------------------------------------------------

    public function testShouldNotRetryWorkingToolNoRetryNeeded(): void
    {
        $model = self::model([['name' => 'working_tool', 'args' => ['input' => 'test'], 'id' => '1']]);
        $retry = ToolRetryMiddleware::create(['maxRetries' => 2, 'initialDelayMs' => 10, 'jitter' => false]);

        $toolMessages = self::toolMessages(self::agent($model, [self::workingTool()], [$retry]), 'Use working tool');

        self::assertCount(1, $toolMessages);
        self::assertStringContainsString('Success: test', $toolMessages[0]->content);
        self::assertNotSame('error', self::toolStatus($toolMessages[0]));
    }

    public function testShouldRetryFailingToolAndReturnErrorMessage(): void
    {
        $model = self::model([['name' => 'failing_tool', 'args' => ['input' => 'test'], 'id' => '1']]);
        $retry = ToolRetryMiddleware::create(['maxRetries' => 2, 'initialDelayMs' => 10, 'jitter' => false, 'onFailure' => 'continue']);

        $toolMessages = self::toolMessages(self::agent($model, [self::failingTool()], [$retry]), 'Use failing tool');

        self::assertCount(1, $toolMessages);
        // The message names the tool and the attempts.
        self::assertStringContainsString('failing_tool', $toolMessages[0]->content);
        self::assertStringContainsString('3 attempts', $toolMessages[0]->content);
        self::assertStringContainsString('Exception', $toolMessages[0]->content);
        self::assertSame('error', self::toolStatus($toolMessages[0]));
    }

    public function testShouldRetryFailingToolAndReRaiseOnFailure(): void
    {
        $model = self::model([['name' => 'failing_tool', 'args' => ['input' => 'test'], 'id' => '1']]);
        $retry = ToolRetryMiddleware::create(['maxRetries' => 2, 'initialDelayMs' => 10, 'jitter' => false, 'onFailure' => 'error']);

        // Raises the error from the tool.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed: test');

        self::toolMessages(self::agent($model, [self::failingTool()], [$retry]), 'Use failing tool');
    }

    public function testShouldUseCustomFailureFormatter(): void
    {
        $customFormatter = static fn (\Throwable $error): string => 'Custom error: ' . (new \ReflectionClass($error))->getShortName();
        $model = self::model([['name' => 'failing_tool', 'args' => ['input' => 'test'], 'id' => '1']]);
        $retry = ToolRetryMiddleware::create(['maxRetries' => 1, 'initialDelayMs' => 10, 'jitter' => false, 'onFailure' => $customFormatter]);

        $toolMessages = self::toolMessages(self::agent($model, [self::failingTool()], [$retry]), 'Use failing tool');

        self::assertCount(1, $toolMessages);
        self::assertStringContainsString('Custom error: Exception', $toolMessages[0]->content);
    }

    public function testShouldSucceedAfterTemporaryFailures(): void
    {
        $model = self::model([['name' => 'temp_failing_tool', 'args' => ['input' => 'test'], 'id' => '1']]);
        $retry = ToolRetryMiddleware::create(['maxRetries' => 3, 'initialDelayMs' => 10, 'jitter' => false]);

        $toolMessages = self::toolMessages(self::agent($model, [self::temporaryFailureTool(2)], [$retry]), 'Use temp failing tool');

        self::assertCount(1, $toolMessages);
        // Succeeds on the 3rd attempt.
        self::assertStringContainsString('Success after 3 attempts', $toolMessages[0]->content);
        self::assertNotSame('error', self::toolStatus($toolMessages[0]));
    }

    // ---- Graph control flow ---------------------------------------------------------------------

    public function testBubblesGraphInterruptsWithoutRetryingOrHandlingThem(): void
    {
        $completedToolCalls = 0;
        $retryOnCalls = 0;
        $onFailureCalls = 0;
        $interruptTool = tool(
            static function () use (&$completedToolCalls): string {
                $approval = interrupt(['action' => 'approve']);
                ++$completedToolCalls;

                return 'Approved: ' . $approval;
            },
            ['name' => 'interrupt_tool', 'description' => 'Tool that pauses for approval', 'schema' => Schema::object([])],
        );
        $agent = self::agent(
            self::model([['name' => 'interrupt_tool', 'args' => [], 'id' => 'call_1']]),
            [$interruptTool],
            [ToolRetryMiddleware::create([
                'maxRetries' => 2,
                'initialDelayMs' => 0,
                'jitter' => false,
                'retryOn' => static function () use (&$retryOnCalls): bool {
                    ++$retryOnCalls;

                    return true;
                },
                'onFailure' => static function () use (&$onFailureCalls): string {
                    ++$onFailureCalls;

                    return 'must not handle graph control flow';
                },
            ])],
        );
        $config = ['configurable' => ['thread_id' => 'tool-retry-graph-interrupt']];

        $interrupts = AgentAssertions::interrupts($agent, ['messages' => [new HumanMessage('Use the interrupt tool')]], $config);

        self::assertCount(1, $interrupts);
        self::assertSame(['action' => 'approve'], $interrupts[0]['value']);
        self::assertSame(0, $completedToolCalls);
        self::assertSame(0, $retryOnCalls);
        self::assertSame(0, $onFailureCalls);

        $resumed = $agent->invoke(new Command(resume: 'approved'), $config);
        $toolMessages = AgentAssertions::ofType($resumed['messages'], ToolMessage::class);

        self::assertCount(1, $toolMessages);
        self::assertSame('Approved: approved', $toolMessages[0]->content);
        self::assertSame(1, $completedToolCalls);
        self::assertSame(0, $retryOnCalls);
        self::assertSame(0, $onFailureCalls);
    }

    // ---- Tool filtering -------------------------------------------------------------------------

    /** @return array<string, array{0: bool}> */
    public static function filterForms(): array
    {
        return ['name' => [false], 'instance' => [true]];
    }

    #[DataProvider('filterForms')]
    public function testShouldOnlyApplyToSpecificTools(bool $asInstance): void
    {
        $model = self::model([
            ['name' => 'failing_tool', 'args' => ['input' => 'test1'], 'id' => '1'],
            ['name' => 'working_tool', 'args' => ['input' => 'test2'], 'id' => '2'],
        ]);
        $failing = self::failingTool();

        // Only retry failing_tool, named or as an instance.
        $retry = ToolRetryMiddleware::create([
            'maxRetries' => 2,
            'tools' => [$asInstance ? $failing : 'failing_tool'],
            'initialDelayMs' => 10,
            'jitter' => false,
            'onFailure' => 'continue',
        ]);

        $toolMessages = self::toolMessages(self::agent($model, [$failing, self::workingTool()], [$retry]), 'Use both tools');

        self::assertCount(2, $toolMessages);

        // failing_tool has the error message (after its retries).
        $failingMsg = self::named($toolMessages, 'failing_tool');
        self::assertSame('error', self::toolStatus($failingMsg));
        self::assertStringContainsString('3 attempts', $failingMsg->content);

        // working_tool succeeds normally (no retry applied).
        $workingMsg = self::named($toolMessages, 'working_tool');
        self::assertStringContainsString('Success: test2', $workingMsg->content);
        self::assertNotSame('error', self::toolStatus($workingMsg));
    }

    /** @param list<ToolMessage> $messages */
    private static function named(array $messages, string $name): ToolMessage
    {
        foreach ($messages as $message) {
            if ($message->name === $name) {
                return $message;
            }
        }
        self::fail("no tool message named {$name}");
    }

    public function testShouldRejectInvalidToolInstancesForFiltering(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Expected a tool name string or tool instance to be passed to toolRetryMiddleware');

        ToolRetryMiddleware::create(['tools' => [['foo' => 'bar']]]);
    }

    // ---- Exception filtering --------------------------------------------------------------------

    public function testShouldOnlyRetrySpecificExceptionTypesArray(): void
    {
        $throwing = static fn (string $name, string $class, string $label): StructuredTool => tool(
            static function (array $in) use ($class, $label): never {
                throw new $class("{$label}: {$in['input']}");
            },
            ['name' => $name, 'description' => "Tool that raises {$class}", 'schema' => Schema::object(['input' => ['type' => 'string']], ['input'])],
        );
        $timeoutTool = $throwing('timeout_tool', ToolRetryTimeoutError::class, 'Timeout');
        $networkTool = $throwing('network_tool', ToolRetryNetworkError::class, 'Network');

        $model = self::model([
            ['name' => 'timeout_tool', 'args' => ['input' => 'test1'], 'id' => '1'],
            ['name' => 'network_tool', 'args' => ['input' => 'test2'], 'id' => '2'],
        ]);

        // Only retry the timeout error.
        $retry = ToolRetryMiddleware::create([
            'maxRetries' => 2,
            'retryOn' => [ToolRetryTimeoutError::class],
            'initialDelayMs' => 10,
            'jitter' => false,
            'onFailure' => 'continue',
        ]);

        $toolMessages = self::toolMessages(self::agent($model, [$timeoutTool, $networkTool], [$retry]), 'Use both tools');

        self::assertCount(2, $toolMessages);

        // timeout_tool retried (3 attempts).
        $timeoutMsg = self::named($toolMessages, 'timeout_tool');
        self::assertSame('error', self::toolStatus($timeoutMsg));
        self::assertStringContainsString('3 attempts', $timeoutMsg->content);
        self::assertStringContainsString('ToolRetryTimeoutError', $timeoutMsg->content);

        // network_tool failed immediately (1 attempt only).
        $networkMsg = self::named($toolMessages, 'network_tool');
        self::assertSame('error', self::toolStatus($networkMsg));
        self::assertStringContainsString('1 attempt', $networkMsg->content);
        self::assertStringContainsString('ToolRetryNetworkError', $networkMsg->content);
    }

    public function testShouldOnlyRetrySpecificExceptionTypesFunction(): void
    {
        // Only retry on 5xx errors.
        $shouldRetry = static fn (\Throwable $error): bool => $error::class === ToolRetryHttpError::class
            && $error->statusCode >= 500 && $error->statusCode < 600;

        $http = static fn (string $name, string $label, int $status): StructuredTool => tool(
            static function (array $in) use ($label, $status): never {
                throw new ToolRetryHttpError("{$label}: {$in['input']}", $status);
            },
            ['name' => $name, 'description' => "Tool that raises {$status} error", 'schema' => Schema::object(['input' => ['type' => 'string']], ['input'])],
        );

        $model = self::model([
            ['name' => 'http_500_tool', 'args' => ['input' => 'test1'], 'id' => '1'],
            ['name' => 'http_400_tool', 'args' => ['input' => 'test2'], 'id' => '2'],
        ]);

        $retry = ToolRetryMiddleware::create(['maxRetries' => 2, 'retryOn' => $shouldRetry, 'initialDelayMs' => 10, 'jitter' => false, 'onFailure' => 'continue']);

        $toolMessages = self::toolMessages(
            self::agent($model, [$http('http_500_tool', 'Server error', 500), $http('http_400_tool', 'Client error', 400)], [$retry]),
            'Use both tools',
        );

        self::assertCount(2, $toolMessages);

        $msg500 = self::named($toolMessages, 'http_500_tool');
        self::assertSame('error', self::toolStatus($msg500));
        self::assertStringContainsString('3 attempts', $msg500->content);
        self::assertStringContainsString('ToolRetryHttpError', $msg500->content);

        $msg400 = self::named($toolMessages, 'http_400_tool');
        self::assertSame('error', self::toolStatus($msg400));
        self::assertStringContainsString('1 attempt', $msg400->content);
        self::assertStringContainsString('ToolRetryHttpError', $msg400->content);
    }

    // ---- Backoff calculation --------------------------------------------------------------------

    public function testShouldUseExponentialBackoff(): void
    {
        $model = self::model([['name' => 'temp_failing_tool', 'args' => ['input' => 'test'], 'id' => '1']]);
        $retry = ToolRetryMiddleware::create(['maxRetries' => 3, 'initialDelayMs' => 50, 'backoffFactor' => 2.0, 'jitter' => false]);
        $agent = self::agent($model, [self::temporaryFailureTool(3)], [$retry]);

        $start = microtime(true);
        $toolMessages = self::toolMessages($agent, 'Use temp failing tool');
        $actualDelay = (microtime(true) - $start) * 1000;

        self::assertCount(1, $toolMessages);
        self::assertStringContainsString('Success after 4 attempts', $toolMessages[0]->content);

        // The total delay: 50 + 100 + 200 = 350ms, with some tolerance for execution time.
        $expectedDelay = 50 + 100 + 200;
        self::assertGreaterThanOrEqual($expectedDelay, $actualDelay);
        self::assertLessThan($expectedDelay + 200, $actualDelay);
    }

    public function testShouldUseConstantBackoffWhenBackoffFactorIs0(): void
    {
        $model = self::model([['name' => 'temp_failing_tool', 'args' => ['input' => 'test'], 'id' => '1']]);
        $retry = ToolRetryMiddleware::create(['maxRetries' => 2, 'initialDelayMs' => 50, 'backoffFactor' => 0.0, 'jitter' => false]);
        $agent = self::agent($model, [self::temporaryFailureTool(2)], [$retry]);

        $start = microtime(true);
        $toolMessages = self::toolMessages($agent, 'Use temp failing tool');
        $actualDelay = (microtime(true) - $start) * 1000;

        self::assertCount(1, $toolMessages);
        self::assertStringContainsString('Success after 3 attempts', $toolMessages[0]->content);

        // The total delay is 50 + 50 = 100ms (exponential would be at least 50 + 100 = 150ms).
        $expectedDelay = 50 + 50;
        self::assertGreaterThanOrEqual($expectedDelay, $actualDelay);
        self::assertLessThan($expectedDelay + 200, $actualDelay);
    }

    public function testShouldCapDelayAtMaxDelay(): void
    {
        // calculateRetryDelay directly, as the Python suite does.
        $config = ['backoffFactor' => 10.0, 'initialDelayMs' => 1000, 'maxDelayMs' => 2000, 'jitter' => false];

        self::assertEquals(1000, Utils::calculateRetryDelay($config, 0));
        self::assertEquals(2000, Utils::calculateRetryDelay($config, 1)); // 10000 -> capped to 2000
        self::assertEquals(2000, Utils::calculateRetryDelay($config, 2)); // 100000 -> capped to 2000
    }

    public function testShouldAddJitterToDelays(): void
    {
        $config = ['backoffFactor' => 1.0, 'initialDelayMs' => 100, 'maxDelayMs' => 1000, 'jitter' => true];

        $delays = array_map(static fn (): int|float => Utils::calculateRetryDelay($config, 0), range(1, 10));

        // All delays are within the jitter range (+-25%).
        foreach ($delays as $delay) {
            self::assertGreaterThanOrEqual(75, $delay);
            self::assertLessThanOrEqual(125, $delay);
        }

        // With jitter the delays vary.
        self::assertGreaterThan(1, \count(array_unique($delays)));
    }

    // ---- Zero retries ---------------------------------------------------------------------------

    public function testShouldNotRetryWhenMaxRetriesIs0(): void
    {
        $model = self::model([['name' => 'failing_tool', 'args' => ['input' => 'test'], 'id' => '1']]);
        $retry = ToolRetryMiddleware::create(['maxRetries' => 0, 'initialDelayMs' => 10, 'jitter' => false, 'onFailure' => 'continue']);

        $toolMessages = self::toolMessages(self::agent($model, [self::failingTool()], [$retry]), 'Use failing tool');

        self::assertCount(1, $toolMessages);
        // Fails after 1 attempt only.
        self::assertStringContainsString('1 attempt', $toolMessages[0]->content);
        self::assertSame('error', self::toolStatus($toolMessages[0]));
    }

    // ---- Middleware composition -----------------------------------------------------------------

    public function testShouldComposeCorrectlyWithOtherMiddleware(): void
    {
        $callLog = [];

        // Custom logging middleware.
        $loggingMiddleware = Middleware::create([
            'name' => 'loggingMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler) use (&$callLog): mixed {
                $callLog[] = 'before_' . $request['tool']->name;
                $response = $handler($request);
                $callLog[] = 'after_' . $request['tool']->name;

                return $response;
            },
        ]);

        $model = self::model([['name' => 'working_tool', 'args' => ['input' => 'test'], 'id' => '1']]);
        $retry = ToolRetryMiddleware::create(['maxRetries' => 2, 'initialDelayMs' => 10, 'jitter' => false]);

        $toolMessages = self::toolMessages(self::agent($model, [self::workingTool()], [$loggingMiddleware, $retry]), 'Use working tool');

        // Both middleware are called.
        self::assertSame(['before_working_tool', 'after_working_tool'], $callLog);
        self::assertCount(1, $toolMessages);
        self::assertStringContainsString('Success: test', $toolMessages[0]->content);
    }

    // ---- calculateRetryDelay retryAfterMs floor -------------------------------------------------

    private const FLOOR_CONFIG = ['backoffFactor' => 2.0, 'initialDelayMs' => 100, 'maxDelayMs' => 60000, 'jitter' => false];

    public function testRaisesTheDelayToTheHintWhenTheHintIsLarger(): void
    {
        self::assertEquals(5000, Utils::calculateRetryDelay(self::FLOOR_CONFIG, 0, 5000));
    }

    public function testKeepsTheBackoffWhenItAlreadyExceedsTheHint(): void
    {
        self::assertEquals(100, Utils::calculateRetryDelay(self::FLOOR_CONFIG, 0, 50));
    }

    public function testIgnoresMaxDelayMsForTheHint(): void
    {
        self::assertEquals(9000, Utils::calculateRetryDelay([...self::FLOOR_CONFIG, 'maxDelayMs' => 200], 0, 9000));
    }

    public function testIsUnchangedWhenNoHintIsGiven(): void
    {
        self::assertEquals(100, Utils::calculateRetryDelay(self::FLOOR_CONFIG, 0));
    }
}

class ToolRetryTimeoutError extends \Exception
{
}

class ToolRetryNetworkError extends \Exception
{
}

class ToolRetryHttpError extends \Exception
{
    public function __construct(string $message, public readonly int $statusCode)
    {
        parent::__construct($message);
    }
}
