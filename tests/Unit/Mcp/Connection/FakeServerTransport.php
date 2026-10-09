<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp\Connection;

use LangGraph\Mcp\Client\JsonRpc;
use LangGraph\Mcp\Client\Transport\TransportInterface;
use LangGraph\Mcp\McpClientError;

/**
 * A scripted MCP server behind the transport seam: the stand-in for the mocked SDK client and
 * transport classes upstream's connection and client tests build with `vi.mock`.
 *
 * It answers `initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read` and
 * `logging/setLevel` from public fields a test sets, and records everything for assertions.
 * Breaking it on purpose: `$failOn` throws from `send()` for a method, `$hang` swallows a method
 * so the client waits out its timeout, `$closeError` makes `close()` fail, and `$onRequest` runs
 * a side effect (such as closing the adapter) when a request arrives.
 */
final class FakeServerTransport implements TransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $tools = [];

    /** @var array<string, callable(array<string, mixed>, array<string, mixed>): array<string, mixed>> */
    public array $callHandlers = [];

    /** @var list<array<string, mixed>> */
    public array $resources = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $resourceContents = [];

    public string $version = '2025-06-18';

    /** @var array<string, mixed> */
    public array $capabilities = ['tools' => [], 'resources' => []];

    /** @var array<string, \Throwable> */
    public array $failOn = [];

    /** @var list<string> */
    public array $hang = [];

    public ?\Throwable $closeError = null;

    public ?\Throwable $startError = null;

    /** @var (callable(): void)|null runs when the transport closes for the first time */
    public $onClose = null;

    /** @var (callable(string, array<string, mixed>): void)|null */
    public $onRequest = null;

    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /** @var array<string, string> */
    public array $headers = [];

    public int $starts = 0;

    /** Effective closes: a transport closes once, however often it is told to. */
    public int $closes = 0;

    public bool $broken = false;

    /** @var list<array<string, mixed>> */
    private array $outbox = [];

    /** @var list<float> every request timeout the client set, in seconds */
    public array $requestTimeouts = [];

    /**
     * @param array<string, mixed> $options the connection options the factory was asked to build for
     */
    public function __construct(public readonly string $type = 'stdio', public readonly array $options = [])
    {
        $this->tools = [
            ['name' => 'tool1', 'description' => 'Tool 1', 'inputSchema' => []],
            ['name' => 'tool2', 'description' => 'Tool 2', 'inputSchema' => []],
        ];
    }

    public function start(): void
    {
        ++$this->starts;
        if ($this->startError !== null) {
            throw $this->startError;
        }
    }

    public function send(array $message): void
    {
        if ($this->broken) {
            throw new McpClientError('The channel is broken');
        }
        $this->sent[] = $message;
        if (!JsonRpc::isRequest($message)) {
            return;
        }

        $method = (string) $message['method'];
        $params = (array) ($message['params'] ?? []);
        if ($this->onRequest !== null) {
            ($this->onRequest)($method, $params);
        }
        if (isset($this->failOn[$method])) {
            throw $this->failOn[$method];
        }
        if (in_array($method, $this->hang, true)) {
            return;
        }

        $this->outbox[] = $this->answer($message['id'], $method, $params);
    }

    /** Queue a server notification (or any message) to arrive before the next answer. */
    public function queue(array $message): void
    {
        $this->outbox[] = $message;
    }

    public function receive(float $timeoutSeconds): ?array
    {
        if ($this->broken) {
            throw new McpClientError('The channel is broken');
        }
        if ($this->outbox !== []) {
            return array_shift($this->outbox);
        }
        usleep(1000);

        return null;
    }

    public function setRequestTimeout(float $seconds): void
    {
        $this->requestTimeouts[] = $seconds;
    }

    public function setProtocolVersion(string $version): void
    {
    }

    public function withHeaders(array $headers): TransportInterface
    {
        $fork = new self($this->type, $this->options);
        $fork->headers = [...$this->headers, ...$headers];

        return $fork;
    }

    public function close(): void
    {
        if ($this->closes > 0) {
            return;
        }

        ++$this->closes;
        if ($this->onClose !== null) {
            ($this->onClose)();
        }
        if ($this->closeError !== null) {
            throw $this->closeError;
        }
    }

    /** @return list<string> the methods of every request received, in order */
    public function methods(): array
    {
        return array_values(array_map(
            static fn (array $message): string => (string) $message['method'],
            array_filter($this->sent, static fn (array $message): bool => isset($message['method'])),
        ));
    }

    /** The `params` of the last request for `$method`. */
    public function lastParams(string $method): ?array
    {
        $found = null;
        foreach ($this->sent as $message) {
            if (($message['method'] ?? null) === $method) {
                $found = (array) ($message['params'] ?? []);
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function answer(int|string $id, string $method, array $params): array
    {
        switch ($method) {
            case 'initialize':
                return JsonRpc::response($id, [
                    'protocolVersion' => $this->version,
                    'capabilities' => $this->capabilities,
                    'serverInfo' => ['name' => 'fake', 'version' => '1.0.0'],
                ]);
            case 'tools/list':
                return JsonRpc::response($id, ['tools' => $this->tools]);
            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
                $arguments = (array) ($params['arguments'] ?? []);
                $handler = $this->callHandlers[$name] ?? null;

                return JsonRpc::response($id, $handler !== null
                    ? $handler($arguments, $params)
                    : ['content' => [['type' => 'text', 'text' => "{$name} result"]]]);
            case 'resources/list':
                return JsonRpc::response($id, ['resources' => $this->resources]);
            case 'resources/read':
                $uri = (string) ($params['uri'] ?? '');
                if (!isset($this->resourceContents[$uri])) {
                    return JsonRpc::errorResponse($id, -32002, "Resource not found: {$uri}");
                }

                return JsonRpc::response($id, ['contents' => $this->resourceContents[$uri]]);
            case 'logging/setLevel':
                return JsonRpc::response($id, []);
        }

        return JsonRpc::errorResponse($id, JsonRpc::METHOD_NOT_FOUND, "Method not found: {$method}");
    }
}
