<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

use LangChain\Tools\DynamicStructuredTool;
use LangGraph\Mcp\Connection\ConnectionConfig;
use LangGraph\Mcp\Connection\ConnectionManager;
use LangGraph\Mcp\Connection\ManagedClient;
use LangGraph\Mcp\Connection\Misc;

/**
 * A client for several MCP servers at once that loads their tools as LangChain tools.
 *
 * Port of `MCPAdapter` (also exported as `MultiServerMCPClient`) from
 * `langchain-mcp-adapters/src/client.ts`, over the in-house MCP client in `Mcp\Client`.
 * Construction validates the configuration and opens nothing; connections open on demand.
 *
 * ## Configuration
 *
 * `['servers' => [...]]`, the legacy `['mcpServers' => [...]]`, or a bare map of server name to
 * connection, with these client-wide options beside it: `throwOnLoadError` (true),
 * `prefixToolNameWithServerName` (false), `additionalToolNamePrefix` (''), `outputHandling`,
 * `defaultToolTimeout` (milliseconds, at least 1; wins over a server's own), `beforeToolCall`,
 * `afterToolCall` and `onConnectionError` (`'throw'`, `'ignore'` or `callable(array{serverName,
 * error}): void`). See {@see ConnectionConfig} for what a connection accepts: stdio and streamable
 * HTTP only, and anything else raises an {@see McpClientError}.
 *
 * ## Differences from upstream
 *
 *  - PHP is synchronous, so discovery runs one server after another and a stdio server that dies is
 *    noticed when its channel next breaks or its transport closes, then restarted before the call
 *    that tripped it returns its error;
 *  - there is no automatic fallback from streamable HTTP to SSE (`automaticSSEFallback` is
 *    recorded but never acted on), no `listResourceTemplates()`, and no resource subscriptions;
 *  - the SDK's discovery cache does not exist, so `cacheMode` is validated and only `'bypass'`
 *    changes anything (it keeps the result out of this client's own tool cache);
 *  - a `close()` issued while a discovery is mid-flight (from a callback) makes that discovery fail
 *    with "MCP connections closed during discovery", as upstream does;
 *  - `$dependencies` takes `transportFactory` (`callable(string $type, array $connection):
 *    TransportInterface`) and `sleep` (`callable(int $milliseconds): void`, the restart backoff),
 *    seams for tests.
 */
final class MultiServerMcpClient
{
    private const LOGGING_LEVELS = ConnectionConfig::LOG_LEVELS;

    private ConnectionManager $clientConnections;

    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, array<string, mixed>> */
    private array $loadToolsOptions = [];

    /** @var 'throw'|'ignore'|callable */
    private $onConnectionError;

    /** @var callable(int): void */
    private $sleep;

    /** Cancellation for the current connection epoch: `close()` aborts it and installs a fresh one. */
    private \stdClass $epoch;

    private bool $closing = false;

    /** @var \WeakMap<ManagedClient, array{descriptorKey: string, tools: list<DynamicStructuredTool>}> */
    private \WeakMap $toolsByClient;

    /** @var \WeakMap<ManagedClient, \stdClass> */
    private \WeakMap $toolDiscoveryState;

    /** @var array<string, true> */
    private array $failedServers = [];

    /**
     * @param array<string, mixed> $config
     * @param array{transportFactory?: callable, sleep?: callable} $dependencies
     *
     * @throws McpClientError when the configuration is invalid
     */
    public function __construct(array $config, array $dependencies = [])
    {
        $parsed = ConnectionConfig::parseAdapterConfig($config);

        foreach ($parsed['servers'] as $serverName => $server) {
            $outputHandling = Content::resolveAndApplyOverrideHandlingOverrides($parsed['outputHandling'] ?? null, $server['outputHandling'] ?? null);
            $defaultToolTimeout = $parsed['defaultToolTimeout'] ?? $server['defaultToolTimeout'] ?? null;

            $this->loadToolsOptions[$serverName] = array_filter([
                'logLevel' => $server['logLevel'] ?? null,
                'elicitation' => $server['elicitation'] ?? null,
                'throwOnLoadError' => $parsed['throwOnLoadError'],
                'prefixToolNameWithServerName' => $parsed['prefixToolNameWithServerName'],
                'additionalToolNamePrefix' => $parsed['additionalToolNamePrefix'],
                'outputHandling' => $outputHandling === [] ? null : $outputHandling,
                'defaultToolTimeout' => $defaultToolTimeout ?: null,
                'onProgress' => $server['onProgress'] ?? null,
                'beforeToolCall' => $parsed['beforeToolCall'] ?? null,
                'afterToolCall' => $parsed['afterToolCall'] ?? null,
            ], static fn (mixed $option): bool => $option !== null);
        }

        $this->config = $parsed;
        $this->onConnectionError = $parsed['onConnectionError'];
        $this->sleep = $dependencies['sleep'] ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
        $this->epoch = self::newEpoch();
        $this->toolsByClient = new \WeakMap();
        $this->toolDiscoveryState = new \WeakMap();
        $this->clientConnections = new ConnectionManager(function (array $options): void {
            $client = $this->clientConnections->get($options);
            if ($client !== null) {
                unset($this->toolsByClient[$client]);
            }
        }, $dependencies['transportFactory'] ?? null);
    }

    /**
     * The resolved configuration: defaults applied, `servers` canonical. Callbacks keep their
     * identity; the arrays are copies, so editing one changes nothing here.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * Discover the tools of every server, grouped by server name. Opens connections as needed.
     *
     * A server that fails to connect throws when `onConnectionError` is `'throw'` (the default);
     * otherwise it is skipped.
     *
     * @param array{headers?: array<string, string>, cacheMode?: string}|null $options
     *
     * @return array<string, list<DynamicStructuredTool>>
     *
     * @throws McpClientError
     */
    public function listToolsets(?array $options = null): array
    {
        return $this->discoverToolsets(ConnectionConfig::parseToolDiscoveryOptions($options));
    }

    /**
     * @param array{headers?: array<string, string>, cacheMode?: string}|null $options
     *
     * @return array<string, list<DynamicStructuredTool>>
     *
     * @deprecated use {@see self::listToolsets()}; this also discovers tools
     */
    public function initializeConnections(?array $options = null): array
    {
        return $this->listToolsets($options);
    }

    /**
     * The tools of the named servers (all servers when none are named) as one flat list.
     *
     * Call as `listTools('a', 'b')` or `listTools(['a', 'b'], ['headers' => [...]])`. A server
     * that does not exist contributes nothing.
     *
     * @return list<DynamicStructuredTool>
     *
     * @throws McpClientError
     */
    public function listTools(string|array ...$args): array
    {
        [$servers, $options] = self::selection($args, discovery: true);
        $catalog = $this->discoverToolsets($options);

        $tools = [];
        foreach ($servers !== [] ? $servers : array_keys($catalog) as $name) {
            array_push($tools, ...($catalog[$name] ?? []));
        }

        return $tools;
    }

    /**
     * The name `listTools()` had before toolsets existed.
     *
     * @return list<DynamicStructuredTool>
     */
    public function getTools(string|array ...$args): array
    {
        return $this->listTools(...$args);
    }

    /**
     * Set the logging level of every server, or of one: `setLoggingLevel('debug')` or
     * `setLoggingLevel('server1', 'debug')`.
     *
     * @throws McpClientError when a connected server speaks the modern protocol
     */
    public function setLoggingLevel(string ...$args): void
    {
        if (count($args) === 1) {
            $serverName = null;
            $level = $args[0];
        } elseif (count($args) === 2) {
            [$serverName, $level] = $args;
        } else {
            throw new McpClientError('setLoggingLevel takes a level, or a server name and a level');
        }
        if (!in_array($level, self::LOGGING_LEVELS, true)) {
            throw new McpClientError('Invalid logging level "' . $level . '": expected one of ' . implode('|', self::LOGGING_LEVELS));
        }

        $clients = $serverName === null
            ? $this->clientConnections->getAllClients()
            : array_values(array_filter([$this->clientConnections->get($this->transportOptions($serverName))]));

        foreach ($clients as $client) {
            if ($client->getProtocolEra() === 'modern') {
                throw new McpClientError('setLoggingLevel is legacy-only; configure logLevel for modern tool requests');
            }
        }
        foreach ($clients as $client) {
            $client->setLoggingLevel($level);
        }
    }

    /**
     * The MCP client for a server, for prompts or resources; null when the server is unknown or
     * failed to connect under a tolerant `onConnectionError`.
     *
     * @param array{headers?: array<string, string>}|null $options
     *
     * @throws McpClientError
     */
    public function getClient(string $serverName, ?array $options = null): ?ManagedClient
    {
        $parsed = ConnectionConfig::parseTransportOptions($options);
        $this->discoverToolsets($parsed);

        return $this->clientConnections->get($this->transportOptions($serverName, $parsed));
    }

    /**
     * The resources of the named servers (all when none are named), keyed by server name.
     *
     * @return array<string, list<array<string, mixed>>>
     *
     * @throws McpClientError
     */
    public function listResources(string|array ...$args): array
    {
        [$servers, $options] = self::selection($args, discovery: false);
        $this->discoverToolsets($options);

        $result = [];
        foreach ($servers !== [] ? $servers : array_keys($this->config['servers']) as $serverName) {
            $client = $this->clientConnections->get($this->transportOptions($serverName, $options));
            if ($client === null) {
                continue;
            }

            $result[$serverName] = array_map(
                static fn (array $resource): array => [...$resource, 'name' => $resource['title'] ?? $resource['name'] ?? null],
                $client->listResources()['resources'],
            );
        }

        return $result;
    }

    /**
     * Read a resource from one server.
     *
     * @param array{headers?: array<string, string>}|null $options
     *
     * @return list<array<string, mixed>> the resource contents
     *
     * @throws McpClientError
     */
    public function readResource(string $serverName, string $uri, ?array $options = null): array
    {
        $client = $this->getClient($serverName, $options);
        if ($client === null) {
            throw new McpClientError("Server \"{$serverName}\" not found or not connected", $serverName);
        }

        try {
            return $client->readResource($uri)['contents'] ?? [];
        } catch (\Throwable $e) {
            throw new McpClientError("Failed to read resource \"{$uri}\" from server \"{$serverName}\": " . Misc::describeError($e), $serverName, $e);
        }
    }

    /**
     * Close every connection and clear the discovery caches.
     *
     * Cancels the current epoch, so a reconnect waiting on its backoff gives up instead of
     * resurrecting a connection. The client stays usable: the configuration survives, and a later
     * discovery connects again with fresh clients.
     *
     * @throws McpClientError "Failed to close MCP connections" when a connection would not close
     */
    public function close(): void
    {
        if ($this->closing) {
            return;
        }

        $this->closing = true;
        try {
            $this->epoch->aborted = true;
            $this->epoch->reason = new McpClientError('MCP adapter closed');
            $this->clientConnections->delete();
        } finally {
            $this->toolsByClient = new \WeakMap();
            $this->toolDiscoveryState = new \WeakMap();
            $this->failedServers = [];
            $this->epoch = self::newEpoch();
            $this->closing = false;
        }
    }

    /**
     * @param array{headers?: array<string, string>, cacheMode?: string} $options
     *
     * @return array<string, list<DynamicStructuredTool>>
     */
    private function discoverToolsets(array $options): array
    {
        if ($this->closing) {
            throw new McpClientError('MCP adapter is closing');
        }

        $epoch = $this->epoch;
        $catalog = [];

        foreach ($this->config['servers'] as $serverName => $connection) {
            $serverName = (string) $serverName;
            $key = $this->clientConnections->identity($this->transportOptions($serverName, $options));
            if (isset($this->failedServers[$key])) {
                continue;
            }

            try {
                $this->initializeConnection($serverName, $connection, $options);

                $client = $this->clientConnections->get($this->transportOptions($serverName, $options));
                if ($client !== null) {
                    $catalog[$serverName] = $this->loadToolsForServer($serverName, $client, $options['cacheMode'] ?? 'use');
                }
            } catch (\Throwable $error) {
                if ($this->onConnectionError === 'throw') {
                    throw $error;
                }
                if ($this->onConnectionError !== 'ignore') {
                    ($this->onConnectionError)(['serverName' => $serverName, 'error' => $error]);
                }

                // A login can complete later, so an authentication failure must not block the
                // server for this client's lifetime. Neither does a failure that a restart
                // already repaired while it was being raised: the server is connected again.
                if (!Errors::isAuthenticationError($error) && !$this->clientConnections->has($this->transportOptions($serverName, $options))) {
                    $this->failedServers[$key] = true;
                }
            }
        }

        // The per-server policy swallows failures, an interrupted request included, so a close
        // during discovery has to be re-checked here or it would surface as a partial catalog.
        if ($epoch->aborted) {
            throw new McpClientError('MCP connections closed during discovery', null, $epoch->reason);
        }

        return $catalog;
    }

    /**
     * @param array{headers?: array<string, string>} $options
     *
     * @return array{serverName: string, headers?: array<string, string>}
     */
    private function transportOptions(string $serverName, array $options = []): array
    {
        $connection = $this->config['servers'][$serverName] ?? null;

        return $connection === null || $connection['transport'] === 'stdio'
            ? ['serverName' => $serverName]
            : ['serverName' => $serverName, 'headers' => Misc::mergeHeaders($options['headers'] ?? null, $connection['headers'] ?? null)];
    }

    /**
     * @param array<string, mixed>                   $connection
     * @param array{headers?: array<string, string>} $options
     */
    private function initializeConnection(string $serverName, array $connection, array $options): void
    {
        if ($connection['transport'] === 'stdio') {
            if ($this->clientConnections->has($serverName)) {
                return;
            }

            $this->initializeStdioConnection($serverName, $connection);

            return;
        }

        // Callers may use other headers for discovery than the server configures.
        $connection['headers'] = Misc::mergeHeaders($options['headers'] ?? null, $connection['headers'] ?? null);
        if ($this->clientConnections->has(['serverName' => $serverName, 'headers' => $connection['headers']])) {
            return;
        }

        $this->initializeStreamableHttpConnection($serverName, $connection);
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function initializeStdioConnection(string $serverName, array $connection): void
    {
        try {
            $this->clientConnections->createClient('stdio', $serverName, $connection);

            if (($connection['restart']['enabled'] ?? false) === true) {
                $this->setupStdioRestart($serverName, $connection, $connection['restart']);
            }
        } catch (\Throwable $error) {
            throw new McpClientError(
                "Failed to connect to stdio server \"{$serverName}\" in {$connection['mode']} mode: " . Misc::describeError($error),
                $serverName,
                $error,
            );
        }
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function initializeStreamableHttpConnection(string $serverName, array $connection): void
    {
        $url = $connection['url'];

        try {
            $this->clientConnections->createClient('http', $serverName, $connection);
        } catch (\Throwable $error) {
            if (Errors::isAuthenticationError($error)) {
                throw new McpClientError(
                    Errors::createAuthenticationErrorMessage($serverName, $url, 'HTTP', Misc::describeError($error)),
                    $serverName,
                    $error,
                );
            }

            throw new McpClientError(
                "Failed to connect to streamable HTTP server \"{$serverName}, url: {$url}\" in {$connection['mode']} mode: " . Misc::describeError($error),
                $serverName,
                $error,
            );
        }
    }

    /**
     * Restart a stdio server when its transport closes.
     *
     * @param array<string, mixed>                                              $connection
     * @param array{enabled?: bool, maxAttempts?: int, delayMs?: int|float}    $restart
     */
    private function setupStdioRestart(string $serverName, array $connection, array $restart): void
    {
        $transport = $this->clientConnections->getTransport(['serverName' => $serverName]);
        if ($transport === null) {
            return;
        }

        $original = $transport->onclose;
        $transport->onclose = function () use ($serverName, $connection, $restart, $transport, $original): void {
            try {
                if ($original !== null) {
                    $original();
                }

                // Only restart a connection that has not been cleaned up already.
                if ($this->clientConnections->getTransport(['serverName' => $serverName]) === $transport) {
                    $this->attemptReconnect($serverName, $connection, $restart['maxAttempts'] ?? null, $restart['delayMs'] ?? null);
                }
            } catch (\Throwable) {
                // Reconnection runs detached from any caller, as upstream's does; an exhausted
                // budget reaches onConnectionError instead.
            }
        };
    }

    /**
     * @return list<DynamicStructuredTool>
     */
    private function loadToolsForServer(string $serverName, ManagedClient $client, string $cacheMode): array
    {
        $state = $this->toolDiscoveryState[$client] ?? new \stdClass();
        $state->inFlight ??= 0;
        $state->ready ??= false;
        $state->inFlight++;
        $this->toolDiscoveryState[$client] = $state;

        $failure = null;
        try {
            $descriptors = $client->listTools()['tools'] ?? [];
            $descriptorKey = (string) json_encode($descriptors, \JSON_PARTIAL_OUTPUT_ON_ERROR);

            $existing = $this->toolsByClient[$client] ?? null;
            if ($existing !== null && $existing['descriptorKey'] === $descriptorKey) {
                $state->ready = true;

                return $existing['tools'];
            }

            $tools = McpTools::convertMcpTools($serverName, $client, $descriptors, $this->loadToolsOptions[$serverName]);
            if ($cacheMode !== 'bypass') {
                $this->toolsByClient[$client] = ['descriptorKey' => $descriptorKey, 'tools' => $tools];
            }
            $state->ready = true;

            return $tools;
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            $state->inFlight--;
        }

        // Keep the connection while another discovery is running or tools from this client were
        // already handed to a caller; otherwise a failed first discovery must not leave it open.
        if ($state->inFlight === 0 && !$state->ready) {
            unset($this->toolDiscoveryState[$client]);

            try {
                $this->clientConnections->release($client);
            } catch (\Throwable $cleanupError) {
                throw new McpClientError("MCP discovery and cleanup failed for \"{$serverName}\"", $serverName, [$failure, $cleanupError]);
            }
        }

        throw new McpClientError("Failed to load tools from server \"{$serverName}\": " . Misc::describeError($failure), $serverName, $failure);
    }

    /**
     * Rebuild a stdio connection after its server went away, up to `$maxAttempts` times with
     * `$delayMs` between them. A `close()` during the backoff cancels it.
     *
     * @param array<string, mixed> $connection
     */
    private function attemptReconnect(string $serverName, array $connection, ?int $maxAttempts, int|float|null $delayMs): void
    {
        $maxAttempts ??= 3;
        $delayMs ??= 1000;
        $epoch = $this->epoch;
        $connected = false;
        $attempts = 0;
        $lastError = null;

        $stale = $this->clientConnections->get($serverName);
        if ($stale !== null) {
            unset($this->toolsByClient[$stale]);
        }
        $this->clientConnections->delete(['serverName' => $serverName]);

        while (!$connected && $attempts < $maxAttempts) {
            ++$attempts;

            try {
                if ($delayMs) {
                    ($this->sleep)((int) $delayMs);
                }
                if ($epoch->aborted) {
                    return;
                }

                $this->initializeStdioConnection($serverName, $connection);

                if ($this->clientConnections->has($serverName)) {
                    $connected = true;
                    unset($this->failedServers[$this->clientConnections->identity(['serverName' => $serverName])]);
                }
            } catch (\Throwable $error) {
                $lastError = $error;
            }
        }

        // Reconnection has no caller to throw at, so an exhausted budget goes to the handler.
        if (!$connected && !$epoch->aborted && $this->onConnectionError !== 'throw' && $this->onConnectionError !== 'ignore') {
            ($this->onConnectionError)([
                'serverName' => $serverName,
                'error' => $lastError ?? new McpClientError("Failed to reconnect to MCP server \"{$serverName}\" after {$attempts} attempts", $serverName),
            ]);
        }
    }

    /**
     * Server selection for the listing methods: server names as arguments, or a list of names
     * followed by options.
     *
     * @param list<string|array<mixed>> $args
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private static function selection(array $args, bool $discovery): array
    {
        if ($args === []) {
            return [[], []];
        }

        if (is_array($args[0])) {
            $servers = $args[0];
            if (count($args) > 2 || !array_is_list($servers) || array_filter($servers, static fn (mixed $name): bool => !is_string($name)) !== []) {
                throw new McpClientError('Expected a list of server names, optionally followed by options');
            }
            $options = $args[1] ?? null;

            return [$servers, $discovery ? ConnectionConfig::parseToolDiscoveryOptions($options) : ConnectionConfig::parseTransportOptions($options)];
        }

        foreach ($args as $name) {
            if (!is_string($name)) {
                throw new McpClientError('Expected server names as strings, or a list of names followed by options');
            }
        }

        return [array_values($args), []];
    }

    private static function newEpoch(): \stdClass
    {
        $epoch = new \stdClass();
        $epoch->aborted = false;
        $epoch->reason = null;

        return $epoch;
    }
}
