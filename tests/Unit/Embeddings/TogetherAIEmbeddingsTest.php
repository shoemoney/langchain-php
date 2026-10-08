<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Embeddings;

use LangChain\Embeddings\TogetherAIEmbeddings;
use LangChain\LanguageModels\Chat\TogetherAI\TogetherAIException;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `embeddings.test.ts` from `@langchain/together-ai`.
 */
#[CoversClass(TogetherAIEmbeddings::class)]
#[CoversClass(TogetherAIException::class)]
final class TogetherAIEmbeddingsTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private static function payload(array $embedding, string $requestId = 'req_123'): array
    {
        return [
            'object' => 'list',
            'data' => [['object' => 'embedding', 'embedding' => $embedding, 'index' => 0]],
            'model' => 'test-model',
            'request_id' => $requestId,
        ];
    }

    public function testUsesTheEnvironmentVariableWhenApiKeyIsOmitted(): void
    {
        putenv('TOGETHER_AI_API_KEY=env-api-key');

        $embeddings = new TogetherAIEmbeddings();

        self::assertSame('env-api-key', $embeddings->apiKey);
        self::assertSame('togethercomputer/m2-bert-80M-8k-retrieval', $embeddings->model);
    }

    public function testThrowsWhenTheApiKeyIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TOGETHER_AI_API_KEY not found.');

        new TogetherAIEmbeddings();
    }

    public function testEmbedQueryStripsNewLinesAndSendsTheAuthHeader(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::payload([0.1, 0.2]))]);
        $embeddings = new TogetherAIEmbeddings([
            'apiKey' => 'test-api-key', 'model' => 'test-model', 'stripNewLines' => true, 'httpClient' => $http,
        ]);

        $result = $embeddings->embedQuery("hello\nworld");

        self::assertSame([0.1, 0.2], $result);
        self::assertSame('https://api.together.xyz/v1/embeddings', $http->requests[0]['url']);
        self::assertSame('Bearer test-api-key', $http->requests[0]['headers']['Authorization']);
        self::assertSame(['model' => 'test-model', 'input' => 'hello world'], $http->lastRequestBody());
    }

    public function testEmbedDocumentsPreservesOrderAcrossBatches(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(200, self::payload([1], 'req_1')),
            FakeHttpClient::json(200, self::payload([2], 'req_2')),
            FakeHttpClient::json(200, self::payload([3], 'req_3')),
        ]);
        $embeddings = new TogetherAIEmbeddings([
            'apiKey' => 'test-api-key', 'model' => 'test-model', 'batchSize' => 2, 'httpClient' => $http,
        ]);

        $result = $embeddings->embedDocuments(['a', 'b', 'c']);

        self::assertCount(3, $http->requests);
        self::assertSame([[1], [2], [3]], $result);
        self::assertSame(['a', 'b', 'c'], array_map(
            static fn (array $request): string => json_decode($request['body'], true)['input'],
            $http->requests,
        ));
    }

    public function testSurfacesApiErrors(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(400, ['error' => 'bad request'])]);
        $embeddings = new TogetherAIEmbeddings(['apiKey' => 'test-api-key', 'maxRetries' => 0, 'httpClient' => $http]);

        try {
            $embeddings->embedQuery('hello');
            self::fail('Expected a TogetherAIException.');
        } catch (TogetherAIException $e) {
            self::assertMatchesRegularExpression('/Error getting prompt completion from Together AI\./', $e->getMessage());
            self::assertStringContainsString("{\n  \"error\": \"bad request\"\n}", $e->getMessage());
            self::assertSame(400, $e->status);
        }
    }

    public function testADeterministicClientErrorIsNotRetried(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(401, ['error' => 'unauthorized'])]);
        $embeddings = new TogetherAIEmbeddings(['apiKey' => 'bad', 'maxRetries' => 5, 'httpClient' => $http]);

        $this->expectException(TogetherAIException::class);
        try {
            $embeddings->embedQuery('hello');
        } finally {
            self::assertCount(1, $http->requests);
        }
    }
}
