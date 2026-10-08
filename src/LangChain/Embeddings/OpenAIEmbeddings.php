<?php

declare(strict_types=1);

namespace LangChain\Embeddings;

use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Azure;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Requester;
use LangChain\Utils\Env;
use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Http\HttpClient;

/**
 * Embeddings from the OpenAI `/embeddings` endpoint.
 *
 * Port of `OpenAIEmbeddings` from `@langchain/openai` (`embeddings.ts`). To use
 * Azure, use {@see AzureOpenAIEmbeddings}.
 *
 * Documents are cut into batches of `batchSize` and each batch is one request.
 * Upstream sends the batches concurrently through `AsyncCaller`; PHP is
 * synchronous (see {@see Embeddings}), so they go one after another and
 * `maxConcurrency` is stored without throttling anything. The retry rules are
 * those of the OpenAI chat clients: 429, 5xx and transport errors are retried up
 * to `maxRetries` times, a 4xx never is.
 *
 * ```php
 * $vector = (new OpenAIEmbeddings(['model' => 'text-embedding-3-small', 'dimensions' => 256]))
 *     ->embedQuery('What would be a good name for a sock company?');
 * ```
 */
class OpenAIEmbeddings extends Embeddings
{
    public const DEFAULT_API_URL = 'https://api.openai.com/v1/embeddings';

    public string $model = 'text-embedding-ada-002';

    /** @deprecated Use $model. */
    public string $modelName = 'text-embedding-ada-002';

    public int $batchSize = 512;

    /**
     * Replace newlines with spaces. Upstream's TODO is to default this to
     * `false` in a later minor release; it is `true` today and so it is here.
     */
    public bool $stripNewLines = true;

    /** Only supported by `text-embedding-3` and later models. */
    public ?int $dimensions = null;

    public ?float $timeout = null;

    public ?string $organization = null;

    /** `float` or `base64`. */
    public ?string $encodingFormat = null;

    public ?string $apiKey = null;

    /** The API root or the full `/embeddings` URL; null means OpenAI's. */
    public ?string $baseUrl = null;

    /** @var array<string, string> Extra headers (`configuration.defaultHeaders` upstream). */
    public array $defaultHeaders = [];

    public int $maxRetries = 2;

    /** Accepted for parity; nothing runs concurrently in a synchronous runtime. */
    public int $maxConcurrency = 2;

    public ?HttpClient $httpClient = null;

    /** Replaces the sleep between retries; receives the attempt number. */
    public ?\Closure $backoffHandler = null;

    /**
     * @param array<string, mixed> $fields model|modelName, batchSize, stripNewLines, dimensions, timeout,
     *                                     encodingFormat, apiKey|openAIApiKey, organization, baseUrl,
     *                                     defaultHeaders, maxRetries, maxConcurrency, httpClient;
     *                                     `configuration` carries organization, baseURL and defaultHeaders.
     */
    public function __construct(array $fields = [])
    {
        $configuration = is_array($fields['configuration'] ?? null) ? $fields['configuration'] : [];

        $this->apiKey = self::string($fields['apiKey'] ?? null)
            ?? self::string($fields['openAIApiKey'] ?? null)
            ?? Env::getEnvironmentVariable('OPENAI_API_KEY');
        $this->organization = self::string($configuration['organization'] ?? null)
            ?? self::string($fields['organization'] ?? null)
            ?? Env::getEnvironmentVariable('OPENAI_ORGANIZATION');

        $this->model = (string) ($fields['model'] ?? $fields['modelName'] ?? $this->model);
        $this->modelName = $this->model;
        $this->batchSize = (int) ($fields['batchSize'] ?? $this->batchSize);
        $this->stripNewLines = (bool) ($fields['stripNewLines'] ?? $this->stripNewLines);
        $this->timeout = isset($fields['timeout']) ? (float) $fields['timeout'] : null;
        $this->dimensions = isset($fields['dimensions']) ? (int) $fields['dimensions'] : null;
        $this->encodingFormat = self::string($fields['encodingFormat'] ?? null);
        $this->baseUrl = self::string($configuration['baseURL'] ?? null) ?? self::string($fields['baseUrl'] ?? null);
        $this->defaultHeaders = (array) ($configuration['defaultHeaders'] ?? $fields['defaultHeaders'] ?? []);
        $this->maxRetries = (int) ($fields['maxRetries'] ?? $this->maxRetries);
        $this->maxConcurrency = (int) ($fields['maxConcurrency'] ?? $this->maxConcurrency);
        $this->httpClient = $fields['httpClient'] ?? null;

        if ($this->batchSize < 1) {
            throw new \InvalidArgumentException('batchSize must be at least 1.');
        }
    }

    /**
     * Embed documents, one request per batch.
     *
     * @param list<string> $documents
     *
     * @return list<list<float>>
     */
    public function embedDocuments(array $documents): array
    {
        $texts = array_values($this->stripNewLines
            ? array_map(static fn (string $t): string => str_replace("\n", ' ', $t), $documents)
            : $documents);

        $embeddings = [];
        foreach (array_chunk($texts, $this->batchSize) as $batch) {
            $response = $this->embeddingWithRetry($this->request($batch));
            foreach (array_keys($batch) as $j) {
                $embeddings[] = self::vector($response['data'][$j]['embedding'] ?? null);
            }
        }

        return $embeddings;
    }

    /**
     * @return list<float>
     */
    public function embedQuery(string $document): array
    {
        $response = $this->embeddingWithRetry($this->request(
            $this->stripNewLines ? str_replace("\n", ' ', $document) : $document,
        ));

        return self::vector($response['data'][0]['embedding'] ?? null);
    }

    /**
     * @param string|list<string> $input
     *
     * @return array<string, mixed>
     */
    protected function request(string|array $input): array
    {
        $params = ['model' => $this->model, 'input' => $input];
        if ($this->dimensions) {
            $params['dimensions'] = $this->dimensions;
        }
        if ($this->encodingFormat) {
            $params['encoding_format'] = $this->encodingFormat;
        }

        return $params;
    }

    /**
     * Send one embeddings request, with retries.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    protected function embeddingWithRetry(array $request): array
    {
        $this->httpClient ??= new GuzzleHttpClient();

        return (new Requester(
            $this->httpClient,
            $this->maxRetries,
            $this->timeout,
            $this->backoffHandler,
        ))->post($this->url(), $this->headers(), $request, $this->query());
    }

    protected function url(): string
    {
        if ($this->baseUrl === null) {
            return self::DEFAULT_API_URL;
        }

        $root = rtrim($this->baseUrl, '/');

        return str_ends_with($root, '/embeddings') ? $root : $root . '/embeddings';
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new OpenAIException(
                'No OpenAI API key. Pass one to the constructor or set the OPENAI_API_KEY environment variable.',
                0,
                '',
            );
        }

        $headers = ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->apiKey];
        if ($this->organization !== null) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        return $headers + Azure::getHeadersWithUserAgent($this->defaultHeaders);
    }

    /**
     * @return array<string, mixed>
     */
    protected function query(): array
    {
        return [];
    }

    /**
     * A vector as floats. A `base64` embedding arrives as a string of
     * little-endian float32; the interface promises an array, so it is unpacked.
     *
     * @return list<float>
     */
    private static function vector(mixed $embedding): array
    {
        if (is_string($embedding)) {
            $bytes = base64_decode($embedding, true);
            if ($bytes === false || $bytes === '') {
                return [];
            }

            return array_map(floatval(...), array_values((array) unpack('g*', $bytes)));
        }

        if (!is_array($embedding)) {
            throw new OpenAIException('The embeddings response had no embedding for an input.', 0, '');
        }

        return array_map(floatval(...), array_values($embedding));
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
