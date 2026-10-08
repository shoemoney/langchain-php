<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Runtime;
use LangGraph\Store\InMemoryStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The state, context, control-action and store cases of `langchain/src/agents/tests/middleware.test.ts`.
 *
 * Zod objects and `StateSchema`s become JSON Schema arrays (`properties`, `required`, `default`). The
 * `wrapModelCall`, `wrapToolCall`, agent-hook and Command cases are in the sibling `Middleware*Test` files.
 */
#[CoversClass(Middleware::class)]
final class MiddlewareTest extends TestCase
{
    /**
     * @param list<string> $names
     * @return array<string, mixed>
     */
    private static function requiredStrings(array $names): array
    {
        return [
            'type' => 'object',
            'properties' => array_fill_keys($names, ['type' => 'string']),
            'required' => $names,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function withoutMessages(array $state): array
    {
        unset($state['messages']);

        return $state;
    }

    public function testShouldPropagateStateSchemaToMiddlewareHooksAndResult(): void
    {
        $initialState = [
            'messages' => [new HumanMessage('What is the weather in Tokyo?')],
            'middlewareABeforeModelState' => 'ABefore',
            'middlewareAAfterModelState' => 'AAfter',
            'middlewareBBeforeModelState' => 'BBefore',
            'middlewareBAfterModelState' => 'BAfter',
            'middlewareCBeforeModelState' => 'CBefore',
            'middlewareCAfterModelState' => 'CAfter',
        ];
        $model = AgentAssertions::fakeChat([new AIMessage('The weather in Tokyo is 25°C')]);

        $middlewareA = Middleware::create([
            'name' => 'middlewareA',
            'stateSchema' => self::requiredStrings(['middlewareABeforeModelState', 'middlewareAAfterModelState']),
            'beforeModel' => function (array $state): array {
                self::assertSame(
                    ['middlewareABeforeModelState' => 'ABefore', 'middlewareAAfterModelState' => 'AAfter'],
                    self::withoutMessages($state),
                );

                return ['middlewareABeforeModelState' => 'middlewareABeforeModelState'];
            },
            'afterModel' => function (array $state): array {
                self::assertSame(
                    ['middlewareABeforeModelState' => 'middlewareABeforeModelState', 'middlewareAAfterModelState' => 'AAfter'],
                    self::withoutMessages($state),
                );

                return ['middlewareAAfterModelState' => 'middlewareAAfterModelState'];
            },
        ]);
        $middlewareB = Middleware::create([
            'name' => 'middlewareB',
            'stateSchema' => self::requiredStrings(['middlewareBBeforeModelState', 'middlewareBAfterModelState']),
            'beforeModel' => function (array $state): array {
                self::assertEqualsCanonicalizing(
                    ['middlewareBAfterModelState' => 'BAfter', 'middlewareBBeforeModelState' => 'BBefore'],
                    self::withoutMessages($state),
                );

                return ['middlewareBBeforeModelState' => 'middlewareBBeforeModelState'];
            },
        ]);
        $middlewareC = Middleware::create([
            'name' => 'middlewareC',
            'stateSchema' => self::requiredStrings(['middlewareCBeforeModelState', 'middlewareCAfterModelState']),
            'afterModel' => function (array $state): array {
                self::assertEqualsCanonicalizing(
                    ['middlewareCAfterModelState' => 'CAfter', 'middlewareCBeforeModelState' => 'CBefore'],
                    self::withoutMessages($state),
                );

                return ['middlewareCAfterModelState' => 'middlewareCAfterModelState'];
            },
        ]);

        $agent = Agent::create(['model' => $model, 'tools' => [], 'middleware' => [$middlewareA, $middlewareB, $middlewareC]]);

        $result = $agent->invoke($initialState);

        // Overwritten by middlewareA's beforeModel hook.
        self::assertSame('middlewareABeforeModelState', $result['middlewareABeforeModelState']);
        // Overwritten by middlewareA's afterModel hook.
        self::assertSame('middlewareAAfterModelState', $result['middlewareAAfterModelState']);
        // Overwritten by middlewareB's beforeModel hook.
        self::assertSame('middlewareBBeforeModelState', $result['middlewareBBeforeModelState']);
        // Not overwritten by middlewareB's beforeModel hook.
        self::assertSame('BAfter', $result['middlewareBAfterModelState']);
        // Not overwritten by middlewareC's beforeModel hook.
        self::assertSame('CBefore', $result['middlewareCBeforeModelState']);
        // Overwritten by middlewareC's afterModel hook.
        self::assertSame('middlewareCAfterModelState', $result['middlewareCAfterModelState']);
    }

    public function testShouldPropagateContextSchemaToMiddlewareHooks(): void
    {
        $contexts = [];
        $middleware = Middleware::create([
            'name' => 'middleware',
            'contextSchema' => [
                'type' => 'object',
                'properties' => ['customMiddlewareContext' => ['type' => 'string'], 'customMiddlewareContext2' => ['type' => 'number', 'default' => 42]],
                'required' => ['customMiddlewareContext'],
            ],
            'beforeModel' => static function (array $state, Runtime $runtime) use (&$contexts): void {
                $contexts[] = $runtime->context;
            },
            'afterModel' => static function (array $state, Runtime $runtime) use (&$contexts): void {
                $contexts[] = $runtime->context;
            },
        ]);

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('The weather in Tokyo is 25°C')]),
            'tools' => [],
            'contextSchema' => [
                'type' => 'object',
                'properties' => ['customContext' => ['type' => 'string'], 'customContext2' => ['type' => 'number', 'default' => 42]],
                'required' => ['customContext'],
            ],
            'middleware' => [$middleware],
        ]);

        $agent->invoke(
            ['messages' => [new HumanMessage('Hello, world!')]],
            ['context' => ['customMiddlewareContext' => 'customMiddlewareContext', 'customContext' => 'customContext']],
        );

        $expected = ['customMiddlewareContext' => 'customMiddlewareContext', 'customMiddlewareContext2' => 42];
        self::assertSame([$expected, $expected], $contexts);
    }

    public function testShouldTerminateTheAgentInBeforeModelHook(): void
    {
        $toolCalls = 0;
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('The weather in Tokyo is 25°C')]),
            'tools' => [tool(
                static function () use (&$toolCalls): void {
                    $toolCalls++;
                },
                ['name' => 'tool', 'description' => 'tool', 'schema' => Schema::object(['name' => ['type' => 'string']], ['name'])],
            )],
            'middleware' => [Middleware::create([
                'name' => 'middleware',
                'beforeModel' => static function (): void {
                    throw new \Exception('middleware terminated');
                },
            ])],
        ]);

        try {
            $agent->invoke(['messages' => [new HumanMessage('Hello, world!')]]);
            self::fail('The middleware error should surface.');
        } catch (\Exception $e) {
            self::assertSame('middleware terminated', $e->getMessage());
        }
        self::assertSame(0, $toolCalls);
    }

    public function testShouldTerminateTheAgentInAfterModelHook(): void
    {
        $beforeModelCalls = 0;
        $toolCalls = 0;
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['id' => 'call_1', 'name' => 'tool', 'args' => ['name' => 'test']]]]]),
            'tools' => [tool(
                static function () use (&$toolCalls): void {
                    $toolCalls++;
                },
                ['name' => 'tool', 'description' => 'tool', 'schema' => Schema::object(['name' => ['type' => 'string']], ['name'])],
            )],
            'middleware' => [Middleware::create([
                'name' => 'middleware',
                'beforeModel' => static function () use (&$beforeModelCalls): void {
                    $beforeModelCalls++;
                },
                'afterModel' => static function (): void {
                    throw new \Exception('middleware terminated in afterModel');
                },
            ])],
        ]);

        try {
            $agent->invoke(['messages' => [new HumanMessage('Hello, world!')]]);
            self::fail('The middleware error should surface.');
        } catch (\Exception $e) {
            self::assertSame('middleware terminated in afterModel', $e->getMessage());
        }
        self::assertSame(0, $toolCalls);
        self::assertSame(1, $beforeModelCalls);
    }

    public function testShouldThrowIfMiddlewareJumpsButTargetIsNotDefined(): void
    {
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('The weather in Tokyo is 25°C')]),
            'tools' => [],
            'middleware' => [Middleware::create([
                'name' => 'foobar',
                'beforeModel' => static fn (): array => ['jumpTo' => 'model'],
            ])],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid jump target: model, no beforeModel.canJumpTo defined in middleware foobar.');

        $agent->invoke(['messages' => [new HumanMessage('Hello, world!')]]);
    }

    public function testShouldThrowIfMiddlewareJumpsButTargetIsNotDefinedWithAnEmptyCanJumpTo(): void
    {
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('The weather in Tokyo is 25°C')]),
            'tools' => [],
            'middleware' => [Middleware::create([
                'name' => 'foobar',
                'beforeModel' => ['canJumpTo' => [], 'hook' => static fn (): array => ['jumpTo' => 'model']],
            ])],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid jump target: model, no beforeModel.canJumpTo defined in middleware foobar.');

        $agent->invoke(['messages' => [new HumanMessage('Hello, world!')]]);
    }

    public function testShouldThrowIfMiddlewareJumpsButTargetIsNotValid(): void
    {
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('The weather in Tokyo is 25°C')]),
            'tools' => [],
            'middleware' => [Middleware::create([
                'name' => 'foobar',
                'beforeModel' => ['hook' => static fn (): array => ['jumpTo' => 'model'], 'canJumpTo' => ['tools', 'end']],
            ])],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid jump target: model, must be one of: tools, end.');

        $agent->invoke(['messages' => [new HumanMessage('Hello, world!')]]);
    }

    public function testShouldPropagateStoreToMiddlewareRuntime(): void
    {
        $store = new InMemoryStore();
        $storeValues = [];

        $hook = static function (array $state, Runtime $runtime) use (&$storeValues): array {
            $storeValues[] = $runtime->store;

            return [];
        };
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('hello')]),
            'tools' => [],
            'store' => $store,
            'middleware' => [Middleware::create(['name' => 'storeCheck', 'beforeAgent' => $hook, 'beforeModel' => $hook, 'afterModel' => $hook])],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('hi')]]);

        self::assertGreaterThan(0, \count($storeValues));
        foreach ($storeValues as $value) {
            self::assertNotNull($value);
        }
    }

    public function testShouldPropagateStoreToWrapToolCallMiddlewareRuntime(): void
    {
        $store = new InMemoryStore();
        $captured = [];

        $testTool = tool(static fn (): string => 'tool result', ['name' => 'test_tool', 'description' => 'A test tool', 'schema' => Schema::object([])]);
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'test_tool', 'args' => [], 'id' => '1']]]]),
            'tools' => [$testTool],
            'store' => $store,
            'middleware' => [Middleware::create([
                'name' => 'storeCheck',
                'wrapToolCall' => static function (array $request, callable $handler) use (&$captured): mixed {
                    $captured = ['store' => $request['runtime']->store, 'configurable' => $request['runtime']->configurable];

                    return $handler($request);
                },
            ])],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('call the tool')]]);

        // The store is propagated to wrapToolCall, and so is the configurable.
        self::assertNotNull($captured['store']);
        self::assertNotNull($captured['configurable']);
    }

    public function testShouldPropagateStoreToWrapModelCallMiddlewareRuntime(): void
    {
        $store = new InMemoryStore();
        $capturedStore = null;

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('hello')]),
            'tools' => [],
            'store' => $store,
            'middleware' => [Middleware::create([
                'name' => 'storeCheck',
                'wrapModelCall' => static function (array $request, callable $handler) use (&$capturedStore): mixed {
                    $capturedStore = $request['runtime']->store;

                    return $handler($request);
                },
            ])],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('hi')]]);

        self::assertNotNull($capturedStore);
    }
}
