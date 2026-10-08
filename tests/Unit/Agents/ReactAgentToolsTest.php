<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\Schema;
use LangChain\Tools\ToolRuntime;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\ReactAgent;
use LangGraph\Store\InMemoryStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/tests/tools.test.ts`.
 *
 * Not asserted from the first case: `runtime.writer` being a function (nothing injects a stream writer into a
 * tool runtime in this port yet) and the store's class name (`AsyncBatchedStore` upstream).
 */
#[CoversClass(ReactAgent::class)]
final class ReactAgentToolsTest extends TestCase
{
    public function testCanAccessStateAndContext(): void
    {
        $store = new InMemoryStore();
        $runtimes = [];

        $model = new FakeToolCallingModel([
            'toolCalls' => [[['type' => 'tool_call', 'name' => 'test', 'args' => ['city' => 'Tokyo'], 'id' => '1']]],
        ]);

        $getWeather = tool(
            static function (array $input, ToolRuntime $runtime) use (&$runtimes): string {
                $runtimes[] = $runtime;

                return 'The weather in ' . $input['city'] . ' is sunny. The foo is ' . $runtime->context['foo'] . ' and the bar is ' . $runtime->state['bar'] . '.';
            },
            ['name' => 'test', 'description' => 'test', 'schema' => Schema::object(['city' => ['type' => 'string']], ['city'])],
        );

        $agent = Agent::create([
            'model' => $model,
            'tools' => [$getWeather],
            'store' => $store,
            'contextSchema' => ['type' => 'object', 'properties' => ['foo' => ['type' => 'string', 'default' => 'bar']]],
            'stateSchema' => ['type' => 'object', 'properties' => ['bar' => ['type' => 'string', 'default' => 'baz']]],
        ]);

        $result = $agent->invoke(
            ['messages' => [new HumanMessage('What is the weather in Tokyo?')], 'bar' => 'baz'],
            ['context' => ['foo' => 'bar']],
        );

        $last = $result['messages'][array_key_last($result['messages'])];
        self::assertInstanceOf(ToolMessage::class, $last);
        self::assertSame('The weather in Tokyo is sunny. The foo is bar and the bar is baz.', $last->content);

        self::assertCount(1, $runtimes);
        self::assertSame('baz', $runtimes[0]->state['bar']);
        self::assertSame(['foo' => 'bar'], $runtimes[0]->context);
        self::assertSame('1', $runtimes[0]->toolCallId);
        self::assertIsObject($runtimes[0]->store);
    }

    /**
     * @param \ArrayObject<string, list<string>> $log `order` is the tools in invocation order, the other keys the call ids each tool saw
     */
    private function parallelToolsAgent(string $version, bool $withAfterModel, \ArrayObject $log): ReactAgent
    {
        $model = new FakeToolCallingModel([
            'toolCalls' => [
                [
                    ['type' => 'tool_call', 'name' => 'tool_a', 'args' => [], 'id' => 'call_a'],
                    ['type' => 'tool_call', 'name' => 'tool_b', 'args' => [], 'id' => 'call_b'],
                ],
                // The second turn returns a plain text response to end the agent loop.
                [],
            ],
        ]);

        $makeTool = static function (string $name, string $result) use ($log): \LangChain\Tools\StructuredTool {
            return tool(
                static function (array $in, ToolRuntime $runtime) use ($name, $result, $log): string {
                    $order = $log['order'] ?? [];
                    $order[] = $name;
                    $log['order'] = $order;
                    $ids = $log[$name] ?? [];
                    $ids[] = $runtime->toolCallId;
                    $log[$name] = $ids;

                    return $result;
                },
                ['name' => $name, 'description' => str_replace('_', ' ', $name), 'schema' => Schema::object([])],
            );
        };

        return Agent::create([
            'model' => $model,
            'tools' => [$makeTool('tool_a', 'result_a'), $makeTool('tool_b', 'result_b')],
            'version' => $version,
            ...($withAfterModel ? ['middleware' => [Middleware::create([
                'name' => 'after-model',
                'afterModel' => static fn (array $state): array => ['messages' => $state['messages']],
            ])]] : []),
        ]);
    }

    public function testV1RunsAllToolCallsFromASingleAiMessageInTheSameToolNodeInvocation(): void
    {
        $log = new \ArrayObject();
        $agent = $this->parallelToolsAgent('v1', false, $log);

        $result = $agent->invoke(['messages' => [new HumanMessage('run both tools')]]);

        // Both tools must have been called.
        self::assertContains('tool_a', $log['order']);
        self::assertContains('tool_b', $log['order']);

        // Both ToolMessages must appear in the conversation.
        $toolMessages = AgentAssertions::ofType($result['messages'], ToolMessage::class);
        self::assertCount(2, $toolMessages);
        $ids = array_map(static fn (ToolMessage $m): string => $m->toolCallId, $toolMessages);
        sort($ids);
        self::assertSame(['call_a', 'call_b'], $ids);
    }

    public function testV2DispatchesEachToolCallAsASeparateSendTaskWhenAfterModelMiddlewareIsPresent(): void
    {
        $log = new \ArrayObject();
        // With v2 + afterModel the ToolNode is entered once per tool call via Send, so each invocation
        // sees only its own call id.
        $agent = $this->parallelToolsAgent('v2', true, $log);

        $result = $agent->invoke(['messages' => [new HumanMessage('run both tools')]]);

        // Both tools must have been called exactly once.
        self::assertSame(['call_a'], $log['tool_a']);
        self::assertSame(['call_b'], $log['tool_b']);

        $toolMessages = AgentAssertions::ofType($result['messages'], ToolMessage::class);
        self::assertCount(2, $toolMessages);
        $ids = array_map(static fn (ToolMessage $m): string => $m->toolCallId, $toolMessages);
        sort($ids);
        self::assertSame(['call_a', 'call_b'], $ids);
    }

    public function testV1RunsPendingToolCallsViaTheToolNodeWhenAfterModelMiddlewareIsPresent(): void
    {
        $log = new \ArrayObject();
        // An afterModel middleware forces the after-model router code path.
        $agent = $this->parallelToolsAgent('v1', true, $log);

        $result = $agent->invoke(['messages' => [new HumanMessage('run both tools')]]);

        self::assertContains('tool_a', $log['order']);
        self::assertContains('tool_b', $log['order']);

        $toolMessages = AgentAssertions::ofType($result['messages'], ToolMessage::class);
        self::assertCount(2, $toolMessages);
        $ids = array_map(static fn (ToolMessage $m): string => $m->toolCallId, $toolMessages);
        sort($ids);
        self::assertSame(['call_a', 'call_b'], $ids);
    }
}
