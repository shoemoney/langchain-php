<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk\Support;

use LangChain\Utils\Http\HttpResponse;
use LangGraph\Sdk\Utils\StreamingMethodHttpClient;
use LangGraph\Sdk\Utils\StreamResponse;

/**
 * A {@see StreamingMethodHttpClient} that answers every request with a function of the request, the
 * streaming sibling of {@see RecordingTransport}'s `$handler`: it is how a real graph stands behind
 * the run-stream endpoint.
 */
final class HandlerStreamingTransport implements StreamingMethodHttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>}> */
    public array $requests = [];

    /**
     * @param \Closure(string, string, array<string, string>, ?string): StreamResponse $handler
     */
    public function __construct(private readonly \Closure $handler)
    {
    }

    public function requestStream(string $method, string $url, array $headers, ?string $body = null, ?float $timeout = null): StreamResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];

        return ($this->handler)($method, $url, $headers, $body);
    }

    public function request(string $method, string $url, array $headers, ?string $body = null, ?float $timeout = null): HttpResponse
    {
        throw new \LogicException('The streaming tests use requestStream().');
    }

    public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body, $timeout);
    }

    public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
    {
        throw new \LogicException('The streaming tests use requestStream().');
    }
}
