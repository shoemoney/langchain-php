<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\ReactAgent;
use LangGraph\Pregel\Command;
use LangGraph\State\Annotation;
use LangGraph\State\AnnotationRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The part of `langchain/src/agents/tests/state_schema.test.ts` that a JSON-Schema-native port can express.
 *
 * `StateSchema` / `ReducedValue` become an {@see AnnotationRoot} whose reducer channels play the `ReducedValue`
 * role (`withReducer(reducer, default)`), and `z.object({...})` fields become JSON Schema `properties` with
 * `default` / `required`.
 *
 * Not converted, and why:
 *  - "should infer ReducedValue hooks as value types", "should infer reduced fields in InferAgentState",
 *    "should infer mixed reduced and non-reduced fields" and "should infer agent state type using
 *    InferAgentState": they assert on TypeScript types only (`expectTypeOf`), which PHP does not have;
 *  - "should support StateSchema with responseFormat": needs structured responses (WP-21c).
 */
#[CoversClass(ReactAgent::class)]
final class StateSchemaTest extends TestCase
{
    /**
     * A `history`-style channel that appends each write and records every reducer call.
     *
     * @param list<array{current: list<string>, next: string}> $calls
     */
    private static function appending(array &$calls, ?string $key = null): AnnotationRoot
    {
        return Annotation::root([
            $key ?? 'history' => Annotation::withReducer(
                static function (array $current, string $next) use (&$calls): array {
                    $calls[] = ['current' => $current, 'next' => $next];

                    return [...$current, $next];
                },
                static fn (): array => [],
            ),
        ]);
    }

    private static function modelWithoutCalls(): FakeToolCallingModel
    {
        return new FakeToolCallingModel(['toolCalls' => []]);
    }

    public function testShouldAcceptAStateSchemaAsStateSchema(): void
    {
        $agentState = ['type' => 'object', 'properties' => ['userId' => ['type' => 'string'], 'count' => ['type' => 'number', 'default' => 0]], 'required' => ['userId']];

        $agent = Agent::create(['model' => self::modelWithoutCalls(), 'tools' => [], 'stateSchema' => $agentState]);

        self::assertNotNull($agent);
        self::assertSame($agentState, $agent->options['stateSchema']);
    }

    public function testShouldAcceptAStateSchemaWithReducedValueFields(): void
    {
        $calls = [];
        $agentState = self::appending($calls);

        $agent = Agent::create(['model' => self::modelWithoutCalls(), 'tools' => [], 'stateSchema' => $agentState]);

        self::assertNotNull($agent);
        self::assertSame($agentState, $agent->options['stateSchema']);
    }

    public function testShouldAcceptAStateSchemaOfPlainJsonSchemaTypes(): void
    {
        $agent = Agent::create([
            'model' => self::modelWithoutCalls(),
            'tools' => [],
            'stateSchema' => ['type' => 'object', 'properties' => ['userId' => ['type' => 'string'], 'count' => ['type' => 'number', 'default' => 0]]],
        ]);

        self::assertNotNull($agent);
    }

    public function testShouldInvokeReducerWhenToolReturnsCommandWithStateUpdate(): void
    {
        $reducerCalls = [];
        $addHistoryTool = tool(
            static fn (array $in): Command => new Command(update: [
                'history' => $in['entry'],
                'messages' => [new ToolMessage(['content' => 'Added: ' . $in['entry'], 'tool_call_id' => '1'])],
            ]),
            ['name' => 'add_history', 'description' => 'Add an entry to history', 'schema' => Schema::object(['entry' => ['type' => 'string', 'description' => 'The entry to add']], ['entry'])],
        );

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'add_history', 'args' => ['entry' => 'first_entry'], 'id' => '1']]]]),
            'tools' => [$addHistoryTool],
            'stateSchema' => self::appending($reducerCalls),
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Add an entry')], 'history' => 'initial']);

        // The reducer was actually called.
        self::assertGreaterThan(0, \count($reducerCalls));
        self::assertContains(['current' => ['initial'], 'next' => 'first_entry'], $reducerCalls);

        // The final state.
        self::assertSame(['initial', 'first_entry'], $result['history']);
    }

    public function testShouldInvokeReducerMultipleTimesForMultipleToolCalls(): void
    {
        $reducerCalls = [];
        $addTaskTool = tool(
            static function (array $in, mixed $runManager = null, ?\LangChain\Runnables\RunnableConfig $config = null): Command {
                return new Command(update: [
                    'tasks' => $in['taskName'],
                    'messages' => [new ToolMessage(['content' => 'Added task: ' . $in['taskName'], 'tool_call_id' => $config->toolCall['id'] ?? 'unknown'])],
                ]);
            },
            ['name' => 'add_task', 'description' => 'Add a task to the list', 'schema' => Schema::object(['taskName' => ['type' => 'string', 'description' => 'The name of the task to add']], ['taskName'])],
        );

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[
                ['name' => 'add_task', 'args' => ['taskName' => 'Task A'], 'id' => '1'],
                ['name' => 'add_task', 'args' => ['taskName' => 'Task B'], 'id' => '2'],
            ]]]),
            'tools' => [$addTaskTool],
            'stateSchema' => self::appending($reducerCalls, 'tasks'),
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Add two tasks')], 'tasks' => 'Initial Task']);

        // The reducer was called for each task.
        self::assertGreaterThanOrEqual(2, \count($reducerCalls));
        // The final state contains all of them.
        self::assertSame(['Initial Task', 'Task A', 'Task B'], $result['tasks']);
    }

    public function testShouldInvokeReducerThroughMiddlewareBeforeModelHook(): void
    {
        $reducerCalls = [];
        $middleware = Middleware::create([
            'name' => 'HistoryMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['history' => ['type' => 'string']], 'required' => ['history']],
            'beforeModel' => static fn (): array => ['history' => 'from_beforeModel'],
            'afterModel' => static fn (): array => ['history' => 'from_afterModel'],
        ]);

        $agent = Agent::create([
            'model' => self::modelWithoutCalls(),
            'tools' => [],
            'stateSchema' => self::appending($reducerCalls),
            'middleware' => [$middleware],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Test middleware')], 'history' => 'initial']);

        // The reducer was called from the middleware hooks.
        self::assertGreaterThanOrEqual(2, \count($reducerCalls));
        self::assertContains(['current' => ['initial'], 'next' => 'from_beforeModel'], $reducerCalls);
        self::assertContains(['current' => ['initial', 'from_beforeModel'], 'next' => 'from_afterModel'], $reducerCalls);

        self::assertSame(['initial', 'from_beforeModel', 'from_afterModel'], $result['history']);
    }

    public function testShouldInvokeReducerThroughWrapToolCallReturningCommand(): void
    {
        $reducerCalls = [];
        $dummyTool = tool(static fn (): string => 'tool executed', ['name' => 'dummy_tool', 'description' => 'A dummy tool for testing', 'schema' => Schema::object([])]);

        $middleware = Middleware::create([
            'name' => 'AuditMiddleware',
            'wrapToolCall' => static fn (array $request): Command => new Command(update: [
                'auditLog' => 'tool_called:' . $request['toolCall']['name'],
                'messages' => [new ToolMessage(['content' => 'Intercepted by middleware', 'tool_call_id' => $request['toolCall']['id']])],
            ]),
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'dummy_tool', 'args' => [], 'id' => '1']]]]),
            'tools' => [$dummyTool],
            'stateSchema' => self::appending($reducerCalls, 'auditLog'),
            'middleware' => [$middleware],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Test wrapToolCall Command')], 'auditLog' => 'initial']);

        // The reducer was called from the wrapToolCall Command.
        self::assertGreaterThan(0, \count($reducerCalls));
        self::assertContains(['current' => ['initial'], 'next' => 'tool_called:dummy_tool'], $reducerCalls);

        self::assertSame(['initial', 'tool_called:dummy_tool'], $result['auditLog']);
    }

    public function testShouldWorkWithMiddlewareThatHasItsOwnStateSchemaAlongsideAgentStateSchema(): void
    {
        $agentReducerCalls = [];
        $middleware = Middleware::create([
            'name' => 'TrackerMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['requestCount' => ['type' => 'number', 'default' => 0], 'events' => ['type' => 'string']], 'required' => ['events']],
            'beforeModel' => static fn (array $state): array => [
                'events' => 'model_request_started',
                'requestCount' => ($state['requestCount'] ?? 0) + 1,
            ],
            'afterModel' => static fn (): array => ['events' => 'model_request_completed'],
        ]);

        $agent = Agent::create([
            'model' => self::modelWithoutCalls(),
            'tools' => [],
            'stateSchema' => self::appending($agentReducerCalls, 'events'),
            'middleware' => [$middleware],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Test combined schemas')], 'requestCount' => 0, 'events' => 'initial']);

        // The agent's reducer was called.
        self::assertGreaterThanOrEqual(2, \count($agentReducerCalls));

        // The final state includes both the agent's and the middleware's state.
        self::assertContains('model_request_started', $result['events']);
        self::assertContains('model_request_completed', $result['events']);
        self::assertSame(1, $result['requestCount']);
    }

    public function testShouldPreserveCustomStateValuesThroughAgentExecution(): void
    {
        $agent = Agent::create([
            'model' => self::modelWithoutCalls(),
            'tools' => [],
            'stateSchema' => ['type' => 'object', 'properties' => ['userId' => ['type' => 'string'], 'sessionId' => ['type' => 'string'], 'counter' => ['type' => 'number', 'default' => 0]]],
        ]);

        $result = $agent->invoke([
            'messages' => [new HumanMessage('test')],
            'userId' => 'user-123',
            'sessionId' => 'session-456',
            'counter' => 5,
        ]);

        self::assertSame('user-123', $result['userId']);
        self::assertSame('session-456', $result['sessionId']);
        self::assertSame(5, $result['counter']);
    }

    public function testShouldUseDefaultValuesWhenNotProvided(): void
    {
        // The default of a state field of the agent lives on its channel.
        $agent = Agent::create([
            'model' => self::modelWithoutCalls(),
            'tools' => [],
            'stateSchema' => Annotation::root([
                'count' => Annotation::last(static fn (): int => 42),
                'name' => Annotation::last(static fn (): string => 'default-name'),
            ]),
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('test')]]);

        self::assertSame(42, $result['count']);
        self::assertSame('default-name', $result['name']);
    }

    public function testShouldHaveProperResultStructureWithAStateSchema(): void
    {
        $agent = Agent::create([
            'model' => self::modelWithoutCalls(),
            'tools' => [],
            'stateSchema' => ['type' => 'object', 'properties' => ['userId' => ['type' => 'string'], 'count' => ['type' => 'number', 'default' => 0]]],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('test')], 'userId' => 'user-123', 'count' => 5]);

        // The result has the expected properties.
        self::assertArrayHasKey('messages', $result);
        self::assertArrayHasKey('userId', $result);
        self::assertArrayHasKey('count', $result);
        self::assertIsArray($result['messages']);
    }

    public function testShouldHaveProperResultStructureWithReducedValue(): void
    {
        $calls = [];
        $agent = Agent::create(['model' => self::modelWithoutCalls(), 'tools' => [], 'stateSchema' => self::appending($calls)]);

        $result = $agent->invoke(['messages' => [new HumanMessage('test')], 'history' => 'initial']);

        // history is an array at runtime.
        self::assertIsArray($result['history']);
        self::assertSame(['initial'], $result['history']);
    }

    public function testShouldProperlyMergeStateSchemaWithMiddlewareStateSchemaTypes(): void
    {
        $middleware = Middleware::create([
            'name' => 'TestMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['middlewareField' => ['type' => 'number', 'default' => 0]]],
        ]);

        $agent = Agent::create([
            'model' => self::modelWithoutCalls(),
            'tools' => [],
            'stateSchema' => ['type' => 'object', 'properties' => ['agentField' => ['type' => 'string']], 'required' => ['agentField']],
            'middleware' => [$middleware],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('test')], 'agentField' => 'value', 'middlewareField' => 42]);

        // Both the agent's and the middleware's fields are present.
        self::assertArrayHasKey('agentField', $result);
        self::assertArrayHasKey('middlewareField', $result);
        self::assertSame('value', $result['agentField']);
        self::assertSame(42, $result['middlewareField']);
    }

    public function testShouldAcceptMiddlewareWithAStateSchemaStateSchema(): void
    {
        $middleware = Middleware::create([
            'name' => 'StateSchemaMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['userId' => ['type' => 'string'], 'count' => ['type' => 'number', 'default' => 0]], 'required' => ['userId']],
            'beforeModel' => function (array $state): array {
                self::assertSame('test-user', $state['userId']);
                self::assertSame(0, $state['count']);

                return ['count' => ($state['count'] ?? 0) + 1];
            },
        ]);

        $agent = Agent::create(['model' => self::modelWithoutCalls(), 'tools' => [], 'middleware' => [$middleware]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Test')], 'userId' => 'test-user', 'count' => 0]);

        self::assertSame('test-user', $result['userId']);
        self::assertSame(1, $result['count']);
    }

    public function testShouldHandleMiddlewareWithAStateSchemaContainingReducedValue(): void
    {
        $calls = [];
        $middleware = Middleware::create([
            'name' => 'HistoryMiddleware',
            'stateSchema' => self::appending($calls),
            'beforeModel' => static fn (): array => ['history' => 'middleware-entry'],
        ]);

        $agent = Agent::create(['model' => self::modelWithoutCalls(), 'tools' => [], 'middleware' => [$middleware]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Test')], 'history' => 'initial']);

        self::assertContains('initial', $result['history']);
        self::assertContains('middleware-entry', $result['history']);
    }

    public function testShouldHandlePrivateFieldsInStateSchemaMiddleware(): void
    {
        $middleware = Middleware::create([
            'name' => 'PrivateFieldMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['publicField' => ['type' => 'string'], '_privateField' => ['type' => 'string']], 'required' => ['publicField', '_privateField']],
            'beforeModel' => function (array $state): array {
                self::assertSame('public-value', $state['publicField']);

                // The private field is accessible internally.
                return ['_privateField' => 'private-value'];
            },
        ]);

        $agent = Agent::create(['model' => self::modelWithoutCalls(), 'tools' => [], 'middleware' => [$middleware]]);

        // Works without providing the private field.
        $result = $agent->invoke(['messages' => [new HumanMessage('Test')], 'publicField' => 'public-value']);

        self::assertSame('public-value', $result['publicField']);
        self::assertArrayNotHasKey('_privateField', $result);
    }

    public function testShouldThrowErrorForMissingRequiredFieldsInStateSchemaMiddleware(): void
    {
        $middleware = Middleware::create([
            'name' => 'RequiredFieldMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['requiredField' => ['type' => 'string'], 'optionalField' => ['type' => 'string']], 'required' => ['requiredField']],
        ]);

        $agent = Agent::create(['model' => self::modelWithoutCalls(), 'tools' => [], 'middleware' => [$middleware]]);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/requiredField.*Required/');

        // requiredField is missing.
        $agent->invoke(['messages' => [new HumanMessage('Test')], 'optionalField' => 'optional-value']);
    }

    public function testShouldWorkWithDefaultValuesInStateSchemaMiddleware(): void
    {
        $middleware = Middleware::create([
            'name' => 'DefaultValueMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['withDefault' => ['type' => 'string', 'default' => 'default-value'], 'withoutDefault' => ['type' => 'string']]],
            'beforeModel' => function (array $state): array {
                self::assertSame('default-value', $state['withDefault']);

                return [];
            },
        ]);

        $agent = Agent::create(['model' => self::modelWithoutCalls(), 'tools' => [], 'middleware' => [$middleware]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Test')], 'withDefault' => 'default-value']);

        self::assertSame('default-value', $result['withDefault']);
    }

    public function testShouldHandleMixedJsonSchemaAndAnnotationMiddleware(): void
    {
        $annotationMiddleware = Middleware::create([
            'name' => 'StateSchemaMiddleware',
            'stateSchema' => Annotation::root(['stateSchemaField' => Annotation::last(static fn (): string => 'from-state-schema')]),
        ]);
        $jsonMiddleware = Middleware::create([
            'name' => 'ZodMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['zodField' => ['type' => 'string', 'default' => 'from-zod']]],
        ]);

        $agent = Agent::create(['model' => self::modelWithoutCalls(), 'tools' => [], 'middleware' => [$annotationMiddleware, $jsonMiddleware]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Test')], 'stateSchemaField' => 'from-state-schema', 'zodField' => 'from-zod']);

        self::assertSame('from-state-schema', $result['stateSchemaField']);
        self::assertSame('from-zod', $result['zodField']);
    }
}
