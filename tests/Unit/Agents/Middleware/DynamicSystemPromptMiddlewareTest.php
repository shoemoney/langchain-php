<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\DynamicSystemPromptMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/middleware/tests/dynamicSystemPrompt.test.ts`.
 *
 * The mock model's `invoke` spy is the spying fake chat model; the first message of its first call is the
 * system message.
 */
#[CoversClass(DynamicSystemPromptMiddleware::class)]
final class DynamicSystemPromptMiddlewareTest extends TestCase
{
    private const CONTEXT_SCHEMA = ['type' => 'object', 'properties' => ['region' => ['type' => 'string']]];

    public function testShouldSetSystemMessageFromDynamicPromptBeforeModelCall(): void
    {
        $model = ReactAgentFixtures::spy([new AIMessage('Response from model')]);

        $middleware = DynamicSystemPromptMiddleware::create(
            static fn (array $state, mixed $runtime): string => 'You are a helpful assistant. Region: ' . ($runtime->context['region'] ?? 'n/a'),
        );

        $agent = Agent::create(['model' => $model, 'middleware' => [$middleware], 'contextSchema' => self::CONTEXT_SCHEMA]);

        $agent->invoke(
            ['messages' => [new HumanMessage('Hello'), new AIMessage('Hi there!')]],
            ['context' => ['region' => 'EU']],
        );

        self::assertNotEmpty($model->generateCalls);
        $firstMessage = $model->generateCalls[0][0];
        self::assertSame('system', $firstMessage->type);
        self::assertSame('You are a helpful assistant. Region: EU', $firstMessage->text());
    }

    public function testShouldSupportReturningASystemMessage(): void
    {
        $model = ReactAgentFixtures::spy([new AIMessage('Response from model')]);

        $middleware = DynamicSystemPromptMiddleware::create(
            static fn (array $state, mixed $runtime): SystemMessage => new SystemMessage('You are a helpful assistant. Region: ' . ($runtime->context['region'] ?? 'n/a')),
        );

        $agent = Agent::create(['model' => $model, 'middleware' => [$middleware], 'contextSchema' => self::CONTEXT_SCHEMA]);

        $agent->invoke(
            ['messages' => [new HumanMessage('Hello'), new AIMessage('Hi there!')]],
            ['context' => ['region' => 'EU']],
        );

        self::assertNotEmpty($model->generateCalls);
        $firstMessage = $model->generateCalls[0][0];
        self::assertSame('system', $firstMessage->type);
        self::assertSame('You are a helpful assistant. Region: EU', $firstMessage->content);
    }

    public function testShouldThrowIfTheFunctionDoesNotReturnAnExpectedType(): void
    {
        $model = ReactAgentFixtures::spy([new AIMessage('Response from model')]);

        // @phpstan-ignore-next-line - the error case under test: not a string or SystemMessage
        $middleware = DynamicSystemPromptMiddleware::create(static fn (array $state, mixed $runtime): int => 123);

        $agent = Agent::create(['model' => $model, 'middleware' => [$middleware], 'contextSchema' => self::CONTEXT_SCHEMA]);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('dynamicSystemPromptMiddleware function must return a string or SystemMessage');

        $agent->invoke(
            ['messages' => [new HumanMessage('Hello'), new AIMessage('Hi there!')]],
            ['context' => ['region' => 'EU']],
        );
    }
}
