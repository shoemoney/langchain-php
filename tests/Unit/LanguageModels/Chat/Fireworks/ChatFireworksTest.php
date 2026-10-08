<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Fireworks;

use LangChain\LanguageModels\Chat\Fireworks\ChatFireworks;
use LangChain\Messages\AIMessage;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `chat_models.test.ts` and `chat_models_stream_events.test.ts` from
 * `@langchain/fireworks`.
 */
#[CoversClass(ChatFireworks::class)]
final class ChatFireworksTest extends TestCase
{
    private const ENV = [
        'LANGSMITH_GATEWAY', 'LANGSMITH_GATEWAY_API_KEY', 'LANGSMITH_API_KEY',
        'FIREWORKS_API_BASE', 'FIREWORKS_BASE_URL', 'FIREWORKS_API_KEY',
    ];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    private const COMPLETION = [
        'id' => 'chatcmpl-1', 'object' => 'chat.completion', 'model' => 'accounts/fireworks/models/firefunction-v2',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hi from Fireworks.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 4, 'total_tokens' => 8],
    ];

    /**
     * @param list<\LangChain\Utils\Http\HttpResponse> $responses
     * @param list<string>                              $stream
     * @param array<string, mixed>                      $fields
     */
    private static function model(array $responses = [], array $stream = [], array $fields = []): ChatFireworks
    {
        return new ChatFireworks($fields + [
            'apiKey' => 'test-api-key', 'maxRetries' => 0,
            'httpClient' => new FakeHttpClient($responses, $stream),
        ]);
    }

    public function testSupportsStringModelShorthand(): void
    {
        $model = new ChatFireworks('accounts/fireworks/models/firefunction-v2', [
            'apiKey' => 'test-api-key',
            'temperature' => 0.2,
        ]);

        self::assertSame('accounts/fireworks/models/firefunction-v2', $model->model);
        self::assertSame(0.2, $model->temperature);
    }

    public function testUsesLangSmithGatewayEnvironmentConfiguration(): void
    {
        putenv('LANGSMITH_GATEWAY=true');
        putenv('LANGSMITH_GATEWAY_API_KEY=gateway-key');
        putenv('FIREWORKS_API_BASE=');
        putenv('FIREWORKS_BASE_URL=');
        putenv('FIREWORKS_API_KEY=provider-key');

        $model = new ChatFireworks(['httpClient' => new FakeHttpClient([FakeHttpClient::json(200, self::COMPLETION)])]);
        $model->invoke('hi');

        self::assertSame('gateway-key', $model->apiKey);
        self::assertSame(
            'https://gateway.smith.langchain.com/fireworks/chat/completions',
            $model->httpClient->requests[0]['url'],
        );
        self::assertSame('Bearer gateway-key', $model->httpClient->requests[0]['headers']['Authorization']);
    }

    public function testPrefersTheFireworksBaseUrlEnvironmentConfiguration(): void
    {
        putenv('LANGSMITH_GATEWAY=true');
        putenv('LANGSMITH_GATEWAY_API_KEY=gateway-key');
        putenv('FIREWORKS_API_BASE=');
        putenv('FIREWORKS_BASE_URL=https://fireworks.example.com/v1');
        putenv('FIREWORKS_API_KEY=provider-key');

        $model = new ChatFireworks(['httpClient' => new FakeHttpClient([FakeHttpClient::json(200, self::COMPLETION)])]);
        $model->invoke('hi');

        self::assertSame('provider-key', $model->apiKey);
        self::assertSame('https://fireworks.example.com/v1/chat/completions', $model->httpClient->requests[0]['url']);
    }

    public function testSerializesWithFireworksSecretAliases(): void
    {
        $model = new ChatFireworks(['apiKey' => 'test-api-key', 'model' => 'accounts/fireworks/models/firefunction-v2']);

        $json = (string) json_encode($model);

        self::assertStringContainsString('"id":["langchain","chat_models","fireworks","ChatFireworks"]', $json);
        self::assertSame(
            ['fireworksApiKey' => 'FIREWORKS_API_KEY', 'apiKey' => 'FIREWORKS_API_KEY'],
            ChatFireworks::lcSecrets(),
        );
        self::assertStringNotContainsString('test-api-key', $json);
    }

    public function testStripsUnsupportedParametersFromTheRequest(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)], fields: [
            'frequencyPenalty' => 1.0,
            'presencePenalty' => 1.0,
            'temperature' => 0.5,
        ]);

        $model->invoke('hi');
        $body = $model->httpClient->lastRequestBody();

        self::assertArrayNotHasKey('frequency_penalty', $body);
        self::assertArrayNotHasKey('presence_penalty', $body);
        self::assertArrayNotHasKey('logit_bias', $body);
        self::assertArrayNotHasKey('functions', $body);
        self::assertSame(0.5, $body['temperature']);
    }

    public function testStripsUnsupportedParametersFromAStreamingRequest(): void
    {
        $model = self::model(stream: [self::sse(['choices' => [['index' => 0, 'delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]), "data: [DONE]\n\n"], fields: ['frequencyPenalty' => 1.0, 'presencePenalty' => 1.0]);

        iterator_to_array($model->stream('hi'));
        $body = $model->httpClient->lastRequestBody();

        self::assertTrue($body['stream']);
        self::assertArrayNotHasKey('frequency_penalty', $body);
        self::assertArrayNotHasKey('presence_penalty', $body);
        // Fireworks has no stream_options, so usage is never requested.
        self::assertArrayNotHasKey('stream_options', $body);
    }

    public function testDefaultsTheModelAndTheEndpoint(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)]);

        $message = $model->invoke('hi');

        self::assertInstanceOf(AIMessage::class, $message);
        self::assertSame('Hi from Fireworks.', $message->content);
        self::assertSame(ChatFireworks::DEFAULT_FIREWORKS_CHAT_MODEL, $model->httpClient->lastRequestBody()['model']);
        self::assertSame('https://api.fireworks.ai/inference/v1/chat/completions', $model->httpClient->requests[0]['url']);
        self::assertSame('fireworks', $model->llmType());
        self::assertSame('fireworks', $model->getLsParams()['ls_provider']);
    }

    public function testThrowsWhenNoApiKeyIsConfigured(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Fireworks API key not found');

        new ChatFireworks();
    }

    public function testAnExplicitBaseUrlIsUsedAsTheApiRoot(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)], fields: ['configuration' => ['baseURL' => 'https://proxy.test/v1/']]);

        $model->invoke('hi');

        self::assertSame('https://proxy.test/v1/chat/completions', $model->httpClient->requests[0]['url']);
    }

    // ---- chat_models_stream_events.test.ts: text, reasoning, tool calls ----------------------------

    public function testStreamsText(): void
    {
        $model = self::model(stream: [
            self::sse(['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello']]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => ['content' => ' world']]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]),
            "data: [DONE]\n\n",
        ]);

        $text = '';
        foreach ($model->stream('Hello') as [, $chunk]) {
            $text .= $chunk->content;
        }

        self::assertSame('Hello world', $text);
    }

    public function testStreamsToolCalls(): void
    {
        $model = self::model(stream: [
            self::sse(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'web_search', 'arguments' => '']]]]]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"query":']]]]]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"weather"}']]]]]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]),
            "data: [DONE]\n\n",
        ]);

        $folded = null;
        foreach ($model->stream('Hello') as [, $chunk]) {
            $folded = $folded === null ? $chunk : $folded->concat($chunk);
        }

        $calls = $folded->toMessage()->toolCalls;
        self::assertSame('web_search', $calls[0]['name']);
        self::assertSame(['query' => 'weather'], $calls[0]['args']);
    }

    // ---- end to end ---------------------------------------------------------------------------------

    public function testAPromptModelParserChainRunsEndToEnd(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)], fields: ['model' => 'accounts/fireworks/models/firefunction-v2']);
        $chain = ChatPromptTemplate::fromMessages([['system', 'Answer as {persona}.'], ['human', '{question}']])
            ->pipe($model)
            ->pipe(new StringOutputParser());

        $out = $chain->invoke(['persona' => 'a pirate', 'question' => 'ahoy?']);

        self::assertSame('Hi from Fireworks.', $out);
        self::assertSame(
            [['role' => 'system', 'content' => 'Answer as a pirate.'], ['role' => 'user', 'content' => 'ahoy?']],
            $model->httpClient->lastRequestBody()['messages'],
        );
        self::assertSame('Bearer test-api-key', $model->httpClient->requests[0]['headers']['Authorization']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function sse(array $payload): string
    {
        return 'data: ' . json_encode($payload + ['id' => 'chatcmpl-test', 'model' => 'accounts/fireworks/models/llama-v3p1-8b-instruct']) . "\n\n";
    }
}
