<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangGraph\Mcp\Connection\ConnectionConfig;
use LangGraph\Mcp\McpClientError;
use LangGraph\Mcp\MultiServerMcpClient;
use LangGraph\Mcp\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Port of the constructor, "configuration boundary" and "protocol-specific server configuration"
 * blocks of `client.basic.test.ts`, plus the config checks scattered through `client.test.ts`.
 *
 * Zod's `ZodError` becomes an {@see McpClientError} whose `cause` is a {@see ValidationException}.
 * The SSE transport and provider based authentication are refused rather than accepted, so the
 * upstream cases that exercise them assert the refusal.
 */
#[CoversClass(ConnectionConfig::class)]
#[CoversClass(MultiServerMcpClient::class)]
final class ConnectionConfigTest extends MultiServerTestCase
{
    private const URL = 'https://example.com/mcp';

    /** @param array<string, mixed> $config */
    private static function invalid(array $config, string $expected = ''): McpClientError
    {
        $error = self::thrownBy(static fn () => new MultiServerMcpClient($config));
        self::assertInstanceOf(McpClientError::class, $error);
        self::assertInstanceOf(ValidationException::class, $error->cause);
        if ($expected !== '') {
            self::assertStringContainsString($expected, $error->getMessage());
        }

        return $error;
    }

    public function testRejectsEmptyConnections(): void
    {
        self::invalid([], 'No MCP servers provided');
    }

    public function testProcessesAValidStdioConnection(): void
    {
        $client = new MultiServerMcpClient(['test-server' => ['mode' => 'legacy', 'transport' => 'stdio', 'command' => 'python', 'args' => ['./script.py']]]);

        // The flat form is lifted under `servers` and the stdio defaults applied.
        self::assertEquals(
            ['mode' => 'legacy', 'transport' => 'stdio', 'command' => 'python', 'args' => ['./script.py'], 'stderr' => 'inherit'],
            $client->config()['servers']['test-server'],
        );
    }

    public function testProcessesAValidStreamableHttpConnection(): void
    {
        $client = new MultiServerMcpClient(['test-server' => ['mode' => 'legacy', 'transport' => 'http', 'url' => 'http://localhost:8000/mcp']]);

        self::assertEquals(
            ['mode' => 'legacy', 'transport' => 'http', 'url' => 'http://localhost:8000/mcp', 'automaticSSEFallback' => true],
            $client->config()['servers']['test-server'],
        );
    }

    public function testAnAutoConnectionResolvesItsModernDefaults(): void
    {
        $client = new MultiServerMcpClient(['servers' => ['remote' => ['url' => self::URL], 'local' => ['command' => 'node', 'args' => []]]]);

        self::assertEquals(['mode' => 'auto', 'transport' => 'http', 'url' => self::URL, 'elicitation' => true], $client->config()['servers']['remote']);
        self::assertEquals(['mode' => 'auto', 'transport' => 'stdio', 'command' => 'node', 'args' => [], 'stderr' => 'inherit', 'elicitation' => true], $client->config()['servers']['local']);
    }

    public function testRefusesTheSseTransportInEverySpelling(): void
    {
        foreach ([
            ['mode' => 'legacy', 'transport' => 'sse', 'url' => 'http://localhost:8000/sse', 'headers' => ['Authorization' => 'Bearer token']],
            ['mode' => 'legacy', 'type' => 'sse', 'url' => self::URL],
            ['transport' => 'sse', 'url' => 'https://example.com/sse'],
            ['mode' => 'modern', 'transport' => 'sse', 'url' => 'https://example.com/sse'],
            ['transport' => 'sse', 'url' => 'https://example.com/sse', 'elicitation' => true],
            ['transport' => 'sse', 'url' => 'https://example.com/sse', 'logLevel' => 'info'],
        ] as $connection) {
            $error = self::invalid(['servers' => ['remote' => $connection]], 'SSE transport is not supported');
            self::assertStringContainsString('remote.', $error->getMessage());
        }
    }

    public function testRefusesAProviderObjectOnEveryConnectionKind(): void
    {
        foreach ([
            ['url' => self::URL, 'authProvider' => new \stdClass()],
            ['command' => 'node', 'args' => [], 'authProvider' => ['token' => 'x']],
            ['mode' => 'legacy', 'url' => self::URL, 'authProvider' => static fn () => 'token'],
        ] as $connection) {
            self::invalid(['servers' => ['remote' => $connection]], 'Provider based authentication is not supported');
        }
    }

    public function testAnUnknownTransportIsRejectedByName(): void
    {
        self::invalid(['test-server' => ['transport' => 'invalid']], 'Invalid transport "invalid"');
        self::invalid(['test-server' => ['transport' => 'invalid', 'url' => 'http://localhost:8000/invalid']], 'Invalid transport "invalid"');
        self::invalid(['mcpServers' => ['bad' => ['transport' => 'carrier-pigeon', 'url' => 'http://127.0.0.1:1']]], 'carrier-pigeon');
    }

    public function testRejectsAStreamableHttpConnectionWithoutAUrl(): void
    {
        self::invalid(['test-server' => ['mode' => 'legacy', 'transport' => 'http']], 'Invalid input: expected string, received undefined');
    }

    public function testRejectsAnInvalidUrl(): void
    {
        self::invalid(['test-server' => ['mode' => 'legacy', 'transport' => 'http', 'url' => 'invalid-url']], 'Invalid URL');
    }

    /** @return array<string, array{callable(): array<string, mixed>}> */
    public static function inputShapes(): array
    {
        $remote = ['url' => self::URL];

        return [
            'servers' => [static fn (): array => ['servers' => ['remote' => $remote]]],
            'mcpServers' => [static fn (): array => ['mcpServers' => ['remote' => $remote]]],
            'bare map' => [static fn (): array => ['remote' => $remote]],
        ];
    }

    #[DataProvider('inputShapes')]
    public function testExposesTheSameCanonicalSnapshotForEveryInputShape(callable $config): void
    {
        $snapshot = (new MultiServerMcpClient($config()))->config();

        self::assertSame('auto', $snapshot['servers']['remote']['mode']);
        self::assertArrayNotHasKey('mcpServers', $snapshot);
        self::assertSame(['remote'], array_keys($snapshot['servers']));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unsupportedSettings(): array
    {
        return [
            'stdio encoding' => [['command' => 'node', 'args' => [], 'encoding' => 'utf8']],
            'negative restart attempts' => [['command' => 'node', 'args' => [], 'restart' => ['maxAttempts' => -1]]],
            'fractional restart attempts' => [['command' => 'node', 'args' => [], 'restart' => ['maxAttempts' => 0.5]]],
            'negative restart delay' => [['command' => 'node', 'args' => [], 'restart' => ['delayMs' => -1]]],
            'negative reconnect attempts' => [['mode' => 'legacy', 'url' => self::URL, 'reconnect' => ['maxAttempts' => -1]]],
            'fractional reconnect attempts' => [['mode' => 'legacy', 'url' => self::URL, 'reconnect' => ['maxAttempts' => 0.5]]],
            'negative reconnect delay' => [['mode' => 'legacy', 'url' => self::URL, 'reconnect' => ['delayMs' => -1]]],
            'overlapped stderr' => [['command' => 'node', 'args' => [], 'stderr' => 'overlapped']],
            'unknown stderr' => [['command' => 'node', 'args' => [], 'stderr' => 'tee']],
            'non-string argument' => [['command' => 'node', 'args' => ['ok', 3]]],
            'missing arguments' => [['command' => 'node']],
            'non-string header' => [['url' => self::URL, 'headers' => ['X-Test' => 1]]],
            'resource subscriptions' => [['mode' => 'legacy', 'url' => self::URL, 'resourceSubscriptions' => ['file:///a']]],
        ];
    }

    /** @param array<string, mixed> $server */
    #[DataProvider('unsupportedSettings')]
    public function testRejectsUnsupportedTransportSettingsBeforeConnecting(array $server): void
    {
        self::invalid(['servers' => ['test' => $server]]);
        self::assertSame(0, $this->factory->attempts);
    }

    public function testAllowsZeroRetriesAndZeroDelay(): void
    {
        $client = new MultiServerMcpClient(['servers' => [
            'local' => ['command' => 'node', 'args' => [], 'restart' => ['maxAttempts' => 0, 'delayMs' => 0]],
            'remote' => ['mode' => 'legacy', 'url' => self::URL, 'reconnect' => ['maxAttempts' => 0, 'delayMs' => 0]],
        ]]);

        self::assertSame(['local', 'remote'], array_keys($client->config()['servers']));
    }

    public function testNormalizesTheTypeAliasWithoutConnecting(): void
    {
        $client = new MultiServerMcpClient(['servers' => ['remote' => ['mode' => 'legacy', 'type' => 'http', 'url' => self::URL]]]);

        self::assertSame('http', $client->config()['servers']['remote']['transport']);
        self::assertArrayNotHasKey('type', $client->config()['servers']['remote']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function connectionsNamedServers(): array
    {
        return [
            'url' => [['url' => self::URL]],
            'command' => [['command' => 'node', 'args' => ['server.js']]],
        ];
    }

    /** @param array<string, mixed> $connection */
    #[DataProvider('connectionsNamedServers')]
    public function testAcceptsALegacyServerNamedServers(array $connection): void
    {
        $client = new MultiServerMcpClient(['servers' => $connection, 'other' => ['url' => 'https://example.com/other']]);

        self::assertSame(['servers', 'other'], array_keys($client->config()['servers']));
        foreach ($connection as $key => $value) {
            self::assertSame($value, $client->config()['servers']['servers'][$key]);
        }
    }

    public function testKeepsCanonicalServerNamesIndependentOfConnectionFieldNames(): void
    {
        $client = new MultiServerMcpClient(['servers' => [
            'url' => ['url' => self::URL],
            'command' => ['command' => 'node', 'args' => []],
            'servers' => ['url' => 'https://example.com/other'],
        ]]);

        self::assertSame(['url', 'command', 'servers'], array_keys($client->config()['servers']));
    }

    public function testAcceptsUndefinedOutputDestinationsAtGlobalAndServerScope(): void
    {
        $client = new MultiServerMcpClient([
            'servers' => ['remote' => ['url' => self::URL, 'outputHandling' => ['image' => null]]],
            'outputHandling' => ['text' => null, 'audio' => 'artifact'],
        ]);

        self::assertSame(['text' => null, 'audio' => 'artifact'], $client->config()['outputHandling']);
        self::assertSame(['image' => null], $client->config()['servers']['remote']['outputHandling']);
    }

    public function testRejectsMixedConfigurationSpellingsAndConflictingTransportChoices(): void
    {
        self::invalid(['servers' => [], 'mcpServers' => []], 'not both');
        self::invalid(['servers' => ['remote' => ['mode' => 'legacy', 'transport' => 'http', 'type' => 'sse', 'url' => self::URL]]], 'conflicts with transport');
        self::invalid(['servers' => ['remote' => ['transport' => 'stdio', 'type' => 'http', 'command' => 'node', 'args' => []]]], 'conflicts with transport');
    }

    public function testRejectsAConnectionThatMixesACommandAndAUrl(): void
    {
        self::invalid(['servers' => ['remote' => ['command' => 'node', 'args' => [], 'url' => self::URL]]], 'command or an HTTP URL');
    }

    public function testRejectsAConnectionWithNeitherACommandNorAUrl(): void
    {
        self::invalid(['servers' => ['remote' => ['headers' => ['A' => 'b']]]], 'Specify a stdio command or an HTTP URL');
    }

    public function testRetainsCallbackIdentityAndIsolatesTheConfigurationSnapshot(): void
    {
        $onMessage = static function (): void {
        };
        $beforeToolCall = static fn (): ?array => null;

        $client = new MultiServerMcpClient([
            'servers' => [
                'local' => ['onMessage' => $onMessage, 'command' => 'node', 'args' => ['server.js'], 'env' => ['MODE' => 'test'], 'restart' => ['enabled' => false]],
                'remote' => ['mode' => 'legacy', 'url' => self::URL, 'headers' => ['X-Test' => 'original'], 'reconnect' => ['enabled' => false]],
            ],
            'outputHandling' => ['text' => 'content'],
            'beforeToolCall' => $beforeToolCall,
        ]);

        $snapshot = $client->config();
        self::assertSame($onMessage, $snapshot['servers']['local']['onMessage']);
        self::assertSame($beforeToolCall, $snapshot['beforeToolCall']);

        $snapshot['servers']['local']['args'][] = 'changed';
        $snapshot['servers']['local']['env']['MODE'] = 'changed';
        $snapshot['servers']['local']['restart']['enabled'] = true;
        $snapshot['servers']['remote']['headers']['X-Test'] = 'changed';
        $snapshot['servers']['remote']['reconnect']['enabled'] = true;

        $fresh = $client->config()['servers'];
        self::assertSame(['server.js'], $fresh['local']['args']);
        self::assertSame(['MODE' => 'test'], $fresh['local']['env']);
        self::assertSame(['enabled' => false], $fresh['local']['restart']);
        self::assertSame(['X-Test' => 'original'], $fresh['remote']['headers']);
        self::assertSame(['enabled' => false], $fresh['remote']['reconnect']);
    }

    public function testAllowsInspectingTheConfigurationAndCopiesIt(): void
    {
        $config = ['mcpServers' => ['test-server' => ['mode' => 'legacy', 'url' => 'http://example.com/mcp']], 'throwOnLoadError' => false, 'prefixToolNameWithServerName' => true];
        $client = new MultiServerMcpClient($config);

        self::assertArrayHasKey('test-server', $client->config()['servers']);
        self::assertFalse($client->config()['throwOnLoadError']);
        self::assertTrue($client->config()['prefixToolNameWithServerName']);

        $config['throwOnLoadError'] = true;
        self::assertFalse($client->config()['throwOnLoadError']);
    }

    public function testDefaultsTheClientWideOptions(): void
    {
        $config = (new MultiServerMcpClient(['servers' => ['remote' => ['url' => self::URL]]]))->config();

        self::assertTrue($config['throwOnLoadError']);
        self::assertFalse($config['prefixToolNameWithServerName']);
        self::assertSame('', $config['additionalToolNamePrefix']);
        self::assertSame('throw', $config['onConnectionError']);
    }

    public function testRejectsInvalidCallbacksAndUnknownOutputDestinations(): void
    {
        $servers = ['remote' => ['url' => self::URL]];

        self::invalid(['servers' => $servers, 'beforeToolCall' => 'invalid'], 'beforeToolCall');
        self::invalid(['servers' => $servers, 'afterToolCall' => 42], 'afterToolCall');
        self::invalid(['servers' => $servers, 'outputHandling' => ['typo' => 'content']], 'outputHandling');
        self::invalid(['servers' => $servers, 'onConnectionError' => 'sometimes'], 'connection error handler');
        self::invalid(['servers' => ['remote' => ['url' => self::URL, 'onMessage' => 'not a function']]], 'Expected a callback');
    }

    public function testValidatesTheClientWideOptionTypes(): void
    {
        $servers = ['remote' => ['url' => self::URL]];

        self::invalid(['servers' => $servers, 'throwOnLoadError' => 'yes'], 'expected boolean');
        self::invalid(['servers' => $servers, 'prefixToolNameWithServerName' => 1], 'expected boolean');
        self::invalid(['servers' => $servers, 'additionalToolNamePrefix' => 5], 'expected string');
        self::invalid(['servers' => $servers, 'surprise' => true], 'Unrecognized key: "surprise"');
    }

    /** @return array<string, array{mixed}> */
    public static function badTimeouts(): array
    {
        return ['zero' => [0], 'negative' => [-5], 'fraction below one' => [0.5], 'string' => ['100']];
    }

    #[DataProvider('badTimeouts')]
    public function testRejectsADefaultToolTimeoutBelowOneMillisecond(mixed $timeout): void
    {
        self::invalid(['servers' => ['remote' => ['url' => self::URL]], 'defaultToolTimeout' => $timeout], 'defaultToolTimeout');
        self::invalid(['servers' => ['remote' => ['url' => self::URL, 'defaultToolTimeout' => $timeout]]], 'defaultToolTimeout');
    }

    public function testKeepsCallbacksOnTheirOwningServerAndPreservesTheirIdentity(): void
    {
        $onMessage = static function (): void {
        };
        $onInitialized = static function (): void {
        };

        $client = new MultiServerMcpClient(['servers' => [
            'modern' => ['url' => self::URL, 'onMessage' => $onMessage],
            'legacy' => ['mode' => 'legacy', 'url' => 'https://example.com/old', 'onInitialized' => $onInitialized],
        ]]);

        $servers = $client->config()['servers'];
        self::assertSame($onMessage, $servers['modern']['onMessage']);
        self::assertArrayNotHasKey('onMessage', $servers['legacy']);
        self::assertSame($onInitialized, $servers['legacy']['onInitialized']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidModernFields(): array
    {
        return [
            'automaticSSEFallback' => [['automaticSSEFallback' => true]],
            'onInitialized' => [['onInitialized' => static fn () => null]],
            'onRootsListChanged' => [['onRootsListChanged' => static fn () => null]],
            'unexpected option' => [['unexpectedOption' => true]],
            'onElicitation' => [['onElicitation' => static fn () => null]],
            'reconnect' => [['reconnect' => ['enabled' => true]]],
        ];
    }

    /** @param array<string, mixed> $invalid */
    #[DataProvider('invalidModernFields')]
    public function testRejectsInvalidModernFieldsInEveryConstructorShape(array $invalid): void
    {
        $remote = ['url' => self::URL, ...$invalid];

        foreach ([['servers' => ['remote' => $remote]], ['mcpServers' => ['remote' => $remote]], ['remote' => $remote]] as $config) {
            self::invalid($config);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function legacyOnlyViolations(): array
    {
        return [
            'elicitation' => ['elicitation', 'elicitation requires mode: auto or modern'],
            'logLevel' => ['logLevel', 'logLevel requires mode: auto or modern'],
        ];
    }

    #[DataProvider('legacyOnlyViolations')]
    public function testALegacyServerRefusesModernOnlyOptions(string $key, string $message): void
    {
        self::invalid(['servers' => ['remote' => ['mode' => 'legacy', 'url' => self::URL, $key => $key === 'elicitation' ? true : 'info']]], $message);
    }

    public function testRejectsTopLevelProtocolCallbacksInsteadOfSilentlyDroppingThem(): void
    {
        foreach (['onMessage', 'onProgress', 'onInitialized', 'onPromptsListChanged', 'onResourcesListChanged', 'onResourcesUpdated', 'onToolsListChanged'] as $callback) {
            self::invalid(['servers' => ['remote' => ['url' => self::URL]], $callback => static fn () => null], $callback);
        }
        self::invalid(['servers' => ['remote' => ['url' => self::URL]], 'onElicitation' => static fn () => null], 'Move onElicitation into a legacy server definition');
        self::invalid(['servers' => ['remote' => ['url' => self::URL]], 'logLevel' => 'info'], 'Move logLevel into a modern server definition');
    }

    public function testExplainsThatUseStandardContentBlocksWasRemoved(): void
    {
        self::invalid(
            ['servers' => ['svc' => ['transport' => 'http', 'url' => 'http://localhost:8000/mcp']], 'useStandardContentBlocks' => true],
            'useStandardContentBlocks was removed: tool content is always standard LangChain content blocks',
        );
    }

    public function testValidatesTheModeAndLogLevel(): void
    {
        self::invalid(['servers' => ['remote' => ['url' => self::URL, 'mode' => 'ancient']]], 'expected one of "auto"|"modern"|"legacy"');
        self::invalid(['servers' => ['remote' => ['url' => self::URL, 'logLevel' => 'loud']]], 'expected one of "debug"');

        $config = (new MultiServerMcpClient(['servers' => ['remote' => ['url' => self::URL, 'logLevel' => 'info', 'elicitation' => false]]]))->config();
        self::assertSame('info', $config['servers']['remote']['logLevel']);
        self::assertFalse($config['servers']['remote']['elicitation']);
    }

    public function testRejectsAConnectionThatIsNotAnObject(): void
    {
        self::invalid(['servers' => ['remote' => 'https://example.com/mcp']], 'expected object, received string');
        self::invalid(['servers' => ['remote' => ['a', 'b']]], 'expected object, received array');
    }

    public function testTheErrorListsTheIssuesWithTheirPaths(): void
    {
        $error = self::invalid(['servers' => ['one' => ['url' => 'nope'], 'two' => ['url' => self::URL, 'headers' => ['A' => 1]]]]);

        self::assertStringContainsString('servers.one.url: Invalid URL', $error->getMessage());
        self::assertStringContainsString('servers.two.headers.A: Invalid input: expected string, received number', $error->getMessage());
        self::assertCount(2, $error->cause->issues);
        self::assertSame(['servers', 'one', 'url'], $error->cause->issues[0]['path']);
    }

    /** @return array<string, array{callable(MultiServerMcpClient, array<string, mixed>): mixed}> */
    public static function publicMethods(): array
    {
        return [
            'listToolsets' => [static fn (MultiServerMcpClient $c, array $o) => $c->listToolsets($o)],
            'initializeConnections' => [static fn (MultiServerMcpClient $c, array $o) => $c->initializeConnections($o)],
            'listTools' => [static fn (MultiServerMcpClient $c, array $o) => $c->listTools([], $o)],
            'getTools' => [static fn (MultiServerMcpClient $c, array $o) => $c->getTools([], $o)],
            'listResources' => [static fn (MultiServerMcpClient $c, array $o) => $c->listResources([], $o)],
            'getClient' => [static fn (MultiServerMcpClient $c, array $o) => $c->getClient('modern', $o)],
            'readResource' => [static fn (MultiServerMcpClient $c, array $o) => $c->readResource('modern', 'file:///test', $o)],
        ];
    }

    #[DataProvider('publicMethods')]
    public function testRejectsUnknownPublicOptionsBeforeOpeningAConnection(callable $call): void
    {
        $client = $this->adapter(['servers' => ['modern' => ['url' => self::URL]]]);

        $error = self::thrownBy(static fn () => $call($client, ['headers' => [], 'cacheMdoe' => 'refresh']));

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertStringContainsString('Unrecognized key: "cacheMdoe"', $error->getMessage());
        self::assertSame(0, $this->factory->attempts);
    }

    public function testResourceMethodsAcceptTransportOptionsButNotDiscoveryCacheOptions(): void
    {
        $client = $this->adapter(['servers' => ['modern' => ['url' => self::URL]]]);

        $error = self::thrownBy(static fn () => $client->listResources([], ['cacheMode' => 'refresh']));

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertStringContainsString('Unrecognized key: "cacheMode"', $error->getMessage());
        self::assertSame(0, $this->factory->attempts);
    }

    public function testDiscoveryOptionsRefuseAProviderAndABadCacheMode(): void
    {
        $client = $this->adapter(['servers' => ['modern' => ['url' => self::URL]]]);

        $provider = self::thrownBy(static fn () => $client->listToolsets(['authProvider' => new \stdClass()]));
        self::assertStringContainsString('Provider based authentication is not supported', $provider->getMessage());

        $mode = self::thrownBy(static fn () => $client->listToolsets(['cacheMode' => 'forever']));
        self::assertStringContainsString('expected one of "use"|"refresh"|"bypass"', $mode->getMessage());

        $headers = self::thrownBy(static fn () => $client->listToolsets(['headers' => ['A' => 1]]));
        self::assertStringContainsString('headers.A', $headers->getMessage());
        self::assertSame(0, $this->factory->attempts);
    }

    public function testAnArgumentListThatIsNotServerNamesIsRejected(): void
    {
        $client = $this->adapter(['servers' => ['modern' => ['url' => self::URL]]]);

        self::assertInstanceOf(McpClientError::class, self::thrownBy(static fn () => $client->listTools(['a', 3])));
        self::assertInstanceOf(McpClientError::class, self::thrownBy(static fn () => $client->listTools(['server' => 'a'])));
        self::assertInstanceOf(McpClientError::class, self::thrownBy(static fn () => $client->listTools(['a'], [], [])));
    }
}
