<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Pregel\Command;
use LangGraph\State\Annotation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/tests/state.test.ts`.
 *
 * The Zod v4 reducer registry of the second case is an `AnnotationRoot` reducer channel here.
 */
#[CoversClass(Middleware::class)]
final class MiddlewareStateTest extends TestCase
{
    /**
     * @param list<string> $names
     * @return array<string, mixed>
     */
    private static function strings(array $names): array
    {
        return ['type' => 'object', 'properties' => array_fill_keys($names, ['type' => 'string']), 'required' => $names];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function rest(array $state): array
    {
        unset($state['messages']);

        return $state;
    }

    public function testShouldAllowToDefinePrivateStatePropsWithUnderscoreThatDoNotLeakOut(): void
    {
        $model = new FakeToolCallingModel(['toolCalls' => [[['name' => 'get_weather', 'args' => ['location' => 'Tokyo'], 'id' => '1']]]]);

        // Track which hooks have been run so the assertions don't run twice.
        $hooksRun = [];
        $once = static function (string $hook) use (&$hooksRun): bool {
            if (isset($hooksRun[$hook])) {
                return false;
            }
            $hooksRun[$hook] = true;

            return true;
        };

        // Middleware A defines beforeModel, wrapToolCall, wrapModelCall and afterModel.
        $middlewareA = Middleware::create([
            'name' => 'middlewareA',
            'stateSchema' => self::strings(['middlewareABeforeModelState', 'middlewareAAfterModelState', '_privateMiddlewareAState']),
            'beforeModel' => function (array $state) use ($once): ?array {
                if (!$once('middlewareA_beforeModel')) {
                    return null;
                }

                // The built-in state is present.
                self::assertArrayHasKey('messages', $state);
                self::assertSame(
                    ['middlewareABeforeModelState' => 'ABefore', 'middlewareAAfterModelState' => 'AAfter'],
                    self::rest($state),
                );

                return ['middlewareABeforeModelState' => 'middlewareABeforeModelState', '_privateMiddlewareAState' => 'privateMiddlewareAState'];
            },
            'wrapToolCall' => function (array $request, callable $handler) use ($once): mixed {
                if ($once('middlewareA_wrapToolCall')) {
                    self::assertEqualsCanonicalizing(
                        [
                            'middlewareABeforeModelState' => 'middlewareABeforeModelState',
                            'middlewareAAfterModelState' => 'middlewareAAfterModelState',
                            '_privateMiddlewareAState' => 'privateMiddlewareAState',
                        ],
                        self::rest($request['state']),
                    );
                }

                return $handler($request);
            },
            'wrapModelCall' => function (array $request, callable $handler) use ($once): mixed {
                if ($once('middlewareA_wrapModelCall')) {
                    self::assertEqualsCanonicalizing(
                        [
                            'middlewareABeforeModelState' => 'middlewareABeforeModelState',
                            'middlewareAAfterModelState' => 'AAfter',
                            '_privateMiddlewareAState' => 'privateMiddlewareAState',
                        ],
                        self::rest($request['state']),
                    );
                }

                return $handler($request);
            },
            'afterModel' => function (array $state) use ($once): ?array {
                if (!$once('middlewareA_afterModel')) {
                    return null;
                }
                self::assertArrayHasKey('messages', $state);
                self::assertEqualsCanonicalizing(
                    [
                        'middlewareABeforeModelState' => 'middlewareABeforeModelState',
                        'middlewareAAfterModelState' => 'AAfter',
                        '_privateMiddlewareAState' => 'privateMiddlewareAState',
                    ],
                    self::rest($state),
                );

                return ['middlewareAAfterModelState' => 'middlewareAAfterModelState', '_privateMiddlewareAState' => 'privateMiddlewareAState'];
            },
        ]);

        // Middleware B defines beforeModel, wrapModelCall and wrapToolCall.
        $middlewareB = Middleware::create([
            'name' => 'middlewareB',
            'stateSchema' => self::strings(['middlewareBBeforeModelState', 'middlewareBAfterModelState', '_privateMiddlewareBState']),
            'beforeModel' => function (array $state) use ($once): ?array {
                if (!$once('middlewareB_beforeModel')) {
                    return null;
                }
                self::assertArrayHasKey('messages', $state);
                self::assertEqualsCanonicalizing(
                    ['middlewareBBeforeModelState' => 'BBefore', 'middlewareBAfterModelState' => 'BAfter'],
                    self::rest($state),
                );

                return ['middlewareBBeforeModelState' => 'middlewareBBeforeModelState', '_privateMiddlewareBState' => 'privateMiddlewareBState'];
            },
            'wrapModelCall' => function (array $request, callable $handler) use ($once): mixed {
                if ($once('middlewareB_wrapModelCall')) {
                    self::assertEqualsCanonicalizing(
                        [
                            'middlewareBBeforeModelState' => 'middlewareBBeforeModelState',
                            'middlewareBAfterModelState' => 'BAfter',
                            '_privateMiddlewareBState' => 'privateMiddlewareBState',
                        ],
                        self::rest($request['state']),
                    );
                }

                return $handler($request);
            },
            'wrapToolCall' => function (array $request, callable $handler) use ($once): mixed {
                if ($once('middlewareB_wrapToolCall')) {
                    self::assertEqualsCanonicalizing(
                        [
                            'middlewareBBeforeModelState' => 'middlewareBBeforeModelState',
                            'middlewareBAfterModelState' => 'BAfter',
                            '_privateMiddlewareBState' => 'privateMiddlewareBState',
                        ],
                        self::rest($request['state']),
                    );
                }

                return $handler($request);
            },
        ]);

        // Middleware C defines wrapModelCall, wrapToolCall and afterModel.
        $middlewareC = Middleware::create([
            'name' => 'middlewareC',
            'stateSchema' => self::strings(['middlewareCBeforeModelState', 'middlewareCAfterModelState', '_privateMiddlewareCState']),
            'wrapModelCall' => function (array $request, callable $handler) use ($once): mixed {
                if ($once('middlewareC_wrapModelCall')) {
                    self::assertEqualsCanonicalizing(
                        ['middlewareCBeforeModelState' => 'CBefore', 'middlewareCAfterModelState' => 'CAfter'],
                        self::rest($request['state']),
                    );
                }

                return $handler($request);
            },
            'wrapToolCall' => function (array $request, callable $handler) use ($once): mixed {
                if ($once('middlewareC_wrapToolCall')) {
                    self::assertEqualsCanonicalizing(
                        [
                            'middlewareCBeforeModelState' => 'CBefore',
                            'middlewareCAfterModelState' => 'middlewareCAfterModelState',
                            '_privateMiddlewareCState' => 'privateMiddlewareCState',
                        ],
                        self::rest($request['state']),
                    );
                }

                return $handler($request);
            },
            'afterModel' => function (array $state) use ($once): ?array {
                if (!$once('middlewareC_afterModel')) {
                    return null;
                }
                self::assertArrayHasKey('messages', $state);
                self::assertEqualsCanonicalizing(
                    ['middlewareCBeforeModelState' => 'CBefore', 'middlewareCAfterModelState' => 'CAfter'],
                    self::rest($state),
                );

                return ['middlewareCAfterModelState' => 'middlewareCAfterModelState', '_privateMiddlewareCState' => 'privateMiddlewareCState'];
            },
        ]);

        $weatherTool = tool(
            static fn (array $in): string => 'The weather in ' . $in['location'] . ' is sunny',
            ['name' => 'get_weather', 'description' => 'Get the weather in a location', 'schema' => Schema::object(['location' => ['type' => 'string']], ['location'])],
        );

        $agent = Agent::create(['model' => $model, 'tools' => [$weatherTool], 'middleware' => [$middlewareA, $middlewareB, $middlewareC]]);

        $result = $agent->invoke([
            'messages' => [new HumanMessage('What is the weather in Tokyo?')],
            'middlewareABeforeModelState' => 'ABefore',
            'middlewareAAfterModelState' => 'AAfter',
            'middlewareBBeforeModelState' => 'BBefore',
            'middlewareBAfterModelState' => 'BAfter',
            'middlewareCBeforeModelState' => 'CBefore',
            'middlewareCAfterModelState' => 'CAfter',
        ]);

        // Every hook ran (and so asserted).
        self::assertCount(10, $hooksRun);

        self::assertCount(3, $result['messages']);
        // The private state does not leak out of the result.
        self::assertEqualsCanonicalizing([
            'middlewareABeforeModelState' => 'middlewareABeforeModelState',
            'middlewareAAfterModelState' => 'middlewareAAfterModelState',
            'middlewareBBeforeModelState' => 'middlewareBBeforeModelState',
            'middlewareBAfterModelState' => 'BAfter',
            'middlewareCBeforeModelState' => 'CBefore',
            'middlewareCAfterModelState' => 'middlewareCAfterModelState',
        ], self::rest($result));
    }

    public function testShouldPreserveReducerMetadataOfTheStateSchema(): void
    {
        // A `tasks` list that accumulates: a string or a list of strings is appended to the existing list.
        $stateSchema = Annotation::root([
            'tasks' => Annotation::withReducer(
                static function (array $existing, mixed $incoming): array {
                    if ($incoming === null) {
                        return $existing;
                    }

                    return [...$existing, ...(\is_array($incoming) ? $incoming : [$incoming])];
                },
                static fn (): array => [],
            ),
        ]);

        $addTaskTool = tool(
            static fn (array $in): Command => new Command(update: [
                'tasks' => $in['taskName'],
                'messages' => [new ToolMessage(['content' => 'Added task: ' . $in['taskName'], 'tool_call_id' => 'test'])],
            ]),
            [
                'name' => 'add_task',
                'description' => 'Add a task to the list',
                'schema' => Schema::object(['taskName' => ['type' => 'string', 'description' => 'The name of the task to add']], ['taskName']),
                'returnDirect' => false,
            ],
        );

        $model = new FakeToolCallingModel(['toolCalls' => [[
            ['name' => 'add_task', 'args' => ['taskName' => 'Task A'], 'id' => '1'],
            ['name' => 'add_task', 'args' => ['taskName' => 'Task B'], 'id' => '2'],
        ]]]);

        $agent = Agent::create(['model' => $model, 'tools' => [$addTaskTool], 'stateSchema' => $stateSchema]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Add two tasks')], 'tasks' => ['Initial Task']]);

        self::assertSame(['Initial Task', 'Task A', 'Task B'], $result['tasks']);
    }
}
