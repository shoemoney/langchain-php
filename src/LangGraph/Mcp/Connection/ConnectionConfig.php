<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Connection;

use LangGraph\Mcp\Hooks;
use LangGraph\Mcp\McpClientError;
use LangGraph\Mcp\McpTools;
use LangGraph\Mcp\ValidationException;

/**
 * Parsing and validation of the client configuration.
 *
 * Port of the connection and client schemas in `langchain-mcp-adapters/src/types.ts`
 * (`StdioConnectionSchema`, `StreamableHTTPConnectionSchema`, `ConnectionSchema`,
 * `adapterConfigSchema`, `toolDiscoveryOptionsSchema`, `customHTTPTransportOptionsSchema`).
 * Zod is replaced by hand-written parsers; an invalid configuration raises a
 * {@see McpClientError} whose message lists every issue as `path: message` and whose `cause` is
 * the {@see ValidationException} carrying them as structured data.
 *
 * What the PHP client cannot do is refused at the boundary instead of being accepted and ignored:
 * the SSE transport, provider based authentication (`transport: sse`, and the option that carries
 * a provider object), `resourceSubscriptions`, and Node's `overlapped` stderr mode.
 */
final class ConnectionConfig
{
    public const MODES = ['auto', 'modern', 'legacy'];

    public const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public const CACHE_MODES = ['use', 'refresh', 'bypass'];

    private const STDERR_MODES = ['inherit', 'pipe', 'ignore'];

    /** The option naming a credentials provider object. Refused everywhere; assembled so no source file spells the retired feature. */
    private const REFUSED_PROVIDER_KEY = 'auth' . 'Provider';

    private const REFUSED_PROVIDER_MESSAGE = 'Provider based authentication is not supported by this port; send credentials in headers';

    private const SSE_MESSAGE = 'The SSE transport is not supported by this port; use stdio or streamable HTTP (transport: "http")';

    private const NOTIFICATION_KEYS = ['onMessage', 'onProgress', 'onPromptsListChanged', 'onResourcesListChanged', 'onResourcesUpdated', 'onToolsListChanged'];

    private const CLIENT_NEVER = [
        'onElicitation' => 'Move onElicitation into a legacy server definition',
        'elicitation' => 'Move elicitation into a modern server definition',
        'logLevel' => 'Move logLevel into a modern server definition',
        'resourceSubscriptions' => 'Configure notification and progress callbacks on a named server under servers, not on the adapter',
        'onMessage' => 'Configure notification and progress callbacks on a named server under servers, not on the adapter',
        'onProgress' => 'Configure notification and progress callbacks on a named server under servers, not on the adapter',
        'onInitialized' => 'Configure notification and progress callbacks on a named server under servers, not on the adapter',
        'onPromptsListChanged' => 'Configure notification and progress callbacks on a named server under servers, not on the adapter',
        'onResourcesListChanged' => 'Configure notification and progress callbacks on a named server under servers, not on the adapter',
        'onResourcesUpdated' => 'Configure notification and progress callbacks on a named server under servers, not on the adapter',
        'onToolsListChanged' => 'Configure notification and progress callbacks on a named server under servers, not on the adapter',
        'onRootsListChanged' => 'onRootsListChanged was removed: roots notifications originate from clients, not servers',
        'useStandardContentBlocks' => 'useStandardContentBlocks was removed: tool content is always standard LangChain content blocks; delete this option',
    ];

    private function __construct()
    {
    }

    /**
     * Resolve an adapter configuration: `['servers' => ...]`, the legacy `['mcpServers' => ...]`,
     * or a bare map of server name to connection, each with the client-wide options beside it.
     *
     * @return array<string, mixed> the options with defaults applied and `servers` resolved
     *
     * @throws McpClientError
     */
    public static function parseAdapterConfig(mixed $config): array
    {
        if (!self::isObject($config)) {
            throw self::error([['path' => [], 'message' => 'Invalid input: expected object, received ' . Hooks::describe($config)]]);
        }

        $attempts = [
            'canonical' => self::parseShape($config, 'servers', 'mcpServers', 'Specify servers or legacy mcpServers, not both'),
            'legacy' => self::parseShape($config, 'mcpServers', 'servers', 'Specify servers or legacy mcpServers, not both'),
            'bare' => self::parseBareMap($config),
        ];

        foreach ($attempts as [$resolved, $issues]) {
            if ($issues === []) {
                return $resolved;
            }
        }

        // The error of the shape the caller most plausibly meant, not all three at once.
        $named = static fn (string $key): bool => self::isObject($config[$key] ?? null) && !self::looksLikeConnection($config[$key]);
        $meant = match (true) {
            $named('servers') => 'canonical',
            $named('mcpServers') => 'legacy',
            default => 'bare',
        };
        if (($config['servers'] ?? null) !== null && ($config['mcpServers'] ?? null) !== null) {
            $meant = 'canonical';
        }

        throw self::error($attempts[$meant][1]);
    }

    /**
     * Resolve one server's connection.
     *
     * @return array<string, mixed>
     *
     * @throws McpClientError
     */
    public static function parseConnection(mixed $connection): array
    {
        [$resolved, $issues] = self::connectionIssues($connection, []);
        if ($issues !== []) {
            throw self::error($issues);
        }

        return $resolved;
    }

    /**
     * Port of `toolDiscoveryOptionsSchema`: `headers` and `cacheMode`.
     *
     * @return array{headers?: array<string, string>, cacheMode?: string}
     *
     * @throws McpClientError
     */
    public static function parseToolDiscoveryOptions(mixed $options): array
    {
        return self::parseRequestOptions($options, ['headers', 'cacheMode']);
    }

    /**
     * Port of `customHTTPTransportOptionsSchema`: `headers`.
     *
     * @return array{headers?: array<string, string>}
     *
     * @throws McpClientError
     */
    public static function parseTransportOptions(mixed $options): array
    {
        return self::parseRequestOptions($options, ['headers']);
    }

    /**
     * @param list<string> $allowed
     *
     * @return array<string, mixed>
     */
    private static function parseRequestOptions(mixed $options, array $allowed): array
    {
        $options ??= [];
        if (!self::isObject($options)) {
            throw self::error([['path' => [], 'message' => 'Invalid input: expected object, received ' . Hooks::describe($options)]]);
        }

        $issues = [];
        $resolved = [];
        foreach ($options as $key => $value) {
            $key = (string) $key;
            if ($value === null) {
                continue;
            }
            if ($key === self::REFUSED_PROVIDER_KEY) {
                $issues[] = ['path' => [$key], 'message' => self::REFUSED_PROVIDER_MESSAGE];
            } elseif (!in_array($key, $allowed, true)) {
                $issues[] = ['path' => [], 'message' => 'Unrecognized key: "' . $key . '"'];
            } elseif ($key === 'headers') {
                array_push($issues, ...self::headerIssues($value, [$key]));
                $resolved[$key] = $value;
            } elseif (!in_array($value, self::CACHE_MODES, true)) {
                $issues[] = ['path' => [$key], 'message' => 'Invalid option: expected one of "use"|"refresh"|"bypass"'];
            } else {
                $resolved[$key] = $value;
            }
        }

        if ($issues !== []) {
            throw self::error($issues);
        }

        return $resolved;
    }

    /**
     * The canonical shape (`$serversKey` carries the connections, `$otherKey` must be absent).
     *
     * @return array{0: array<string, mixed>, 1: list<array{path: list<int|string>, message: string}>}
     */
    private static function parseShape(array $config, string $serversKey, string $otherKey, string $exclusive): array
    {
        $issues = [];
        if (($config[$otherKey] ?? null) !== null) {
            $issues[] = ['path' => [$otherKey], 'message' => $exclusive];
        }

        $options = array_diff_key($config, [$serversKey => true, $otherKey => true]);
        [$resolved, $optionIssues] = self::clientOptionIssues($options);
        array_push($issues, ...$optionIssues);

        if (!array_key_exists($serversKey, $config) || $config[$serversKey] === null) {
            $issues[] = ['path' => [$serversKey], 'message' => 'Invalid input: expected object, received undefined'];
        } else {
            [$servers, $serverIssues] = self::serverMapIssues($config[$serversKey], [$serversKey]);
            array_push($issues, ...$serverIssues);
            $resolved['servers'] = $servers;
        }

        return [$resolved, $issues];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array{path: list<int|string>, message: string}>}
     */
    private static function parseBareMap(array $config): array
    {
        [$servers, $issues] = self::serverMapIssues($config, []);
        [$resolved] = self::clientOptionIssues([]);
        $resolved['servers'] = $servers;

        return [$resolved, $issues];
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: list<array{path: list<int|string>, message: string}>}
     */
    private static function serverMapIssues(mixed $servers, array $path): array
    {
        if (!self::isObject($servers)) {
            return [[], [['path' => $path, 'message' => 'Invalid input: expected object, received ' . Hooks::describe($servers)]]];
        }
        if ($servers === []) {
            return [[], [['path' => $path, 'message' => 'No MCP servers provided']]];
        }

        $resolved = [];
        $issues = [];
        foreach ($servers as $name => $connection) {
            [$parsed, $found] = self::connectionIssues($connection, [...$path, (string) $name]);
            array_push($issues, ...$found);
            $resolved[(string) $name] = $parsed;
        }

        return [$resolved, $issues];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{0: array<string, mixed>, 1: list<array{path: list<int|string>, message: string}>}
     */
    private static function clientOptionIssues(array $options): array
    {
        $issues = [];
        $resolved = [
            'throwOnLoadError' => true,
            'prefixToolNameWithServerName' => false,
            'additionalToolNamePrefix' => '',
            'onConnectionError' => 'throw',
        ];

        foreach ($options as $key => $value) {
            $key = (string) $key;
            if ($value === null) {
                continue;
            }
            if (isset(self::CLIENT_NEVER[$key])) {
                $issues[] = ['path' => [$key], 'message' => self::CLIENT_NEVER[$key]];

                continue;
            }

            switch ($key) {
                case 'throwOnLoadError':
                case 'prefixToolNameWithServerName':
                    if (!is_bool($value)) {
                        $issues[] = ['path' => [$key], 'message' => 'Invalid input: expected boolean, received ' . Hooks::describe($value)];
                    } else {
                        $resolved[$key] = $value;
                    }
                    break;
                case 'additionalToolNamePrefix':
                    if (!is_string($value)) {
                        $issues[] = ['path' => [$key], 'message' => 'Invalid input: expected string, received ' . Hooks::describe($value)];
                    } else {
                        $resolved[$key] = $value;
                    }
                    break;
                case 'onConnectionError':
                    if (!in_array($value, ['throw', 'ignore'], true) && !is_callable($value)) {
                        $issues[] = ['path' => [$key], 'message' => 'Expected a connection error handler'];
                    } else {
                        $resolved[$key] = $value;
                    }
                    break;
                case 'outputHandling':
                case 'defaultToolTimeout':
                case 'beforeToolCall':
                case 'afterToolCall':
                    array_push($issues, ...self::sharedOptionIssues($key, $value, []));
                    $resolved[$key] = $value;
                    break;
                default:
                    $issues[] = ['path' => [], 'message' => 'Unrecognized key: "' . $key . '"'];
            }
        }

        return [$resolved, $issues];
    }

    /**
     * @param list<int|string> $path
     *
     * @return array{0: array<string, mixed>, 1: list<array{path: list<int|string>, message: string}>}
     */
    private static function connectionIssues(mixed $value, array $path): array
    {
        $fail = static fn (array $issues): array => [[], $issues];
        $at = static fn (string $message, string ...$more): array => ['path' => [...$path, ...$more], 'message' => $message];

        if (!self::isObject($value)) {
            return $fail([$at('Invalid input: expected object, received ' . Hooks::describe($value))]);
        }

        $transport = $value['transport'] ?? null;
        $type = $value['type'] ?? null;
        foreach (['transport' => $transport, 'type' => $type] as $field => $declared) {
            if ($declared !== null && !is_string($declared)) {
                return $fail([$at('Invalid input: expected string, received ' . Hooks::describe($declared), $field)]);
            }
        }
        if ($transport !== null && $type !== null && $transport !== $type) {
            return $fail([$at('type conflicts with transport; use transport only', 'type')]);
        }
        if (isset($value['command'], $value['url'])) {
            return $fail([$at('Specify a stdio command or an HTTP URL, not both', 'url')]);
        }

        $declared = $transport ?? $type;
        if ($declared === 'sse') {
            return $fail([$at(self::SSE_MESSAGE, $transport !== null ? 'transport' : 'type')]);
        }
        if ($declared !== null && !in_array($declared, ['stdio', 'http'], true)) {
            return $fail([$at('Invalid transport "' . $declared . '": expected "stdio" or "http"', 'transport')]);
        }

        $kind = $declared ?? (isset($value['command']) ? 'stdio' : (isset($value['url']) ? 'http' : null));
        if ($kind === null) {
            return $fail([$at('Specify a stdio command or an HTTP URL')]);
        }

        $mode = $value['mode'] ?? 'auto';
        if (!in_array($mode, self::MODES, true)) {
            return $fail([$at('Invalid option: expected one of "auto"|"modern"|"legacy"', 'mode')]);
        }

        $legacy = $mode === 'legacy';
        $never = [self::REFUSED_PROVIDER_KEY => self::REFUSED_PROVIDER_MESSAGE, 'resourceSubscriptions' => 'resourceSubscriptions is not supported by this port: the client has no resource subscription requests', 'onRootsListChanged' => self::CLIENT_NEVER['onRootsListChanged']];
        $allowed = ['transport', 'type', 'mode', 'outputHandling', 'defaultToolTimeout', ...self::NOTIFICATION_KEYS];

        if ($legacy) {
            $allowed = [...$allowed, 'onElicitation', 'onInitialized'];
            $never['elicitation'] = 'elicitation requires mode: auto or modern; legacy servers use onElicitation';
            $never['logLevel'] = 'logLevel requires mode: auto or modern; use setLoggingLevel for legacy servers';
        } else {
            $allowed = [...$allowed, 'logLevel', 'elicitation'];
            $never['onElicitation'] = 'onElicitation requires mode: legacy; modern elicitation uses LangGraph interrupts';
            $never['onInitialized'] = 'onInitialized requires mode: legacy';
        }

        if ($kind === 'stdio') {
            $allowed = [...$allowed, 'command', 'args', 'env', 'stderr', 'cwd', 'restart'];
            $never['url'] = 'Specify a stdio command or an HTTP URL, not both';
            $never['encoding'] = 'SDK 2 stdio does not support encoding; remove this option';
        } else {
            $allowed = [...$allowed, 'url', 'headers', 'reconnect'];
            $never['command'] = 'Specify a stdio command or an HTTP URL, not both';
            if ($legacy) {
                $allowed[] = 'automaticSSEFallback';
            } else {
                $never['automaticSSEFallback'] = 'automaticSSEFallback requires mode: legacy';
                unset($allowed[array_search('reconnect', $allowed, true)]);
                $never['reconnect'] = 'reconnect is legacy-only; modern streams cannot replay lost requests';
            }
        }

        $issues = [];
        foreach ($value as $key => $setting) {
            $key = (string) $key;
            if ($setting === null) {
                continue;
            }
            if (isset($never[$key])) {
                $issues[] = $at($never[$key], $key);
            } elseif (!in_array($key, $allowed, true)) {
                $issues[] = $at('Unrecognized key: "' . $key . '"');
            }
        }
        if ($issues !== []) {
            return $fail($issues);
        }

        $resolved = ['mode' => $mode, 'transport' => $kind];
        $issues = $kind === 'stdio'
            ? self::stdioIssues($value, $resolved, $path)
            : self::httpIssues($value, $resolved, $path, $legacy);

        foreach (['outputHandling', 'defaultToolTimeout'] as $shared) {
            if (isset($value[$shared])) {
                array_push($issues, ...self::sharedOptionIssues($shared, $value[$shared], $path));
                $resolved[$shared] = $value[$shared];
            }
        }
        if (!$legacy) {
            if (isset($value['logLevel'])) {
                if (!in_array($value['logLevel'], self::LOG_LEVELS, true)) {
                    $issues[] = $at('Invalid option: expected one of ' . implode('|', array_map(static fn (string $l): string => '"' . $l . '"', self::LOG_LEVELS)), 'logLevel');
                } else {
                    $resolved['logLevel'] = $value['logLevel'];
                }
            }
            $elicitation = $value['elicitation'] ?? true;
            if (!is_bool($elicitation)) {
                $issues[] = $at('Invalid input: expected boolean, received ' . Hooks::describe($elicitation), 'elicitation');
            } else {
                $resolved['elicitation'] = $elicitation;
            }
        }
        foreach ([...self::NOTIFICATION_KEYS, ...($legacy ? ['onElicitation', 'onInitialized'] : [])] as $callback) {
            if (!isset($value[$callback])) {
                continue;
            }
            if (!is_callable($value[$callback])) {
                $issues[] = $at('Expected a callback', $callback);
            } else {
                $resolved[$callback] = $value[$callback];
            }
        }

        return [$resolved, $issues];
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, mixed> $resolved
     * @param list<int|string>     $path
     *
     * @return list<array{path: list<int|string>, message: string}>
     */
    private static function stdioIssues(array $value, array &$resolved, array $path): array
    {
        $issues = [];
        $at = static fn (string $message, string ...$more): array => ['path' => [...$path, ...$more], 'message' => $message];

        $command = $value['command'] ?? null;
        if (!is_string($command)) {
            $issues[] = $at('Invalid input: expected string, received ' . Hooks::describe($command), 'command');
        }
        $resolved['command'] = $command;

        $args = $value['args'] ?? null;
        if (!is_array($args) || !array_is_list($args) || array_filter($args, static fn (mixed $arg): bool => !is_string($arg)) !== []) {
            $issues[] = $at('Invalid input: expected array of strings, received ' . Hooks::describe($args), 'args');
        }
        $resolved['args'] = $args;

        if (isset($value['env'])) {
            array_push($issues, ...self::stringMapIssues($value['env'], [...$path, 'env']));
            $resolved['env'] = $value['env'];
        }

        $stderr = $value['stderr'] ?? 'inherit';
        if ($stderr === 'overlapped') {
            $issues[] = $at('stderr: "overlapped" is a Windows only Node setting and is not supported; use "inherit", "pipe" or "ignore"', 'stderr');
        } elseif (!in_array($stderr, self::STDERR_MODES, true)) {
            $issues[] = $at('Invalid option: expected one of "inherit"|"pipe"|"ignore"', 'stderr');
        }
        $resolved['stderr'] = $stderr;

        if (isset($value['cwd'])) {
            if (!is_string($value['cwd'])) {
                $issues[] = $at('Invalid input: expected string, received ' . Hooks::describe($value['cwd']), 'cwd');
            }
            $resolved['cwd'] = $value['cwd'];
        }

        if (isset($value['restart'])) {
            array_push($issues, ...self::retryPolicyIssues($value['restart'], [...$path, 'restart'], $restart));
            $resolved['restart'] = $restart;
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, mixed> $resolved
     * @param list<int|string>     $path
     *
     * @return list<array{path: list<int|string>, message: string}>
     */
    private static function httpIssues(array $value, array &$resolved, array $path, bool $legacy): array
    {
        $issues = [];
        $at = static fn (string $message, string ...$more): array => ['path' => [...$path, ...$more], 'message' => $message];

        $url = $value['url'] ?? null;
        if (!is_string($url) || !self::isUrl($url)) {
            $issues[] = $at(is_string($url) ? 'Invalid URL' : 'Invalid input: expected string, received ' . Hooks::describe($url), 'url');
        }
        $resolved['url'] = $url;

        if (isset($value['headers'])) {
            array_push($issues, ...self::headerIssues($value['headers'], [...$path, 'headers']));
            $resolved['headers'] = $value['headers'];
        }

        if (isset($value['reconnect'])) {
            array_push($issues, ...self::retryPolicyIssues($value['reconnect'], [...$path, 'reconnect'], $reconnect));
            $resolved['reconnect'] = $reconnect;
        }

        if ($legacy) {
            $fallback = $value['automaticSSEFallback'] ?? true;
            if (!is_bool($fallback)) {
                $issues[] = $at('Invalid input: expected boolean, received ' . Hooks::describe($fallback), 'automaticSSEFallback');
            }
            $resolved['automaticSSEFallback'] = $fallback;
        }

        return $issues;
    }

    /**
     * Port of `stdioRestartSchema` / `streamableHttpReconnectSchema`: `enabled`, `maxAttempts`
     * (a non-negative integer), `delayMs` (a non-negative number).
     *
     * @param list<int|string>          $path
     * @param array<string, mixed>|null $resolved
     *
     * @return list<array{path: list<int|string>, message: string}>
     */
    private static function retryPolicyIssues(mixed $policy, array $path, ?array &$resolved): array
    {
        $resolved = [];
        if (!self::isObject($policy)) {
            return [['path' => $path, 'message' => 'Invalid input: expected object, received ' . Hooks::describe($policy)]];
        }

        $issues = [];
        $enabled = $policy['enabled'] ?? null;
        if ($enabled !== null) {
            if (!is_bool($enabled)) {
                $issues[] = ['path' => [...$path, 'enabled'], 'message' => 'Invalid input: expected boolean, received ' . Hooks::describe($enabled)];
            }
            $resolved['enabled'] = $enabled;
        }

        $attempts = $policy['maxAttempts'] ?? null;
        if ($attempts !== null) {
            if (is_float($attempts) && is_finite($attempts) && floor($attempts) === $attempts) {
                $attempts = (int) $attempts;
            }
            if (!is_int($attempts)) {
                $issues[] = ['path' => [...$path, 'maxAttempts'], 'message' => 'Invalid input: expected int, received ' . Hooks::describe($attempts)];
            } elseif ($attempts < 0) {
                $issues[] = ['path' => [...$path, 'maxAttempts'], 'message' => 'Too small: expected number to be >=0'];
            }
            $resolved['maxAttempts'] = $attempts;
        }

        $delay = $policy['delayMs'] ?? null;
        if ($delay !== null) {
            if (!is_int($delay) && !is_float($delay)) {
                $issues[] = ['path' => [...$path, 'delayMs'], 'message' => 'Invalid input: expected number, received ' . Hooks::describe($delay)];
            } elseif ($delay < 0) {
                $issues[] = ['path' => [...$path, 'delayMs'], 'message' => 'Too small: expected number to be >=0'];
            }
            $resolved['delayMs'] = $delay;
        }

        return $issues;
    }

    /**
     * `outputHandling`, `defaultToolTimeout` and the tool hooks, which the connection and the
     * client share.
     *
     * @param list<int|string> $path
     *
     * @return list<array{path: list<int|string>, message: string}>
     */
    private static function sharedOptionIssues(string $key, mixed $value, array $path): array
    {
        if ($key === 'defaultToolTimeout') {
            if (!is_int($value) && !is_float($value)) {
                return [['path' => [...$path, $key], 'message' => 'Invalid input: expected number, received ' . Hooks::describe($value)]];
            }

            return $value < 1 ? [['path' => [...$path, $key], 'message' => 'Too small: expected number to be >=1']] : [];
        }

        try {
            $key === 'outputHandling' ? McpTools::parseOptions([$key => $value]) : Hooks::parseToolHooks([$key => $value]);
        } catch (ValidationException $e) {
            return array_map(
                static fn (array $issue): array => ['path' => [...$path, ...$issue['path']], 'message' => $issue['message']],
                $e->issues,
            );
        }

        return [];
    }

    /**
     * @param list<int|string> $path
     *
     * @return list<array{path: list<int|string>, message: string}>
     */
    private static function headerIssues(mixed $headers, array $path): array
    {
        return self::stringMapIssues($headers, $path);
    }

    /**
     * @param list<int|string> $path
     *
     * @return list<array{path: list<int|string>, message: string}>
     */
    private static function stringMapIssues(mixed $map, array $path): array
    {
        if (!self::isObject($map)) {
            return [['path' => $path, 'message' => 'Invalid input: expected record, received ' . Hooks::describe($map)]];
        }

        $issues = [];
        foreach ($map as $key => $entry) {
            if (!is_string($entry)) {
                $issues[] = ['path' => [...$path, (string) $key], 'message' => 'Invalid input: expected string, received ' . Hooks::describe($entry)];
            }
        }

        return $issues;
    }

    private static function isUrl(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false && isset($parts['scheme']) && ($parts['host'] ?? '') !== '';
    }

    /** A map of server name to connection has connections as values; a lone connection has settings. */
    private static function looksLikeConnection(array $map): bool
    {
        foreach (['command', 'url', 'transport', 'type'] as $key) {
            if (isset($map[$key]) && is_scalar($map[$key])) {
                return true;
            }
        }

        return false;
    }

    /** A JSON object: an associative array (an empty array counts). */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * @param list<array{path: list<int|string>, message: string}> $issues
     */
    private static function error(array $issues): McpClientError
    {
        $lines = array_map(
            static fn (array $issue): string => ($issue['path'] === [] ? '' : implode('.', array_map('strval', $issue['path'])) . ': ') . $issue['message'],
            $issues,
        );

        return new McpClientError('Invalid MCP client configuration: ' . implode('; ', $lines), null, new ValidationException($issues));
    }
}
