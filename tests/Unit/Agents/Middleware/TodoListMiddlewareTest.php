<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\TodoListMiddleware;
use LangGraph\Agents\Middleware\Utils as MiddlewareUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/middleware/tests/todoList.test.ts`.
 *
 * Case 2 upstream drives `ChatOpenAI` through a mocked `fetch`; here the model sits on a `FakeHttpClient`, which
 * records the request body the same way. The mocked fetch answers the same tool call forever and relies on the
 * agent stopping once every tool call has a result; the fake transport scripts the final reply explicitly.
 */
#[CoversClass(TodoListMiddleware::class)]
final class TodoListMiddlewareTest extends TestCase
{
    private const ERROR_TEXT = 'Error: The `write_todos` tool should never be called multiple times in parallel';

    /**
     * @param list<array<string, mixed>> $toolCalls
     * @return array<string, mixed>
     */
    private static function aiState(array $toolCalls, string $content = "I'll update the todos"): array
    {
        return ['messages' => [new HumanMessage('Hello'), new AIMessage(['content' => $content, 'tool_calls' => $toolCalls])]];
    }

    /** @param array<string, mixed> $middleware */
    private static function afterModel(array $middleware, array $state): ?array
    {
        return MiddlewareUtils::getHookFunction($middleware['afterModel'])($state, null);
    }

    private static function writeTodosCall(string $id, string $task): array
    {
        return ['name' => 'write_todos', 'args' => ['todos' => [['content' => $task, 'status' => 'pending']]], 'id' => $id];
    }

    public function testShouldAddTheSystemPromptToTheModelRequest(): void
    {
        $model = ReactAgentFixtures::spy([new AIMessage('Response from model')]);
        $agent = Agent::create(['model' => $model, 'systemPrompt' => 'You are a helpful assistant.', 'middleware' => [TodoListMiddleware::create()]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Hello, world!')]]);

        self::assertSame([], $result['todos']);
        self::assertNotEmpty($model->generateCalls);
        self::assertStringContainsString(
            "You are a helpful assistant.\n\n## `write_todos`\n\nYou have ",
            $model->generateCalls[0][0]->text(),
        );
    }

    public function testShouldAddTheCustomSystemPromptToTheModelRequestAndCustomToolDescription(): void
    {
        $response = static fn (array $message, string $finish): \LangChain\Utils\Http\HttpResponse => FakeHttpClient::json(200, [
            'id' => 'chatcmpl-test123',
            'object' => 'chat.completion',
            'created' => 1700000000,
            'model' => 'gpt-4o',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant'] + $message, 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 25, 'total_tokens' => 75],
        ]);
        $http = new FakeHttpClient([
            $response([
                'content' => "I'll help you with that task. Let me create a todo list to track the work.",
                'tool_calls' => [[
                    'id' => 'call_test123',
                    'type' => 'function',
                    'function' => [
                        'name' => 'write_todos',
                        'arguments' => json_encode(['todos' => [['content' => 'Complete the requested task', 'status' => 'in_progress']]]),
                    ],
                ]],
            ], 'tool_calls'),
            $response(['content' => 'All done.'], 'stop'),
        ]);
        $model = new ChatOpenAI(['model' => 'gpt-4o', 'apiKey' => 'test-key', 'httpClient' => $http, 'maxRetries' => 0]);
        $middleware = TodoListMiddleware::create(['systemPrompt' => 'Custom system prompt', 'toolDescription' => 'Custom tool description']);
        $agent = Agent::create(['model' => $model, 'systemPrompt' => 'You are a helpful assistant.', 'middleware' => [$middleware]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Hello, world!')]]);

        // The request: the system message is the agent prompt plus the custom prompt, and the tool carries the custom description.
        self::assertStringContainsString('chat/completions', $http->requests[0]['url']);
        $body = json_decode($http->requests[0]['body'], true);
        $system = array_values(array_filter($body['messages'], static fn (array $m): bool => $m['role'] === 'system'))[0];
        self::assertSame(
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'You are a helpful assistant.'], ['type' => 'text', 'text' => "\n\nCustom system prompt"]]],
            $system,
        );
        self::assertStringContainsString('Custom tool description', $body['tools'][0]['function']['description']);

        // The result: the todos landed in the state, with the tool's confirming message.
        self::assertSame([['content' => 'Complete the requested task', 'status' => 'in_progress']], $result['todos']);
        $toolMessages = array_values(array_filter(
            AgentAssertions::ofType($result['messages'], ToolMessage::class),
            static fn (ToolMessage $m): bool => $m->toolCallId === 'call_test123',
        ));
        self::assertCount(1, $toolMessages);
        self::assertSame('write_todos', $toolMessages[0]->name);
        self::assertSame('Updated todo list to [{"content":"Complete the requested task","status":"in_progress"}]', $toolMessages[0]->content);
    }

    // --- parallel write_todos detection

    public function testShouldRejectParallelWriteTodosCallsWithErrorMessages(): void
    {
        $middleware = TodoListMiddleware::create();

        $result = self::afterModel($middleware, self::aiState([self::writeTodosCall('call_1', 'Task 1'), self::writeTodosCall('call_2', 'Task 2')]));

        self::assertNotNull($result);
        self::assertCount(2, $result['messages']);
        foreach ([['call_1', 0], ['call_2', 1]] as [$id, $i]) {
            $message = $result['messages'][$i];
            self::assertInstanceOf(ToolMessage::class, $message);
            self::assertSame($id, $message->toolCallId);
            self::assertSame('error', $message->additional_kwargs['status']);
            self::assertStringContainsString(self::ERROR_TEXT, $message->content);
            self::assertSame('write_todos', $message->name);
        }
    }

    public function testShouldRejectParallelWriteTodosCallsEvenWhenMixedWithOtherTools(): void
    {
        $middleware = TodoListMiddleware::create();

        $result = self::afterModel($middleware, self::aiState([
            ['name' => 'some_other_tool', 'args' => ['param' => 'value'], 'id' => 'call_other'],
            self::writeTodosCall('call_1', 'Task 1'),
            self::writeTodosCall('call_2', 'Task 2'),
        ], 'I\'ll do multiple things'));

        self::assertNotNull($result);
        self::assertCount(2, $result['messages']);
        $ids = array_map(static fn (ToolMessage $m): string => $m->toolCallId, $result['messages']);
        self::assertContains('call_1', $ids);
        self::assertContains('call_2', $ids);
        self::assertNotContains('call_other', $ids);
    }

    public function testShouldAllowASingleWriteTodosCall(): void
    {
        self::assertNull(self::afterModel(TodoListMiddleware::create(), self::aiState([self::writeTodosCall('call_1', 'Task 1')])));
    }

    public function testShouldHandleEmptyMessagesGracefully(): void
    {
        self::assertNull(self::afterModel(TodoListMiddleware::create(), ['messages' => []]));
    }

    public function testShouldHandleAiMessageWithoutToolCallsGracefully(): void
    {
        self::assertNull(self::afterModel(TodoListMiddleware::create(), ['messages' => [new HumanMessage('Hello'), new AIMessage(['content' => 'Just a regular response'])]]));
    }

    // --- end to end

    public function testAgentAnswersAParallelTurnWithErrorsAndDoesNotRunTheTool(): void
    {
        $model = AgentAssertions::fakeChat([
            new AIMessage(['content' => '', 'tool_calls' => [self::writeTodosCall('call_a', 'Plan'), self::writeTodosCall('call_b', 'Plan again')]]),
            new AIMessage('never reached'),
        ]);
        $agent = Agent::create(['model' => $model, 'middleware' => [TodoListMiddleware::create()]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Plan my work')]]);

        // Every call is answered by an error message, so nothing is pending and the run ends without the tool updating the list.
        $toolMessages = AgentAssertions::ofType($result['messages'], ToolMessage::class);
        self::assertCount(2, $toolMessages);
        foreach ($toolMessages as $message) {
            self::assertSame('error', $message->additional_kwargs['status']);
        }
        self::assertSame([], $result['todos']);
    }

    public function testAgentRunsASingleWriteTodosCallAndKeepsTheListForTheNextTurn(): void
    {
        $call = static fn (string $id, string $status): AIMessage => new AIMessage(['content' => '', 'tool_calls' => [
            ['name' => 'write_todos', 'args' => ['todos' => [['content' => 'Plan', 'status' => $status]]], 'id' => $id],
        ]]);
        $model = AgentAssertions::fakeChat([$call('call_a', 'in_progress'), $call('call_b', 'completed'), new AIMessage('Finished')]);
        $agent = Agent::create(['model' => $model, 'middleware' => [TodoListMiddleware::create()]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Plan my work')]]);

        self::assertSame([['content' => 'Plan', 'status' => 'completed']], $result['todos']);
        self::assertCount(2, AgentAssertions::ofType($result['messages'], ToolMessage::class));
        self::assertSame('Finished', $result['messages'][array_key_last($result['messages'])]->content);
    }
}
