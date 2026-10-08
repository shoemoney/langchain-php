<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk\Support;

use LangChain\Utils\Http\HttpResponse;
use LangGraph\Sdk\Utils\StreamingMethodHttpClient;
use LangGraph\Sdk\Utils\StreamResponse;

/**
 * A {@see StreamingMethodHttpClient} whose bodies are GENERATORS, so a test can see when each chunk
 * is produced relative to when the client consumes it. A fake that returns the whole body at once
 * proves parsing, not incremental delivery; this one can prove the latter.
 *
 * Each scripted entry is a callable `(string $method, string $url, array $headers, ?string $body):
 * StreamResponse|\Throwable`; a thrown/returned Throwable stands for a request that failed outright.
 */
final class StreamingTransport implements StreamingMethodHttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @param list<callable> $script */
    public function __construct(private array $script)
    {
    }

    /**
     * @param iterable<string|null>  $chunks
     * @param array<string, string>  $headers
     */
    public static function sse(iterable $chunks, array $headers = [], int $status = 200): StreamResponse
    {
        return new StreamResponse(
            new HttpResponse($status, $headers + ['content-type' => 'text/event-stream']),
            $chunks,
        );
    }

    /**
     * Render parts as SSE text, one chunk per field line plus the blank dispatch line, exactly the
     * pieces the JS tests enqueue.
     *
     * @param list<array{id?: string, event: string, data: mixed}> $parts
     *
     * @return list<string>
     */
    public static function chunksOf(array $parts): array
    {
        $chunks = [];
        foreach ($parts as $part) {
            if (isset($part['id'])) {
                $chunks[] = "id: {$part['id']}\n";
            }
            if ($part['event'] !== '') {
                $chunks[] = "event: {$part['event']}\n";
            }
            $chunks[] = 'data: ' . json_encode($part['data']) . "\n";
            $chunks[] = "\n";
        }

        return $chunks;
    }

    /**
     * A body that yields `$parts` then throws, like a socket dying mid-stream.
     *
     * @param list<array{id?: string, event: string, data: mixed}> $parts
     *
     * @return \Generator<int, string>
     */
    public static function chunksThenError(array $parts, \Throwable $error): \Generator
    {
        yield from self::chunksOf($parts);

        throw $error;
    }

    public function requestStream(string $method, string $url, array $headers, ?string $body = null, ?float $timeout = null): StreamResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        $next = array_shift($this->script) ?? throw new \LogicException('StreamingTransport script exhausted');
        $result = $next($method, $url, $headers, $body);
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
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
