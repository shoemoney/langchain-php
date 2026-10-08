<?php

declare(strict_types=1);

namespace LangGraph\Sdk;

use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Sdk\Utils\BytesLineDecoder;
use LangGraph\Sdk\Utils\GuzzleStreamingMethodHttpClient;
use LangGraph\Sdk\Utils\HttpError;
use LangGraph\Sdk\Utils\Reconnect;
use LangGraph\Sdk\Utils\SseDecoder;
use LangGraph\Sdk\Utils\StreamingMethodHttpClient;
use LangGraph\Sdk\Utils\StreamResponse;
use LangGraph\Sdk\Utils\StreamRetry;

/**
 * Port of `BaseClient.streamWithRetry` from `client/base.ts`, for the clients that stream
 * (`RunsClient`, `ThreadsClient`).
 *
 * It is a trait because `BaseClient` is owned by another work package; upstream puts the method on the
 * base class. The host must extend {@see BaseClient}.
 *
 * ## Transport
 *
 * When the transport is a {@see StreamingMethodHttpClient} (the default here is
 * {@see GuzzleStreamingMethodHttpClient}) the body is read incrementally and a half-read stream can
 * be resumed. Any other transport is used through the buffered path (`callerOptions.fetch`, else
 * `request()` / `post()`): the whole body arrives as one chunk. That parses identically but proves
 * nothing about incremental delivery.
 *
 * ## Test seams
 *
 * `callerOptions.sleep` (also AsyncCaller's) paces the reconnect backoff; `callerOptions.clock`
 * (`callable(): int|float` ms) drives the idle watchdog.
 */
trait StreamsWithRetry
{
    /** @var (callable(int): void)|null */
    private $streamSleep = null;

    /** @var (callable(): (int|float))|null */
    private $streamClock = null;

    /**
     * @param array<string, mixed> $config See {@see BaseClient}.
     */
    public function __construct(array $config = [], ?HttpClient $http = null)
    {
        parent::__construct($config, $http ?? new GuzzleStreamingMethodHttpClient());

        $this->streamSleep = $config['callerOptions']['sleep'] ?? null;
        $this->streamClock = $config['callerOptions']['clock'] ?? null;
    }

    /**
     * Open an SSE endpoint and yield its parts `{id, event, data}`, reconnecting from the last event id.
     *
     * Config: `endpoint`, `method` (default GET), `signal` `callable(): bool`, `headers`, `params`,
     * `json`, `maxRetries`, `idleReconnect` (int ms | 'auto' | 0, see {@see StreamRetry}),
     * `onReconnect`, `onInitialResponse` `callable(HttpResponse): void`.
     *
     * @param array<string, mixed> $config
     *
     * @return \Generator<int, array{id: string|null, event: string, data: mixed}>
     */
    protected function streamWithRetry(array $config): \Generator
    {
        $makeRequest = function (?array $reconnect) use ($config): array {
            $isReconnect = $reconnect !== null && ($reconnect['reconnectPath'] ?? '') !== '';
            $endpoint = $isReconnect ? $reconnect['reconnectPath'] : $config['endpoint'];
            $method = $isReconnect ? 'GET' : ($config['method'] ?? 'GET');

            $headers = $config['headers'] ?? null;
            if ($isReconnect && ($reconnect['lastEventId'] ?? null) !== null) {
                $headers = array_merge($headers ?? [], ['Last-Event-ID' => $reconnect['lastEventId']]);
            }

            [$url, $init] = $this->prepareFetchOptions($endpoint, [
                'method' => $method,
                'timeoutMs' => null,
                'signal' => $config['signal'] ?? null,
                'headers' => $headers,
                'params' => $config['params'] ?? null,
                'json' => $isReconnect ? null : ($config['json'] ?? null),
            ]);

            if ($this->onRequest !== null) {
                $init = ($this->onRequest)($url, $init);
            }

            $opened = $this->openStream($url, $init);
            $response = $opened->response;

            if ($response->status === 202 || $response->status === 204) {
                throw new \RuntimeException('Expected response body from stream endpoint');
            }

            if (!$isReconnect && isset($config['onInitialResponse'])) {
                ($config['onInitialResponse'])($response);
            }

            // The idle watchdog sits on the LINE stream, between the byte-line decoder and the SSE
            // decoder, so it resets on any line and can recognise `:` heartbeats for "auto".
            $idleMode = $config['idleReconnect'] ?? StreamRetry::DEFAULT_IDLE_RECONNECT;
            $lines = self::lineStream($opened->chunks);
            if ($idleMode === 'auto' || (is_int($idleMode) && $idleMode > 0)) {
                $lines = StreamRetry::idleReconnectStream($lines, ['mode' => $idleMode] + ($this->streamClock !== null ? ['clock' => $this->streamClock] : []));
            }

            return ['response' => $response, 'stream' => self::partStream($lines)];
        };

        return StreamRetry::streamWithRetry($makeRequest, [
            'maxRetries' => $config['maxRetries'] ?? Reconnect::DEFAULT_MAX_RECONNECT_ATTEMPTS,
            'signal' => $config['signal'] ?? null,
            'onReconnect' => $config['onReconnect'] ?? null,
        ] + ($this->streamSleep !== null ? ['sleep' => $this->streamSleep] : []));
    }

    /**
     * @param array<string, mixed> $init
     */
    private function openStream(string $url, array $init): StreamResponse
    {
        if (!$this->http instanceof StreamingMethodHttpClient) {
            $response = $this->asyncCaller->fetch($url, $init);

            return new StreamResponse($response, [$response->body]);
        }

        $http = $this->http;

        return $this->asyncCaller->call(static function () use ($http, $url, $init): StreamResponse {
            $timeout = isset($init['timeoutMs']) ? $init['timeoutMs'] / 1000 : null;
            $opened = $http->requestStream(strtoupper($init['method'] ?? 'GET'), $url, $init['headers'] ?? [], $init['body'] ?? null, $timeout);

            if (!$opened->response->isOk()) {
                $body = '';
                foreach ($opened->chunks as $chunk) {
                    $body .= $chunk ?? '';
                }

                throw HttpError::fromResponse(new HttpResponse($opened->response->status, $opened->response->headers(), $body), true);
            }

            return $opened;
        });
    }

    /**
     * @param iterable<string|null> $chunks
     *
     * @return \Generator<int, string|null>
     */
    private static function lineStream(iterable $chunks): \Generator
    {
        $decoder = new BytesLineDecoder();
        foreach ($chunks as $chunk) {
            if ($chunk === null) {
                yield null;

                continue;
            }
            yield from $decoder->feed($chunk);
        }
        yield from $decoder->flush();
    }

    /**
     * @param iterable<string|null> $lines
     *
     * @return \Generator<int, array{id: string|null, event: string, data: mixed}>
     */
    private static function partStream(iterable $lines): \Generator
    {
        $decoder = new SseDecoder();
        foreach ($lines as $line) {
            $part = $line === null ? null : $decoder->feed($line);
            if ($part !== null) {
                yield $part;
            }
        }
        $last = $decoder->flush();
        if ($last !== null) {
            yield $last;
        }
    }
}
