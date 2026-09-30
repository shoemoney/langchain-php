<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Completions;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Tools;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tools\Schema;
use function LangChain\Tools\tool;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * {@see ChatOpenAI} with the retry sleep removed.
 */
final class NoSleepChatOpenAI extends ChatOpenAI
{
    public int $backoffCalls = 0;

    protected function backoff(int $attempt): void
    {
        $this->backoffCalls++;
    }
}

#[CoversClass(ChatOpenAI::class)]
#[CoversClass(Completions::class)]
#[CoversClass(Tools::class)]
#[CoversClass(OpenAIException::class)]
final class ChatOpenAITest extends TestCase
{
    private const COMPLETION = [
        'id' => 'chatcmpl-1',
        'object' => 'chat.completion',
        'model' => 'gpt-4o-2024-08-06',
        'system_fingerprint' => 'fp_44709d6fcb',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Hello there.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 3, 'total_tokens' => 12],
    ];

    /**
     * A client whose retry backoff does not sleep.
     *
     * `backoff()` is `protected` precisely so this is possible: the retry tests
     * care how many times a request was attempted, and paying 1s of `usleep`
     * per attempt to learn that is a suite that is slower for no information.
     */
    private static function model(array $responses = [], array $fields = []): ChatOpenAI
    {
        return new NoSleepChatOpenAI($fields + [
            'model' => 'gpt-4o',
            'apiKey' => 'sk-test',
            'httpClient' => new FakeHttpClient($responses),
            'maxRetries' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private static function completion(array $overrides = []): array
    {
        return array_replace_recursive(self::COMPLETION, $overrides);
    }

    // ---- the round trip --------------------------------------------------

    public function testInvokeReturnsTheAssistantMessage(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::completion())]);

        $message = $model->invoke([new HumanMessage('hi')]);

        self::assertInstanceOf(AIMessage::class, $message);
        self::assertSame('Hello there.', $message->content);
    }

    public function testInvokeSendsTheExpectedWireShape(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::completion())]);
        $model->invoke([new SystemMessage('be terse'), new HumanMessage('hi')]);

        $body = $model->httpClient->lastRequestBody();

        self::assertSame('gpt-4o', $body['model']);
        self::assertSame([
            ['role' => 'system', 'content' => 'be terse'],
            ['role' => 'user', 'content' => 'hi'],
        ], $body['messages']);
    }

    public function testAuthorizationHeaderIsSent(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::completion())]);
        $model->invoke('hi');

        self::assertSame('Bearer sk-test', $model->httpClient->requests[0]['headers']['Authorization']);
    }

    public function testApiKeyFallsBackToTheEnvironment(): void
    {
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=sk-from-env');

        try {
            $model = new ChatOpenAI(['httpClient' => new FakeHttpClient()]);
            self::assertSame('sk-from-env', $model->apiKey);
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
    }

    /**
     * The key must never reach `kwargs()`.
     *
     * `kwargs()` is what the tracer serialises into a run record. A credential
     * in there is a credential in every trace export.
     */
    public function testApiKeyIsNotSerialized(): void
    {
        $model = self::model();

        self::assertArrayNotHasKey('apiKey', $model->kwargs());
    }

    public function testMissingApiKeyFailsWithAnActionableMessage(): void
    {
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY');

        try {
            $model = new ChatOpenAI(['httpClient' => new FakeHttpClient([FakeHttpClient::json(200, self::completion())])]);
            $model->invoke('hi');
            self::fail('expected an OpenAIException');
        } catch (OpenAIException $e) {
            self::assertStringContainsString('OPENAI_API_KEY', $e->getMessage());
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
    }

    public function testBaseUrlIsHonoured(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::completion())], [
            'baseUrl' => 'https://proxy.internal/v1/chat/completions',
        ]);
        $model->invoke('hi');

        self::assertSame('https://proxy.internal/v1/chat/completions', $model->httpClient->requests[0]['url']);
    }

    public function testOrganizationHeaderIsSent(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::completion())], ['organization' => 'org-123']);
        $model->invoke('hi');

        self::assertSame('org-123', $model->httpClient->requests[0]['headers']['OpenAI-Organization']);
    }

    // ---- usage -----------------------------------------------------------

    public function testUsageIsReadOntoTheMessage(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::completion())]);
        $message = $model->invoke('hi');

        self::assertSame([
            'input_tokens' => 9,
            'output_tokens' => 3,
            'total_tokens' => 12,
        ], $message->response_metadata['usage_metadata']);
        self::assertSame('fp_44709d6fcb', $message->response_metadata['system_fingerprint']);
        self::assertSame('openai', $message->response_metadata['model_provider']);
    }

    // ---- tool calls ------------------------------------------------------

    public function testToolCallIsParsedIntoTheMessage(): void
    {
        $payload = self::completion(['choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_abc',
                    'type' => 'function',
                    'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Austin"}'],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]]]);

        $model = self::model([FakeHttpClient::json(200, $payload)]);
        $message = $model->invoke('weather in Austin?');

        self::assertSame([[
            'name' => 'get_weather',
            'args' => ['city' => 'Austin'],
            'id' => 'call_abc',
            'type' => 'tool_call',
        ]], $message->toolCalls);
    }

    /**
     * A tool call whose arguments are not JSON is kept, marked invalid.
     *
     * Dropping it would make a hallucinated call indistinguishable from a model
     * that chose not to call, and the agent loop would go looking for a result
     * that can never arrive.
     */
    public function testUnparseableToolCallBecomesInvalid(): void
    {
        $payload = self::completion(['choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'broken', 'arguments' => '{not json'],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]]]);

        $model = self::model([FakeHttpClient::json(200, $payload)]);
        $message = $model->invoke('go');

        self::assertSame([], $message->toolCalls);
        self::assertCount(1, $message->invalidToolCalls);
        self::assertStringContainsString('not valid JSON', $message->invalidToolCalls[0]['error']);
    }

    public function testToolResultIsSentAsAToolMessage(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::completion())]);
        $model->invoke([
            new AIMessage([
                'content' => '',
                'tool_calls' => [['name' => 'get_weather', 'args' => ['city' => 'Austin'], 'id' => 'call_1', 'type' => 'tool_call']],
            ]),
            new ToolMessage(['content' => '72F', 'tool_call_id' => 'call_1']),
        ]);

        $body = $model->httpClient->lastRequestBody();

        self::assertSame('assistant', $body['messages'][0]['role']);
        // Arguments go out as a JSON *string*, which is the wire format.
        self::assertSame('{"city":"Austin"}', $body['messages'][0]['tool_calls'][0]['function']['arguments']);
        self::assertSame('tool', $body['messages'][1]['role']);
        self::assertSame('call_1', $body['messages'][1]['tool_call_id']);
        self::assertSame('72F', $body['messages'][1]['content']);
    }

    public function testBindToolsRendersARealTool(): void
    {
        $model = self::model();
        $tool = tool(static fn (array $a): string => 'sunny', [
            'name' => 'get_weather',
            'description' => 'Look up the weather',
            'schema' => Schema::object(['city' => ['type' => 'string']]),
        ]);

        $bound = $model->bindTools([$tool]);

        self::assertSame([[
            'type' => 'function',
            'function' => [
                'name' => 'get_weather',
                'description' => 'Look up the weather',
                'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => []],
            ],
        ]], $bound->kwargs()['tools']);
    }

    public function testBindToolsDoesNotMutateTheReceiver(): void
    {
        $model = self::model();
        $model->bindTools([tool(static fn (array $a): string => 'x', ['name' => 'x', 'description' => 'd', 'schema' => Schema::object([])])]);

        self::assertArrayNotHasKey('tools', $model->kwargs());
    }

    /**
     * A provider-native definition passes straight through.
     *
     * Re-wrapping it would produce `function.function`, which is still
     * well-formed enough to look right and is unreadable to the model.
     */
    public function testBindToolsPassesProviderShapeThrough(): void
    {
        $native = ['type' => 'web_search_preview'];
        $bound = self::model()->bindTools([$native]);

        self::assertSame([$native], $bound->kwargs()['tools']);
    }

    public function testBindToolsCarriesCallOptions(): void
    {
        $bound = self::model()->bindTools([], ['temperature' => 0.0]);

        self::assertSame(0.0, $bound->kwargs()['temperature']);
        self::assertSame(0.0, $bound->invocationParams()['temperature']);
    }

    public function testToolChoiceIsFormatted(): void
    {
        $model = self::model();

        self::assertSame('auto', $model->invocationParams(['toolChoice' => 'auto'])['tool_choice']);
        self::assertSame(
            ['type' => 'function', 'function' => ['name' => 'get_weather']],
            $model->invocationParams(['toolChoice' => 'get_weather'])['tool_choice'],
        );
    }

    public function testEmptyToolIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must have a name');

        self::model()->bindTools([['description' => 'nameless']]);
    }

    // ---- end to end with structured output -------------------------------

    /**
     * The whole reason the client exists: a schema in, a value out.
     */
    public function testWithStructuredOutputEndToEnd(): void
    {
        $payload = self::completion(['choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'extract', 'arguments' => '{"name":"Ada Lovelace"}'],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]]]);

        $model = self::model([FakeHttpClient::json(200, $payload)]);

        $result = $model->withStructuredOutput([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ])->invoke('who wrote the first algorithm?');

        self::assertSame(['name' => 'Ada Lovelace'], $result);

        // The schema really was offered to the model as a tool.
        $tools = $model->httpClient->lastRequestBody()['tools'];
        self::assertSame('extract', $tools[0]['function']['name']);
    }

    // ---- streaming -------------------------------------------------------

    public function testStreamFoldsDeltas(): void
    {
        $http = new FakeHttpClient([], [
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hel\"}}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"lo\"}}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"usage\":{\"prompt_tokens\":5,\"completion_tokens\":2,\"total_tokens\":7}}\n\n",
            "data: [DONE]\n\n",
        ]);

        $model = new ChatOpenAI([
            'model' => 'gpt-4o',
            'apiKey' => 'sk-test',
            'httpClient' => $http,
        ]);

        $text = '';
        foreach ($model->stream('hi') as [$channel, $chunk]) {
            $text .= is_string($chunk->content) ? $chunk->content : '';
        }

        self::assertSame('Hello', $text);
        self::assertTrue($http->lastRequestBody()['stream']);
        self::assertSame(['include_usage' => true], $http->lastRequestBody()['stream_options']);
    }

    /**
     * A streamed tool call is accumulated from fragments, not decoded per chunk.
     */
    public function testStreamFoldsToolCallFragments(): void
    {
        $http = new FakeHttpClient([], [
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_1\",\"function\":{\"name\":\"get_weather\",\"arguments\":\"\"}}]}}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"{\\\"city\\\":\"}}]}}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"\\\"Austin\\\"}\"}}]}}]}\n\n",
            "data: [DONE]\n\n",
        ]);

        $model = new ChatOpenAI(['model' => 'gpt-4o', 'apiKey' => 'sk-test', 'httpClient' => $http]);

        $folded = null;
        foreach ($model->stream('weather?') as [, $chunk]) {
            $folded = $folded === null ? $chunk : $folded->concat($chunk);
        }

        [$calls] = $folded->parseToolCalls();
        self::assertSame([[
            'name' => 'get_weather',
            'args' => ['city' => 'Austin'],
            'id' => 'call_1',
            'type' => 'tool_call',
            'index' => 0,
        ]], $calls);
    }

    // ---- errors ----------------------------------------------------------

    public function testProviderErrorIsRaisedWithItsCode(): void
    {
        $http = new FakeHttpClient([
            new HttpResponse(400, [], (string) json_encode([
                'error' => ['message' => 'Unknown model', 'code' => 'model_not_found', 'type' => 'invalid_request_error'],
            ])),
        ]);

        $model = new NoSleepChatOpenAI(['model' => 'nope', 'apiKey' => 'sk-test', 'httpClient' => $http, 'maxRetries' => 0]);

        try {
            $model->invoke('hi');
            self::fail('expected an OpenAIException');
        } catch (OpenAIException $e) {
            self::assertSame(400, $e->status);
            self::assertStringContainsString('Unknown model', $e->getMessage());
            self::assertStringContainsString('model_not_found', $e->getMessage());
            self::assertSame('model_not_found', $e->providerError['code']);
        }
    }

    /**
     * A 4xx is the caller's fault, so it is not retried.
     */
    public function testClientErrorIsNotRetried(): void
    {
        $http = new FakeHttpClient([
            new HttpResponse(401, [], '{"error":{"message":"bad key"}}'),
            FakeHttpClient::json(200, self::completion()),
        ]);

        $model = new NoSleepChatOpenAI(['model' => 'gpt-4o', 'apiKey' => 'sk-test', 'httpClient' => $http, 'maxRetries' => 3]);

        try {
            $model->invoke('hi');
            self::fail('expected an OpenAIException');
        } catch (OpenAIException) {
            self::assertCount(1, $http->requests, 'a 401 must not be retried');
        }
    }

    /**
     * A 429 is worth another attempt.
     */
    public function testRateLimitIsRetried(): void
    {
        $http = new FakeHttpClient([
            new HttpResponse(429, [], '{"error":{"message":"slow down"}}'),
            FakeHttpClient::json(200, self::completion()),
        ]);

        $model = new NoSleepChatOpenAI(['model' => 'gpt-4o', 'apiKey' => 'sk-test', 'httpClient' => $http, 'maxRetries' => 2]);

        self::assertInstanceOf(AIMessage::class, $model->invoke('hi'));
        self::assertCount(2, $http->requests);
    }

    /**
     * A transport failure consumes the retry budget rather than leaking out on
     * the first attempt. `post()` catches `HttpException` around the call, so a
     * connection reset is retried like a 5xx.
     */
    public function testTransportFailureIsRetried(): void
    {
        $http = new class implements \LangChain\Utils\Http\HttpClient {
            public int $calls = 0;

            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \LangChain\Utils\Http\HttpResponse
            {
                $this->calls++;
                throw new \LangChain\Utils\Http\HttpException('connection refused');
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                throw new \LangChain\Utils\Http\HttpException('connection refused');
            }
        };

        $model = new NoSleepChatOpenAI(['apiKey' => 'sk-test', 'httpClient' => $http, 'maxRetries' => 2]);

        try {
            $model->invoke('hi');
            self::fail('expected the transport failure to surface');
        } catch (OpenAIException $e) {
            // Converted, so a caller catching one type sees every failure —
            // provider error, rate limit, bad request and a dead network.
            self::assertSame(0, $e->status);

            // ...and the cause is attached rather than discarded. "status 0" on
            // its own does not say the connection was refused, and a network
            // failure is precisely when that detail is wanted.
            self::assertInstanceOf(\LangChain\Utils\Http\HttpException::class, $e->getPrevious());
            self::assertSame('connection refused', $e->getPrevious()->getMessage());
        }

        self::assertSame(3, $http->calls, 'initial attempt plus two retries');
    }

    /**
     * The backoff hook is the seam that keeps the retry tests off the clock.
     */
    public function testBackoffIsOverridable(): void
    {
        $http = new FakeHttpClient([
            new HttpResponse(429, [], '{}'),
            FakeHttpClient::json(200, self::completion()),
        ]);

        $model = new NoSleepChatOpenAI(['apiKey' => 'sk-test', 'httpClient' => $http, 'maxRetries' => 1]);
        $model->invoke('hi');

        self::assertSame(1, $model->backoffCalls);
    }

    /**
     * A `HttpClient` implementation is free to name its parameters anything.
     *
     * A PHP named argument binds to the *implementing* class's parameter name,
     * so a caller using `timeout:` would make this double fatal at runtime even
     * though it satisfies the interface.
     */
    public function testAnImplementationMayUseItsOwnParameterNames(): void
    {
        $odd = new class implements \LangChain\Utils\Http\HttpClient {
            public function post(string $a, array $b, string $c, array $d = [], ?float $e = null): \LangChain\Utils\Http\HttpResponse
            {
                return new \LangChain\Utils\Http\HttpResponse(200, [], (string) json_encode(self::payload()));
            }

            public function postStream(string $a, array $b, string $c, array $d = [], ?float $e = null): \Generator
            {
                yield '';
            }

            public static function payload(): array
            {
                return ['id' => 'x', 'model' => 'gpt-4o', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]]];
            }
        };

        $model = new ChatOpenAI(['apiKey' => 'sk-test', 'httpClient' => $odd]);

        self::assertSame('ok', $model->invoke('hi')->content);
    }

    public function testEmptyChoicesAreAnError(): void
    {
        $model = self::model([FakeHttpClient::json(200, ['id' => 'x', 'choices' => []])]);

        $this->expectException(OpenAIException::class);
        $this->expectExceptionMessage('no choices');

        $model->invoke('hi');
    }

    public function testNonJsonBodyIsReportedNotSwallowed(): void
    {
        $model = self::model([new HttpResponse(200, [], '<html>gateway</html>')]);

        $this->expectException(\LangChain\Utils\Http\HttpException::class);
        $this->expectExceptionMessage('not valid JSON');

        $model->invoke('hi');
    }

    // ---- invocation params ------------------------------------------------

    public function testNullFieldsAreOmittedFromTheRequest(): void
    {
        $params = (new ChatOpenAI(['model' => 'gpt-4o']))->invocationParams();

        self::assertSame(['model' => 'gpt-4o'], $params);
    }

    public function testPerCallOptionsBeatConstructorState(): void
    {
        $model = new ChatOpenAI(['model' => 'gpt-4o', 'temperature' => 0.7]);

        self::assertSame(0.7, $model->invocationParams()['temperature']);
        self::assertSame(0.0, $model->invocationParams(['temperature' => 0.0])['temperature']);
    }
}
