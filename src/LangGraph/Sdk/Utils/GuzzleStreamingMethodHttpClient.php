<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use Psr\Http\Message\StreamInterface;

/**
 * {@see GuzzleMethodHttpClient} plus {@see StreamingMethodHttpClient}: the default transport for the
 * clients that stream.
 *
 * `stream => true` makes Guzzle pick its stream handler, which hands the response back before the
 * body is read. An empty read is reported as a `null` tick rather than waited on, so a stalled
 * socket reaches the idle watchdog instead of spinning here.
 */
final class GuzzleStreamingMethodHttpClient implements StreamingMethodHttpClient
{
    private readonly GuzzleMethodHttpClient $inner;

    private ?Client $client = null;

    /**
     * @param array<string, mixed> $options Guzzle client options, passed through untouched.
     */
    public function __construct(
        private readonly array $options = [],
        private readonly float $defaultTimeout = 60.0,
    ) {
        $this->inner = new GuzzleMethodHttpClient($options, $defaultTimeout);
    }

    public function request(string $method, string $url, array $headers, ?string $body = null, ?float $timeout = null): HttpResponse
    {
        return $this->inner->request($method, $url, $headers, $body, $timeout);
    }

    public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
    {
        return $this->inner->post($url, $headers, $body, $query, $timeout);
    }

    public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
    {
        return $this->inner->postStream($url, $headers, $body, $query, $timeout);
    }

    public function requestStream(string $method, string $url, array $headers, ?string $body = null, ?float $timeout = null): StreamResponse
    {
        // A stream has no overall deadline; 0 is Guzzle's "wait indefinitely".
        $options = ['headers' => $headers, 'timeout' => $timeout ?? 0, 'http_errors' => false, 'stream' => true];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = ($this->client ??= new Client($this->options))->request(strtoupper($method), $url, $options);
        } catch (GuzzleException $e) {
            throw new HttpException('Request to ' . $url . ' failed: ' . $e->getMessage(), 0, '', $e);
        }

        return new StreamResponse(
            new HttpResponse($response->getStatusCode(), $response->getHeaders(), ''),
            self::chunks($response->getBody(), $url),
        );
    }

    /**
     * @return \Generator<int, string|null>
     */
    private static function chunks(StreamInterface $stream, string $url): \Generator
    {
        try {
            while (!$stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    usleep(1000);
                    yield null;

                    continue;
                }

                yield $chunk;
            }
        } catch (GuzzleException | \RuntimeException $e) {
            throw new HttpException('Stream from ' . $url . ' failed: ' . $e->getMessage(), 0, '', $e);
        } finally {
            $stream->close();
        }
    }
}
