<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Embeddings;

use LangChain\Embeddings\AzureOpenAIEmbeddings;
use LangChain\Embeddings\OpenAIEmbeddings;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `embeddings.int.test.ts` and `azure/tests/embeddings.int.test.ts`.
 *
 * The upstream tests call the live API; here the same calls run against a
 * transport that answers like the embeddings endpoint (one vector per input, of
 * the requested `dimensions`, else 1536), so what is asserted is what this
 * client sends and how it reads the answer.
 */
#[CoversClass(OpenAIEmbeddings::class)]
#[CoversClass(AzureOpenAIEmbeddings::class)]
final class OpenAIEmbeddingsTest extends TestCase
{
    private const ENV = [
        'OPENAI_API_KEY', 'OPENAI_ORGANIZATION', 'AZURE_OPENAI_API_KEY', 'AZURE_OPENAI_API_INSTANCE_NAME',
        'AZURE_OPENAI_API_DEPLOYMENT_NAME', 'AZURE_OPENAI_API_EMBEDDINGS_DEPLOYMENT_NAME', 'AZURE_OPENAI_API_VERSION',
        'AZURE_OPENAI_BASE_PATH',
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    /** @return HttpClient&object{requests: list<array<string, mixed>>} */
    private static function endpoint(): HttpClient
    {
        return new class implements HttpClient {
            /** @var list<array{url: string, headers: array<string, string>, body: string, query: array<string, mixed>}> */
            public array $requests = [];

            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'query' => $query];
                $request = json_decode($body, true);
                $inputs = (array) $request['input'];
                $size = $request['dimensions'] ?? 1536;
                $data = [];
                foreach (array_values($inputs) as $i => $_) {
                    $data[] = ['object' => 'embedding', 'index' => $i, 'embedding' => array_fill(0, $size, 0.5 + $i)];
                }

                return FakeHttpClient::json(200, ['object' => 'list', 'data' => $data, 'model' => $request['model']]);
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                yield from [];
            }
        };
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function openAI(array $fields = []): OpenAIEmbeddings
    {
        return new OpenAIEmbeddings($fields + ['apiKey' => 'sk-test', 'httpClient' => self::endpoint(), 'maxRetries' => 0]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function azure(array $fields = []): AzureOpenAIEmbeddings
    {
        return new AzureOpenAIEmbeddings($fields + [
            'azureOpenAIApiKey' => 'azure-key', 'azureOpenAIApiInstanceName' => 'inst',
            'azureOpenAIApiDeploymentName' => 'emb', 'azureOpenAIApiVersion' => '2024-10-21',
            'httpClient' => self::endpoint(), 'maxRetries' => 0,
        ]);
    }

    // ---- embeddings.int.test.ts ----------------------------------------------

    public function testEmbedQuery(): void
    {
        $embeddings = self::openAI();

        $res = $embeddings->embedQuery('Hello world');

        self::assertIsFloat($res[0]);
        self::assertSame('https://api.openai.com/v1/embeddings', $embeddings->httpClient->requests[0]['url']);
        self::assertSame('Bearer sk-test', $embeddings->httpClient->requests[0]['headers']['Authorization']);
    }

    public function testEmbedDocuments(): void
    {
        $res = self::openAI()->embedDocuments(['Hello world', 'Bye bye']);

        self::assertCount(2, $res);
        self::assertIsFloat($res[0][0]);
        self::assertIsFloat($res[1][0]);
        self::assertSame(0.5, $res[0][0]);
        self::assertSame(1.5, $res[1][0], 'vectors come back in input order within a batch');
    }

    public function testConcurrency(): void
    {
        $embeddings = self::openAI(['batchSize' => 1, 'maxConcurrency' => 2]);

        $res = $embeddings->embedDocuments(['Hello world', 'Bye bye', 'Hello world', 'Bye bye', 'Hello world', 'Bye bye']);

        self::assertCount(6, $res);
        self::assertSame([], array_filter($res, static fn (array $e): bool => !is_float($e[0])));
        self::assertCount(6, $embeddings->httpClient->requests, 'one request per batch of one');
        self::assertSame(2, $embeddings->maxConcurrency);
    }

    public function testTimeoutErrorThrownFromTheTransport(): void
    {
        $transport = new class implements HttpClient {
            public ?float $timeoutSeen = null;

            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                $this->timeoutSeen = $timeout;
                throw new HttpException('Request timed out.', 0);
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                yield from [];
            }
        };
        $model = new OpenAIEmbeddings(['apiKey' => 'sk-test', 'timeout' => 1, 'maxRetries' => 0, 'httpClient' => $transport]);

        try {
            $model->embedDocuments(['Hello world', 'Bye bye', 'Hello world', 'Bye bye', 'Hello world', 'Bye bye']);
            self::fail('expected the timeout to surface');
        } catch (OpenAIException $e) {
            self::assertSame(1.0, $transport->timeoutSeen);
            self::assertInstanceOf(HttpException::class, $e->getPrevious());
        }
    }

    public function testAnInvalidOrganisationThrows(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(401, ['error' => [
            'message' => 'OpenAI-Organization header should match organization for API key',
            'type' => 'invalid_request_error', 'code' => 'mismatched_organization',
        ]])]);
        $model = new OpenAIEmbeddings([
            'apiKey' => 'sk-test', 'maxRetries' => 0, 'httpClient' => $http,
            'configuration' => ['organization' => 'NOT_REAL'],
        ]);

        try {
            $model->embedDocuments(['Hello world', 'Bye bye']);
            self::fail('expected a 401');
        } catch (OpenAIException $e) {
            self::assertSame(401, $e->status);
            self::assertSame('NOT_REAL', $http->requests[0]['headers']['OpenAI-Organization']);
        }
    }

    public function testEmbedQueryWithV3AndDimensions(): void
    {
        $embeddings = self::openAI(['modelName' => 'text-embedding-3-small', 'dimensions' => 127]);

        $res = $embeddings->embedQuery('Hello world');

        self::assertIsFloat($res[0]);
        self::assertCount(127, $res);
        $sent = json_decode($embeddings->httpClient->requests[0]['body'], true);
        self::assertSame(['model' => 'text-embedding-3-small', 'input' => 'Hello world', 'dimensions' => 127], $sent);
    }

    public function testEmbedDocumentsWithV3AndDimensions(): void
    {
        $res = self::openAI(['modelName' => 'text-embedding-3-small', 'dimensions' => 127])->embedDocuments(['Hello world', 'Bye bye']);

        self::assertCount(2, $res);
        self::assertIsFloat($res[0][0]);
        self::assertIsFloat($res[1][0]);
        self::assertCount(127, $res[0]);
        self::assertCount(127, $res[1]);
    }

    public function testEmbedQueryWithEncodingFormat(): void
    {
        $embeddings = self::openAI(['modelName' => 'text-embedding-3-small', 'encodingFormat' => 'float']);

        $res = $embeddings->embedQuery('Hello world');

        self::assertIsFloat($res[0]);
        self::assertCount(1536, $res);
        self::assertSame('float', json_decode($embeddings->httpClient->requests[0]['body'], true)['encoding_format']);
    }

    public function testEmbedDocumentsWithEncodingFormat(): void
    {
        $res = self::openAI(['modelName' => 'text-embedding-3-small', 'encodingFormat' => 'float'])->embedDocuments(['Hello world', 'Bye bye']);

        self::assertCount(2, $res);
        self::assertCount(1536, $res[0]);
        self::assertCount(1536, $res[1]);
    }

    public function testEncodingFormatAndCustomDimensions(): void
    {
        $res = self::openAI(['modelName' => 'text-embedding-3-small', 'encodingFormat' => 'float', 'dimensions' => 256])->embedQuery('Hello world');

        self::assertIsFloat($res[0]);
        self::assertCount(256, $res);
    }

    // ---- behaviour the live tests take for granted ---------------------------

    public function testNewlinesAreStrippedByDefaultAndKeptWhenAsked(): void
    {
        $stripped = self::openAI();
        $stripped->embedQuery("a\nb");
        $kept = self::openAI(['stripNewLines' => false]);
        $kept->embedQuery("a\nb");

        self::assertSame('a b', json_decode($stripped->httpClient->requests[0]['body'], true)['input']);
        self::assertSame("a\nb", json_decode($kept->httpClient->requests[0]['body'], true)['input']);
    }

    public function testDocumentsAreBatchedByBatchSize(): void
    {
        $embeddings = self::openAI(['batchSize' => 2]);

        $res = $embeddings->embedDocuments(['a', 'b', 'c', 'd', 'e']);

        self::assertCount(5, $res);
        $sizes = array_map(static fn (array $r): int => count(json_decode($r['body'], true)['input']), $embeddings->httpClient->requests);
        self::assertSame([2, 2, 1], $sizes);
    }

    public function testDefaultsMatchUpstream(): void
    {
        $embeddings = new OpenAIEmbeddings(['apiKey' => 'k']);

        self::assertSame('text-embedding-ada-002', $embeddings->model);
        self::assertSame(512, $embeddings->batchSize);
        self::assertTrue($embeddings->stripNewLines);
        self::assertSame(2, $embeddings->maxConcurrency);
    }

    public function testTheKeyAndOrganisationComeFromTheEnvironment(): void
    {
        putenv('OPENAI_API_KEY=env-key');
        putenv('OPENAI_ORGANIZATION=env-org');
        $embeddings = new OpenAIEmbeddings(['httpClient' => self::endpoint(), 'maxRetries' => 0]);

        $embeddings->embedQuery('x');

        self::assertSame('Bearer env-key', $embeddings->httpClient->requests[0]['headers']['Authorization']);
        self::assertSame('env-org', $embeddings->httpClient->requests[0]['headers']['OpenAI-Organization']);
    }

    public function testNoKeyIsAnOpenAIException(): void
    {
        $this->expectException(OpenAIException::class);

        (new OpenAIEmbeddings(['httpClient' => self::endpoint()]))->embedQuery('x');
    }

    public function testABase64EmbeddingIsUnpackedToFloats(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['data' => [['embedding' => base64_encode(pack('g*', 0.25, -1.5))]]])]);

        $res = (new OpenAIEmbeddings(['apiKey' => 'k', 'encodingFormat' => 'base64', 'httpClient' => $http]))->embedQuery('x');

        self::assertSame([0.25, -1.5], $res);
    }

    public function testA429IsRetriedAndA400IsNot(): void
    {
        $slept = [];
        $http = new FakeHttpClient([
            FakeHttpClient::json(429, ['error' => ['message' => 'slow down']]),
            FakeHttpClient::json(200, ['data' => [['embedding' => [1, 2]]]]),
        ]);
        $embeddings = new OpenAIEmbeddings(['apiKey' => 'k', 'httpClient' => $http, 'maxRetries' => 2]);
        $embeddings->backoffHandler = static function (int $attempt) use (&$slept): void {
            $slept[] = $attempt;
        };

        self::assertSame([1.0, 2.0], $embeddings->embedQuery('x'));
        self::assertSame([1], $slept);

        $bad = new FakeHttpClient([FakeHttpClient::json(400, ['error' => ['message' => 'bad']])]);
        try {
            (new OpenAIEmbeddings(['apiKey' => 'k', 'httpClient' => $bad, 'maxRetries' => 3]))->embedQuery('x');
            self::fail('a 400 must surface');
        } catch (OpenAIException $e) {
            self::assertCount(1, $bad->requests);
        }
    }

    // ---- azure/tests/embeddings.int.test.ts ----------------------------------

    public function testAzureEmbedQueryUsesTheDeploymentUrlKeyHeaderAndApiVersion(): void
    {
        $embeddings = self::azure();

        $res = $embeddings->embedQuery('Hello world');

        self::assertIsFloat($res[0]);
        $request = $embeddings->httpClient->requests[0];
        self::assertSame('https://inst.openai.azure.com/openai/deployments/emb/embeddings', $request['url']);
        self::assertSame(['api-version' => '2024-10-21'], $request['query']);
        self::assertSame('azure-key', $request['headers']['api-key']);
        self::assertArrayNotHasKey('Authorization', $request['headers']);
    }

    public function testAzureEmbedDocumentsDefaultsToABatchSizeOfOne(): void
    {
        $embeddings = self::azure();

        $res = $embeddings->embedDocuments(['Hello world', 'Bye bye']);

        self::assertCount(2, $res);
        self::assertSame(1, $embeddings->batchSize);
        self::assertCount(2, $embeddings->httpClient->requests);
    }

    public function testAzureConcurrency(): void
    {
        $embeddings = self::azure(['batchSize' => 1, 'maxConcurrency' => 2]);

        $res = $embeddings->embedDocuments(['Hello world', 'Bye bye', 'Hello world', 'Bye bye', 'Hello world', 'Bye bye']);

        self::assertCount(6, $res);
        self::assertSame([], array_filter($res, static fn (array $e): bool => !is_float($e[0])));
    }

    public function testAzureTimeoutErrorThrownFromTheTransport(): void
    {
        $transport = new class implements HttpClient {
            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                throw new HttpException('Request timed out.', 0);
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                yield from [];
            }
        };

        $this->expectException(OpenAIException::class);
        self::azure(['timeout' => 1, 'maxRetries' => 0, 'httpClient' => $transport])->embedDocuments(['Hello world', 'Bye bye']);
    }

    public function testAzureV3AndDimensions(): void
    {
        $embeddings = self::azure(['modelName' => 'text-embedding-3-small', 'dimensions' => 127]);

        $query = $embeddings->embedQuery('Hello world');
        $docs = $embeddings->embedDocuments(['Hello world', 'Bye bye']);

        self::assertCount(127, $query);
        self::assertCount(2, $docs);
        self::assertCount(127, $docs[0]);
        self::assertCount(127, $docs[1]);
    }

    public function testAzureReadsTheEmbeddingsDeploymentFromTheEnvironmentAheadOfTheGenericOne(): void
    {
        putenv('AZURE_OPENAI_API_KEY=env-key');
        putenv('AZURE_OPENAI_API_INSTANCE_NAME=envinst');
        putenv('AZURE_OPENAI_API_DEPLOYMENT_NAME=generic');
        putenv('AZURE_OPENAI_API_EMBEDDINGS_DEPLOYMENT_NAME=embeddings-only');
        putenv('AZURE_OPENAI_API_VERSION=2024-10-21');
        $embeddings = new AzureOpenAIEmbeddings(['httpClient' => self::endpoint(), 'maxRetries' => 0]);

        $embeddings->embedQuery('x');

        self::assertSame(
            'https://envinst.openai.azure.com/openai/deployments/embeddings-only/embeddings',
            $embeddings->httpClient->requests[0]['url'],
        );
    }

    public function testAzureTokenProviderSendsABearerToken(): void
    {
        $embeddings = new AzureOpenAIEmbeddings([
            'azureOpenAIApiInstanceName' => 'inst', 'azureOpenAIApiDeploymentName' => 'emb',
            'azureOpenAIApiVersion' => 'v1', 'azureADTokenProvider' => static fn (): string => 'tok',
            'httpClient' => self::endpoint(), 'maxRetries' => 0,
        ]);

        $embeddings->embedQuery('x');

        self::assertSame('Bearer tok', $embeddings->httpClient->requests[0]['headers']['Authorization']);
    }
}
