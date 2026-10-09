<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Client;

use LangGraph\Agents\Nodes\Utils as AbortUtils;
use LangGraph\Mcp\Client\Transport\TransportInterface;
use LangGraph\Mcp\ElicitationCapableClientInterface;
use LangGraph\Mcp\ForkableMcpClientInterface;
use LangGraph\Mcp\JsonSchemaValidator;
use LangGraph\Mcp\McpClientError;
use LangGraph\Mcp\McpClientInterface;

/**
 * An MCP client over a pull-based JSON-RPC transport.
 *
 * Upstream leans on `Client` from `@modelcontextprotocol/client`; this covers the surface the
 * adapter calls on it: the `initialize` handshake with protocol-era negotiation, `tools/list`,
 * `tools/call`, resources, logging level, the `elicitation/create` request handler, and sibling
 * connections carrying other headers.
 *
 * Options: `mode` (`auto` offers the modern revision and accepts an older answer, `modern` and
 * `legacy` pin an era), `clientInfo`, `requestTimeoutMs` (default 60000), `onNotification`
 * (`callable(string $method, array $params): void`).
 */
final class McpClient implements McpClientInterface, ElicitationCapableClientInterface, ForkableMcpClientInterface
{
    public const DEFAULT_TIMEOUT_MS = 60000;

    private const POLL_SECONDS = 0.1;

    private bool $connected = false;

    private int $nextId = 0;

    private string $era = ProtocolEra::LEGACY;

    private ?string $protocolVersion = null;

    /** @var array<string, mixed>|null */
    private ?array $serverCapabilities = null;

    /** @var array<string, mixed>|null */
    private ?array $serverInfo = null;

    private ?string $instructions = null;

    /** @var (callable(array<string, mixed>): array<string, mixed>)|null */
    private $elicitationHandler = null;

    /** @var array<string, callable(array<string, mixed>): void> */
    private array $notificationHandlers = [];

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array{mode?: 'auto'|'modern'|'legacy', clientInfo?: array{name: string, version: string}, requestTimeoutMs?: int|float, onNotification?: callable} $options
     */
    public function __construct(private readonly TransportInterface $transport, array $options = [])
    {
        $mode = $options['mode'] ?? 'auto';
        if (!in_array($mode, ['auto', 'modern', 'legacy'], true)) {
            throw new McpClientError("Invalid MCP protocol mode \"{$mode}\".");
        }
        $this->options = $options + ['mode' => 'auto'];
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Open the transport and run the `initialize` handshake. Install the elicitation handler first
     * so the declared capabilities and the handler agree.
     */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $this->transport->start();
        try {
            $this->handshake();
        } catch (\Throwable $e) {
            $this->transport->close();

            throw $e;
        }
        $this->connected = true;
    }

    public function close(): void
    {
        $this->connected = false;
        $this->transport->close();
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function listTools(): array
    {
        return ['tools' => $this->paginate('tools/list', 'tools')];
    }

    public function callTool(string $name, array $arguments, array $options = []): array
    {
        $this->connect();

        $params = ['name' => $name, 'arguments' => $arguments === [] ? new \stdClass() : $arguments];
        $meta = (array) ($options['_meta'] ?? []);
        $onProgress = $options['onprogress'] ?? null;
        $token = null;
        if (is_callable($onProgress)) {
            $token = $this->nextId + 1;
            $meta['progressToken'] = $token;
        }
        if ($meta !== []) {
            $params['_meta'] = $meta;
        }
        if ($this->era === ProtocolEra::MODERN) {
            foreach (['inputResponses', 'requestState'] as $retry) {
                if (isset($options[$retry])) {
                    $params[$retry] = $options[$retry];
                }
            }
        }

        $result = $this->request('tools/call', $params, [
            'timeout' => $options['timeout'] ?? null,
            'signal' => $options['signal'] ?? null,
            'progressToken' => $token,
            'onprogress' => $onProgress,
        ]);

        if (($result['resultType'] ?? null) === 'input_required') {
            if (empty($options['allowInputRequired'])) {
                throw new McpClientError("MCP tool \"{$name}\" asked for input, which this call did not allow.");
            }

            return $result;
        }

        $definition = $options['toolDefinition'] ?? null;
        if (is_array($definition) && isset($definition['outputSchema']) && ($result['isError'] ?? false) !== true) {
            $this->validateStructuredOutput($name, $result, $definition['outputSchema']);
        }

        return $result;
    }

    /** @return array{resources: list<array<string, mixed>>} */
    public function listResources(): array
    {
        return ['resources' => $this->paginate('resources/list', 'resources')];
    }

    /** @return array<string, mixed> a `ReadResourceResult` */
    public function readResource(string $uri): array
    {
        $this->connect();

        return $this->request('resources/read', ['uri' => $uri]);
    }

    /** `logging/setLevel` is legacy-only; modern requests carry the level in their metadata. */
    public function setLoggingLevel(string $level): void
    {
        $this->connect();
        if ($this->era === ProtocolEra::MODERN) {
            throw new McpClientError('setLoggingLevel is legacy-only; configure logLevel for modern tool requests');
        }

        $this->request('logging/setLevel', ['level' => $level]);
    }

    public function getServerCapabilities(): ?array
    {
        $this->connect();

        return $this->serverCapabilities;
    }

    /** @return array<string, mixed>|null */
    public function getServerVersion(): ?array
    {
        $this->connect();

        return $this->serverInfo;
    }

    public function getInstructions(): ?string
    {
        $this->connect();

        return $this->instructions;
    }

    public function getProtocolEra(): string
    {
        $this->connect();

        return $this->era;
    }

    public function getProtocolVersion(): ?string
    {
        $this->connect();

        return $this->protocolVersion;
    }

    public function setElicitationHandler(callable $handler): void
    {
        $this->elicitationHandler = $handler;
    }

    /** @param callable(array<string, mixed>): void $handler a handler for server notification `$method` */
    public function setNotificationHandler(string $method, callable $handler): void
    {
        $this->notificationHandlers[$method] = $handler;
    }

    public function fork(array $headers): McpClientInterface
    {
        $forked = new self($this->transport->withHeaders($headers), $this->options);
        $forked->elicitationHandler = $this->elicitationHandler;
        $forked->notificationHandlers = $this->notificationHandlers;
        $forked->connect();

        return $forked;
    }

    private function handshake(): void
    {
        $mode = $this->options['mode'];
        $offered = $mode === 'legacy' ? ProtocolEra::LEGACY_VERSIONS[0] : ProtocolEra::MODERN_VERSION;
        $capabilities = [];
        if ($this->elicitationHandler !== null && $mode !== 'modern') {
            $capabilities['elicitation'] = ['form' => new \stdClass(), 'url' => new \stdClass()];
        }

        $result = $this->request('initialize', [
            'protocolVersion' => $offered,
            'capabilities' => $capabilities === [] ? new \stdClass() : $capabilities,
            'clientInfo' => $this->options['clientInfo'] ?? ['name' => 'langchain-php-mcp', 'version' => '1.0.0'],
        ]);

        $version = (string) ($result['protocolVersion'] ?? '');
        $era = ProtocolEra::forVersion($version);
        if ($era === null) {
            throw new McpClientError("MCP server answered with unsupported protocol version \"{$version}\".");
        }
        if ($mode !== 'auto' && $era !== $mode) {
            throw new McpClientError("MCP server negotiated the {$era} protocol ({$version}) but this connection is pinned to {$mode}.");
        }

        $this->era = $era;
        $this->protocolVersion = $version;
        $this->serverCapabilities = is_array($result['capabilities'] ?? null) ? $result['capabilities'] : [];
        $this->serverInfo = is_array($result['serverInfo'] ?? null) ? $result['serverInfo'] : null;
        $this->instructions = is_string($result['instructions'] ?? null) ? $result['instructions'] : null;

        $this->transport->setProtocolVersion($version);
        $this->transport->send(JsonRpc::notification('notifications/initialized'));
    }

    /**
     * Follow `nextCursor` until the listing is exhausted.
     *
     * @return list<array<string, mixed>>
     */
    private function paginate(string $method, string $key): array
    {
        $this->connect();

        $items = [];
        $cursor = null;
        do {
            $page = $this->request($method, $cursor === null ? [] : ['cursor' => $cursor]);
            array_push($items, ...array_values((array) ($page[$key] ?? [])));
            $cursor = is_string($page['nextCursor'] ?? null) && $page['nextCursor'] !== '' ? $page['nextCursor'] : null;
        } while ($cursor !== null);

        return $items;
    }

    /**
     * @param array<string, mixed> $params
     * @param array{timeout?: int|float|null, signal?: mixed, progressToken?: int|string|null, onprogress?: mixed} $extra
     *
     * @return array<string, mixed> the response `result`
     */
    private function request(string $method, array $params, array $extra = []): array
    {
        $id = ++$this->nextId;
        $this->transport->send(JsonRpc::request($id, $method, $params));

        $timeoutMs = $extra['timeout'] ?? $this->options['requestTimeoutMs'] ?? self::DEFAULT_TIMEOUT_MS;
        $signal = $extra['signal'] ?? null;
        $deadline = microtime(true) + $timeoutMs / 1000;

        while (true) {
            if ($signal !== null && AbortUtils::isAborted($signal)) {
                $this->cancel($id, 'aborted');

                throw new McpClientError("MCP request \"{$method}\" was aborted.");
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $this->cancel($id, 'timeout');

                throw new McpClientError("MCP request \"{$method}\" timed out after {$timeoutMs}ms.");
            }

            $message = $this->transport->receive($signal === null ? $remaining : min($remaining, self::POLL_SECONDS));
            if ($message === null) {
                continue;
            }

            if (JsonRpc::isResponse($message)) {
                if ($message['id'] !== $id) {
                    continue;
                }
                if (isset($message['error'])) {
                    $error = (array) $message['error'];

                    throw new JsonRpcException((string) ($error['message'] ?? 'MCP error'), (int) ($error['code'] ?? 0), $error['data'] ?? null);
                }

                return (array) ($message['result'] ?? []);
            }

            if (JsonRpc::isRequest($message)) {
                $this->answerServerRequest($message);
            } elseif (JsonRpc::isNotification($message)) {
                $this->handleNotification($message, $extra);
            }
        }
    }

    /** @param array<string, mixed> $request */
    private function answerServerRequest(array $request): void
    {
        $id = $request['id'];
        $params = (array) ($request['params'] ?? []);

        switch ($request['method']) {
            case 'ping':
                $reply = JsonRpc::response($id);
                break;
            case 'elicitation/create':
                if ($this->elicitationHandler === null) {
                    $reply = JsonRpc::errorResponse($id, JsonRpc::METHOD_NOT_FOUND, 'Method not found: elicitation/create');
                    break;
                }
                try {
                    $reply = JsonRpc::response($id, ($this->elicitationHandler)($params));
                } catch (\Throwable $e) {
                    $reply = JsonRpc::errorResponse($id, JsonRpc::INTERNAL_ERROR, $e->getMessage());
                }
                break;
            default:
                $reply = JsonRpc::errorResponse($id, JsonRpc::METHOD_NOT_FOUND, "Method not found: {$request['method']}");
        }

        $this->transport->send($reply);
    }

    /**
     * @param array<string, mixed> $notification
     * @param array<string, mixed> $extra
     */
    private function handleNotification(array $notification, array $extra): void
    {
        $method = (string) $notification['method'];
        $params = (array) ($notification['params'] ?? []);

        if ($method === 'notifications/progress' && is_callable($extra['onprogress'] ?? null)
            && ($params['progressToken'] ?? null) === ($extra['progressToken'] ?? null)) {
            ($extra['onprogress'])($params);

            return;
        }

        if (isset($this->notificationHandlers[$method])) {
            ($this->notificationHandlers[$method])($params);
        }
        if (isset($this->options['onNotification'])) {
            ($this->options['onNotification'])($method, $params);
        }
    }

    private function cancel(int $requestId, string $reason): void
    {
        try {
            $this->transport->send(JsonRpc::notification('notifications/cancelled', ['requestId' => $requestId, 'reason' => $reason]));
        } catch (McpClientError) {
            // Best effort: the request is already being abandoned.
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function validateStructuredOutput(string $name, array $result, mixed $schema): void
    {
        if (!array_key_exists('structuredContent', $result)) {
            throw new McpClientError("MCP tool \"{$name}\" has an output schema but did not return structured content.");
        }
        $issues = JsonSchemaValidator::validate($result['structuredContent'], $schema);
        if ($issues !== []) {
            throw new McpClientError("Structured content of MCP tool \"{$name}\" does not match its output schema: " . implode('; ', array_column($issues, 'message')));
        }
    }
}
