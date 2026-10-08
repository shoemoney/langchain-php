<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Runtime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/tests/runtime.test.ts`: the runtime a middleware hook receives cannot be written to.
 * Upstream expects "Cannot assign to read only property"; the PHP counterpart is the engine's own error for a
 * `readonly` property.
 */
#[CoversClass(Runtime::class)]
final class MiddlewareRuntimeTest extends TestCase
{
    private function expectReadonlyError(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
    }

    public function testShouldThrowOnTheAttemptToWriteToTheRuntimeInBeforeModel(): void
    {
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(),
            'middleware' => [Middleware::create([
                'name' => 'middleware',
                'beforeModel' => static function (array $state, Runtime $runtime): void {
                    $runtime->context = 123;
                },
            ])],
        ]);

        $this->expectReadonlyError();

        $agent->invoke(['messages' => [new HumanMessage('What is the weather in Tokyo?')]]);
    }

    public function testShouldThrowOnTheAttemptToWriteToTheRuntimeInAfterModel(): void
    {
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(),
            'middleware' => [Middleware::create([
                'name' => 'middleware',
                'afterModel' => static function (array $state, Runtime $runtime): void {
                    $runtime->context = 123;
                },
            ])],
        ]);

        $this->expectReadonlyError();

        $agent->invoke(['messages' => [new HumanMessage('What is the weather in Tokyo?')]]);
    }

    public function testShouldThrowOnTheAttemptToWriteToTheRuntimeInWrapModelCall(): void
    {
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(),
            'middleware' => [Middleware::create([
                'name' => 'middleware',
                'wrapModelCall' => static function (array $request, callable $handler): mixed {
                    $request['runtime']->context = 123;

                    return $handler($request);
                },
            ])],
        ]);

        // The error is wrapped like any error raised by a wrapModelCall hook.
        try {
            $agent->invoke(['messages' => [new HumanMessage('What is the weather in Tokyo?')]]);
            self::fail('Writing to the runtime should fail.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('Cannot modify readonly property', $e->getMessage());
        }
    }
}
