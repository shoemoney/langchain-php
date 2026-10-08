<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\DeepSeek;

use LangChain\LanguageModels\Chat\DeepSeek\ChatDeepSeek;
use LangChain\LanguageModels\Chat\DeepSeek\Profiles;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The OpenAI-compatible surface of `ChatDeepSeek`, asserted on the wire.
 */
#[CoversClass(ChatDeepSeek::class)]
#[CoversClass(Profiles::class)]
final class ChatDeepSeekTest extends TestCase
{
    private const REASONED = [
        'id' => 'chatcmpl-9', 'object' => 'chat.completion', 'model' => 'deepseek-reasoner',
        'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => [
            'role' => 'assistant', 'content' => 'The answer is 42', 'reasoning_content' => 'Let me think about this...',
        ]]],
        'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 9, 'total_tokens' => 12],
    ];

    private string|false $savedKey = false;

    protected function setUp(): void
    {
        $this->savedKey = getenv('DEEPSEEK_API_KEY');
        putenv('DEEPSEEK_API_KEY');
    }

    protected function tearDown(): void
    {
        putenv($this->savedKey === false ? 'DEEPSEEK_API_KEY' : 'DEEPSEEK_API_KEY=' . $this->savedKey);
    }

    /**
     * @param list<\LangChain\Utils\Http\HttpResponse> $responses
     * @param array<string, mixed>                      $fields
     */
    private static function model(array $responses = [], array $fields = []): ChatDeepSeek
    {
        return new ChatDeepSeek($fields + [
            'apiKey' => 'test', 'maxRetries' => 0,
            'httpClient' => new FakeHttpClient($responses),
        ]);
    }

    public function testThrowsWhenNoApiKeyIsConfigured(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Deepseek API key not found');

        new ChatDeepSeek();
    }

    public function testReadsTheKeyFromTheEnvironmentAndTargetsTheDeepSeekEndpoint(): void
    {
        putenv('DEEPSEEK_API_KEY=env-key');
        $model = new ChatDeepSeek(['httpClient' => new FakeHttpClient([FakeHttpClient::json(200, self::REASONED)])]);

        $model->invoke('hi');

        self::assertSame('https://api.deepseek.com/chat/completions', $model->httpClient->requests[0]['url']);
        self::assertSame('Bearer env-key', $model->httpClient->requests[0]['headers']['Authorization']);
        self::assertSame('deepseek-chat', $model->httpClient->lastRequestBody()['model']);
        self::assertSame('deepseek', $model->llmType());
    }

    public function testAnExplicitBaseUrlIsUsedAsTheApiRoot(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::REASONED)], ['configuration' => ['baseURL' => 'https://proxy.test/']]);

        $model->invoke('hi');

        self::assertSame('https://proxy.test/chat/completions', $model->httpClient->requests[0]['url']);
    }

    public function testSerializesWithTheDeepSeekIdAndNoCredential(): void
    {
        $model = self::model(fields: ['apiKey' => 'sk-secret']);

        $json = (string) json_encode($model);

        self::assertStringContainsString('"id":["langchain","chat_models","deepseek","ChatDeepSeek"]', $json);
        self::assertStringNotContainsString('sk-secret', $json);
        self::assertSame(['apiKey' => 'DEEPSEEK_API_KEY'], ChatDeepSeek::lcSecrets());
    }

    // ---- reasoning on a finished message ---------------------------------------------------------------

    public function testAFinishedMessageKeepsReasoningContentAndIsStampedWithTheProvider(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::REASONED)], ['model' => 'deepseek-reasoner']);

        $message = $model->invoke('What is the answer?');

        self::assertInstanceOf(AIMessage::class, $message);
        self::assertSame('The answer is 42', $message->content);
        self::assertSame('Let me think about this...', $message->additional_kwargs['reasoning_content']);
        self::assertSame('deepseek', $message->response_metadata['model_provider']);
        self::assertSame(
            [
                ['type' => 'reasoning', 'reasoning' => 'Let me think about this...'],
                ['type' => 'text', 'text' => 'The answer is 42'],
            ],
            ChatDeepSeek::contentBlocks($message),
        );
    }

    public function testAMessageWithoutReasoningHasNoReasoningKey(): void
    {
        $payload = self::REASONED;
        unset($payload['choices'][0]['message']['reasoning_content']);
        $model = self::model([FakeHttpClient::json(200, $payload)]);

        $message = $model->invoke('hi');

        self::assertArrayNotHasKey('reasoning_content', $message->additional_kwargs);
        self::assertSame([['type' => 'text', 'text' => 'The answer is 42']], ChatDeepSeek::contentBlocks($message));
    }

    public function testReasoningIsNotSentBackInRequestHistory(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::REASONED)]);
        $earlier = new AIMessage([
            'content' => 'earlier answer',
            'additional_kwargs' => ['reasoning_content' => 'earlier thinking'],
        ]);

        $model->invoke([new HumanMessage('first'), $earlier, new HumanMessage('second')]);

        self::assertStringNotContainsString('earlier thinking', $model->httpClient->requests[0]['body']);
    }

    // ---- profiles ------------------------------------------------------------------------------------------

    public function testProfileDescribesTheConfiguredModel(): void
    {
        self::assertTrue(self::model(fields: ['model' => 'deepseek-reasoner'])->profile()['reasoningOutput']);
        self::assertFalse(self::model(fields: ['model' => 'deepseek-chat'])->profile()['reasoningOutput']);
        self::assertSame(1000000, self::model()->profile()['maxInputTokens']);
        self::assertSame([], self::model(fields: ['model' => 'not-a-model'])->profile());
    }

    public function testProfilesCarryTheFourUpstreamModels(): void
    {
        self::assertSame(
            ['deepseek-chat', 'deepseek-v4-pro', 'deepseek-reasoner', 'deepseek-v4-flash'],
            array_keys(Profiles::all()),
        );
        self::assertTrue(Profiles::for('deepseek-v4-pro')['structuredOutput']);
        self::assertFalse(Profiles::for('deepseek-reasoner')['structuredOutput']);
        self::assertSame(384000, Profiles::for('deepseek-v4-flash')['maxOutputTokens']);
    }

    // ---- structured output -----------------------------------------------------------------------------------

    public function testStructuredOutputDefaultsToFunctionCalling(): void
    {
        $payload = ['id' => 'c1', 'model' => 'deepseek-chat', 'choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => [
            'role' => 'assistant', 'content' => null,
            'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'extract', 'arguments' => '{"name":"Ada"}']]],
        ]]]];
        $model = self::model([FakeHttpClient::json(200, $payload)]);

        $result = $model->withStructuredOutput([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ])->invoke('who?');

        self::assertSame(['name' => 'Ada'], $result);
        self::assertSame('extract', $model->httpClient->lastRequestBody()['tools'][0]['function']['name']);
    }

    // ---- end to end --------------------------------------------------------------------------------------------

    public function testAPromptModelParserChainRunsEndToEnd(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::REASONED)]);
        $chain = ChatPromptTemplate::fromMessages([['system', 'Answer as {persona}.'], ['human', '{question}']])
            ->pipe($model)
            ->pipe(new StringOutputParser());

        self::assertSame('The answer is 42', $chain->invoke(['persona' => 'a sage', 'question' => 'meaning of life?']));
        self::assertSame(
            [['role' => 'system', 'content' => 'Answer as a sage.'], ['role' => 'user', 'content' => 'meaning of life?']],
            $model->httpClient->lastRequestBody()['messages'],
        );
    }

    public function testAGraphNodeCallsTheModelAndReasoningLandsInState(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::REASONED)]);

        $graph = (new \LangGraph\State\StateGraph([
            'question' => new \LangGraph\Channels\AnyValue(),
            'answer' => new \LangGraph\Channels\AnyValue(),
            'reasoning' => new \LangGraph\Channels\AnyValue(),
        ]))
            ->addNode('ask', static function (array $s) use ($model): array {
                $reply = $model->invoke((string) $s['question'], new RunnableConfig());

                return ['answer' => $reply->content, 'reasoning' => $reply->additional_kwargs['reasoning_content']];
            })
            ->addEdge('__start__', 'ask')
            ->addEdge('ask', '__end__')
            ->compile();

        $final = $graph->invoke(['question' => 'meaning of life?']);

        self::assertSame('The answer is 42', $final['answer']);
        self::assertSame('Let me think about this...', $final['reasoning']);
    }
}
