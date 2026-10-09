<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\AsyncCaller;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\InvalidRetryConfigError;
use LangGraph\Agents\Middleware\ModelRetryMiddleware;
use LangGraph\Checkpoint\MemorySaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/middleware/tests/modelRetry.test.ts`.
 *
 * Not converted: "should not retry a core error that is non-retryable by construction", which needs
 * `ContextOverflowError` from `@langchain/core/errors`; this port has no such error class.
 *
 * Upstream's `error.constructor.name` is the short class name here, so the custom-formatter case reads
 * `Custom error: Exception` where upstream reads `Custom error: Error`.
 */
#[CoversClass(ModelRetryMiddleware::class)]
#[CoversClass(InvalidRetryConfigError::class)]
final class ModelRetryMiddlewareTest extends TestCase
{
    private static function agent(object $model, array $middleware): \LangGraph\Agents\ReactAgent
    {
        return Agent::create(['model' => $model, 'tools' => [], 'middleware' => $middleware, 'checkpointer' => new MemorySaver()]);
    }

    /** @return list<AIMessage> */
    private static function invokeAgent(\LangGraph\Agents\ReactAgent $agent, string $thread = 'test'): array
    {
        $result = $agent->invoke(['messages' => [new HumanMessage('Hello')]], ['configurable' => ['thread_id' => $thread]]);

        return array_values(array_filter($result['messages'], static fn (mixed $m): bool => $m instanceof AIMessage));
    }

    // ---- Initialization -------------------------------------------------------------------------

    public function testShouldInitializeWithDefaultValues(): void
    {
        $retry = ModelRetryMiddleware::create();

        self::assertSame('modelRetryMiddleware', $retry['name']);
    }

    public function testShouldInitializeWithCustomValues(): void
    {
        $retry = ModelRetryMiddleware::create([
            'maxRetries' => 5,
            'retryOn' => [ModelRetryTimeoutError::class, ModelRetryNetworkError::class],
            'onFailure' => 'error',
            'backoffFactor' => 1.5,
            'initialDelayMs' => 500,
            'maxDelayMs' => 30000,
            'jitter' => false,
        ]);

        self::assertSame('modelRetryMiddleware', $retry['name']);
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
            ModelRetryMiddleware::create([$field => -1]);
            self::fail('Should have thrown an error');
        } catch (InvalidRetryConfigError $error) {
            self::assertStringContainsString('Number must be greater than or equal to 0', $error->getMessage());
            self::assertSame([$field], $error->issues()[0]['path']);
            self::assertSame('too_small', $error->issues()[0]['code']);
        }
    }

    // ---- Basic functionality --------------------------------------------------------------------

    public function testShouldNotRetryWorkingModelNoRetryNeeded(): void
    {
        $model = new FakeToolCallingModel(['toolCalls' => [[]]]);
        $retry = ModelRetryMiddleware::create(['maxRetries' => 2, 'initialDelayMs' => 10, 'jitter' => false]);

        $aiMessages = self::invokeAgent(self::agent($model, [$retry]));

        self::assertNotEmpty($aiMessages);
        self::assertSame('Hello', $aiMessages[array_key_last($aiMessages)]->content);
    }

    public function testShouldRetryFailingModelAndSucceedAfterTemporaryFailures(): void
    {
        $model = new ModelRetryTemporaryFailureModel(2);
        $retry = ModelRetryMiddleware::create(['maxRetries' => 3, 'initialDelayMs' => 10, 'jitter' => false]);

        $aiMessages = self::invokeAgent(self::agent($model, [$retry]));

        self::assertNotEmpty($aiMessages);
        self::assertStringContainsString('Success after 3 attempts', $aiMessages[array_key_last($aiMessages)]->content);
        self::assertSame(3, $model->generateCalls());
    }

    public function testShouldRetryFailingModelAndRaiseOnFailureDefault(): void
    {
        $model = new ModelRetryAlwaysFailingModel(new \Exception('Model failed'));
        $retry = ModelRetryMiddleware::create(['maxRetries' => 2, 'initialDelayMs' => 10, 'jitter' => false, 'onFailure' => 'error']);

        // Should raise the error from the model.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Model failed');

        self::invokeAgent(self::agent($model, [$retry]));
    }

    public function testShouldRetryFailingModelAndReturnErrorMessage(): void
    {
        $model = new ModelRetryAlwaysFailingModel(new \Exception('Model failed'));
        $retry = ModelRetryMiddleware::create(['maxRetries' => 2, 'initialDelayMs' => 10, 'jitter' => false, 'onFailure' => 'continue']);

        $aiMessages = self::invokeAgent(self::agent($model, [$retry]));

        self::assertNotEmpty($aiMessages);
        $content = $aiMessages[array_key_last($aiMessages)]->content;
        self::assertStringContainsString('3 attempts', $content);
        self::assertStringContainsString('Exception', $content);
        self::assertStringContainsString('Model failed', $content);
        self::assertSame(3, $model->generateCalls());
    }

    public function testShouldUseCustomFailureFormatter(): void
    {
        $customFormatter = static fn (\Throwable $error): string => 'Custom error: ' . (new \ReflectionClass($error))->getShortName();
        $model = new ModelRetryAlwaysFailingModel(new \Exception('Model failed'));
        $retry = ModelRetryMiddleware::create(['maxRetries' => 1, 'initialDelayMs' => 10, 'jitter' => false, 'onFailure' => $customFormatter]);

        $aiMessages = self::invokeAgent(self::agent($model, [$retry]));

        self::assertNotEmpty($aiMessages);
        self::assertSame('Custom error: Exception', $aiMessages[array_key_last($aiMessages)]->content);
    }

    // ---- Retry on specific exceptions -----------------------------------------------------------

    public function testShouldRetryOnSpecifiedErrorTypes(): void
    {
        $model = new ModelRetryTemporaryFailureModel(1, static fn (): \Throwable => new ModelRetryTimeoutError('Timeout'));
        $retry = ModelRetryMiddleware::create(['maxRetries' => 2, 'initialDelayMs' => 10, 'jitter' => false, 'retryOn' => [ModelRetryTimeoutError::class]]);

        $aiMessages = self::invokeAgent(self::agent($model, [$retry]));

        self::assertNotEmpty($aiMessages);
        self::assertSame(2, $model->generateCalls());
    }

    public function testShouldNotRetryOnNonSpecifiedErrorTypes(): void
    {
        $model = new ModelRetryAlwaysFailingModel(new \Exception('Generic error'));
        $retry = ModelRetryMiddleware::create([
            'maxRetries' => 2,
            'initialDelayMs' => 10,
            'jitter' => false,
            'retryOn' => [ModelRetryTimeoutError::class, ModelRetryRateLimitError::class],
            'onFailure' => 'continue',
        ]);

        $aiMessages = self::invokeAgent(self::agent($model, [$retry]));

        self::assertNotEmpty($aiMessages);
        // Fails immediately: Exception is not in the retryOn list.
        self::assertStringContainsString('1 attempt', $aiMessages[array_key_last($aiMessages)]->content);
        self::assertSame(1, $model->generateCalls());
    }

    public function testShouldUseCustomRetryFunction(): void
    {
        $model = new ModelRetryTemporaryFailureModel(1, static function (): \Throwable {
            $error = new ModelRetryRateLimitError('Rate limit exceeded');
            $error->statusCode = 429;

            return $error;
        });

        $calls = 0;
        $shouldRetry = static function (\Throwable $error) use (&$calls): bool {
            ++$calls;

            return $error instanceof ModelRetryRateLimitError || ($error->statusCode ?? null) === 429;
        };

        $retry = ModelRetryMiddleware::create(['maxRetries' => 2, 'initialDelayMs' => 10, 'jitter' => false, 'retryOn' => $shouldRetry]);

        $aiMessages = self::invokeAgent(self::agent($model, [$retry]));

        self::assertNotEmpty($aiMessages);
        self::assertSame(1, $calls);
        self::assertSame(2, $model->generateCalls());
    }

    // ---- Default retryability classification ----------------------------------------------------

    private static function generateCallsWithDefaultRetryOn(\Throwable $error, string $thread): int
    {
        $model = new ModelRetryAlwaysFailingModel($error);
        $retry = ModelRetryMiddleware::create(['maxRetries' => 2, 'initialDelayMs' => 10, 'jitter' => false, 'onFailure' => 'continue']);

        self::invokeAgent(self::agent($model, [$retry]), $thread);

        return $model->generateCalls();
    }

    public function testShouldNotRetryAnErrorMarkedNonRetryable(): void
    {
        $error = AsyncCaller::stampRetryable(new \Exception('Invalid API key'), false);

        self::assertSame(1, self::generateCallsWithDefaultRetryOn($error, 'non-retryable'));
    }

    public function testShouldRetryAnErrorMarkedRetryable(): void
    {
        $error = AsyncCaller::stampRetryable(new \Exception('Rate limited'), true);

        self::assertSame(3, self::generateCallsWithDefaultRetryOn($error, 'retryable'));
    }

    public function testShouldRetryAnUnclassifiedError(): void
    {
        self::assertSame(3, self::generateCallsWithDefaultRetryOn(new \Exception('Who knows'), 'unclassified'));
    }

    // ---- Backoff behavior -----------------------------------------------------------------------

    public function testShouldApplyExponentialBackoff(): void
    {
        $model = new ModelRetryTemporaryFailureModel(2);
        $retry = ModelRetryMiddleware::create(['maxRetries' => 3, 'initialDelayMs' => 100, 'backoffFactor' => 2.0, 'jitter' => false]);

        self::invokeAgent(self::agent($model, [$retry]));

        $delays = $model->delays();
        // There are delays between retries.
        self::assertNotEmpty($delays);
        // The first is around initialDelayMs (100ms), the second doubles it.
        self::assertGreaterThanOrEqual(90, $delays[0]);
        self::assertLessThan(150, $delays[0]);
        self::assertGreaterThanOrEqual(180, $delays[1]);
        self::assertLessThan(250, $delays[1]);
        self::assertSame(3, $model->generateCalls());
    }

    public function testShouldApplyConstantBackoffWhenBackoffFactorIs0(): void
    {
        $model = new ModelRetryTemporaryFailureModel(2);
        $retry = ModelRetryMiddleware::create(['maxRetries' => 3, 'initialDelayMs' => 100, 'backoffFactor' => 0.0, 'jitter' => false]);

        self::invokeAgent(self::agent($model, [$retry]));

        $delays = $model->delays();
        $avgDelay = array_sum($delays) / \count($delays);
        self::assertGreaterThanOrEqual(90, $avgDelay);
        self::assertLessThan(150, $avgDelay);
        self::assertSame(3, $model->generateCalls());
    }

    // ---- Retry-After handling -------------------------------------------------------------------

    public function testWaitsAtLeastAsLongAsTheErrorAsks(): void
    {
        $error = new ModelRetryRateLimitError('rate limited');
        $error->retryAfterMs = 300;
        $model = new ModelRetryAlwaysFailingModel($error);
        $retry = ModelRetryMiddleware::create(['maxRetries' => 1, 'initialDelayMs' => 1, 'jitter' => false, 'onFailure' => 'continue']);

        $start = microtime(true);
        self::invokeAgent(self::agent($model, [$retry]), 'retry-after');
        $elapsedMs = (microtime(true) - $start) * 1000;

        self::assertGreaterThanOrEqual(280, $elapsedMs);
        self::assertSame(2, $model->generateCalls());
    }

    public function testUsesItsOwnBackoffWhenNoHintIsPresent(): void
    {
        $model = new ModelRetryAlwaysFailingModel(new \Exception('boom'));
        $retry = ModelRetryMiddleware::create(['maxRetries' => 1, 'initialDelayMs' => 1, 'jitter' => false, 'onFailure' => 'continue']);

        $start = microtime(true);
        self::invokeAgent(self::agent($model, [$retry]), 'no-hint');
        $elapsedMs = (microtime(true) - $start) * 1000;

        self::assertLessThan(200, $elapsedMs);
        self::assertSame(2, $model->generateCalls());
    }
}

class ModelRetryTimeoutError extends \Exception
{
}

class ModelRetryNetworkError extends \Exception
{
}

class ModelRetryRateLimitError extends \Exception
{
    public ?int $statusCode = null;

    public int|float|null $retryAfterMs = null;
}

/**
 * Upstream's `ModelRetryTemporaryFailureModel` (fails `$failCount` times, then answers `Success after N attempts`),
 * which also records the gaps between attempts like `BackoffTestModel`. The counters are shared by the copies
 * `bindTools()` makes, so the test sees the calls the agent's bound model received.
 */
class ModelRetryTemporaryFailureModel extends FakeToolCallingModel
{
    /** @var \stdClass{attempt: int, delays: list<float>, last: float|null} */
    public \stdClass $record;

    /** @param (\Closure(): \Throwable)|null $makeError */
    public function __construct(private int $failCount, private ?\Closure $makeError = null)
    {
        parent::__construct(['toolCalls' => [[]]]);
        $this->record = (object) ['attempt' => 0, 'delays' => [], 'last' => null];
    }

    public function generateCalls(): int
    {
        return $this->record->attempt;
    }

    /** @return list<float> */
    public function delays(): array
    {
        return $this->record->delays;
    }

    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $now = microtime(true) * 1000;
        if ($this->record->attempt > 0) {
            $this->record->delays[] = $now - $this->record->last;
        }
        $this->record->last = $now;
        ++$this->record->attempt;

        if ($this->record->attempt <= $this->failCount) {
            throw $this->makeError !== null ? ($this->makeError)() : new \Exception("Temporary failure {$this->record->attempt}");
        }

        $result = parent::generate($messages, $options, $runManager);
        $generation = $result->generations[0];
        $generation->message = new AIMessage(['content' => "Success after {$this->record->attempt} attempts", 'id' => $generation->message->id]);

        return $result;
    }
}

/** Upstream's `ModelRetryAlwaysFailingModel`: every generation throws the given error. */
class ModelRetryAlwaysFailingModel extends FakeToolCallingModel
{
    /** @var \stdClass{calls: int} */
    public \stdClass $record;

    public function __construct(private \Throwable $error)
    {
        parent::__construct(['toolCalls' => [[]]]);
        $this->record = (object) ['calls' => 0];
    }

    public function generateCalls(): int
    {
        return $this->record->calls;
    }

    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        ++$this->record->calls;

        throw $this->error;
    }
}
