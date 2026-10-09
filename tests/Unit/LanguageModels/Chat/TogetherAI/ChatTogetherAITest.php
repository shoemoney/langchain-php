<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\TogetherAI;

use LangChain\LanguageModels\Chat\TogetherAI\ChatTogetherAI;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `chat_models.test.ts` and `chat_models_stream_events.test.ts` from
 * `@langchain/together-ai`.
 */
#[CoversClass(ChatTogetherAI::class)]
final class ChatTogetherAITest extends TestCase
{
    private const COMPLETION = [
        'id' => 'chatcmpl-1', 'object' => 'chat.completion', 'model' => 'meta-llama/Meta-Llama-3.1-8B-Instruct-Turbo',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hi from Together.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 4, 'total_tokens' => 8],
    ];

    private string|false $savedKey = false;

    protected function setUp(): void
    {
        $this->savedKey = getenv('TOGETHER_AI_API_KEY');
        putenv('TOGETHER_AI_API_KEY');
    }

    protected function tearDown(): void
    {
        putenv($this->savedKey === false ? 'TOGETHER_AI_API_KEY' : 'TOGETHER_AI_API_KEY=' . $this->savedKey);
    }

    /**
     * @param list<\LangChain\Utils\Http\HttpResponse> $responses
     * @param list<string>                              $stream
     * @param array<string, mixed>                      $fields
     */
    private static function model(array $responses = [], array $stream = [], array $fields = []): ChatTogetherAI
    {
        return new ChatTogetherAI($fields + [
            'apiKey' => 'test-api-key', 'maxRetries' => 0,
            'httpClient' => new FakeHttpClient($responses, $stream),
        ]);
    }

    public function testDefaultsTheModelAndUsesApiKey(): void
    {
        $model = new ChatTogetherAI(['apiKey' => 'test-api-key']);

        self::assertSame('mistralai/Mixtral-8x7B-Instruct-v0.1', $model->model);
        self::assertSame('together', $model->getLsParams()['ls_provider']);
        self::assertSame('togetherAI', $model->llmType());
    }

    public function testAcceptsTheTogetherAIApiKeyAlias(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)], fields: ['apiKey' => null, 'togetherAIApiKey' => 'alias-key']);

        $model->invoke('hi');

        self::assertSame(
            ['togetherAIApiKey' => 'TOGETHER_AI_API_KEY', 'apiKey' => 'TOGETHER_AI_API_KEY'],
            ChatTogetherAI::lcSecrets(),
        );
        self::assertSame(
            ['togetherAIApiKey' => 'together_ai_api_key', 'apiKey' => 'together_ai_api_key'],
            ChatTogetherAI::lcAliases(),
        );
        self::assertSame('Bearer alias-key', $model->httpClient->requests[0]['headers']['Authorization']);
    }

    public function testReadsTheKeyFromTheEnvironment(): void
    {
        putenv('TOGETHER_AI_API_KEY=env-key');

        $model = new ChatTogetherAI(['httpClient' => new FakeHttpClient([FakeHttpClient::json(200, self::COMPLETION)])]);
        $model->invoke('hi');

        self::assertSame('Bearer env-key', $model->httpClient->requests[0]['headers']['Authorization']);
    }

    public function testThrowsWhenNoApiKeyIsConfigured(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Together AI API key not found/');

        new ChatTogetherAI();
    }

    public function testStripsUnsupportedRequestArgumentsBeforeSending(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)], fields: [
            'model' => 'meta-llama/Meta-Llama-3.1-8B-Instruct-Turbo',
            'frequencyPenalty' => 1.0,
            'presencePenalty' => 1.0,
            'topP' => 0.9,
        ]);

        $model->invoke('hi');
        $body = $model->httpClient->lastRequestBody();

        self::assertArrayNotHasKey('frequency_penalty', $body);
        self::assertArrayNotHasKey('presence_penalty', $body);
        self::assertArrayNotHasKey('logit_bias', $body);
        self::assertArrayNotHasKey('functions', $body);
        self::assertSame(0.9, $body['top_p']);
        self::assertSame('https://api.together.xyz/v1/chat/completions', $model->httpClient->requests[0]['url']);
    }

    public function testSerializationOmitsInheritedOpenAiConfigurationFields(): void
    {
        $model = new ChatTogetherAI(['apiKey' => 'test-api-key']);

        $json = (string) json_encode($model->toJson());

        self::assertStringContainsString('"id":["langchain","chat_models","together_ai","ChatTogetherAI"]', $json);
        self::assertStringNotContainsString('openai_api_key', $json);
        self::assertStringNotContainsString('configuration', $json);
        self::assertStringNotContainsString('test-api-key', $json);
    }

    public function testResponseFormatReachesTheWire(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)]);
        $format = ['type' => 'json_object', 'schema' => ['type' => 'object']];

        $model->invoke('hi', new RunnableConfig(options: ['response_format' => $format]));

        self::assertSame($format, $model->httpClient->lastRequestBody()['response_format']);
    }

    // ---- chat_models_stream_events.test.ts: text and tool calls -----------------------------------

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

    public function testStreamsReasoning(): void
    {
        $model = self::model(stream: [
            self::sse(['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'reasoning_content' => 'Let me ']]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => ['reasoning_content' => 'reason...']]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => ['content' => 'Done']]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]),
            "data: [DONE]\n\n",
        ]);

        $folded = null;
        foreach ($model->stream('Hello') as [, $chunk]) {
            $folded = $folded === null ? $chunk : $folded->concat($chunk);
        }

        self::assertSame('Let me reason...', $folded->additional_kwargs['reasoning_content']);
        self::assertSame('Done', $folded->content);
    }

    public function testStreamsToolCalls(): void
    {
        $model = self::model(stream: [
            self::sse(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'web_search', 'arguments' => '']]]]]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"query":"weather"}']]]]]]]),
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
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)]);
        $chain = ChatPromptTemplate::fromMessages([['system', 'Answer as {persona}.'], ['human', '{question}']])
            ->pipe($model)
            ->pipe(new StringOutputParser());

        $out = $chain->invoke(['persona' => 'a pirate', 'question' => 'ahoy?']);

        self::assertSame('Hi from Together.', $out);
        self::assertSame(
            [['role' => 'system', 'content' => 'Answer as a pirate.'], ['role' => 'user', 'content' => 'ahoy?']],
            $model->httpClient->lastRequestBody()['messages'],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function sse(array $payload): string
    {
        return 'data: ' . json_encode($payload + ['id' => 'chatcmpl-test', 'model' => 'meta-llama/Llama-3-8b-chat-hf']) . "\n\n";
    }
}
