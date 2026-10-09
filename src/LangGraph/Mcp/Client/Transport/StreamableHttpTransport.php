<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Client\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use LangGraph\Mcp\Client\JsonRpc;
use LangGraph\Mcp\McpClientError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * MCP "Streamable HTTP" client transport (`StreamableHTTPClientTransport`).
 *
 * Every client message is a POST accepting `application/json` or `text/event-stream`; the
 * answer is either one JSON body or an SSE stream read lazily as {@see self::receive()} pulls it.
 * The `Mcp-Session-Id` the server assigns is echoed on later requests and released with a DELETE
 * on {@see self::close()}. A stream that ends before its response arrived is resumed with a GET
 * carrying `Last-Event-ID`, up to `reconnect.maxRetries` times with exponential backoff.
 *
 * The optional standalone server-to-client GET stream is not opened.
 *
 * Config: `url`, `headers`, `reconnect` (`maxRetries` 2, `initialReconnectionDelay` 1000,
 * `maxReconnectionDelay` 30000, `reconnectionDelayGrowFactor` 1.5), `client` (a Guzzle client).
 */
final class StreamableHttpTransport implements TransportInterface
{
    private const MAX_ERROR_BODY_BYTES = 2000;

    private ClientInterface $http;

    private ?string $sessionId = null;

    private ?string $protocolVersion = null;

    private ?float $requestTimeout = null;

    private bool $started = false;

    /** @var list<array<string, mixed>> */
    private array $inbox = [];

    private ?StreamInterface $stream = null;

    private ?EventStreamParser $parser = null;

    private int|string|null $awaitedId = null;

    private int $reconnectAttempts = 0;

    /** @var array<string, string> */
    private array $headers;

    /** @var array{maxRetries: int, initialReconnectionDelay: int, maxReconnectionDelay: int, reconnectionDelayGrowFactor: float} */
    private array $reconnect;

    /**
     * @param array{url: string, headers?: array<string, string>, reconnect?: array<string, int|float>, client?: ClientInterface} $config
     */
    public function __construct(private readonly array $config)
    {
        if (!isset($config['url']) || $config['url'] === '') {
            throw new McpClientError('The streamable HTTP transport needs a "url".');
        }
        $this->http = $config['client'] ?? new Client();
        $this->headers = $config['headers'] ?? [];
        $reconnect = $config['reconnect'] ?? [];
        $this->reconnect = [
            'maxRetries' => (int) ($reconnect['maxRetries'] ?? 2),
            'initialReconnectionDelay' => (int) ($reconnect['initialReconnectionDelay'] ?? 1000),
            'maxReconnectionDelay' => (int) ($reconnect['maxReconnectionDelay'] ?? 30000),
            'reconnectionDelayGrowFactor' => (float) ($reconnect['reconnectionDelayGrowFactor'] ?? 1.5),
        ];
    }

    public function start(): void
    {
        if ($this->started) {
            throw new McpClientError('The streamable HTTP transport is already started.');
        }
        $this->started = true;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setRequestTimeout(float $seconds): void
    {
        $this->requestTimeout = $seconds;
    }

    public function setProtocolVersion(string $version): void
    {
        $this->protocolVersion = $version;
    }

    public function withHeaders(array $headers): TransportInterface
    {
        $config = $this->config;
        $config['headers'] = [...$this->headers, ...$headers];
        $config['client'] = $this->http;

        return new self($config);
    }

    public function send(array $message): void
    {
        $this->assertStarted();
        $this->dropStream();

        $response = $this->request('POST', [
            'headers' => $this->requestHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream']),
            'body' => JsonRpc::encode($message),
        ]);

        $expectsResponse = JsonRpc::isRequest($message);
        $session = $response->getHeaderLine('mcp-session-id');
        if ($session !== '') {
            $this->sessionId = $session;
        }

        if ($response->getStatusCode() === 202) {
            $response->getBody()->close();
            if ($expectsResponse) {
                throw new McpClientError('The MCP server accepted a request without answering it (HTTP 202).');
            }

            return;
        }

        $type = strtolower($response->getHeaderLine('content-type'));
        if (str_starts_with($type, 'text/event-stream')) {
            $this->openStream($response->getBody(), $expectsResponse ? $message['id'] : null);

            return;
        }

        $body = (string) $response->getBody();
        if (trim($body) === '') {
            if ($expectsResponse) {
                throw new McpClientError('The MCP server returned an empty body for a request.');
            }

            return;
        }
        if (!str_starts_with($type, 'application/json')) {
            throw new McpClientError("Unexpected content type from the MCP server: \"{$type}\".");
        }
        $this->queueJson($body);
    }

    public function receive(float $timeoutSeconds): ?array
    {
        $this->assertStarted();
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            if ($this->inbox !== []) {
                return array_shift($this->inbox);
            }
            if ($this->stream === null) {
                throw new McpClientError('No response is in flight on the streamable HTTP transport.');
            }
            if (microtime(true) >= $deadline) {
                return null;
            }

            $chunk = $this->stream->eof() ? '' : $this->stream->read(8192);
            if ($chunk !== '') {
                foreach ($this->parser?->feed($chunk) ?? [] as $event) {
                    $this->handleEvent($event);
                }
                continue;
            }

            if ($this->stream->eof()) {
                $this->streamEnded();
                continue;
            }
            usleep(5_000);
        }
    }

    public function close(): void
    {
        $this->dropStream();
        $this->inbox = [];
        if (!$this->started) {
            return;
        }
        $this->started = false;

        if ($this->sessionId !== null) {
            try {
                $this->request('DELETE', ['headers' => $this->requestHeaders()], false)->getBody()->close();
            } catch (McpClientError) {
                // A server may refuse termination (405) or already have dropped the session.
            }
        }
        $this->sessionId = null;
    }

    /** @param array{event: string, data: string, id: string|null} $event */
    private function handleEvent(array $event): void
    {
        if ($event['event'] !== 'message' || trim($event['data']) === '') {
            return;
        }
        $this->queueJson($event['data']);
    }

    private function queueJson(string $json): void
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new McpClientError('The MCP server sent malformed JSON: ' . $e->getMessage(), null, $e);
        }
        if (!is_array($decoded)) {
            throw new McpClientError('The MCP server sent a JSON value that is not a message.');
        }

        foreach (array_is_list($decoded) ? $decoded : [$decoded] as $message) {
            if (is_array($message) && JsonRpc::isResponse($message) && $message['id'] === $this->awaitedId) {
                $this->awaitedId = null;
            }
            $this->inbox[] = $message;
        }
    }

    private function openStream(StreamInterface $body, int|string|null $awaitedId): void
    {
        $this->stream = $body;
        $this->parser = new EventStreamParser();
        $this->awaitedId = $awaitedId;
        $this->reconnectAttempts = 0;
    }

    /** The stream hit EOF: finish, resume, or fail depending on whether a response is still owed. */
    private function streamEnded(): void
    {
        $lastEventId = $this->parser?->lastEventId();
        $owed = $this->awaitedId !== null;
        $this->dropStream(keepAwaited: true);

        if (!$owed) {
            return;
        }
        if ($lastEventId === null || $this->reconnectAttempts >= $this->reconnect['maxRetries']) {
            $this->awaitedId = null;

            throw new McpClientError('The MCP response stream ended before the response arrived.');
        }

        $delay = min(
            $this->reconnect['initialReconnectionDelay'] * ($this->reconnect['reconnectionDelayGrowFactor'] ** $this->reconnectAttempts),
            $this->reconnect['maxReconnectionDelay'],
        );
        $attempt = ++$this->reconnectAttempts;
        usleep((int) ($delay * 1000));

        $response = $this->request('GET', [
            'headers' => $this->requestHeaders(['Accept' => 'text/event-stream', 'Last-Event-ID' => $lastEventId]),
        ]);
        $awaited = $this->awaitedId;
        $this->openStream($response->getBody(), $awaited);
        $this->reconnectAttempts = $attempt;
    }

    private function dropStream(bool $keepAwaited = false): void
    {
        $this->stream?->close();
        $this->stream = null;
        $this->parser = null;
        if (!$keepAwaited) {
            $this->awaitedId = null;
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws McpClientError
     */
    private function request(string $method, array $options, bool $stream = true): ResponseInterface
    {
        try {
            $response = $this->http->request($method, $this->config['url'], [
                ...$options,
                'http_errors' => false,
                'stream' => $stream,
                'read_timeout' => 1,
                ...($this->requestTimeout === null ? [] : ['timeout' => $this->requestTimeout]),
            ]);
        } catch (GuzzleException $e) {
            throw new McpClientError("MCP HTTP request failed: {$e->getMessage()}", null, $e);
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $body = substr($response->getBody()->read(self::MAX_ERROR_BODY_BYTES), 0, self::MAX_ERROR_BODY_BYTES);
            $response->getBody()->close();
            $detail = "Error {$method}ing to endpoint";
            throw new HttpStatusException($status, trim($detail . ($body === '' ? '' : ': ' . $body)));
        }

        return $response;
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function requestHeaders(array $extra = []): array
    {
        $headers = [...$this->headers, ...$extra];
        if ($this->sessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }
        if ($this->protocolVersion !== null) {
            $headers['MCP-Protocol-Version'] = $this->protocolVersion;
        }

        return $headers;
    }

    private function assertStarted(): void
    {
        if (!$this->started) {
            throw new McpClientError('The streamable HTTP transport is not started.');
        }
    }
}
