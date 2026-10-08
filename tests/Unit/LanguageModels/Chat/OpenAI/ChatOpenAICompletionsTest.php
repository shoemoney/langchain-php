<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The Chat Completions client on its own: what `ChatOpenAITest` asserts through
 * the facade, asserted directly against the class the facade delegates to.
 */
#[CoversClass(ChatOpenAICompletions::class)]
final class ChatOpenAICompletionsTest extends TestCase
{
    private const COMPLETION = [
        'id' => 'chatcmpl-1', 'object' => 'chat.completion', 'model' => 'gpt-4o-2024-08-06',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello there.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 3, 'total_tokens' => 12],
    ];

    /**
     * @param list<\LangChain\Utils\Http\HttpResponse> $responses
     * @param list<string>                              $stream
     * @param array<string, mixed>                      $fields
     */
    private static function model(array $responses = [], array $stream = [], array $fields = []): ChatOpenAICompletions
    {
        return new ChatOpenAICompletions($fields + [
            'model' => 'gpt-4o', 'apiKey' => 'sk-test', 'maxRetries' => 0,
            'httpClient' => new FakeHttpClient($responses, $stream),
        ]);
    }

    public function testInvokeSendsTheChatCompletionsWireShape(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)]);

        $message = $model->invoke([new SystemMessage('be terse'), new HumanMessage('hi')]);

        self::assertInstanceOf(AIMessage::class, $message);
        self::assertSame('Hello there.', $message->content);
        self::assertSame('https://api.openai.com/v1/chat/completions', $model->httpClient->requests[0]['url']);
        self::assertSame([
            ['role' => 'system', 'content' => 'be terse'],
            ['role' => 'user', 'content' => 'hi'],
        ], $model->httpClient->lastRequestBody()['messages']);
    }

    public function testTheDefaultUrlIsTheCompletionsEndpoint(): void
    {
        self::assertSame('https://api.openai.com/v1/chat/completions', ChatOpenAICompletions::DEFAULT_API_URL);
        self::assertSame(ChatOpenAICompletions::DEFAULT_API_URL, ChatOpenAI::DEFAULT_API_URL);
    }

    public function testAResponsesUrlIsRetargetedAtTheCompletionsEndpoint(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)], fields: ['baseUrl' => 'https://proxy.internal/v1/responses']);

        $model->invoke('hi');

        self::assertSame('https://proxy.internal/v1/chat/completions', $model->httpClient->requests[0]['url']);
    }

    public function testACustomCompletionsUrlIsUsedAsGiven(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)], fields: ['baseUrl' => 'https://proxy.internal/v1/chat/completions']);

        $model->invoke('hi');

        self::assertSame('https://proxy.internal/v1/chat/completions', $model->httpClient->requests[0]['url']);
    }

    public function testInvocationParamsLayerOptionsOverBoundOverConstructor(): void
    {
        $model = self::model(fields: ['temperature' => 0.7, 'maxTokens' => 10])->bindTools([], ['temperature' => 0.3, 'maxTokens' => 20]);

        self::assertSame(0.3, $model->invocationParams()['temperature']);
        self::assertSame(20, $model->invocationParams()['max_tokens']);
        self::assertSame(0.1, $model->invocationParams(['temperature' => 0.1])['temperature']);
    }

    public function testNullFieldsAreOmittedRatherThanSentAsNull(): void
    {
        $params = self::model()->invocationParams();

        self::assertSame(['model' => 'gpt-4o'], $params);
    }

    public function testStreamingAddsUsageOptionsUnlessDisabled(): void
    {
        self::assertSame(['include_usage' => true], self::model()->invocationParams([], ['streaming' => true])['stream_options']);
        self::assertArrayNotHasKey('stream_options', self::model(fields: ['streamUsage' => false])->invocationParams([], ['streaming' => true]));
    }

    public function testStreamFoldsDeltasAndUsage(): void
    {
        $model = self::model(stream: [
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hel\"}}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"lo\"}}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"usage\":{\"prompt_tokens\":5,\"completion_tokens\":2,\"total_tokens\":7}}\n\n",
            "data: [DONE]\n\n",
        ]);

        $text = '';
        foreach ($model->stream('hi') as [, $chunk]) {
            $text .= is_string($chunk->content) ? $chunk->content : '';
        }

        self::assertSame('Hello', $text);
        self::assertTrue($model->httpClient->lastRequestBody()['stream']);
    }

    public function testResponsesOnlyOptionsAreNotTranslatedByThisProtocol(): void
    {
        // Routing is the facade's job: used directly, this class sends Chat Completions
        // parameters only, whatever Responses-shaped options a caller hands it.
        $params = self::model()->invocationParams(['truncation' => 'auto', 'previous_response_id' => 'resp_0']);

        self::assertArrayNotHasKey('truncation', $params);
        self::assertArrayNotHasKey('previous_response_id', $params);
        self::assertArrayNotHasKey('input', $params);
    }

    public function testToolsAreOfferedInTheCompletionsShape(): void
    {
        $params = self::model()->bindTools([['type' => 'function', 'function' => ['name' => 'f', 'parameters' => ['type' => 'object']]]])->invocationParams();

        self::assertSame('f', $params['tools'][0]['function']['name']);
    }
}
