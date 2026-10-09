<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Connection;

use LangGraph\Mcp\Client\McpClient;
use LangGraph\Mcp\Client\Transport\StdioTransport;
use LangGraph\Mcp\Client\Transport\StreamableHttpTransport;
use LangGraph\Mcp\Client\Transport\TransportInterface;
use LangGraph\Mcp\Elicitation;
use LangGraph\Mcp\McpClientError;

/**
 * A pool of MCP clients keyed by server name and header set, so the same server with the same
 * configuration never gets two connections.
 *
 * Port of `ConnectionManager` from `langchain-mcp-adapters/src/connection.ts` over the in-house
 * {@see McpClient}. Differences from upstream, all forced by PHP being synchronous or by what is
 * not ported:
 *
 *  - only `stdio` and `http` connections exist (no SSE, no provider based authentication);
 *  - an identity is a string, not an object kept by reference;
 *  - `createClient()` cannot wait for a concurrent acquisition of the same identity, so a
 *    re-entrant one throws, and a `delete()` that arrives while a client is still connecting is
 *    carried out as soon as that connect settles;
 *  - closing several connections that fail reports one {@see McpClientError} whose `cause` is the
 *    list of failures, where upstream throws an `AggregateError`;
 *  - the transport is built by `$transportFactory` (`callable(string $type, array $options): TransportInterface`),
 *    a seam so tests can run the whole stack over a scripted transport.
 */
final class ConnectionManager
{
    public const CLIENT_NAME = '@langchain/mcp-adapters';

    public const CLIENT_VERSION = '1.0.0';

    /**
     * @var array<string, array{type: string, serverName: string, transport: ObservedTransport, client: ManagedClient, transportOptions: array<string, mixed>}>
     */
    private array $connections = [];

    /** @var array<string, true> */
    private array $pending = [];

    private bool $closing = false;

    private bool $closeAfterConnect = false;

    /** @var (callable(array<string, mixed>): void)|null */
    private $onToolsChanged;

    /** @var (callable(string, array<string, mixed>): TransportInterface)|null */
    private $transportFactory;

    /**
     * @param (callable(array<string, mixed>): void)|null                             $onToolsChanged   told which identity's tool list changed
     * @param (callable(string, array<string, mixed>): TransportInterface)|null       $transportFactory
     */
    public function __construct(?callable $onToolsChanged = null, ?callable $transportFactory = null)
    {
        $this->onToolsChanged = $onToolsChanged;
        $this->transportFactory = $transportFactory;
    }

    /**
     * The identity of a connection: the server name plus its normalised headers. Header names
     * are case-insensitive, so `['A' => '1']` and `['a' => '1']` are the same identity.
     *
     * @param array{serverName: string, headers?: array<string, string>|null} $options
     */
    public function identity(array $options): string
    {
        return $options['serverName'] . "\0" . (Misc::serializeHeaders($options['headers'] ?? null) ?? '');
    }

    /**
     * Connect to a server, or return the client already connected under the same identity.
     *
     * @param 'stdio'|'http'       $type
     * @param array<string, mixed> $options a resolved connection
     */
    public function createClient(string $type, string $serverName, array $options): ManagedClient
    {
        if ($this->closing) {
            throw new McpClientError('MCP connections are closing');
        }
        if (!in_array($type, ['stdio', 'http'], true)) {
            throw new McpClientError($type === 'sse'
                ? 'The SSE transport is not supported; use stdio or streamable HTTP'
                : "Invalid transport type: {$type}");
        }

        $key = $this->identity($type === 'stdio'
            ? ['serverName' => $serverName]
            : ['serverName' => $serverName, 'headers' => $options['headers'] ?? null]);

        $existing = $this->connections[$key]['client'] ?? null;
        if ($existing !== null) {
            return $existing;
        }
        if (isset($this->pending[$key])) {
            throw new McpClientError("MCP server \"{$serverName}\" is already being connected", $serverName);
        }

        $this->pending[$key] = true;
        try {
            return $this->connect($type, $serverName, $options, $key);
        } finally {
            unset($this->pending[$key]);
            $this->finishDeferredClose();
        }
    }

    /**
     * The client for a server: by name alone, or by name and headers.
     *
     * @param string|array{serverName: string, headers?: array<string, string>|null} $options
     */
    public function get(string|array $options): ?ManagedClient
    {
        return $this->queryConnection(is_string($options) ? ['serverName' => $options] : $options)['client'] ?? null;
    }

    /** @param string|array{serverName: string, headers?: array<string, string>|null} $options */
    public function has(string|array $options): bool
    {
        return $this->get($options) !== null;
    }

    /** @return list<ManagedClient> */
    public function getAllClients(): array
    {
        return array_values(array_map(static fn (array $connection): ManagedClient => $connection['client'], $this->connections));
    }

    /**
     * The transport behind a client, or behind a server name and headers.
     *
     * @param ManagedClient|array{serverName: string, headers?: array<string, string>|null} $subject
     */
    public function getTransport(ManagedClient|array $subject): ?ObservedTransport
    {
        if ($subject instanceof ManagedClient) {
            foreach ($this->connections as $connection) {
                if ($connection['client'] === $subject) {
                    return $connection['transport'];
                }
            }

            return null;
        }

        return $this->queryConnection($subject)['transport'] ?? null;
    }

    /**
     * Close one connection, or every connection when `$options` is null.
     *
     * Closing everything settles every close before reporting: a connection that fails to close
     * is still removed, and the failures arrive together.
     *
     * @param array{serverName: string, headers?: array<string, string>|null}|null $options
     *
     * @throws McpClientError "Failed to close MCP connections", `cause` being the list of failures
     */
    public function delete(?array $options = null): void
    {
        if ($this->closing) {
            return;
        }

        if ($options !== null) {
            $key = $this->identity($options);
            $connection = $this->connections[$key] ?? null;
            unset($this->connections[$key]);
            if ($connection !== null) {
                $connection['client']->close();
            }

            return;
        }

        if ($this->pending !== []) {
            $this->closing = true;
            $this->closeAfterConnect = true;

            return;
        }

        $this->closeAll();
    }

    /** Stop tracking a client and close it. Unknown clients are ignored. */
    public function release(ManagedClient $client): void
    {
        foreach ($this->connections as $key => $connection) {
            if ($connection['client'] === $client) {
                unset($this->connections[$key]);
                $client->close();

                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function connect(string $type, string $serverName, array $options, string $key): ManagedClient
    {
        $transport = new ObservedTransport(
            $this->transportFactory !== null ? ($this->transportFactory)($type, $options) : $this->defaultTransport($type, $options),
            watchErrors: $type === 'stdio',
        );

        $mode = $options['mode'] ?? 'auto';
        $client = new McpClient($transport, [
            'mode' => $mode,
            'clientInfo' => ['name' => self::CLIENT_NAME, 'version' => self::CLIENT_VERSION],
        ]);

        if ($mode === 'legacy') {
            Elicitation::configureElicitation($client, $serverName, $options['onElicitation'] ?? null);
        }
        $this->registerNotificationHandlers($client, $serverName, $options, $type);

        try {
            $client->connect();
        } catch (\Throwable $e) {
            foreach ([$client, $transport] as $closable) {
                try {
                    $closable->close();
                } catch (\Throwable) {
                    // Best effort: the handshake failure is the error worth reporting.
                }
            }

            throw $e;
        }

        $managed = new ManagedClient($client, fn (array $headers): ManagedClient => $this->forkClient($key, $headers));
        $this->connections[$key] = [
            'type' => $type,
            'serverName' => $serverName,
            'transport' => $transport,
            'client' => $managed,
            'transportOptions' => $options,
        ];

        return $managed;
    }

    /**
     * Each callback gets the notification and a copy of the connection options as its source, so
     * a callback that edits what it was handed cannot change the next call.
     *
     * @param array<string, mixed> $options
     */
    private function registerNotificationHandlers(McpClient $client, string $serverName, array $options, string $type): void
    {
        $source = ['server' => $serverName, 'options' => $options];

        if (isset($options['onMessage'])) {
            $client->setNotificationHandler('notifications/message', static fn (array $params) => ($options['onMessage'])($params, $source));
        }
        if (isset($options['onInitialized'])) {
            $client->setNotificationHandler('notifications/initialized', static fn (array $params) => ($options['onInitialized'])($source));
        }
        if (isset($options['onPromptsListChanged'])) {
            $client->setNotificationHandler('notifications/prompts/list_changed', static fn (array $params) => ($options['onPromptsListChanged'])($source));
        }
        if (isset($options['onResourcesListChanged'])) {
            $client->setNotificationHandler('notifications/resources/list_changed', static fn (array $params) => ($options['onResourcesListChanged'])($source));
        }
        if (isset($options['onResourcesUpdated'])) {
            $client->setNotificationHandler('notifications/resources/updated', static fn (array $params) => ($options['onResourcesUpdated'])($params, $source));
        }
        if (isset($options['onToolsListChanged']) || $this->onToolsChanged !== null) {
            $client->setNotificationHandler('notifications/tools/list_changed', function (array $params) use ($options, $serverName, $source, $type): void {
                if ($this->onToolsChanged !== null) {
                    ($this->onToolsChanged)($type === 'stdio'
                        ? ['serverName' => $serverName]
                        : ['serverName' => $serverName, 'headers' => $options['headers'] ?? null]);
                }
                if (isset($options['onToolsListChanged'])) {
                    ($options['onToolsListChanged'])($source);
                }
            });
        }
    }

    /**
     * A sibling connection carrying more headers: the base headers with the new ones laid over
     * them. Nothing to add is the same client; stdio carries no headers to add to.
     *
     * @param array<string, string> $headers
     */
    private function forkClient(string $key, array $headers): ManagedClient
    {
        $connection = $this->connections[$key] ?? null;
        if ($connection === null) {
            throw new McpClientError('Transport not found');
        }
        if ($headers === []) {
            return $connection['client'];
        }
        if ($connection['type'] === 'stdio') {
            throw new McpClientError('Forking stdio transport is not supported', $connection['serverName']);
        }

        $options = $connection['transportOptions'];

        return $this->createClient('http', $connection['serverName'], [
            ...$options,
            'headers' => Misc::mergeHeaders($options['headers'] ?? null, $headers),
        ]);
    }

    /**
     * @param array{serverName: string, headers?: array<string, string>|null} $options
     *
     * @return array{type: string, serverName: string, transport: ObservedTransport, client: ManagedClient, transportOptions: array<string, mixed>}|null
     */
    private function queryConnection(array $options): ?array
    {
        return $this->connections[$this->identity($options)] ?? null;
    }

    private function closeAll(): void
    {
        $this->closing = true;
        try {
            $connections = array_values($this->connections);
            $this->connections = [];

            $errors = [];
            foreach ($connections as $connection) {
                try {
                    $connection['client']->close();
                } catch (\Throwable $e) {
                    $errors[] = $e;
                }
            }

            if ($errors !== []) {
                throw new McpClientError('Failed to close MCP connections', null, $errors);
            }
        } finally {
            $this->closing = false;
        }
    }

    /** A `delete()` that arrived mid-connect runs once nothing is connecting. */
    private function finishDeferredClose(): void
    {
        if (!$this->closeAfterConnect || $this->pending !== []) {
            return;
        }
        $this->closeAfterConnect = false;

        try {
            $this->closeAll();
        } catch (McpClientError) {
            // The caller that asked for the close returned long ago and has nobody to tell.
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function defaultTransport(string $type, array $options): TransportInterface
    {
        $config = self::transportConfig($type, $options);

        return $type === 'stdio' ? new StdioTransport($config) : new StreamableHttpTransport($config);
    }

    /**
     * The transport configuration a resolved connection maps to.
     *
     * A streamable HTTP connection that is not pinned to the legacy protocol never retries a
     * dropped stream (modern streams cannot replay lost requests); a legacy one maps `reconnect`
     * onto the transport's backoff: `delayMs` is both the initial and the maximum delay,
     * `maxAttempts` the retry count, and `enabled: false` zero retries.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function transportConfig(string $type, array $options): array
    {
        if ($type === 'stdio') {
            return array_filter([
                'command' => $options['command'] ?? '',
                'args' => $options['args'] ?? [],
                'env' => $options['env'] ?? null,
                'stderr' => $options['stderr'] ?? 'inherit',
                'cwd' => $options['cwd'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $config = ['url' => (string) ($options['url'] ?? '')];
        if (isset($options['headers'])) {
            $config['headers'] = $options['headers'];
        }

        $reconnect = $options['reconnect'] ?? null;
        if (($options['mode'] ?? 'auto') !== 'legacy') {
            $config['reconnect'] = [
                'maxRetries' => 0,
                'initialReconnectionDelay' => 1000,
                'maxReconnectionDelay' => 30000,
                'reconnectionDelayGrowFactor' => 1.5,
            ];
        } elseif ($reconnect !== null) {
            $config['reconnect'] = [
                'initialReconnectionDelay' => $reconnect['delayMs'] ?? 1000,
                'maxReconnectionDelay' => $reconnect['delayMs'] ?? 30000,
                'maxRetries' => ($reconnect['enabled'] ?? null) === false ? 0 : ($reconnect['maxAttempts'] ?? 2),
                'reconnectionDelayGrowFactor' => 1.5,
            ];
        }

        return $config;
    }
}
