<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware\Provider\OpenAI;

use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;

/**
 * OpenAI's moderations endpoint (`client.moderations.create` in the OpenAI SDK), over {@see HttpClient}.
 *
 * The moderation middleware needs only this one call, so it talks to `/moderations` itself instead of
 * borrowing the chat model's client: the chat model supplies the credentials and the transport (read off the
 * model's `apiKey`, `organization`, `baseUrl` and `httpClient` fields, with `OPENAI_API_KEY` as the key's
 * fallback), exactly as the SDK client the model owns upstream.
 */
final class ModerationClient
{
    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    private readonly HttpClient $http;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly ?string $organization = null,
        ?HttpClient $http = null,
        private readonly ?float $timeout = null,
    ) {
        $this->http = $http ?? new GuzzleHttpClient();
    }

    /**
     * A client with the credentials and transport of an OpenAI chat model.
     *
     * A chat model's `baseUrl` is the full chat endpoint (`.../v1/chat/completions` or `.../v1/responses`);
     * the moderations endpoint is its sibling.
     */
    public static function fromModel(object $model): self
    {
        $apiKey = self::stringField($model, 'apiKey');
        if ($apiKey === null) {
            $env = getenv('OPENAI_API_KEY');
            $apiKey = \is_string($env) && $env !== '' ? $env : null;
        }

        $baseUrl = self::stringField($model, 'baseUrl');
        $http = property_exists($model, 'httpClient') && $model->httpClient instanceof HttpClient ? $model->httpClient : null;

        return new self(
            $apiKey,
            $baseUrl === null ? self::DEFAULT_BASE_URL : (preg_replace('#/(chat/completions|responses)/?$#', '', $baseUrl) ?? $baseUrl),
            self::stringField($model, 'organization'),
            $http,
        );
    }

    /**
     * Classify text with a moderation model.
     *
     * @param array{input: string|list<string>, model: string} $request
     * @return array{id?: string, model?: string, results: list<array<string, mixed>>}
     * @throws HttpException when the request fails or the endpoint answers with an error status
     */
    public function create(array $request): array
    {
        if ($this->apiKey === null) {
            throw new \RuntimeException('No OpenAI API key. Pass one to the chat model or set the OPENAI_API_KEY environment variable.');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];
        if ($this->organization !== null) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        $response = $this->http->post(
            rtrim($this->baseUrl, '/') . '/moderations',
            $headers,
            json_encode($request, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            [],
            $this->timeout,
        );

        if (!$response->isOk()) {
            throw new HttpException(\sprintf('OpenAI moderation request failed with status %d: %s', $response->status, $response->body), $response->status, $response->body);
        }

        /** @var array{id?: string, model?: string, results: list<array<string, mixed>>} */
        return $response->json();
    }

    private static function stringField(object $model, string $field): ?string
    {
        return property_exists($model, $field) && \is_string($model->{$field}) && $model->{$field} !== '' ? $model->{$field} : null;
    }
}
