<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Embeddings;

use LangChain\Embeddings\OllamaEmbeddings;
use LangChain\LanguageModels\Chat\Ollama\OllamaCamelCaseOptions;
use LangChain\LanguageModels\Chat\Ollama\OllamaException;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OllamaEmbeddings::class)]
#[CoversClass(OllamaCamelCaseOptions::class)]
final class OllamaEmbeddingsTest extends TestCase
{
    // ---- embeddings.test.ts --------------------------------------------------

    public function testAllowsPassthroughOfRequestOptions(): void
    {
        $embeddings = new OllamaEmbeddings([
            'requestOptions' => ['num_ctx' => 1234, 'numPredict' => 4321],
        ]);

        self::assertSame(['num_ctx' => 1234, 'num_predict' => 4321], $embeddings->requestOptions);
    }

    public function testDimensionsParameter(): void
    {
        self::assertSame(512, (new OllamaEmbeddings(['dimensions' => 512]))->dimensions);
    }

    // ---- the wire --------------------------------------------------------------

    public function testEmbedDocumentsPostsTheBatchToApiEmbed(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['embeddings' => [[0.1, 0.2], [0.3, 0.4]]])]);
        $embeddings = new OllamaEmbeddings([
            'baseUrl' => 'http://ollama.test:11434/',
            'model' => 'nomic-embed-text',
            'httpClient' => $http,
        ]);

        $vectors = $embeddings->embedDocuments(['alpha', 'beta']);

        self::assertSame([[0.1, 0.2], [0.3, 0.4]], $vectors);
        self::assertSame('http://ollama.test:11434/api/embed', $http->requests[0]['url']);
        self::assertSame(
            ['model' => 'nomic-embed-text', 'input' => ['alpha', 'beta'], 'truncate' => false],
            $http->lastRequestBody(),
        );
    }

    public function testEmbedQueryReturnsTheFirstVector(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['embeddings' => [[1.5, 2.5, 3.5]]])]);

        $vector = (new OllamaEmbeddings(['httpClient' => $http]))->embedQuery('hello');

        self::assertSame([1.5, 2.5, 3.5], $vector);
        self::assertSame(['hello'], $http->lastRequestBody()['input']);
        self::assertSame('mxbai-embed-large', $http->lastRequestBody()['model']);
    }

    public function testDimensionsKeepAliveTruncateAndOptionsReachTheWire(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['embeddings' => [[0.5]]])]);
        $embeddings = new OllamaEmbeddings([
            'dimensions' => 256,
            'keepAlive' => '10m',
            'truncate' => true,
            'requestOptions' => ['numCtx' => 2048],
            'headers' => ['Authorization' => 'Bearer t'],
            'httpClient' => $http,
        ]);

        $embeddings->embedQuery('x');

        $body = $http->lastRequestBody();
        self::assertSame(256, $body['dimensions']);
        self::assertSame('10m', $body['keep_alive']);
        self::assertTrue($body['truncate']);
        self::assertSame(['num_ctx' => 2048], $body['options']);
        self::assertSame('Bearer t', $http->requests[0]['headers']['Authorization']);
        self::assertSame('application/json', $http->requests[0]['headers']['Content-Type']);
    }

    public function testUnknownRequestOptionsPassThroughUnchanged(): void
    {
        $embeddings = new OllamaEmbeddings(['requestOptions' => ['some_new_option' => 1, 'topK' => 5]]);

        self::assertSame(['some_new_option' => 1, 'top_k' => 5], $embeddings->requestOptions);
    }

    public function testBaseUrlFallsBackToTheEnvironment(): void
    {
        $previous = getenv('OLLAMA_BASE_URL');
        putenv('OLLAMA_BASE_URL=http://from-env:9999');
        try {
            self::assertSame('http://from-env:9999', (new OllamaEmbeddings())->baseUrl);
            self::assertSame('http://explicit:1', (new OllamaEmbeddings(['baseUrl' => 'http://explicit:1']))->baseUrl);
        } finally {
            $previous === false ? putenv('OLLAMA_BASE_URL') : putenv('OLLAMA_BASE_URL=' . $previous);
        }
    }

    // ---- failures ----------------------------------------------------------------

    public function testAnErrorStatusRaisesWithOllamasMessage(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(404, ['error' => "model 'nope' not found"])]);

        try {
            (new OllamaEmbeddings(['model' => 'nope', 'httpClient' => $http]))->embedQuery('x');
            self::fail('expected OllamaException');
        } catch (OllamaException $e) {
            self::assertSame("model 'nope' not found", $e->getMessage());
            self::assertSame(404, $e->status);
        }
    }

    public function testAResponseWithoutEmbeddingsIsAnError(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['model' => 'm'])]);

        $this->expectException(OllamaException::class);
        $this->expectExceptionMessage('no "embeddings" array');

        (new OllamaEmbeddings(['httpClient' => $http]))->embedQuery('x');
    }

    public function testATransportFailureIsWrappedWithItsCause(): void
    {
        $http = new class () implements \LangChain\Utils\Http\HttpClient {
            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                throw new HttpException('connection refused', 0, '');
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                yield '';
            }
        };

        try {
            (new OllamaEmbeddings(['httpClient' => $http]))->embedQuery('x');
            self::fail('expected OllamaException');
        } catch (OllamaException $e) {
            self::assertInstanceOf(HttpException::class, $e->getPrevious());
            self::assertStringContainsString('http://localhost:11434/api/embed', $e->getMessage());
        }
    }
}
