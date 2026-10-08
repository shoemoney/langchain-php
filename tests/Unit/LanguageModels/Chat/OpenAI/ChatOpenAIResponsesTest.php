<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAIResponses;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * {@see ChatOpenAIResponses} with the retry sleep removed and the tracing hooks exposed.
 */
final class TraceableChatOpenAIResponses extends ChatOpenAIResponses
{
    public int $backoffCalls = 0;

    protected function backoff(int $attempt): void
    {
        $this->backoffCalls++;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function traceParams(array $options = []): array
    {
        return $this->invocationParamsForTracing($options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function traceOptions(array $options = []): array
    {
        return $this->callOptionsForTracing($options);
    }
}

/**
 * `chat_models/tests/responses.test.ts` and
 * `chat_models/tests/chat_models_responses_stream_events.test.ts`, with the SDK
 * client replaced by a scripted {@see FakeHttpClient}.
 */
#[CoversClass(ChatOpenAIResponses::class)]
final class ChatOpenAIResponsesTest extends TestCase
{
    private const RESPONSE = [
        'id' => 'resp_1',
        'object' => 'response',
        'created_at' => 1700000000,
        'status' => 'completed',
        'model' => 'gpt-4o-2024-08-06',
        'output' => [[
            'type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => 'Hello there.', 'annotations' => []]],
        ]],
        'usage' => ['input_tokens' => 9, 'output_tokens' => 3, 'total_tokens' => 12],
    ];

    /**
     * @param list<HttpResponse>   $responses
     * @param list<string>         $stream
     * @param array<string, mixed> $fields
     */
    private static function model(array $responses = [], array $stream = [], array $fields = []): TraceableChatOpenAIResponses
    {
        return new TraceableChatOpenAIResponses($fields + [
            'model' => 'gpt-4o',
            'apiKey' => 'sk-test',
            'httpClient' => new FakeHttpClient($responses, $stream),
            'maxRetries' => 0,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<string>
     */
    private static function sse(array $events): array
    {
        return array_map(
            static fn (array $e): string => 'event: ' . $e['type'] . "\ndata: " . json_encode($e) . "\n\n",
            $events,
        );
    }

    /** @return array<string, mixed> */
    private static function functionTool(string $name = 'test_func'): array
    {
        return ['type' => 'function', 'function' => ['name' => $name, 'description' => 'testing', 'parameters' => ['type' => 'object', 'properties' => []]]];
    }

    /** @return list<array<string, mixed>> */
    private static function textEvents(): array
    {
        return [
            ['type' => 'response.created', 'response' => ['id' => 'resp_test', 'model' => 'gpt-4o-mini']],
            ['type' => 'response.output_text.delta', 'delta' => 'Foo', 'content_index' => 0, 'output_index' => 0],
            ['type' => 'response.output_text.delta', 'delta' => ' bar', 'content_index' => 0, 'output_index' => 0],
            ['type' => 'response.completed', 'response' => [
                'id' => 'resp_test', 'object' => 'response', 'created_at' => 0, 'status' => 'completed', 'model' => 'gpt-4o-mini',
                'output' => [['type' => 'message', 'id' => 'm', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Foo bar', 'annotations' => []]]]],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 2, 'total_tokens' => 3],
            ]],
        ];
    }

    // ---- MCP credential tracing ------------------------------------------

    public function testRedactsMcpCredentialsWithoutChangingRequestParams(): void
    {
        $model = self::model(fields: ['model' => 'gpt-4o']);
        $function = self::functionTool('lookup');
        $mcp = [
            'type' => 'mcp', 'server_label' => 'private-server', 'server_url' => 'https://example.com/mcp',
            'headers' => ['Authorization' => 'Bearer header-secret'], 'authorization' => 'Bearer authorization-secret',
        ];
        $options = ['temperature' => 0.3, 'tools' => [$function, $mcp]];

        $requestParams = $model->invocationParams($options);
        $traceParams = $model->traceParams($options);
        $traceOptions = $model->traceOptions($options);

        self::assertSame(['Authorization' => 'Bearer header-secret'], $requestParams['tools'][1]['headers']);
        self::assertSame('Bearer authorization-secret', $requestParams['tools'][1]['authorization']);
        self::assertSame('**REDACTED**', $traceParams['tools'][1]['headers']);
        self::assertSame('**REDACTED**', $traceParams['tools'][1]['authorization']);
        self::assertSame('**REDACTED**', $traceOptions['tools'][1]['headers']);
        self::assertSame('**REDACTED**', $traceOptions['tools'][1]['authorization']);
        self::assertSame(0.3, $traceOptions['temperature']);
        self::assertSame($function, $traceOptions['tools'][0]);
        self::assertStringNotContainsString('secret', json_encode([$traceParams, $traceOptions]));
    }

    // ---- constructor / params --------------------------------------------

    public function testConstructorStateReachesTheRequest(): void
    {
        $model = self::model(fields: ['temperature' => 0.3, 'topP' => 0.9, 'maxTokens' => 77, 'user' => 'u-1']);
        $params = $model->invocationParams();

        self::assertSame('gpt-4o', $params['model']);
        self::assertSame(0.3, $params['temperature']);
        self::assertSame(0.9, $params['top_p']);
        self::assertSame(77, $params['max_output_tokens']);
        self::assertSame('u-1', $params['user']);
        self::assertArrayNotHasKey('stream', $params);
        self::assertArrayNotHasKey('text', $params);
    }

    public function testAMaxTokensOfMinusOneIsOmitted(): void
    {
        self::assertArrayNotHasKey('max_output_tokens', self::model(fields: ['maxTokens' => -1])->invocationParams());
    }

    public function testFallsBackToSupportsStrictToolCallingWhenStrictIsUndefined(): void
    {
        $model = self::model(fields: ['supportsStrictToolCalling' => true]);

        $params = $model->invocationParams(['tools' => [self::functionTool()]]);

        self::assertArrayNotHasKey('strict', $params);
        self::assertTrue($params['tools'][0]['strict']);
    }

    public function testRespectsAUserProvidedStrictOption(): void
    {
        $model = self::model(fields: ['supportsStrictToolCalling' => true]);

        $params = $model->invocationParams(['strict' => false, 'tools' => [self::functionTool()]]);

        self::assertArrayNotHasKey('strict', $params);
        self::assertFalse($params['tools'][0]['strict']);
    }

    public function testServiceTierIsPassedToInvocationParams(): void
    {
        self::assertSame('auto', self::model(fields: ['service_tier' => 'auto'])->invocationParams()['service_tier']);
        self::assertSame('flex', self::model(fields: ['serviceTier' => 'flex'])->invocationParams()['service_tier']);
    }

    public function testZdrSendsStoreFalse(): void
    {
        self::assertFalse(self::model(fields: ['zdrEnabled' => true])->invocationParams()['store']);
        self::assertArrayNotHasKey('store', self::model()->invocationParams());
    }

    public function testResponsesOnlyOptionsReachTheRequest(): void
    {
        $params = self::model()->invocationParams([
            'previous_response_id' => 'resp_prev', 'truncation' => 'auto', 'include' => ['reasoning.encrypted_content'],
            'parallelToolCalls' => false,
        ]);

        self::assertSame('resp_prev', $params['previous_response_id']);
        self::assertSame('auto', $params['truncation']);
        self::assertSame(['reasoning.encrypted_content'], $params['include']);
        self::assertFalse($params['parallel_tool_calls']);
    }

    public function testBoundOptionsAreReadBack(): void
    {
        $bound = self::model()->bindTools([], ['truncation' => 'auto', 'temperature' => 0.0]);

        $params = $bound->invocationParams();

        self::assertSame('auto', $params['truncation']);
        self::assertSame(0.0, $params['temperature']);
    }

    public function testPromptCacheOptionsAreNormalised(): void
    {
        $params = self::model(fields: ['promptCacheKey' => 'k', 'promptCacheRetention' => 'in-memory', 'promptCacheOptions' => ['a' => 1]])->invocationParams();

        self::assertSame('k', $params['prompt_cache_key']);
        self::assertSame('in_memory', $params['prompt_cache_retention']);
        self::assertSame(['a' => 1], $params['prompt_cache_options']);
    }

    public function testModelKwargsAreMergedIntoTheBody(): void
    {
        $params = self::model(fields: ['modelKwargs' => ['metadata' => ['run' => '1']]])->invocationParams();

        self::assertSame(['run' => '1'], $params['metadata']);
    }

    public function testReasoningAppliesToReasoningModelsOnly(): void
    {
        $reasoning = self::model(fields: ['model' => 'o3', 'reasoning' => ['effort' => 'high', 'summary' => 'auto']])->invocationParams();
        $plain = self::model(fields: ['model' => 'gpt-4o', 'reasoning' => ['effort' => 'high']])->invocationParams();

        self::assertSame(['effort' => 'high', 'summary' => 'auto'], $reasoning['reasoning']);
        self::assertArrayNotHasKey('reasoning', $plain);
    }

    public function testReasoningEffortFillsAMissingEffortButNeverOverridesOne(): void
    {
        $model = self::model(fields: ['model' => 'o3']);
        self::assertSame(['effort' => 'low'], $model->invocationParams(['reasoningEffort' => 'low'])['reasoning']);

        $explicit = self::model(fields: ['model' => 'o3', 'reasoning' => ['effort' => 'high']]);
        self::assertSame(['effort' => 'high'], $explicit->invocationParams(['reasoningEffort' => 'low'])['reasoning']);
    }

    public function testAPerCallReasoningObjectSupersedesTheConstructorOne(): void
    {
        $model = self::model(fields: ['model' => 'o3', 'reasoning' => ['effort' => 'high', 'summary' => 'auto']]);

        self::assertSame(['effort' => 'low', 'summary' => 'auto'], $model->invocationParams(['reasoning' => ['effort' => 'low']])['reasoning']);
    }

    public function testJsonSchemaResponseFormatBecomesATextFormat(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];
        $params = self::model()->invocationParams([
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'out', 'schema' => $schema, 'strict' => true, 'description' => 'd']],
            'verbosity' => 'low',
        ]);

        self::assertSame([
            'format' => ['type' => 'json_schema', 'schema' => $schema, 'description' => 'd', 'name' => 'out', 'strict' => true],
            'verbosity' => 'low',
        ], $params['text']);
        self::assertArrayNotHasKey('response_format', $params);
    }

    public function testAnExplicitTextOptionWins(): void
    {
        $params = self::model()->invocationParams([
            'text' => ['verbosity' => 'high'],
            'response_format' => ['type' => 'json_object'],
        ]);

        self::assertSame(['verbosity' => 'high'], $params['text']);
    }

    public function testAJsonObjectFormatPassesThroughAsTheTextFormat(): void
    {
        $params = self::model()->invocationParams(['response_format' => ['type' => 'json_object']]);

        self::assertSame(['format' => ['type' => 'json_object']], $params['text']);
    }

    public function testToolChoiceIsFlattenedForTheResponsesShape(): void
    {
        $model = self::model();

        self::assertSame(['type' => 'function', 'name' => 'get_weather'], $model->invocationParams(['toolChoice' => 'get_weather'])['tool_choice']);
        self::assertSame(['type' => 'web_search_preview'], $model->invocationParams(['toolChoice' => ['type' => 'web_search_preview']])['tool_choice']);
    }

    public function testImageGenerationToolsGetPartialImagesOnlyWhenStreaming(): void
    {
        $model = self::model();
        $tool = ['type' => 'image_generation'];

        self::assertArrayNotHasKey('partial_images', $model->invocationParams(['tools' => [$tool]])['tools'][0]);
        self::assertSame(1, $model->invocationParams(['tools' => [$tool]], ['streaming' => true])['tools'][0]['partial_images']);
    }

    // ---- tool_search ------------------------------------------------------

    public function testToolSearchPassesThroughAsABuiltInTool(): void
    {
        $params = self::model(fields: ['model' => 'gpt-5.3'])->invocationParams(['tools' => [['type' => 'tool_search'], self::functionTool('get_weather')]]);

        self::assertCount(2, $params['tools']);
        self::assertSame(['type' => 'tool_search'], $params['tools'][0]);
        self::assertSame('function', $params['tools'][1]['type']);
    }

    public function testToolSearchWithClientExecutionPassesThrough(): void
    {
        $tool = [
            'type' => 'tool_search', 'execution' => 'client', 'description' => 'Search tools',
            'parameters' => ['type' => 'object', 'properties' => ['goal' => ['type' => 'string']]],
        ];

        $params = self::model(fields: ['model' => 'gpt-5.3'])->invocationParams(['tools' => [$tool]]);

        self::assertSame([$tool], $params['tools']);
    }

    public function testDeferLoadingIsPropagatedFromFunctionToolDefinitions(): void
    {
        $deferred = self::functionTool('get_weather') + ['defer_loading' => true];

        $params = self::model(fields: ['model' => 'gpt-5.3'])->invocationParams(['tools' => [['type' => 'tool_search'], $deferred]]);

        self::assertCount(2, $params['tools']);
        self::assertTrue($params['tools'][1]['defer_loading']);
        self::assertSame('function', $params['tools'][1]['type']);
        self::assertSame('get_weather', $params['tools'][1]['name']);
    }

    public function testALangChainCustomToolIsFlattenedForResponses(): void
    {
        $tool = \LangChain\Tools\tool(static fn (array $a): string => 'x', [
            'name' => 'exec', 'description' => 'run it', 'schema' => \LangChain\Tools\Schema::object([]),
            'metadata' => ['customTool' => ['name' => 'exec', 'description' => 'run it', 'format' => ['type' => 'text']]],
        ]);

        $params = self::model()->invocationParams(['tools' => [$tool]]);

        self::assertSame([['type' => 'custom', 'name' => 'exec', 'description' => 'run it', 'format' => ['type' => 'text']]], $params['tools']);
    }

    public function testAChatCompletionsCustomToolPassesThroughAsBuiltInLikeUpstream(): void
    {
        // `isBuiltInTool` is `type !== "function"` upstream too, so the
        // `{type: "custom", custom: {...}}` shape is forwarded untouched.
        $custom = ['type' => 'custom', 'custom' => ['name' => 'exec']];

        self::assertSame([$custom], self::model()->invocationParams(['tools' => [$custom]])['tools']);
    }

    // ---- the round trip ---------------------------------------------------

    public function testInvokePostsToTheResponsesEndpointWithInputItems(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)]);

        $message = $model->invoke([new SystemMessage('be terse'), new HumanMessage('hi')]);

        self::assertInstanceOf(AIMessage::class, $message);
        self::assertSame('https://api.openai.com/v1/responses', $model->httpClient->requests[0]['url']);
        self::assertSame('Bearer sk-test', $model->httpClient->requests[0]['headers']['Authorization']);

        $body = $model->httpClient->lastRequestBody();
        self::assertSame('gpt-4o', $body['model']);
        self::assertFalse($body['stream']);
        self::assertSame([
            ['type' => 'message', 'role' => 'system', 'content' => 'be terse'],
            ['type' => 'message', 'role' => 'user', 'content' => 'hi'],
        ], $body['input']);
        self::assertArrayNotHasKey('messages', $body);
    }

    public function testInvokeReturnsTheConvertedMessage(): void
    {
        $message = self::model([FakeHttpClient::json(200, self::RESPONSE)])->invoke('hi');

        self::assertSame('resp_1', $message->id);
        self::assertSame('Hello there.', $message->content[0]['text']);
        self::assertSame(9, $message->response_metadata['usage_metadata']['input_tokens']);
        self::assertSame('openai', $message->response_metadata['model_provider']);
    }

    public function testInvokeReportsTokenUsageOnTheResult(): void
    {
        $result = self::model([FakeHttpClient::json(200, self::RESPONSE)])->generateMessages([[new HumanMessage('hi')]]);

        self::assertSame(12, $result->llmOutput['tokenUsage']['totalTokens']);
        self::assertSame('Hello there.', $result->generations[0][0]->text);
    }

    public function testToolCallsComeBackParsed(): void
    {
        $payload = ['output' => [['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'get_weather', 'arguments' => '{"city":"Austin"}']]] + self::RESPONSE;

        $message = self::model([FakeHttpClient::json(200, $payload)])->invoke('weather?');

        self::assertSame('get_weather', $message->toolCalls[0]['name']);
        self::assertSame(['city' => 'Austin'], $message->toolCalls[0]['args']);
        self::assertSame('call_1', $message->toolCalls[0]['id']);
    }

    public function testAToolMessageGoesBackAsAFunctionCallOutput(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)]);

        $model->invoke([new HumanMessage('hi'), new ToolMessage(['content' => '72F', 'tool_call_id' => 'call_1'])]);

        self::assertSame(
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '72F'],
            $model->httpClient->lastRequestBody()['input'][1],
        );
    }

    public function testAnErrorObjectInTheResponseIsRaised(): void
    {
        $model = self::model([FakeHttpClient::json(200, ['error' => ['code' => 'server_error', 'message' => 'boom']] + self::RESPONSE)]);

        $this->expectException(OpenAIException::class);
        $this->expectExceptionMessage('boom');

        $model->invoke('hi');
    }

    public function testAnHttpErrorIsAnOpenAiException(): void
    {
        $model = self::model([FakeHttpClient::json(401, ['error' => ['message' => 'bad key', 'code' => 'invalid_api_key']])]);

        try {
            $model->invoke('hi');
            self::fail('expected an exception');
        } catch (OpenAIException $e) {
            self::assertSame(401, $e->status);
            self::assertStringContainsString('bad key', $e->getMessage());
        }
    }

    public function testATransientStatusIsRetriedThroughTheSharedTransport(): void
    {
        $model = self::model(
            [FakeHttpClient::json(429, ['error' => ['message' => 'slow down']]), FakeHttpClient::json(200, self::RESPONSE)],
            fields: ['maxRetries' => 1],
        );

        self::assertSame('resp_1', $model->invoke('hi')->id);
        self::assertSame(1, $model->backoffCalls);
        self::assertCount(2, $model->httpClient->requests);
    }

    // ---- streaming --------------------------------------------------------

    public function testStreamFoldsEventsIntoChunks(): void
    {
        $model = self::model(stream: self::sse(self::textEvents()));

        $text = '';
        foreach ($model->stream('hi') as [$channel, $chunk]) {
            $text .= is_string($chunk->content) ? $chunk->content : implode('', array_column(array_filter($chunk->content, static fn (mixed $b): bool => is_array($b) && ($b['type'] ?? null) === 'text'), 'text'));
        }

        self::assertSame('Foo bar', $text);
        self::assertSame('https://api.openai.com/v1/responses', $model->httpClient->streamRequests[0]['url']);
        self::assertTrue($model->httpClient->lastRequestBody()['stream']);
    }

    public function testAStreamingModelAggregatesTheStreamOnInvoke(): void
    {
        $model = self::model(stream: self::sse(self::textEvents()), fields: ['streaming' => true]);

        $message = $model->invoke('hi');

        self::assertSame('Foo bar', $message->content[0]['text']);
        self::assertSame(1, $message->response_metadata['usage_metadata']['input_tokens']);
        self::assertSame(3, $message->response_metadata['usage_metadata']['total_tokens']);
        self::assertSame([], $model->httpClient->requests, 'the eager endpoint was never used');
        self::assertCount(1, $model->httpClient->streamRequests);
    }

    public function testAStreamedToolCallIsReassembledOnInvoke(): void
    {
        $events = [
            ['type' => 'response.created', 'response' => ['id' => 'resp_t', 'model' => 'gpt-4o']],
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_abc', 'name' => 'web_search', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => '{"query"'],
            ['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => ':"weather"}'],
            ['type' => 'response.completed', 'response' => ['id' => 'resp_t', 'model' => 'gpt-4o', 'status' => 'completed', 'output' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2]]],
        ];

        $message = self::model(stream: self::sse($events), fields: ['streaming' => true])->invoke('hi');

        self::assertSame('web_search', $message->toolCalls[0]['name']);
        self::assertSame(['query' => 'weather'], $message->toolCalls[0]['args']);
        self::assertSame('call_abc', $message->toolCalls[0]['id']);
    }

    public function testATokenCallbackFiresPerChunkWhenTheHandlerPrefersStreaming(): void
    {
        $handler = new class () extends BaseCallbackHandler {
            public bool $preferStreaming = true;

            /** @var list<string> */
            public array $tokens = [];

            public function handleLLMNewToken(string $token, array $idx, string $runId, ?string $parentRunId = null, array $tags = [], array $fields = []): void
            {
                $this->tokens[] = $token;
            }
        };
        $model = self::model(stream: self::sse(self::textEvents()));

        $result = $model->invoke('test', new RunnableConfig(callbacks: [$handler]));

        self::assertContains('Foo', $handler->tokens);
        self::assertContains(' bar', $handler->tokens);
        self::assertSame('Foo bar', $result->content[0]['text']);
    }

    public function testAnErrorEventOnTheStreamIsRaisedWithItsProviderCode(): void
    {
        $model = self::model(stream: self::sse([
            ['type' => 'response.created', 'response' => ['id' => 'r', 'model' => 'm']],
            ['type' => 'error', 'code' => 'context_length_exceeded', 'message' => 'The request exceeds the context window.'],
        ]));

        try {
            iterator_to_array($model->stream('hi'), false);
            self::fail('expected an exception');
        } catch (OpenAIException $e) {
            self::assertStringContainsString('The request exceeds the context window.', $e->getMessage());
            self::assertSame('context_length_exceeded', $e->providerError['code']);
        }
    }

    public function testAMidStreamFailureIsNotRetried(): void
    {
        $model = self::model(stream: [
            ...self::sse([self::textEvents()[0]]),
            "data: {\"type\":\"error\",\"code\":\"server_error\",\"message\":\"x\"}\n\n",
        ], fields: ['maxRetries' => 3]);

        try {
            iterator_to_array($model->stream('hi'), false);
            self::fail('expected an exception');
        } catch (OpenAIException) {
            self::assertCount(1, $model->httpClient->streamRequests);
        }
    }

    // ---- stream events ----------------------------------------------------

    public function testStreamChatModelEventsEmitsMessageStartWithTheId(): void
    {
        $model = self::model(stream: self::sse(self::textEvents()));

        $events = iterator_to_array($model->streamChatModelEvents([]), false);

        $start = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'message-start'))[0];
        self::assertSame('resp_test', $start['id']);
    }

    public function testStreamChatModelEventsStreamsText(): void
    {
        $model = self::model(stream: self::sse(self::textEvents()));

        $events = iterator_to_array($model->streamChatModelEvents([new HumanMessage('Hello')]), false);

        $finish = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'content-block-finish'))[0];
        self::assertSame('Foo bar', $finish['content']['text']);
        self::assertTrue($model->httpClient->lastRequestBody()['stream']);
    }

    public function testStreamChatModelEventsStreamsUsage(): void
    {
        $events = iterator_to_array(self::model(stream: self::sse(self::textEvents()))->streamChatModelEvents([]), false);

        $usage = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'usage'))[0]['usage'];
        self::assertSame(['input_tokens' => 1, 'output_tokens' => 2, 'total_tokens' => 3], array_intersect_key($usage, array_flip(['input_tokens', 'output_tokens', 'total_tokens'])));
    }

    public function testStreamChatModelEventsHonoursStreamUsageFalse(): void
    {
        $model = self::model(stream: self::sse(self::textEvents()), fields: ['streamUsage' => false]);

        $events = iterator_to_array($model->streamChatModelEvents([]), false);

        self::assertSame([], array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'usage')));
    }

    public function testStreamChatModelEventsStreamsReasoning(): void
    {
        $model = self::model(stream: self::sse([
            ['type' => 'response.created', 'response' => ['id' => 'resp_reasoning', 'model' => 'o3']],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Let me', 'summary_index' => 0, 'output_index' => 0],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => ' think', 'summary_index' => 0, 'output_index' => 0],
            ['type' => 'response.completed', 'response' => ['id' => 'resp_reasoning', 'model' => 'o3', 'status' => 'completed', 'output' => []]],
        ]), fields: ['model' => 'o3']);

        $events = iterator_to_array($model->streamChatModelEvents([]), false);

        $finish = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'content-block-finish'))[0];
        self::assertSame('Let me think', $finish['content']['reasoning']);
    }

    public function testStreamChatModelEventsStreamsToolCalls(): void
    {
        $model = self::model(stream: self::sse([
            ['type' => 'response.created', 'response' => ['id' => 'resp_tools', 'model' => 'gpt-4o-mini']],
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_abc', 'name' => 'web_search', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => '{"query"'],
            ['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => ':"weather"}'],
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_abc', 'name' => 'web_search', 'arguments' => '{"query":"weather"}']],
            ['type' => 'response.completed', 'response' => ['id' => 'resp_tools', 'model' => 'gpt-4o-mini', 'status' => 'completed', 'output' => []]],
        ]));

        $events = iterator_to_array($model->streamChatModelEvents([]), false);

        $finish = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'content-block-finish'))[0];
        self::assertSame('tool_call', $finish['content']['type']);
        self::assertSame('web_search', $finish['content']['name']);
        self::assertSame(['query' => 'weather'], $finish['content']['args']);
    }

    // ---- url --------------------------------------------------------------

    public function testAChatCompletionsBaseUrlIsRetargetedAtTheResponsesEndpoint(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)], fields: ['baseUrl' => 'https://proxy.internal/v1/chat/completions']);

        $model->invoke('hi');

        self::assertSame('https://proxy.internal/v1/responses', $model->httpClient->requests[0]['url']);
    }

    public function testAnExplicitResponsesUrlIsUsedAsGiven(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)], fields: ['baseUrl' => 'https://proxy.internal/openai/responses']);

        $model->invoke('hi');

        self::assertSame('https://proxy.internal/openai/responses', $model->httpClient->requests[0]['url']);
    }

    public function testMissingApiKeyIsReportedBeforeAnyRequest(): void
    {
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY');

        try {
            $model = new TraceableChatOpenAIResponses(['httpClient' => new FakeHttpClient()]);
            $this->expectException(OpenAIException::class);
            $this->expectExceptionMessage('OPENAI_API_KEY');
            $model->invoke('hi');
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
    }
}
