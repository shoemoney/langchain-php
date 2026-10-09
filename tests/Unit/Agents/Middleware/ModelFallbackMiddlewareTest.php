<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\FlakyChatModel;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\ModelFallbackMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/middleware/tests/modelFallback.test.ts`.
 *
 * Upstream's mock models (`invoke: vi.fn().mockRejectedValue(...)`) are {@see FlakyChatModel}s: `failFirst`
 * says how many calls fail before the model answers, and its record counts the calls.
 */
#[CoversClass(ModelFallbackMiddleware::class)]
final class ModelFallbackMiddlewareTest extends TestCase
{
    private const ALWAYS = \PHP_INT_MAX;

    private static function model(int $failFirst = 0): FlakyChatModel
    {
        return new FlakyChatModel([
            'responses' => [new AIMessage('Response from model')],
            'failFirst' => $failFirst,
            'failMessage' => 'Model error',
        ]);
    }

    public function testShouldRetryTheModelRequestWithTheNewModel(): void
    {
        $model = self::model(self::ALWAYS);
        $retryModel = self::model();
        $agent = Agent::create(['model' => $model, 'tools' => [], 'middleware' => [ModelFallbackMiddleware::create($retryModel)]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Hello, world!')]]);

        self::assertCount(1, $model->invokeCalls());
        self::assertCount(1, $retryModel->invokeCalls());
        self::assertSame('Response from model', $result['messages'][array_key_last($result['messages'])]->content);
    }

    public function testShouldAllowToConfigureAdditionalModels(): void
    {
        $model = self::model(1);
        $anotherFailingModel = self::model(self::ALWAYS);
        $retryModel = self::model();
        $agent = Agent::create([
            'model' => $model,
            'tools' => [],
            'middleware' => [ModelFallbackMiddleware::create($anotherFailingModel, $anotherFailingModel, $anotherFailingModel, $retryModel)],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('Hello, world!')]]);

        self::assertCount(1, $model->invokeCalls());
        self::assertCount(3, $anotherFailingModel->invokeCalls());
        self::assertCount(1, $retryModel->invokeCalls());
    }

    public function testShouldThrowIfListIsExhausted(): void
    {
        $model = self::model(1);
        $anotherFailingModel = self::model(self::ALWAYS);
        $agent = Agent::create([
            'model' => $model,
            'tools' => [],
            'middleware' => [ModelFallbackMiddleware::create($anotherFailingModel, $anotherFailingModel, $anotherFailingModel)],
        ]);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Model error');

        $agent->invoke(['messages' => [new HumanMessage('Hello, world!')]]);
    }
}
