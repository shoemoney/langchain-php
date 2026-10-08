<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\LLMs;

use LangChain\LanguageModels\Chat\TogetherAI\TogetherAIException;
use LangChain\LanguageModels\LLMs\TogetherAI;
use LangChain\Prompts\PromptTemplate;
use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `llms.test.ts` from `@langchain/together-ai`.
 */
#[CoversClass(TogetherAI::class)]
final class TogetherAITest extends TestCase
{
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

    private const FINISHED = [
        'object' => 'text_completion', 'status' => 'finished', 'prompt' => ['Hello'], 'model' => 'override-model',
        'output' => ['choices' => [['finish_reason' => 'stop', 'index' => 0, 'text' => 'Hi!']], 'result_type' => 'text'],
    ];

    /**
     * @param list<\LangChain\Utils\Http\HttpResponse> $responses
     * @param list<string>                              $stream
     * @param array<string, mixed>                      $fields
     */
    private static function llm(array $responses = [], array $stream = [], array $fields = []): TogetherAI
    {
        return new TogetherAI($fields + [
            'model' => 'togethercomputer/StripedHyena-Nous-7B', 'apiKey' => 'test-api-key', 'maxRetries' => 0,
            'httpClient' => new FakeHttpClient($responses, $stream),
        ]);
    }

    public function testThrowsWhenTheApiKeyIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TOGETHER_AI_API_KEY not found.');

        new TogetherAI(['model' => 'togethercomputer/StripedHyena-Nous-7B']);
    }

    public function testRequiresAModelName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model name is required for TogetherAI.');

        new TogetherAI(['apiKey' => 'test-api-key']);
    }

    public function testWarnsWhenAChatModelIsUsedWithTheLegacyLlm(): void
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, \E_USER_WARNING);

        try {
            new TogetherAI(['modelName' => 'meta-llama/Llama-3.2-11B-Vision-Instruct-Turbo', 'apiKey' => 'test-api-key']);
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertStringContainsString('Consider using ChatTogetherAI', $warnings[0]);
    }

    public function testDoesNotWarnForABaseModel(): void
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, \E_USER_WARNING);

        try {
            self::llm();
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
    }

    public function testProvidesAHelpfulErrorForChatModelResponses(): void
    {
        $model = self::llm([FakeHttpClient::json(200, ['error' => 'Invalid model'])], fields: [
            'modelName' => 'base-model', 'model' => null,
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/may require the ChatTogetherAI class/');

        $model->invoke('Hello');
    }

    public function testCallOptionsOverrideConstructorDefaultsInTheRequestPayload(): void
    {
        $model = self::llm([FakeHttpClient::json(200, self::FINISHED)], fields: [
            'model' => 'base-model', 'temperature' => 0.7, 'topP' => 0.2, 'topK' => 50, 'repetitionPenalty' => 1, 'maxTokens' => 64,
        ]);

        $response = $model->invoke('Hello', new RunnableConfig(options: [
            'model' => 'override-model', 'temperature' => 0.1, 'topP' => 0.9, 'topK' => 12, 'repetitionPenalty' => 1.5,
            'logprobs' => 3, 'safetyModel' => 'meta-llama/Llama-Guard-7b', 'maxTokens' => 9, 'stop' => ['END'],
        ]));

        self::assertSame('Hi!', $response);
        self::assertSame('https://api.together.xyz/inference', $model->httpClient->requests[0]['url']);
        self::assertSame('Bearer test-api-key', $model->httpClient->requests[0]['headers']['Authorization']);
        self::assertEqualsWithDelta([
            'model' => 'override-model', 'prompt' => 'Hello', 'temperature' => 0.1, 'top_p' => 0.9, 'top_k' => 12,
            'repetition_penalty' => 1.5, 'logprobs' => 3, 'safety_model' => 'meta-llama/Llama-Guard-7b',
            'max_tokens' => 9, 'stop' => ['END'], 'stream_tokens' => false,
        ], $model->httpClient->lastRequestBody(), 0.0001);
    }

    public function testReadsTheTopLevelChoicesShapeToo(): void
    {
        $model = self::llm([FakeHttpClient::json(200, ['choices' => [['text' => 'plain']]])]);

        self::assertSame('plain', $model->invoke('Hello'));
    }

    public function testSurfacesApiErrors(): void
    {
        $model = self::llm([FakeHttpClient::json(400, ['error' => 'bad request'])]);

        $this->expectException(TogetherAIException::class);
        $this->expectExceptionMessageMatches('/Error getting prompt completion from Together AI/');

        $model->invoke('Hello');
    }

    public function testStreamsCompletionText(): void
    {
        $model = self::llm(stream: [
            "data: {\"choices\":[{\"text\":\"Hel\"}]}\n\n",
            "data: {\"choices\":[{\"text\":\"lo\"}]}\n\n",
            "data: [DONE]\n\n",
        ]);

        $text = '';
        foreach ($model->stream('Say hello') as [, $chunk]) {
            $text .= $chunk;
        }

        self::assertSame('Hello', $text);
        self::assertTrue($model->httpClient->lastRequestBody()['stream_tokens']);
    }

    public function testAPromptModelChainRunsEndToEnd(): void
    {
        $model = self::llm([FakeHttpClient::json(200, self::FINISHED)]);
        $chain = PromptTemplate::fromTemplate('Say {word}')->pipe($model);

        self::assertSame('Hi!', $chain->invoke(['word' => 'hi']));
        self::assertSame('Say hi', $model->httpClient->lastRequestBody()['prompt']);
    }
}
