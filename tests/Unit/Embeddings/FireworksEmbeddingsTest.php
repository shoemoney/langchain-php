<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Embeddings;

use LangChain\Embeddings\FireworksEmbeddings;
use LangChain\LanguageModels\Chat\Fireworks\FireworksResponseError;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `embeddings.test.ts` from `@langchain/fireworks`.
 */
#[CoversClass(FireworksEmbeddings::class)]
final class FireworksEmbeddingsTest extends TestCase
{
    /** @var list<int|float> */
    private array $sleeps = [];

    /**
     * @param list<\LangChain\Utils\Http\HttpResponse> $responses
     * @param array<string, mixed>                      $fields
     */
    private function embeddings(array $responses, array $fields = []): FireworksEmbeddings
    {
        return new FireworksEmbeddings($fields + [
            'apiKey' => 'test-api-key',
            'httpClient' => new FakeHttpClient($responses),
            'sleeper' => function (int|float $ms): void {
                $this->sleeps[] = $ms;
            },
        ]);
    }

    public function testUsesTheProvidedBasePathAndCustomHeaders(): void
    {
        $embeddings = $this->embeddings(
            [FakeHttpClient::json(200, ['data' => [['embedding' => [0.1, 0.2, 0.3]]]])],
            ['basePath' => 'https://example.test/v1', 'headers' => ['X-Test' => 'yes']],
        );

        $result = $embeddings->embedQuery('hello world');

        self::assertSame([0.1, 0.2, 0.3], $result);
        $request = $embeddings->httpClient->requests[0];
        self::assertSame('https://example.test/v1/embeddings', $request['url']);
        self::assertSame(
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer test-api-key', 'X-Test' => 'yes'],
            $request['headers'],
        );
        self::assertSame('{"model":"nomic-ai/nomic-embed-text-v1.5","input":"hello world"}', $request['body']);
    }

    public function testBatchesEmbedDocumentsRequests(): void
    {
        $embeddings = $this->embeddings([
            FakeHttpClient::json(200, ['data' => [['embedding' => [1]], ['embedding' => [2]]]]),
            FakeHttpClient::json(200, ['data' => [['embedding' => [3]]]]),
        ], ['batchSize' => 2]);

        $result = $embeddings->embedDocuments(['a', 'b', 'c']);

        self::assertSame([[1], [2], [3]], $result);
        self::assertCount(2, $embeddings->httpClient->requests);
        self::assertSame(['a', 'b'], json_decode($embeddings->httpClient->requests[0]['body'], true)['input']);
        self::assertSame(['c'], json_decode($embeddings->httpClient->requests[1]['body'], true)['input']);
    }

    public function testSurfacesApiErrors(): void
    {
        $embeddings = $this->embeddings([FakeHttpClient::json(400, ['error' => 'bad request'])], ['maxRetries' => 0]);

        $this->expectException(FireworksResponseError::class);
        $this->expectExceptionMessage('Error 400: bad request');

        $embeddings->embedQuery('hello world');
    }

    public function testThrowsWhenNoApiKeyIsConfigured(): void
    {
        $saved = getenv('FIREWORKS_API_KEY');
        putenv('FIREWORKS_API_KEY');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Fireworks API key not found');
            new FireworksEmbeddings();
        } finally {
            if ($saved !== false) {
                putenv('FIREWORKS_API_KEY=' . $saved);
            }
        }
    }

    // ---- retryability ---------------------------------------------------------------------------

    public function testDoesNotRetryADeterministic413(): void
    {
        $embeddings = $this->embeddings(
            [FakeHttpClient::json(413, ['error' => 'payload too large'])],
            ['maxRetries' => 5],
        );

        try {
            $embeddings->embedQuery('hello world');
            self::fail('Expected a FireworksResponseError.');
        } catch (FireworksResponseError $e) {
            self::assertSame('Error 413: payload too large', $e->getMessage());
        }

        self::assertCount(1, $embeddings->httpClient->requests);
    }

    public function testDoesNotRetryABadApiKey(): void
    {
        $embeddings = $this->embeddings(
            [FakeHttpClient::json(401, ['error' => 'unauthorized'])],
            ['apiKey' => 'bad-key', 'maxRetries' => 5],
        );

        try {
            $embeddings->embedQuery('hello world');
            self::fail('Expected a FireworksResponseError.');
        } catch (FireworksResponseError $e) {
            self::assertSame('Error 401: unauthorized', $e->getMessage());
        }

        self::assertCount(1, $embeddings->httpClient->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testStillRetriesATransient503(): void
    {
        $embeddings = $this->embeddings([
            FakeHttpClient::json(503, ['error' => 'unavailable']),
            FakeHttpClient::json(200, ['data' => [['embedding' => [0.5]]]]),
        ], ['maxRetries' => 3]);

        self::assertSame([0.5], $embeddings->embedQuery('hello world'));
        self::assertCount(2, $embeddings->httpClient->requests);
        self::assertCount(1, $this->sleeps);
    }

    public function testATransportFailureIsRetriedThenSurfaced(): void
    {
        $http = new class () implements \LangChain\Utils\Http\HttpClient {
            public int $calls = 0;

            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \LangChain\Utils\Http\HttpResponse
            {
                ++$this->calls;
                throw new HttpException('connection refused', 0);
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                yield '';
            }
        };
        $embeddings = new FireworksEmbeddings([
            'apiKey' => 'k', 'httpClient' => $http, 'maxRetries' => 2, 'sleeper' => static function (): void {
            },
        ]);

        try {
            $embeddings->embedQuery('x');
            self::fail('Expected an HttpException.');
        } catch (HttpException $e) {
            self::assertSame('connection refused', $e->getMessage());
        }

        self::assertSame(3, $http->calls);
    }
}
