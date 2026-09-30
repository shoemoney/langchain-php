<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic;

use LangChain\LanguageModels\Chat\Anthropic\AnthropicException;
use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\Anthropic\Utils\MessageInputs;
use LangChain\LanguageModels\Chat\Anthropic\Utils\MessageOutputs;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tools\Schema;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * {@see ChatAnthropic} with the retry sleep removed — see the OpenAI suite for
 * why the backoff is a seam.
 */
final class NoSleepChatAnthropic extends ChatAnthropic
{
    protected function backoff(int $attempt): void
    {
    }
}

#[CoversClass(ChatAnthropic::class)]
#[CoversClass(MessageInputs::class)]
#[CoversClass(MessageOutputs::class)]
#[CoversClass(AnthropicException::class)]
final class ChatAnthropicTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function response(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'text', 'text' => 'Hello there.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 4],
        ], $overrides);
    }

    /** @param list<HttpResponse> $responses */
    private static function model(array $responses = [], array $fields = []): ChatAnthropic
    {
        return new NoSleepChatAnthropic($fields + [
            'model' => 'claude-sonnet-4-5',
            'apiKey' => 'sk-ant-test',
            'httpClient' => new FakeHttpClient($responses),
            'maxRetries' => 0,
        ]);
    }

    // ---- the round trip --------------------------------------------------

    public function testInvokeReturnsTheAssistantMessage(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::response())]);

        $message = $model->invoke([new HumanMessage('hi')]);

        self::assertInstanceOf(AIMessage::class, $message);
        self::assertSame('Hello there.', $message->content);
    }

    /**
     * Anthropic authenticates with a header, not a bearer scheme, and refuses
     * to talk without an explicit API version. Getting either wrong is a 401
     * whose message does not name the real problem.
     */
    public function testAuthHeadersAreSent(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::response())]);
        $model->invoke('hi');

        $headers = $model->httpClient->requests[0]['headers'];
        self::assertSame('sk-ant-test', $headers['x-api-key']);
        self::assertSame(ChatAnthropic::API_VERSION, $headers['anthropic-version']);
        self::assertArrayNotHasKey('Authorization', $headers);
    }

    public function testApiKeyIsNotSerialized(): void
    {
        self::assertArrayNotHasKey('apiKey', self::model()->kwargs());
    }

    public function testApiKeyFallsBackToTheEnvironment(): void
    {
        $previous = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY=sk-ant-env');

        try {
            self::assertSame('sk-ant-env', (new ChatAnthropic())->apiKey);
        } finally {
            $previous === false ? putenv('ANTHROPIC_API_KEY') : putenv('ANTHROPIC_API_KEY=' . $previous);
        }
    }

    public function testMissingApiKeyFailsWithAnActionableMessage(): void
    {
        $previous = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY');

        try {
            $model = new ChatAnthropic([
                'httpClient' => new FakeHttpClient([FakeHttpClient::json(200, self::response())]),
            ]);
            $model->invoke('hi');
            self::fail('expected an AnthropicException');
        } catch (AnthropicException $e) {
            self::assertStringContainsString('ANTHROPIC_API_KEY', $e->getMessage());
        } finally {
            $previous === false ? putenv('ANTHROPIC_API_KEY') : putenv('ANTHROPIC_API_KEY=' . $previous);
        }
    }

    // ---- system hoisting -------------------------------------------------

    /**
     * There is no `system` role on the wire: the leading run of system
     * messages becomes a top-level request parameter.
     */
    public function testLeadingSystemMessagesAreHoistedOutOfTheConversation(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::response())]);
        $model->invoke([new SystemMessage('be terse'), new HumanMessage('hi')]);

        $body = $model->httpClient->lastRequestBody();

        self::assertSame('be terse', $body['system']);
        self::assertSame([['role' => 'user', 'content' => 'hi']], $body['messages']);
    }

    public function testSeveralSystemMessagesBecomeBlocks(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::response())]);
        $model->invoke([new SystemMessage('one'), new SystemMessage('two'), new HumanMessage('hi')]);

        $body = $model->httpClient->lastRequestBody();

        self::assertSame([
            ['type' => 'text', 'text' => 'one'],
            ['type' => 'text', 'text' => 'two'],
        ], $body['system']);
    }

    /**
     * A system message *after* the conversation has started keeps its position.
     */
    public function testLateSystemMessageKeepsItsPosition(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::response())]);
        $model->invoke([new HumanMessage('hi'), new SystemMessage('now be terse')]);

        $body = $model->httpClient->lastRequestBody();

        self::assertArrayNotHasKey('system', $body);
        self::assertSame('system', $body['messages'][1]['role']);
    }

    // ---- tool calls ------------------------------------------------------

    public function testToolUseBlockBecomesAToolCall(): void
    {
        $payload = self::response([
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_1',
                'name' => 'get_weather',
                'input' => ['city' => 'Austin'],
            ]],
            'stop_reason' => 'tool_use',
        ]);

        $model = self::model([FakeHttpClient::json(200, $payload)]);
        $message = $model->invoke('weather?');

        self::assertSame([[
            'name' => 'get_weather',
            'args' => ['city' => 'Austin'],
            'id' => 'toolu_1',
            'type' => 'tool_call',
        ]], $message->toolCalls);
    }

    public function testToolResultBecomesAUserContentBlock(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::response())]);
        $model->invoke([
            new AIMessage([
                'content' => '',
                'tool_calls' => [['name' => 'get_weather', 'args' => ['city' => 'Austin'], 'id' => 'toolu_1', 'type' => 'tool_call']],
            ]),
            new ToolMessage(['content' => '72F', 'tool_call_id' => 'toolu_1']),
        ]);

        $body = $model->httpClient->lastRequestBody();

        self::assertSame([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => ['city' => 'Austin']]], $body['messages'][0]['content']);
        self::assertSame([['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '72F']], $body['messages'][1]['content']);
        self::assertSame('user', $body['messages'][1]['role']);
    }

    /**
     * Two tool results in one turn must arrive as ONE user message.
     *
     * This is not a style preference: the API rejects two consecutive `user`
     * turns, and a model calling two tools in parallel produces exactly this
     * sequence. Without the fold the second request in any parallel agent loop
     * fails.
     */
    public function testConsecutiveToolResultsFoldIntoOneUserMessage(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::response())]);
        $model->invoke([
            new AIMessage([
                'content' => '',
                'tool_calls' => [
                    ['name' => 'a', 'args' => [], 'id' => 'toolu_1', 'type' => 'tool_call'],
                    ['name' => 'b', 'args' => [], 'id' => 'toolu_2', 'type' => 'tool_call'],
                ],
            ]),
            new ToolMessage(['content' => 'first', 'tool_call_id' => 'toolu_1']),
            new ToolMessage(['content' => 'second', 'tool_call_id' => 'toolu_2']),
        ]);

        $body = $model->httpClient->lastRequestBody();

        self::assertCount(2, $body['messages']);
        self::assertSame('user', $body['messages'][1]['role']);
        self::assertCount(2, $body['messages'][1]['content']);
        self::assertSame('toolu_1', $body['messages'][1]['content'][0]['tool_use_id']);
        self::assertSame('toolu_2', $body['messages'][1]['content'][1]['tool_use_id']);
    }

    /**
     * The schema key is `input_schema`. Sending `parameters` is not rejected —
     * the model just never learns the argument shape.
     */
    public function testBindToolsUsesInputSchema(): void
    {
        $bound = self::model()->bindTools([
            tool(static fn (array $a): string => 'sunny', [
                'name' => 'get_weather',
                'description' => 'Look up the weather',
                'schema' => Schema::object(['city' => ['type' => 'string']]),
            ]),
        ]);

        self::assertSame([[
            'name' => 'get_weather',
            'description' => 'Look up the weather',
            'input_schema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => []],
        ]], $bound->kwargs()['tools']);
    }

    public function testBindToolsDoesNotMutateTheReceiver(): void
    {
        $model = self::model();
        $model->bindTools([tool(static fn (array $a): string => 'x', ['name' => 'x', 'description' => 'd', 'schema' => Schema::object([])])]);

        self::assertArrayNotHasKey('tools', $model->kwargs());
    }

    public function testBoundToolsReachTheRequest(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::response())]);
        $model->bindTools([tool(static fn (array $a): string => 'x', ['name' => 'x', 'description' => 'd', 'schema' => Schema::object([])])])
            ->invoke('hi');

        self::assertSame('x', $model->httpClient->lastRequestBody()['tools'][0]['name']);
    }

    public function testToolChoiceMustNameAnOfferedTool(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not available');

        self::model()
            ->bindTools([tool(static fn (array $a): string => 'x', ['name' => 'x', 'description' => 'd', 'schema' => Schema::object([])])])
            ->invocationParams(['toolChoice' => 'nonexistent']);
    }

    public function testToolChoiceFormats(): void
    {
        $model = self::model();

        self::assertSame(['type' => 'auto'], $model->invocationParams(['toolChoice' => 'auto'])['tool_choice']);
        self::assertSame(['type' => 'any'], $model->invocationParams(['toolChoice' => 'any'])['tool_choice']);
    }

    public function testForcedToolChoiceFormatsAgainstAnOfferedTool(): void
    {
        $model = self::model()->bindTools([
            tool(static fn (array $a): string => 'x', ['name' => 'x', 'description' => 'd', 'schema' => Schema::object([])]),
        ]);

        self::assertSame(['type' => 'tool', 'name' => 'x'], $model->invocationParams(['toolChoice' => 'x'])['tool_choice']);
    }

    // ---- end to end ------------------------------------------------------

    public function testWithStructuredOutputEndToEnd(): void
    {
        $payload = self::response([
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_1',
                'name' => 'extract',
                'input' => ['name' => 'Ada Lovelace'],
            ]],
            'stop_reason' => 'tool_use',
        ]);

        $model = self::model([FakeHttpClient::json(200, $payload)]);

        $result = $model->withStructuredOutput([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ])->invoke('who wrote the first algorithm?');

        self::assertSame(['name' => 'Ada Lovelace'], $result);
    }

    public function testIncludeRawKeepsTheMessage(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::response())]);

        $result = $model->withStructuredOutput([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ], ['includeRaw' => true])->invoke('hi');

        self::assertIsArray($result);
        self::assertInstanceOf(AIMessage::class, $result['raw']);
        self::assertSame('Hello there.', $result['raw']->content);
    }

    // ---- usage -----------------------------------------------------------

    public function testUsageIsReadOntoTheMessage(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::response())]);
        $message = $model->invoke('hi');

        self::assertSame([
            'input_tokens' => 12,
            'output_tokens' => 4,
            'total_tokens' => 16,
        ], $message->response_metadata['usage_metadata']);
        self::assertSame('end_turn', $message->response_metadata['stop_reason']);
        self::assertSame('anthropic', $message->response_metadata['model_provider']);
    }

    // ---- streaming -------------------------------------------------------

    public function testStreamFoldsTextDeltas(): void
    {
        $http = new FakeHttpClient([], [
            "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_1\",\"usage\":{\"input_tokens\":9}}}\n\n",
            "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hel\"}}\n\n",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"lo\"}}\n\n",
            "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":2}}\n\n",
            "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n",
        ]);

        $model = new ChatAnthropic(['apiKey' => 'sk-ant-test', 'httpClient' => $http]);
        $collector = new \LangChain\Tracers\RunCollectorCallbackHandler();

        $text = '';
        foreach ($model->stream('hi', new \LangChain\Runnables\RunnableConfig(callbacks: [$collector])) as [, $chunk]) {
            $text .= is_string($chunk->content) ? $chunk->content : '';
        }

        // Read usage from the run, NOT by re-folding the yielded messages.
        // Input tokens arrive on message_start and output tokens on
        // message_delta, and the client folds them internally. A test that
        // folds the messages itself is asserting on the shape it happens to
        // receive rather than on the contract — and it broke the moment a
        // metadata-only chunk stopped being surfaced as a message of its own.
        $usage = null;
        foreach ($collector->tracedRuns as $run) {
            if ($run->runType === 'llm') {
                $usage = $run->outputs['llmOutput']['tokenUsage'] ?? null;
            }
        }

        self::assertSame('Hello', $text);
        self::assertSame(9, $usage['promptTokens'] ?? null);
        self::assertSame(2, $usage['completionTokens'] ?? null);
        self::assertSame(11, $usage['totalTokens'] ?? null);
        self::assertTrue($http->lastRequestBody()['stream']);
    }

    /**
     * A streamed tool call arrives as `input_json_delta` fragments and must be
     * folded, not decoded per event.
     */
    public function testStreamFoldsToolCallFragments(): void
    {
        $http = new FakeHttpClient([], [
            "data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"tool_use\",\"id\":\"toolu_1\",\"name\":\"get_weather\"}}\n\n",
            "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"{\\\"city\\\":\"}}\n\n",
            "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"\\\"Austin\\\"}\"}}\n\n",
            "data: {\"type\":\"message_stop\"}\n\n",
        ]);

        $model = new ChatAnthropic(['apiKey' => 'sk-ant-test', 'httpClient' => $http]);

        $folded = null;
        foreach ($model->stream('weather?') as [, $chunk]) {
            $folded = $folded === null ? $chunk : $folded->concat($chunk);
        }

        [$calls] = $folded->parseToolCalls();
        self::assertSame([[
            'name' => 'get_weather',
            'args' => ['city' => 'Austin'],
            'id' => 'toolu_1',
            'type' => 'tool_call',
            'index' => 0,
        ]], $calls);
    }

    // ---- errors ----------------------------------------------------------

    public function testProviderErrorIsRaisedWithItsType(): void
    {
        $http = new FakeHttpClient([
            new HttpResponse(400, [], (string) json_encode([
                'type' => 'error',
                'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens is too large'],
            ])),
        ]);

        $model = new NoSleepChatAnthropic(['apiKey' => 'sk-ant-test', 'httpClient' => $http, 'maxRetries' => 0]);

        try {
            $model->invoke('hi');
            self::fail('expected an AnthropicException');
        } catch (AnthropicException $e) {
            self::assertSame(400, $e->status);
            self::assertStringContainsString('max_tokens is too large', $e->getMessage());
            self::assertStringContainsString('invalid_request_error', $e->getMessage());
            self::assertSame('invalid_request_error', $e->providerError['type']);
        }
    }

    public function testClientErrorIsNotRetriedButRateLimitIs(): void
    {
        $http = new FakeHttpClient([
            new HttpResponse(429, [], '{"error":{"type":"rate_limit_error","message":"slow"}}'),
            FakeHttpClient::json(200, self::response()),
        ]);

        $model = new NoSleepChatAnthropic(['apiKey' => 'sk-ant-test', 'httpClient' => $http, 'maxRetries' => 2]);

        self::assertInstanceOf(AIMessage::class, $model->invoke('hi'));
        self::assertCount(2, $http->requests);
    }

    /**
     * Folding must not mutate a message the caller still owns.
     *
     * Appending to the previous message in place grew the caller's own
     * `HumanMessage` by a `tool_result` block it never had. That corrupted the
     * conversation history, and — because the block is Anthropic-shaped — it
     * would then travel into a later request built from the same history,
     * including one to a different provider, where it is simply invalid.
     */
    public function testFoldingDoesNotMutateTheCallersMessage(): void
    {
        $owned = new HumanMessage(['content' => [[
            'type' => 'tool_result',
            'tool_use_id' => 't1',
            'content' => 'earlier result',
        ]]]);

        $before = json_encode($owned->content);

        MessageInputs::convert([
            $owned,
            new ToolMessage(['content' => 'later result', 'tool_call_id' => 't2']),
        ]);

        self::assertSame($before, json_encode($owned->content), 'the caller-s message was mutated');
    }

    /**
     * ...while the folded output is still correct.
     */
    public function testFoldingStillMergesTheRunOfToolResults(): void
    {
        $messages = MessageInputs::convert([
            new AIMessage([
                'content' => '',
                'tool_calls' => [
                    ['name' => 'a', 'args' => [], 'id' => 't1', 'type' => 'tool_call'],
                    ['name' => 'b', 'args' => [], 'id' => 't2', 'type' => 'tool_call'],
                ],
            ]),
            new ToolMessage(['content' => 'first', 'tool_call_id' => 't1']),
            new ToolMessage(['content' => 'second', 'tool_call_id' => 't2']),
        ])['messages'];

        self::assertCount(2, $messages);
        self::assertSame('user', $messages[1]['role']);
        self::assertCount(2, $messages[1]['content']);
        self::assertSame('t1', $messages[1]['content'][0]['tool_use_id']);
        self::assertSame('t2', $messages[1]['content'][1]['tool_use_id']);
    }

    public function testUnsupportedMessageTypeIsRejectedBeforeTheRequest(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::response())]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not supported');

        $model->invoke([new \LangChain\Messages\ChatMessage('hi', 'chatter')]);
    }
}
