<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\LLMs;

use LangChain\LanguageModels\LLMs\Fireworks;
use LangChain\Prompts\PromptTemplate;
use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `llms.test.ts` from `@langchain/fireworks`.
 */
#[CoversClass(Fireworks::class)]
final class FireworksTest extends TestCase
{
    private const COMPLETION = [
        'id' => 'cmpl-1', 'object' => 'text_completion', 'model' => 'accounts/fireworks/models/llama-v2-13b',
        'choices' => [['index' => 0, 'text' => ' 2', 'finish_reason' => 'stop']],
    ];

    /**
     * @param list<\LangChain\Utils\Http\HttpResponse> $responses
     * @param list<string>                              $stream
     * @param array<string, mixed>                      $fields
     */
    private static function llm(array $responses = [], array $stream = [], array $fields = []): Fireworks
    {
        return new Fireworks($fields + [
            'apiKey' => 'test-api-key', 'maxRetries' => 0,
            'httpClient' => new FakeHttpClient($responses, $stream),
        ]);
    }

    public function testSupportsStringModelShorthand(): void
    {
        $model = new Fireworks('accounts/fireworks/models/llama-v2-13b', ['apiKey' => 'test-api-key', 'temperature' => 0.1]);

        self::assertSame('accounts/fireworks/models/llama-v2-13b', $model->model);
        self::assertSame(0.1, $model->temperature);
    }

    public function testSerializesWithFireworksSecretAliases(): void
    {
        $model = new Fireworks(['apiKey' => 'test-api-key', 'model' => 'accounts/fireworks/models/llama-v2-13b']);

        $json = (string) json_encode($model);

        self::assertStringContainsString('"id":["langchain","llms","fireworks","Fireworks"]', $json);
        self::assertSame(
            ['fireworksApiKey' => 'FIREWORKS_API_KEY', 'apiKey' => 'FIREWORKS_API_KEY'],
            Fireworks::lcSecrets(),
        );
        self::assertStringNotContainsString('test-api-key', $json);
    }

    public function testCompletionWithRetryNormalizesSingleElementPromptArrays(): void
    {
        $model = self::llm([FakeHttpClient::json(200, self::COMPLETION)]);

        $model->completionWithRetry([
            'model' => 'accounts/fireworks/models/llama-v2-13b',
            'prompt' => ['hello'],
            'stream' => false,
            'frequency_penalty' => 1,
            'presence_penalty' => 1,
            'best_of' => 2,
            'logit_bias' => ['1' => 1],
        ]);

        $body = $model->httpClient->lastRequestBody();
        self::assertSame('hello', $body['prompt']);
        self::assertArrayNotHasKey('frequency_penalty', $body);
        self::assertArrayNotHasKey('presence_penalty', $body);
        self::assertArrayNotHasKey('best_of', $body);
        self::assertArrayNotHasKey('logit_bias', $body);
    }

    public function testRejectsMultiplePrompts(): void
    {
        $model = self::llm();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiple prompts are not supported by Fireworks');

        $model->completionWithRetry([
            'model' => 'accounts/fireworks/models/llama-v2-13b',
            'prompt' => ['hello', 'world'],
            'stream' => false,
        ]);
    }

    public function testRejectsANonStringPrompt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only string prompts are supported by Fireworks');

        self::llm()->completionWithRetry(['prompt' => [['not', 'a string']]]);
    }

    public function testInvokePostsToTheCompletionsEndpoint(): void
    {
        $model = self::llm([FakeHttpClient::json(200, self::COMPLETION)], fields: ['temperature' => 0.0, 'maxTokens' => 5]);

        $out = $model->invoke('1 + 1 =');

        self::assertSame(' 2', $out);
        self::assertSame('https://api.fireworks.ai/inference/v1/completions', $model->httpClient->requests[0]['url']);
        self::assertEquals(
            ['model' => Fireworks::DEFAULT_FIREWORKS_LLM_MODEL, 'temperature' => 0.0, 'max_tokens' => 5, 'prompt' => '1 + 1 ='],
            $model->httpClient->lastRequestBody(),
        );
    }

    public function testCallOptionsOverrideConstructorDefaults(): void
    {
        $model = self::llm([FakeHttpClient::json(200, self::COMPLETION)], fields: ['temperature' => 0.9]);

        $model->invoke('hi', new RunnableConfig(options: ['temperature' => 0.1, 'model' => 'other-model', 'stop' => ['END']]));

        $body = $model->httpClient->lastRequestBody();
        self::assertSame(0.1, $body['temperature']);
        self::assertSame('other-model', $body['model']);
        self::assertSame(['END'], $body['stop']);
    }

    public function testThrowsWhenNoApiKeyIsConfigured(): void
    {
        $saved = getenv('FIREWORKS_API_KEY');
        putenv('FIREWORKS_API_KEY');

        try {
            $this->expectException(\InvalidArgumentException::class);
            new Fireworks();
        } finally {
            if ($saved !== false) {
                putenv('FIREWORKS_API_KEY=' . $saved);
            }
        }
    }

    public function testStreamsCompletionText(): void
    {
        $model = self::llm(stream: [
            "data: {\"choices\":[{\"index\":0,\"text\":\"Hel\"}]}\n\n",
            "data: {\"choices\":[{\"index\":0,\"text\":\"lo\",\"finish_reason\":\"stop\"}]}\n\n",
            "data: [DONE]\n\n",
        ]);

        $text = '';
        foreach ($model->stream('Say hello') as [, $chunk]) {
            $text .= $chunk;
        }

        self::assertSame('Hello', $text);
        self::assertTrue($model->httpClient->lastRequestBody()['stream']);
    }

    public function testAPromptModelChainRunsEndToEnd(): void
    {
        $model = self::llm([FakeHttpClient::json(200, self::COMPLETION)]);
        $chain = PromptTemplate::fromTemplate('{a} + {b} =')->pipe($model);

        self::assertSame(' 2', $chain->invoke(['a' => 1, 'b' => 1]));
        self::assertSame('1 + 1 =', $model->httpClient->lastRequestBody()['prompt']);
    }
}
