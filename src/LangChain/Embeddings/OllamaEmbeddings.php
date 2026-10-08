<?php

declare(strict_types=1);

namespace LangChain\Embeddings;

use LangChain\LanguageModels\Chat\Ollama\OllamaCamelCaseOptions;
use LangChain\LanguageModels\Chat\Ollama\OllamaException;
use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Js;

/**
 * Embeddings from an Ollama server's `/api/embed` endpoint.
 *
 * Port of `OllamaEmbeddings` from `@langchain/ollama`.
 *
 * Upstream wraps the call in `AsyncCaller` for retry and concurrency control,
 * which this port does not have (see {@see Embeddings}); a failed request
 * raises {@see OllamaException} and the caller owns any retry.
 */
class OllamaEmbeddings extends Embeddings
{
    public string $model = 'mxbai-embed-large';

    public string $baseUrl = 'http://localhost:11434';

    public ?int $dimensions = null;

    public string|int|null $keepAlive = null;

    /** @var array<string, mixed>|null Snake-cased Ollama options. */
    public ?array $requestOptions = null;

    public bool $truncate = false;

    /** @var array<string, string> */
    public array $headers = [];

    public ?float $timeout = null;

    public ?HttpClient $httpClient = null;

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        $env = getenv('OLLAMA_BASE_URL');
        $this->baseUrl = rtrim(
            (string) ($fields['baseUrl'] ?? (is_string($env) && $env !== '' ? $env : $this->baseUrl)),
            '/',
        );

        $this->model = (string) ($fields['model'] ?? $this->model);
        $this->dimensions = isset($fields['dimensions']) ? (int) $fields['dimensions'] : null;
        $this->keepAlive = $fields['keepAlive'] ?? null;
        $this->truncate = (bool) ($fields['truncate'] ?? $this->truncate);
        $this->headers = (array) ($fields['headers'] ?? []);
        $this->timeout = isset($fields['timeout']) ? (float) $fields['timeout'] : null;
        $this->httpClient = $fields['httpClient'] ?? null;
        $this->requestOptions = isset($fields['requestOptions'])
            ? $this->convertOptions((array) $fields['requestOptions'])
            : null;
    }

    /**
     * Convert camelCased Ollama request options like `useMmap` to the
     * snake_cased equivalent the API actually uses. Unknown keys pass through.
     *
     * @param array<string, mixed> $requestOptions
     *
     * @return array<string, mixed>
     */
    public function convertOptions(array $requestOptions): array
    {
        return OllamaCamelCaseOptions::toWire($requestOptions);
    }

    public function embedDocuments(array $documents): array
    {
        return $this->embed(array_values($documents));
    }

    public function embedQuery(string $document): array
    {
        return $this->embed([$document])[0] ?? [];
    }

    /**
     * @param list<string> $texts
     *
     * @return list<list<float>>
     */
    private function embed(array $texts): array
    {
        $body = Js::encode(array_filter([
            'model' => $this->model,
            'input' => $texts,
            'dimensions' => $this->dimensions,
            'keep_alive' => $this->keepAlive,
            'options' => $this->requestOptions === null || $this->requestOptions === [] ? null : $this->requestOptions,
            'truncate' => $this->truncate,
        ], static fn (mixed $v): bool => $v !== null));

        $url = $this->baseUrl . '/api/embed';

        try {
            $response = ($this->httpClient ??= new GuzzleHttpClient())->post(
                $url,
                $this->headers + ['Content-Type' => 'application/json'],
                $body,
                [],
                $this->timeout,
            );
        } catch (OllamaException $e) {
            throw $e;
        } catch (HttpException $e) {
            throw OllamaException::fromResponse($e->body, $e->status, $url, $e);
        } catch (\Exception $e) {
            throw new OllamaException('The HTTP transport raised ' . $e::class . ': ' . $e->getMessage(), 0, '', $e);
        }

        if (!$response->isOk()) {
            throw OllamaException::fromResponse($response->body, $response->status, $url);
        }

        $embeddings = $response->json()['embeddings'] ?? null;
        if (!is_array($embeddings)) {
            throw new OllamaException('Ollama /api/embed response had no "embeddings" array.', $response->status, $response->body);
        }

        return array_values($embeddings);
    }
}
