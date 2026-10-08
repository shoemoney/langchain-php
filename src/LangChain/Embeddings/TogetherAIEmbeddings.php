<?php

declare(strict_types=1);

namespace LangChain\Embeddings;

use LangChain\LanguageModels\Chat\TogetherAI\TogetherAIException;
use LangChain\Utils\AsyncCaller;
use LangChain\Utils\Env;
use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Js;

/**
 * Embeddings from the Together AI embeddings API.
 *
 * Port of `TogetherAIEmbeddings` from `@langchain/together-ai`.
 *
 * Together takes one input per request, so `embedDocuments()` sends one request
 * per text, `batchSize` texts at a time. Upstream fires each batch concurrently;
 * here every request goes out in order.
 */
class TogetherAIEmbeddings extends Embeddings
{
    public const DEFAULT_MODEL = 'togethercomputer/m2-bert-80M-8k-retrieval';

    public const EMBEDDINGS_API_URL = 'https://api.together.xyz/v1/embeddings';

    public string $modelName = self::DEFAULT_MODEL;

    public string $model = self::DEFAULT_MODEL;

    public string $apiKey;

    public int $batchSize = 512;

    public bool $stripNewLines = false;

    public ?float $timeout = null;

    public ?HttpClient $httpClient = null;

    private AsyncCaller $caller;

    /**
     * @param array<string, mixed> $fields `apiKey`, `model` / `modelName`, `timeout`, `batchSize`,
     *                                     `stripNewLines`, `maxRetries`, `httpClient`, `asyncCaller`, `sleeper`.
     */
    public function __construct(array $fields = [])
    {
        $apiKey = $fields['apiKey'] ?? Env::getEnvironmentVariable('TOGETHER_AI_API_KEY');
        if (!is_string($apiKey) || $apiKey === '') {
            throw new \InvalidArgumentException('TOGETHER_AI_API_KEY not found.');
        }

        $this->apiKey = $apiKey;
        $this->modelName = (string) ($fields['model'] ?? $fields['modelName'] ?? $this->model);
        $this->model = $this->modelName;
        $this->timeout = isset($fields['timeout']) ? (float) $fields['timeout'] : null;
        $this->batchSize = max(1, (int) ($fields['batchSize'] ?? $this->batchSize));
        $this->stripNewLines = (bool) ($fields['stripNewLines'] ?? $this->stripNewLines);
        $this->httpClient = $fields['httpClient'] ?? null;
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
        return ['apiKey' => 'TOGETHER_AI_API_KEY'];
    }

    /**
     * @return list<string>
     */
    public static function lcNamespace(): array
    {
        return ['langchain', 'embeddings', 'together_ai'];
    }

    public function embedDocuments(array $documents): array
    {
        $texts = array_map($this->clean(...), array_values($documents));

        $embeddings = [];
        foreach (array_chunk($texts, $this->batchSize) as $batch) {
            foreach ($batch as $text) {
                $embeddings[] = $this->embeddingWithRetry($text)['data'][0]['embedding'];
            }
        }

        return $embeddings;
    }

    public function embedQuery(string $document): array
    {
        return $this->embeddingWithRetry($this->clean($document))['data'][0]['embedding'];
    }

    private function clean(string $text): string
    {
        return $this->stripNewLines ? str_replace("\n", ' ', $text) : $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function embeddingWithRetry(string $input): array
    {
        $body = Js::encode(['model' => $this->model, 'input' => $input]);
        $headers = [
            'accept' => 'application/json',
            'content-type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];

        return $this->caller->call(function () use ($body, $headers): array {
            try {
                $response = ($this->httpClient ??= new GuzzleHttpClient())->post(
                    self::EMBEDDINGS_API_URL,
                    $headers,
                    $body,
                    [],
                    $this->timeout,
                );
            } catch (HttpException $e) {
                throw $e->status === 0 ? $e : TogetherAIException::fromBody($e->status, $e->body);
            }

            if ($response->status !== 200) {
                throw TogetherAIException::fromBody($response->status, $response->body);
            }

            return $response->json();
        });
    }
}
