<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tools\Schema;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\ReactAgent;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;
use function LangGraph\Pregel\interrupt;

/**
 * An agent running a real provider client (`ChatOpenAI`, over a scripted transport) through the whole stack:
 * the agent graph, a tool, middleware at every hook, a checkpointer and the wire format of each request.
 */
#[CoversClass(ReactAgent::class)]
final class ReactAgentEndToEndTest extends TestCase
{
    private static function completion(array $message, string $finish, string $id): \LangChain\Utils\Http\HttpResponse
    {
        // Each completion needs its own id: the messages reducer replaces a message that has the id of an earlier one.
        return FakeHttpClient::json(200, [
            'id' => $id, 'object' => 'chat.completion', 'model' => 'gpt-4o',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', ...$message], 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10],
        ]);
    }

    private static function weatherTool(): \LangChain\Tools\StructuredTool
    {
        return tool(
            static fn (array $in): string => 'It is sunny in ' . $in['location'],
            ['name' => 'get_weather', 'description' => 'Get the weather for a location.', 'schema' => Schema::object(['location' => ['type' => 'string']], ['location'])],
        );
    }

    public function testAnAgentLoopsThroughARealProviderClientAndATool(): void
    {
        $http = new FakeHttpClient([
            self::completion([
                'content' => null,
                'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"location":"Tokyo"}']]],
            ], 'tool_calls', 'chatcmpl-1'),
            self::completion(['content' => 'Tokyo is sunny.'], 'stop', 'chatcmpl-2'),
        ]);
        $model = new ChatOpenAI(['model' => 'gpt-4o', 'apiKey' => 'sk-test', 'maxRetries' => 0, 'httpClient' => $http]);

        $log = [];
        $middleware = Middleware::create([
            'name' => 'trace',
            'beforeAgent' => static function () use (&$log): void {
                $log[] = 'beforeAgent';
            },
            'beforeModel' => static function () use (&$log): void {
                $log[] = 'beforeModel';
            },
            'wrapModelCall' => static function (array $request, callable $handler) use (&$log): mixed {
                $log[] = 'wrapModelCall:' . \count($request['messages']);

                return $handler($request);
            },
            'afterModel' => static function () use (&$log): void {
                $log[] = 'afterModel';
            },
            'wrapToolCall' => static function (array $request, callable $handler) use (&$log): mixed {
                $log[] = 'wrapToolCall:' . $request['toolCall']['name'];

                return $handler($request);
            },
            'afterAgent' => static function () use (&$log): void {
                $log[] = 'afterAgent';
            },
        ]);

        $agent = Agent::create([
            'model' => $model,
            'tools' => [self::weatherTool()],
            'systemPrompt' => 'Be terse.',
            'name' => 'weather-bot',
            'middleware' => [$middleware],
            'checkpointer' => new MemorySaver(),
        ]);

        $result = $agent->invoke(
            ['messages' => [new HumanMessage('Weather in Tokyo?')]],
            ['configurable' => ['thread_id' => 'e2e']],
        );

        // human, ai (the call), tool result, ai (the answer)
        [$human, $call, $toolResult, $final] = $result['messages'];
        self::assertInstanceOf(HumanMessage::class, $human);
        self::assertInstanceOf(AIMessage::class, $call);
        self::assertSame('get_weather', $call->toolCalls[0]['name']);
        self::assertSame(['location' => 'Tokyo'], $call->toolCalls[0]['args']);
        self::assertSame('weather-bot', $call->name);
        self::assertInstanceOf(ToolMessage::class, $toolResult);
        self::assertSame('It is sunny in Tokyo', $toolResult->content);
        self::assertSame('Tokyo is sunny.', $final->content);

        // Every hook ran, in graph order.
        self::assertSame(
            ['beforeAgent', 'beforeModel', 'wrapModelCall:1', 'afterModel', 'wrapToolCall:get_weather', 'beforeModel', 'wrapModelCall:3', 'afterModel', 'afterAgent'],
            $log,
        );

        // The wire format: the first request offers the tool, the second carries the tool result back.
        self::assertCount(2, $http->requests);
        $first = json_decode($http->requests[0]['body'], true);
        self::assertSame('system', $first['messages'][0]['role']);
        // The agent hands the prompt over as one text block.
        self::assertSame([['type' => 'text', 'text' => 'Be terse.']], $first['messages'][0]['content']);
        self::assertSame('get_weather', $first['tools'][0]['function']['name']);
        $second = json_decode($http->requests[1]['body'], true);
        self::assertSame(['system', 'user', 'assistant', 'tool'], array_column($second['messages'], 'role'));
        self::assertSame('It is sunny in Tokyo', $second['messages'][3]['content']);
        self::assertSame('call_1', $second['messages'][3]['tool_call_id']);

        // The run was checkpointed: the thread now holds the whole conversation.
        $saved = $agent->checkpointer->getTuple(['configurable' => ['thread_id' => 'e2e']]);
        self::assertCount(4, $saved->checkpoint->channelValues['messages']);
    }

    public function testAnExplicitChatCompletionsModelInstanceCallsTheCompletionsEndpoint(): void
    {
        $http = new FakeHttpClient([self::completion(['content' => 'hi'], 'stop', 'chatcmpl-1')]);
        $model = new ChatOpenAI(['model' => 'gpt-5.5', 'apiKey' => 'test', 'useResponsesApi' => false, 'maxRetries' => 0, 'httpClient' => $http]);

        Agent::create(['model' => $model, 'tools' => []])->invoke(['messages' => [['role' => 'user', 'content' => 'hi']]]);

        self::assertMatchesRegularExpression('~/chat/completions$~', parse_url($http->requests[0]['url'], \PHP_URL_PATH) ?: '');
    }

    public function testTheAgentCanBeStreamed(): void
    {
        $agent = Agent::create([
            'model' => \LangChain\Tests\Unit\Agents\Support\AgentAssertions::fakeChat([new AIMessage('streamed answer')]),
            'tools' => [],
        ]);

        $chunks = iterator_to_array($agent->stream(['messages' => [new HumanMessage('hi')]]), false);

        $modes = array_unique(array_column($chunks, 0));
        self::assertContains('updates', $modes);
        $updates = array_values(array_filter($chunks, static fn (array $chunk): bool => $chunk[0] === 'updates'));
        self::assertArrayHasKey('model_request', $updates[0][1]);
    }

    public function testAToolThatInterruptsPausesTheRunAndACommandResumesIt(): void
    {
        $calls = 0;
        $askTool = tool(
            static function (array $in) use (&$calls): string {
                $calls++;
                $answer = interrupt(['question' => $in['question']]);

                return 'The user said: ' . $answer;
            },
            ['name' => 'ask_user', 'description' => 'Ask the user', 'schema' => Schema::object(['question' => ['type' => 'string']], ['question'])],
        );

        $agent = Agent::create([
            'model' => new Support\FakeToolCallingModel(['toolCalls' => [[['name' => 'ask_user', 'args' => ['question' => 'Color?'], 'id' => 'ask_1']], []]]),
            'tools' => [$askTool],
            'middleware' => [Middleware::create(['name' => 'pass', 'wrapToolCall' => static fn (array $request, callable $handler): mixed => $handler($request)])],
            'checkpointer' => new MemorySaver(),
        ]);
        $thread = ['configurable' => ['thread_id' => 'interrupt-thread']];

        // The first run pauses at the tool.
        $interrupts = Support\AgentAssertions::interrupts($agent, ['messages' => [new HumanMessage('Pick one')]], $thread);
        self::assertSame([['question' => 'Color?']], array_column($interrupts, 'value'));
        self::assertSame(1, $calls);

        // A Command resumes it: the tool runs again, now with the answer, and the agent finishes.
        $result = $agent->invoke(new Command(resume: 'blue'), $thread);

        self::assertSame(2, $calls);
        $toolMessages = Support\AgentAssertions::ofType($result['messages'], ToolMessage::class);
        self::assertSame(['The user said: blue'], array_map(static fn (ToolMessage $m): string => $m->content, $toolMessages));
    }
}
