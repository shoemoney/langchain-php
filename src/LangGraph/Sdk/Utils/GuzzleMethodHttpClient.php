<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;

/**
 * The default {@see MethodHttpClient}: Guzzle for every verb.
 *
 * `post()` and `postStream()` delegate to the shared {@see GuzzleHttpClient} so the POST path is the
 * one implementation the rest of the SDK already trusts; only the extra verbs are new here.
 */
final class GuzzleMethodHttpClient implements MethodHttpClient
{
    private ?Client $client = null;

    private readonly GuzzleHttpClient $inner;

    /**
     * @param array<string, mixed> $options Guzzle client options, passed through untouched.
     */
    public function __construct(
        private readonly array $options = [],
        private readonly float $defaultTimeout = 60.0,
    ) {
        $this->inner = new GuzzleHttpClient($options, $defaultTimeout);
    }

    public function request(string $method, string $url, array $headers, ?string $body = null, ?float $timeout = null): HttpResponse
    {
        $options = [
            'headers' => $headers,
            'timeout' => $timeout ?? $this->defaultTimeout,
            'http_errors' => false,
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $this->client()->request(strtoupper($method), $url, $options);
        } catch (GuzzleException $e) {
            throw new HttpException('Request to ' . $url . ' failed: ' . $e->getMessage(), 0, '', $e);
        }

        return new HttpResponse($response->getStatusCode(), $response->getHeaders(), (string) $response->getBody());
    }

    public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
    {
        return $this->inner->post($url, $headers, $body, $query, $timeout);
    }

    public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
    {
        return $this->inner->postStream($url, $headers, $body, $query, $timeout);
    }

    private function client(): Client
    {
        return $this->client ??= new Client($this->options);
    }
}
