<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Stream;

use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Sdk\Utils\BytesLineDecoder;
use LangGraph\Sdk\Utils\MethodHttpClient;
use LangGraph\Sdk\Utils\Reconnect;
use LangGraph\Sdk\Utils\SseDecoder;
use LangGraph\Sdk\Utils\StreamingMethodHttpClient;
use LangGraph\Sdk\Utils\StreamResponse;
use LangGraph\Sdk\Utils\StreamIdleTimeoutError;

/**
 * HTTP commands plus a filtered SSE connection, from stream/transport/http.ts.
 * Streaming requests bypass AsyncCaller; this reconnect loop owns resilience.
 * Null ticks mark byte-chunk boundaries so PHP can settle terminal projections
 * after trailing events already present in the same network chunk. The first
 * tick signals validated response headers. Idle reconnect is checked on lines
 * and transport null ticks; synchronous PHP cannot interrupt a blocking read.
 */
final class ProtocolSseTransport
{
    private bool $closed = false;

    public function __construct(
        public readonly string $threadId,
        private readonly HttpClient $http,
        private readonly \Closure $prepare,
        private readonly \Closure $fetch,
        private readonly mixed $onRequest = null,
        private readonly array $options = [],
    ) {
    }

    public function send(array $command): array
    {
        $this->assertOpen();
        $path = "/threads/{$this->threadId}/commands";
        $options = ['method' => 'POST', 'json' => $command, 'timeoutMs' => null];
        if (isset($this->options['fetch'])) {
            [$url, $init] = $this->requestInit($path, $options);
            $response = ($this->options['fetch'])($url, $init);
            if (!$response instanceof HttpResponse) {
                throw new \UnexpectedValueException('Protocol fetch must return HttpResponse for commands.');
            }
            if (!$response->isOk()) {
                throw new \RuntimeException('Protocol request failed: ' . $response->status . ' ' . $response->body);
            }
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } else {
            $payload = ($this->fetch)($path, $options);
        }
        if (!is_array($payload) || !in_array($payload['type'] ?? null, ['response', 'error'], true)) {
            throw new \UnexpectedValueException('Protocol command did not return a valid response.');
        }
        if ($payload['type'] === 'error') {
            throw new ProtocolError($payload);
        }

        return $payload;
    }

    /**
     * Pull-based counterpart of openEventStream(). No background connection.
     * `since` is retained before the first successful open, then omitted on
     * reconnect because seq is connection-local. event_id dedup lives above.
     *
     * @return \Generator<int, array<string, mixed>|null>
     */
    public function events(array $params, callable $cancelled): \Generator
    {
        $ready = false;
        $attempt = 0;
        $received = false;
        $max = $this->options['maxReconnectAttempts'] ?? Reconnect::DEFAULT_MAX_RECONNECT_ATTEMPTS;
        while (!$this->closed && !$cancelled()) {
            $finalSignal = null;
            try {
                $body = $params;
                if ($ready) {
                    unset($body['since']);
                }
                [$url, $init] = $this->requestInit("/threads/{$this->threadId}/stream/events", [
                    'method' => 'POST', 'json' => $body, 'headers' => ['accept' => 'text/event-stream'],
                    'timeoutMs' => null, 'signal' => $cancelled,
                ]);
                $finalSignal = $init['signal'] ?? null;
                self::checkSignal($finalSignal);
                $opened = $this->open($url, $init);
                if (!$opened->response->isOk()) {
                    $errorBody = '';
                    foreach ($opened->chunks as $chunk) {
                        $errorBody .= $chunk ?? '';
                    }
                    throw new \RuntimeException('Protocol request failed: ' . $opened->response->status . ' ' . $errorBody);
                }
                $contentType = $opened->response->header('content-type');
                if ($contentType !== null && !str_contains($contentType, 'text/event-stream')) {
                    throw new \RuntimeException("Expected response header Content-Type to contain 'text/event-stream', got '{$contentType}'");
                }
                if (isset($this->options['onConnected'])) {
                    ($this->options['onConnected'])(['kind' => $attempt === 0 ? 'initial' : 'reconnected', 'attempt' => $attempt]);
                }
                $ready = true;
                // Expose ready before reading bytes, allowing safe shared-stream rotation.
                yield null;
                $clock = $this->options['clock'] ?? static fn (): float => hrtime(true) / 1_000_000;
                $mode = $this->options['streamIdleReconnect'] ?? 'auto';
                $timeout = is_int($mode) && $mode > 0 ? $mode : null;
                $lastActivity = $clock();
                $lastHeartbeat = null;
                $observeLine = static function (?string $line) use ($clock, $mode, &$timeout, &$lastActivity, &$lastHeartbeat): void {
                    $now = $clock();
                    if ($timeout !== null && $now - $lastActivity >= $timeout) {
                        throw new StreamIdleTimeoutError($timeout);
                    }
                    if ($line === null) {
                        return;
                    }
                    if ($mode === 'auto' && str_starts_with($line, ':')) {
                        if ($lastHeartbeat !== null && $now > $lastHeartbeat) {
                            $candidate = (int) min(max(($now - $lastHeartbeat) * 3, 6000), 30000);
                            $timeout = max($timeout ?? 0, $candidate);
                        }
                        $lastHeartbeat = $now;
                    }
                    $lastActivity = $now;
                };
                $lines = new BytesLineDecoder();
                $sse = new SseDecoder();
                $chunks = $opened->chunks instanceof \Iterator ? $opened->chunks : new \IteratorIterator(
                    is_array($opened->chunks) ? new \ArrayIterator($opened->chunks) : $opened->chunks,
                );
                $started = false;
                while (true) {
                    // Poll before advancing the body; cancellation must not read a future chunk.
                    if ($this->closed || $cancelled()) {
                        return;
                    }
                    self::checkSignal($finalSignal);
                    if ($started) {
                        $chunks->next();
                    } else {
                        $chunks->rewind();
                        $started = true;
                    }
                    if (!$chunks->valid()) {
                        break;
                    }
                    $chunk = $chunks->current();
                    if ($chunk !== null) {
                        foreach ($lines->feed($chunk) as $line) {
                            $observeLine($line);
                            $part = $sse->feed($line);
                            if (is_array($part['data'] ?? null)) {
                                $received = true;
                                yield $part['data'];
                            }
                        }
                    } else {
                        $observeLine(null);
                    }
                    yield null;
                }
                foreach ($lines->flush() as $line) {
                    $observeLine($line);
                    $part = $sse->feed($line);
                    if (is_array($part['data'] ?? null)) {
                        $received = true;
                        yield $part['data'];
                    }
                }
                $part = $sse->flush();
                if (is_array($part['data'] ?? null)) {
                    $received = true;
                    yield $part['data'];
                }
                if ($max <= 0 || $this->closed || $cancelled()) {
                    return;
                }
                throw new \RuntimeException('Event stream closed by the server');
            } catch (\Throwable $error) {
                if ($this->closed || $cancelled()) {
                    return;
                }
                self::checkSignal($finalSignal);
                if ($received) {
                    $attempt = 0;
                    $received = false;
                }
                if ($max <= 0 || ++$attempt > $max) {
                    throw $error;
                }
                $delay = isset($this->options['reconnectDelayMs'])
                    ? ($this->options['reconnectDelayMs'])($attempt) : Reconnect::reconnectDelayMs($attempt);
                if (isset($this->options['onReconnect'])) {
                    ($this->options['onReconnect'])(['attempt' => $attempt, 'cause' => $error, 'delayMs' => $delay]);
                }
                if ($delay > 0) {
                    usleep((int) ($delay * 1000));
                }
            }
        }
    }

    private function requestInit(string $path, array $options): array
    {
        $this->assertOpen();
        [$url, $init] = ($this->prepare)($path, $options);
        if ($this->onRequest !== null) {
            $init = ($this->onRequest)($url, $init);
        }
        self::checkSignal($init['signal'] ?? null);

        return [$url, $init];
    }

    private function open(string $url, array $init): StreamResponse
    {
        if (isset($this->options['fetch'])) {
            $response = ($this->options['fetch'])($url, $init);
        } elseif ($this->http instanceof StreamingMethodHttpClient) {
            return $this->http->requestStream($init['method'] ?? 'POST', $url, $init['headers'] ?? [], $init['body'] ?? null, null);
        } elseif ($this->http instanceof MethodHttpClient) {
            $response = $this->http->request($init['method'] ?? 'POST', $url, $init['headers'] ?? [], $init['body'] ?? null, null);
        } else {
            $response = $this->http->post($url, $init['headers'] ?? [], $init['body'] ?? '');
        }
        if ($response instanceof StreamResponse) {
            return $response;
        }
        if (!$response instanceof HttpResponse) {
            throw new \UnexpectedValueException('Protocol fetch must return HttpResponse or StreamResponse.');
        }

        return new StreamResponse($response, [$response->body]);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('ThreadStream closed');
        }
    }

    private static function checkSignal(mixed $signal): void
    {
        if (is_callable($signal) && $signal()) {
            throw new \RuntimeException('AbortError');
        }
    }
}
