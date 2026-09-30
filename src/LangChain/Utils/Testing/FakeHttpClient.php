<?php

declare(strict_types=1);

namespace LangChain\Utils\Testing;

use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpResponse;

/**
 * An {@see HttpClient} that replays scripted responses and records what it was
 * asked for.
 *
 * This is the seam that makes a provider client testable. A chat model's logic
 * is almost entirely translation, and translation can only be tested against a
 * known payload — which means the payload has to come from the test rather than
 * from a socket. It also captures the exact request, so a test can assert on
 * the wire format the model produced.
 */
final class FakeHttpClient implements HttpClient
{
    /**
     * @param list<HttpResponse>    $responses   Consumed in order by `post()`.
     * @param list<string>          $streamChunks Raw bytes handed to `postStream()`.
     */
    public function __construct(
        public array $responses = [],
        public array $streamChunks = [],
    ) {
    }

    /** @var list<array{url: string, headers: array<string, string>, body: string, query: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{url: string, headers: array<string, string>, body: string, query: array<string, mixed>}> */
    public array $streamRequests = [];

    public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'query' => $query];

        if ($this->responses === []) {
            throw new \LogicException('FakeHttpClient ran out of scripted responses.');
        }

        return array_shift($this->responses);
    }

    public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
    {
        $this->streamRequests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'query' => $query];

        foreach ($this->streamChunks as $chunk) {
            yield $chunk;
        }
    }

    /**
     * The decoded body of the last request, for asserting on the wire format.
     *
     * @return array<string, mixed>
     */
    public function lastRequestBody(): array
    {
        $last = $this->lastOf($this->requests) ?? $this->lastOf($this->streamRequests);

        if ($last === null) {
            throw new \LogicException('No request was made.');
        }

        return (array) json_decode($last['body'], true);
    }

    /**
     * @param list<array{url: string, headers: array<string, string>, body: string, query: array<string, mixed>}> $requests
     *
     * @return array{url: string, headers: array<string, string>, body: string, query: array<string, mixed>}|null
     */
    private function lastOf(array $requests): ?array
    {
        // `array_key_last` on an empty array is null, and using that as an array
        // offset is deprecated — so check the length first.
        return $requests === [] ? null : $requests[count($requests) - 1];
    }

    /**
     * Build a JSON {@see HttpResponse} in one call.
     *
     * Headers use the PSR-7 `string[]` value shape that `GuzzleHttpClient`
     * actually produces. A fake that used bare strings would never exercise the
     * header flattening in `HttpResponse`, which is how a real
     * `header()` -> `TypeError` shipped with a fully green suite.
     *
     * @param array<string, mixed> $payload
     */
    public static function json(int $status, array $payload, array $headers = ['Content-Type' => 'application/json']): HttpResponse
    {
        $psr7 = [];
        foreach ($headers as $name => $value) {
            $psr7[(string) $name] = [(string) $value];
        }

        return new HttpResponse($status, $psr7, (string) json_encode($payload));
    }
}
