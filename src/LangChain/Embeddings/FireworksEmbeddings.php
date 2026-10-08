<?php

declare(strict_types=1);

namespace LangChain\Embeddings;

use LangChain\LanguageModels\Chat\Fireworks\FireworksResponseError;
use LangChain\Utils\AsyncCaller;
use LangChain\Utils\Env;
use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Js;

/**
 * Embeddings from Fireworks' OpenAI-compatible `/embeddings` endpoint.
 *
 * Port of `FireworksEmbeddings` from `@langchain/fireworks`.
 *
 * Upstream sends the batches concurrently with `Promise.all`; here they go one
 * after another, in order. Retries are {@see AsyncCaller}'s, steered by the
 * status stamp {@see FireworksResponseError} puts on each failure.
 */
class FireworksEmbeddings extends Embeddings
{
    public const FIREWORKS_BASE_URL = 'https://api.fireworks.ai/inference/v1';

    public const DEFAULT_FIREWORKS_EMBEDDING_MODEL = 'nomic-ai/nomic-embed-text-v1.5';

    public string $model = self::DEFAULT_FIREWORKS_EMBEDDING_MODEL;

    /** Fireworks currently limits a request to 8 documents. */
    public int $batchSize = 8;

    public string $apiKey;

    public string $basePath = self::FIREWORKS_BASE_URL;

    public string $apiUrl;

    /** @var array<string, string>|null */
    public ?array $headers = null;

    public ?HttpClient $httpClient = null;

    public ?float $timeout = null;

    private AsyncCaller $caller;

    /**
     * @param array<string, mixed> $fields `apiKey`, `model`, `batchSize`, `basePath`, `headers`,
     *                                     `maxRetries`, `timeout`, `httpClient`, `asyncCaller`
     *                                     (replaces the default retry policy), `sleeper`.
     */
    public function __construct(array $fields = [])
    {
        $apiKey = $fields['apiKey'] ?? Env::getEnvironmentVariable('FIREWORKS_API_KEY');
        if (!is_string($apiKey) || $apiKey === '') {
            throw new \InvalidArgumentException(
                'Fireworks API key not found. Please set the FIREWORKS_API_KEY environment variable '
                . 'or pass the key into "apiKey".'
            );
        }

        $this->apiKey = $apiKey;
        $this->model = (string) ($fields['model'] ?? $this->model);
        $this->batchSize = max(1, (int) ($fields['batchSize'] ?? $this->batchSize));
        $this->basePath = (string) ($fields['basePath'] ?? $this->basePath);
        $this->apiUrl = $this->basePath . '/embeddings';
        $this->headers = isset($fields['headers']) ? (array) $fields['headers'] : null;
        $this->httpClient = $fields['httpClient'] ?? null;
        $this->timeout = isset($fields['timeout']) ? (float) $fields['timeout'] : null;
        $this->caller = $fields['asyncCaller'] ?? new AsyncCaller(
            maxRetries: (int) ($fields['maxRetries'] ?? 6),
            sleeper: $fields['sleeper'] ?? null,
        );
    }

    /**
     * @return array<string, string>
     */
    public static function lcSecrets(): array
    {
        return ['apiKey' => 'FIREWORKS_API_KEY'];
    }

    /**
     * @return list<string>
     */
    public static function lcNamespace(): array
    {
        return ['langchain', 'embeddings', 'fireworks'];
    }

    public function embedDocuments(array $documents): array
    {
        $embeddings = [];
        foreach (array_chunk(array_values($documents), $this->batchSize) as $batch) {
            $data = $this->embeddingWithRetry(['model' => $this->model, 'input' => $batch])['data'] ?? [];
            foreach (array_keys($batch) as $position) {
                $embeddings[] = $data[$position]['embedding'];
            }
        }

        return $embeddings;
    }

    public function embedQuery(string $document): array
    {
        return $this->embeddingWithRetry(['model' => $this->model, 'input' => $document])['data'][0]['embedding'];
    }

    /**
     * @param array{model: string, input: string|list<string>} $request
     *
     * @return array<string, mixed>
     */
    private function embeddingWithRetry(array $request): array
    {
        return $this->caller->call(fn (): array => $this->send($request));
    }

    /**
     * @param array{model: string, input: string|list<string>} $request
     *
     * @return array<string, mixed>
     */
    private function send(array $request): array
    {
        // Caller headers win over the defaults, as the object spread does upstream.
        $headers = array_merge(
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->apiKey],
            $this->headers ?? [],
        );

        try {
            $response = ($this->httpClient ??= new GuzzleHttpClient())->post(
                $this->apiUrl,
                $headers,
                Js::encode($request),
                [],
                $this->timeout,
            );
        } catch (HttpException $e) {
            // A status of 0 is a transport failure: nothing to classify, left to the caller's retry policy.
            throw $e->status === 0 ? $e : FireworksResponseError::fromBody($e->status, $e->body);
        }

        if (!$response->isOk()) {
            throw FireworksResponseError::fromBody($response->status, $response->body);
        }

        return $response->json();
    }
}
