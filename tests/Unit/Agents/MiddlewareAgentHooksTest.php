<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Runtime;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\State\Annotation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The "before/after agent hook" describe block of `langchain/src/agents/tests/middleware.test.ts`.
 *
 * Not converted: "should allow modifying structured response in after_agent hook", which needs a
 * `toolStrategy` response format (WP-21c). The `StateSchema` with a `ReducedValue` of the thread-leak case is an
 * `AnnotationRoot` with a reducer channel.
 */
#[CoversClass(Middleware::class)]
final class MiddlewareAgentHooksTest extends TestCase
{
    private function sampleTool(?\ArrayObject $log = null): \LangChain\Tools\StructuredTool
    {
        return tool(
            static function (array $in) use ($log): string {
                $log?->append('tool_execution');

                return 'Result for: ' . $in['query'];
            },
            ['name' => 'sample_tool', 'description' => 'A sample tool for testing', 'schema' => Schema::object(['query' => ['type' => 'string']], ['query'])],
        );
    }

    public function testShouldRunBeforeAgentAndAfterAgentOnlyOnceWithMultipleModelCalls(): void
    {
        $executionLog = new \ArrayObject();
        $record = static fn (string $entry): \Closure => static function () use ($executionLog, $entry): void {
            $executionLog->append($entry);
        };

        $middleware = Middleware::create([
            'name' => 'TestMiddleware',
            'beforeAgent' => $record('before_agent'),
            'beforeModel' => $record('before_model'),
            'afterModel' => $record('after_model'),
            'afterAgent' => $record('after_agent'),
        ]);

        // The model calls a tool twice, then answers: 3 model invocations, but the agent hooks still run once.
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [
                [['name' => 'sample_tool', 'args' => ['query' => 'first'], 'id' => '1']],
                [['name' => 'sample_tool', 'args' => ['query' => 'second'], 'id' => '2']],
                [], // The third call returns no tool calls (the final answer).
            ]]),
            'tools' => [$this->sampleTool()],
            'middleware' => [$middleware],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('Test')]]);

        self::assertSame([
            'before_agent',
            'before_model',
            'after_model',
            'before_model',
            'after_model',
            'before_model',
            'after_model',
            'after_agent',
        ], $executionLog->getArrayCopy());
    }

    public function testShouldExecuteMultipleBeforeAgentAndAfterAgentMiddlewareInCorrectOrder(): void
    {
        $executionLog = new \ArrayObject();
        $record = static fn (string $entry): \Closure => static function () use ($executionLog, $entry): void {
            $executionLog->append($entry);
        };

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Response')]),
            'tools' => [],
            'middleware' => [
                Middleware::create(['name' => 'Middleware1', 'beforeAgent' => $record('before_agent_1'), 'afterAgent' => $record('after_agent_1')]),
                Middleware::create(['name' => 'Middleware2', 'beforeAgent' => $record('before_agent_2'), 'afterAgent' => $record('after_agent_2')]),
            ],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('Test')]]);

        // beforeAgent runs forward, afterAgent in reverse.
        self::assertSame(['before_agent_1', 'before_agent_2', 'after_agent_2', 'after_agent_1'], $executionLog->getArrayCopy());
    }

    public function testShouldAllowStateModificationsInBeforeAndAfterAgentHook(): void
    {
        $middleware = Middleware::create([
            'name' => 'TestMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['customField' => ['type' => 'string', 'default' => 'initial']]],
            'beforeAgent' => function (array $state): array {
                self::assertSame('initial', $state['customField']);

                return ['customField' => 'modified_by_before_agent'];
            },
            'beforeModel' => function (array $state): void {
                self::assertSame('modified_by_before_agent', $state['customField']);
            },
            'afterAgent' => function (array $state): array {
                self::assertSame('modified_by_before_agent', $state['customField']);

                return ['customField' => 'modified_by_after_agent'];
            },
        ]);

        $agent = Agent::create(['model' => AgentAssertions::fakeChat([new AIMessage('Response')]), 'tools' => [], 'middleware' => [$middleware]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Test')]]);

        self::assertSame('modified_by_after_agent', $result['customField']);
    }

    public function testShouldOnlyAllowMiddlewareToModifyItsOwnStateNotOtherMiddlewareState(): void
    {
        $middleware1 = Middleware::create([
            'name' => 'Middleware1',
            'stateSchema' => ['type' => 'object', 'properties' => ['field1' => ['type' => 'string', 'default' => 'value1']]],
            'beforeAgent' => function (array $state): array {
                // Only its own field, not field2 from middleware2.
                self::assertArrayNotHasKey('field2', $state);

                return ['field1' => 'modified1'];
            },
        ]);
        $middleware2 = Middleware::create([
            'name' => 'Middleware2',
            'stateSchema' => ['type' => 'object', 'properties' => ['field2' => ['type' => 'string', 'default' => 'value2']]],
            'beforeAgent' => function (array $state): array {
                // Only its own field, not field1 from middleware1.
                self::assertArrayNotHasKey('field1', $state);

                return ['field2' => 'modified2'];
            },
        ]);

        $agent = Agent::create(['model' => AgentAssertions::fakeChat([new AIMessage('Response')]), 'tools' => [], 'middleware' => [$middleware1, $middleware2]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Test')]]);

        // Both fields are in the final result.
        self::assertSame('modified1', $result['field1']);
        self::assertSame('modified2', $result['field2']);
    }

    public function testShouldNotAllowMiddlewareToModifyContextInBeforeAgent(): void
    {
        $middleware = Middleware::create([
            'name' => 'TestMiddleware',
            'contextSchema' => ['type' => 'object', 'properties' => ['userId' => ['type' => 'string']], 'required' => ['userId']],
            'beforeAgent' => static function (array $state, Runtime $runtime): void {
                $runtime->context['userId'] = '123user';
            },
        ]);

        $agent = Agent::create(['model' => AgentAssertions::fakeChat([new AIMessage('Response')]), 'tools' => [], 'middleware' => [$middleware]]);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('readonly property LangGraph\\Agents\\Runtime::$context');

        $agent->invoke(['messages' => [new HumanMessage('Test')]], ['context' => ['userId' => 'user123']]);
    }

    public function testShouldSupportAllHooksTogetherWithCorrectExecutionOrder(): void
    {
        $executionLog = new \ArrayObject();
        $record = static fn (string $entry): \Closure => static function () use ($executionLog, $entry): void {
            $executionLog->append($entry);
        };

        $middleware = Middleware::create([
            'name' => 'FullMiddleware',
            'beforeAgent' => $record('before_agent'),
            'beforeModel' => $record('before_model'),
            'afterModel' => $record('after_model'),
            'afterAgent' => $record('after_agent'),
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [
                [['name' => 'sample_tool', 'args' => ['query' => 'test'], 'id' => '1']],
                [], // The second call has no more tools.
            ]]),
            'tools' => [$this->sampleTool($executionLog)],
            'middleware' => [$middleware],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('Test')]]);

        self::assertSame([
            'before_agent',
            'before_model',
            'after_model',
            'tool_execution',
            'before_model',
            'after_model',
            'after_agent',
        ], $executionLog->getArrayCopy());
    }

    public function testShouldPreserveMessageAdditionsInBeforeAgent(): void
    {
        $middleware = Middleware::create([
            'name' => 'MessageModifier',
            'beforeAgent' => static fn (array $state): array => ['messages' => [new SystemMessage('Added by before_agent')]],
        ]);

        $agent = Agent::create(['model' => AgentAssertions::fakeChat([new AIMessage('Response')]), 'tools' => [], 'middleware' => [$middleware]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Original message')]]);

        $messageContents = array_map(static fn (BaseMessage $m): mixed => $m->content, $result['messages']);
        self::assertContains('Added by before_agent', $messageContents);
        self::assertContains('Original message', $messageContents);
    }

    public function testShouldPropagateStateChangesFromBeforeAgentThroughTheEntireAgentExecution(): void
    {
        $middleware = Middleware::create([
            'name' => 'StateTracker',
            'stateSchema' => ['type' => 'object', 'properties' => ['trackedValue' => ['type' => 'string', 'default' => 'initial']]],
            'beforeAgent' => static fn (): array => ['trackedValue' => 'set_in_before_agent'],
            'beforeModel' => function (array $state): void {
                self::assertSame('set_in_before_agent', $state['trackedValue']);
            },
            'afterModel' => function (array $state): void {
                self::assertSame('set_in_before_agent', $state['trackedValue']);
            },
            'afterAgent' => function (array $state): array {
                self::assertSame('set_in_before_agent', $state['trackedValue']);

                return ['trackedValue' => 'final_value'];
            },
        ]);

        $agent = Agent::create(['model' => AgentAssertions::fakeChat([new AIMessage('Response')]), 'tools' => [], 'middleware' => [$middleware]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Test')]]);

        self::assertSame('final_value', $result['trackedValue']);
    }

    public function testHooksRunBeforeAndAfterEveryInvocation(): void
    {
        $calls = ['before' => 0, 'after' => 0];
        $middleware = Middleware::create([
            'name' => 'CallTracker',
            'beforeAgent' => static function () use (&$calls): void {
                $calls['before']++;
            },
            'afterAgent' => static function () use (&$calls): void {
                $calls['after']++;
            },
        ]);

        $agent = Agent::create(['model' => AgentAssertions::fakeChat([new AIMessage('Response')]), 'tools' => [], 'middleware' => [$middleware]]);

        $agent->invoke(['messages' => 'Test']);
        self::assertSame(['before' => 1, 'after' => 1], $calls);

        $agent->invoke(['messages' => 'Test2']);
        self::assertSame(['before' => 2, 'after' => 2], $calls);

        $agent->invoke(['messages' => 'Test3']);
        self::assertSame(['before' => 3, 'after' => 3], $calls);
    }

    public function testShouldNotLeakBeforeAgentMiddlewareStateAcrossThreadId(): void
    {
        $seenEntityStates = [];

        // A middleware with only a state schema: a channel with a reducer that keeps the newest value.
        $stateOnly = Middleware::create([
            'name' => 'StateOnly',
            'stateSchema' => Annotation::root([
                'contentStrategy' => Annotation::withReducer(static fn (mixed $current, mixed $next): mixed => $next, static fn (): mixed => null),
            ]),
        ]);

        $documents = Middleware::create([
            'name' => 'Documents',
            'beforeAgent' => function (array $state): void {
                self::assertNull($state['contentStrategy'] ?? null);
            },
        ]);

        $entityExtraction = Middleware::create([
            'name' => 'EntityExtraction',
            'stateSchema' => ['type' => 'object', 'properties' => ['contentStrategy' => ['type' => 'string']]],
            'beforeAgent' => static function (array $state, Runtime $runtime) use (&$seenEntityStates): ?array {
                $threadId = $runtime->configurable['thread_id'];
                $seenEntityStates[$threadId] = $state['contentStrategy'] ?? null;

                if ($threadId === 'thread-a') {
                    return ['contentStrategy' => 'thread-a-only-value'];
                }

                return null;
            },
        ]);

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Response A'), new AIMessage('Response B')]),
            'tools' => [],
            'middleware' => [$stateOnly, $documents, $entityExtraction],
            'checkpointer' => new MemorySaver(),
        ]);

        $agent->invoke(['messages' => [new HumanMessage('first')]], ['configurable' => ['thread_id' => 'thread-a']]);
        $agent->invoke(['messages' => [new HumanMessage('second')]], ['configurable' => ['thread_id' => 'thread-b']]);

        self::assertNull($seenEntityStates['thread-a']);
        self::assertNull($seenEntityStates['thread-b']);
    }

    public function testShouldJumpToAfterAgentWhenBeforeAgentJumpsToEnd(): void
    {
        $executionLog = new \ArrayObject();

        $middleware1 = Middleware::create([
            'name' => 'Middleware1',
            'beforeAgent' => [
                'hook' => static function () use ($executionLog): array {
                    $executionLog->append('before_agent_1');

                    return ['jumpTo' => 'end'];
                },
                'canJumpTo' => ['end'],
            ],
            'afterAgent' => static function () use ($executionLog): void {
                $executionLog->append('after_agent_1');
            },
        ]);
        $middleware2 = Middleware::create([
            'name' => 'Middleware2',
            'beforeAgent' => static function () use ($executionLog): void {
                $executionLog->append('before_agent_2');
            },
            'afterAgent' => static function () use ($executionLog): void {
                $executionLog->append('after_agent_2');
            },
        ]);

        $agent = Agent::create(['model' => AgentAssertions::fakeChat([new AIMessage('Response')]), 'tools' => [], 'middleware' => [$middleware1, $middleware2]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Test')]]);

        // When beforeAgent jumps to "end": the remaining beforeAgent hooks and the model are skipped, and the
        // afterAgent hooks run.
        self::assertSame(['before_agent_1', 'after_agent_2', 'after_agent_1'], $executionLog->getArrayCopy());

        // Only the input message is in the result (no model response).
        self::assertCount(1, $result['messages']);
        self::assertSame('Test', $result['messages'][0]->content);
    }

    public function testShouldTerminateWhenAfterModelJumpsToEndSkippingTools(): void
    {
        $executionLog = new \ArrayObject();
        $toolCalls = 0;

        $sampleTool = tool(
            static function (array $in) use (&$toolCalls, $executionLog): string {
                $toolCalls++;
                $executionLog->append('tool_execution');

                return $in['query'];
            },
            ['name' => 'sample_tool', 'description' => 'Sample tool', 'schema' => Schema::object(['query' => ['type' => 'string']], ['query'])],
        );

        $middleware = Middleware::create([
            'name' => 'Middleware',
            'afterModel' => [
                'hook' => static function () use ($executionLog): array {
                    $executionLog->append('after_model');

                    return ['jumpTo' => 'end'];
                },
                'canJumpTo' => ['end'],
            ],
        ]);

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'sample_tool', 'args' => ['query' => 'Test'], 'id' => 'test_id']]]]),
            'tools' => [$sampleTool],
            'middleware' => [$middleware],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Test')]]);

        self::assertSame(['after_model'], $executionLog->getArrayCopy());
        self::assertSame(0, $toolCalls);
        self::assertCount(2, $result['messages']);
        self::assertSame('Test', $result['messages'][0]->content);
        self::assertInstanceOf(AIMessage::class, $result['messages'][1]);
        self::assertCount(1, $result['messages'][1]->toolCalls);
        self::assertSame([], AgentAssertions::ofType($result['messages'], ToolMessage::class));
    }
}
