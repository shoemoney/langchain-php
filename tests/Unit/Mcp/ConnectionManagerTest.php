<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangGraph\Mcp\Connection\ConnectionManager;
use LangGraph\Mcp\Connection\ManagedClient;
use LangGraph\Mcp\McpClientError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Port of `connection.test.ts`: the connection pool over a scripted transport. The SDK classes
 * upstream mocks with `vi.mock` are replaced by the manager's transport factory seam; the SSE
 * cases are not ported (the transport is refused).
 */
#[CoversClass(ConnectionManager::class)]
#[CoversClass(ManagedClient::class)]
final class ConnectionManagerTest extends MultiServerTestCase
{
    private ConnectionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new ConnectionManager(null, $this->factory);
    }

    protected function tearDown(): void
    {
        try {
            $this->manager->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function transportKinds(): array
    {
        return ['http' => ['http'], 'stdio' => ['stdio']];
    }

    #[DataProvider('transportKinds')]
    public function testIsolatesNotificationOptionsForEachClient(string $kind): void
    {
        $observed = [];
        $mutate = function (array $source) use (&$observed, $kind): void {
            $options = $source['options'];
            $observed[] = $options;
            self::assertEquals(['text' => 'content'], $options['outputHandling']);
            $options['outputHandling']['text'] = 'artifact';

            if ($kind === 'stdio') {
                self::assertSame(['server.js'], $options['args']);
                self::assertSame(['MODE' => 'test'], $options['env']);
                self::assertSame(['enabled' => false], $options['restart']);
                $options['args'][] = 'changed';
                $options['env']['MODE'] = 'changed';
                $options['restart']['enabled'] = true;
            } else {
                self::assertSame('https://example.com/mcp', $options['url']);
                self::assertSame(['X-Test' => 'original'], $options['headers']);
                self::assertSame(['enabled' => false], $options['reconnect']);
                $options['url'] = 'https://example.com/changed';
                $options['headers']['X-Test'] = 'changed';
                $options['reconnect']['enabled'] = true;
            }
        };
        $messages = [];
        $onMessage = function (array $params, array $source) use (&$messages, $mutate): void {
            $messages[] = $params;
            $mutate($source);
        };

        $options = $kind === 'http'
            ? ['mode' => 'legacy', 'transport' => 'http', 'url' => 'https://example.com/mcp', 'automaticSSEFallback' => false, 'headers' => ['X-Test' => 'original'], 'reconnect' => ['enabled' => false]]
            : ['mode' => 'legacy', 'transport' => 'stdio', 'command' => 'node', 'args' => ['server.js'], 'stderr' => 'inherit', 'env' => ['MODE' => 'test'], 'restart' => ['enabled' => false]];
        $options += ['outputHandling' => ['text' => 'content'], 'onMessage' => $onMessage, 'onResourcesListChanged' => $mutate];

        $client = $this->manager->createClient($kind, 'test', $options);
        $params = ['level' => 'info', 'data' => 'test', '_meta' => ['extension' => true]];
        $transport = $this->transport();
        $transport->queue(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => $params]);
        $transport->queue(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => $params]);
        $transport->queue(['jsonrpc' => '2.0', 'method' => 'notifications/resources/list_changed']);
        $client->listTools();

        self::assertSame([$params, $params], $messages);
        self::assertCount(3, $observed);
        self::assertEquals(['text' => 'content'], $options['outputHandling']);
        self::assertEquals($observed[0], $observed[1]);
    }

    public function testCreatesAStdioClientAndConnects(): void
    {
        $client = $this->manager->createClient('stdio', 'stdio-server', [
            'mode' => 'legacy', 'transport' => 'stdio', 'command' => 'python', 'args' => ['./script.py'], 'stderr' => 'inherit',
        ]);

        self::assertInstanceOf(ManagedClient::class, $client);
        self::assertSame('stdio', $this->transport()->type);
        self::assertSame('python', $this->transport()->options['command']);
        self::assertSame(['./script.py'], $this->transport()->options['args']);
        self::assertSame(['initialize', 'notifications/initialized'], $this->transport()->methods());
        self::assertSame('legacy', $client->getProtocolEra());
    }

    public function testIdentifiesItselfAsTheAdapterAndOffersTheConfiguredProtocolMode(): void
    {
        $this->manager->createClient('stdio', 's', ['mode' => 'legacy', 'transport' => 'stdio', 'command' => 'node', 'args' => []]);
        $initialize = $this->transport()->lastParams('initialize');

        self::assertSame('@langchain/mcp-adapters', $initialize['clientInfo']['name']);
        self::assertSame('2025-11-25', $initialize['protocolVersion']);
    }

    public function testAnAutoConnectionOffersTheModernRevisionAndAcceptsALegacyAnswer(): void
    {
        $client = $this->manager->createClient('http', 's', ['mode' => 'auto', 'transport' => 'http', 'url' => 'http://localhost:8000/mcp']);

        self::assertSame('2026-07-28', $this->transport()->lastParams('initialize')['protocolVersion']);
        self::assertSame('legacy', $client->getProtocolEra());
    }

    public function testAModernPinnedConnectionRefusesALegacyServer(): void
    {
        $error = self::thrownBy(fn () => $this->manager->createClient('http', 's', ['mode' => 'modern', 'transport' => 'http', 'url' => 'http://localhost:8000/mcp']));

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertStringContainsString('pinned to modern', $error->getMessage());
        self::assertSame([], $this->manager->getAllClients());
    }

    public function testPassesCwdToTheTransport(): void
    {
        $this->manager->createClient('stdio', 'stdio-server', [
            'mode' => 'legacy', 'transport' => 'stdio', 'command' => 'node', 'args' => ['./server.js'], 'stderr' => 'inherit', 'cwd' => '/custom/working/directory',
        ]);

        self::assertSame('/custom/working/directory', $this->transport()->options['cwd']);
        self::assertSame(
            ['command' => 'node', 'args' => ['./server.js'], 'stderr' => 'inherit', 'cwd' => '/custom/working/directory'],
            ConnectionManager::transportConfig('stdio', $this->transport()->options),
        );
    }

    public function testMapsLegacyReconnectOptionsOntoTheTransportBackoff(): void
    {
        $config = ConnectionManager::transportConfig('http', [
            'mode' => 'legacy', 'url' => 'http://localhost:8000/mcp', 'reconnect' => ['enabled' => true, 'maxAttempts' => 5, 'delayMs' => 250],
        ]);

        self::assertSame('http://localhost:8000/mcp', $config['url']);
        self::assertSame(250, $config['reconnect']['initialReconnectionDelay']);
        self::assertSame(250, $config['reconnect']['maxReconnectionDelay']);
        self::assertSame(5, $config['reconnect']['maxRetries']);
        self::assertSame(1.5, $config['reconnect']['reconnectionDelayGrowFactor']);
    }

    public function testDisablingReconnectMeansZeroRetries(): void
    {
        $config = ConnectionManager::transportConfig('http', ['mode' => 'legacy', 'url' => 'http://x.test/mcp', 'reconnect' => ['enabled' => false, 'maxAttempts' => 5]]);

        self::assertSame(0, $config['reconnect']['maxRetries']);
    }

    public function testALegacyConnectionWithoutReconnectLeavesTheTransportDefaults(): void
    {
        $config = ConnectionManager::transportConfig('http', ['mode' => 'legacy', 'url' => 'http://x.test/mcp']);

        self::assertArrayNotHasKey('reconnect', $config);
    }

    #[DataProvider('modernModes')]
    public function testAConnectionNotPinnedToLegacyNeverRetriesAStream(string $mode): void
    {
        $config = ConnectionManager::transportConfig('http', ['mode' => $mode, 'url' => 'http://x.test/mcp']);

        self::assertSame(0, $config['reconnect']['maxRetries']);
        self::assertSame(1000, $config['reconnect']['initialReconnectionDelay']);
        self::assertSame(30000, $config['reconnect']['maxReconnectionDelay']);
    }

    /** @return array<string, array{string}> */
    public static function modernModes(): array
    {
        return ['auto' => ['auto'], 'modern' => ['modern']];
    }

    public function testHeadersReachTheTransportConfiguration(): void
    {
        $config = ConnectionManager::transportConfig('http', ['mode' => 'legacy', 'url' => 'http://x.test/mcp', 'headers' => ['Authorization' => 'Bearer token', 'X-Test' => '1']]);

        self::assertSame(['Authorization' => 'Bearer token', 'X-Test' => '1'], $config['headers']);
    }

    public function testRefusesTheSseTransportWithoutBuildingAnything(): void
    {
        $error = self::thrownBy(fn () => $this->manager->createClient('sse', 'sse-server', [
            'mode' => 'legacy', 'transport' => 'sse', 'url' => 'http://localhost:8000/sse',
        ]));

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertStringContainsString('SSE transport is not supported', $error->getMessage());
        self::assertSame(0, $this->factory->attempts);
    }

    public function testRefusesAnUnknownTransportType(): void
    {
        $this->expectException(McpClientError::class);
        $this->expectExceptionMessage('Invalid transport type: pigeon');

        $this->manager->createClient('pigeon', 'x', []);
    }

    public function testManagesDistinctConnectionsKeyedByHeaders(): void
    {
        $first = $this->manager->createClient('http', 'svc', $this->httpOptions(['headers' => ['A' => '1']]));
        $second = $this->manager->createClient('http', 'svc', $this->httpOptions(['headers' => ['A' => '2']]));

        self::assertNotSame($first, $second);
        self::assertCount(2, $this->manager->getAllClients());
        self::assertTrue($this->manager->has(['serverName' => 'svc', 'headers' => ['A' => '1']]));
        self::assertTrue($this->manager->has(['serverName' => 'svc', 'headers' => ['A' => '2']]));
        self::assertFalse($this->manager->has(['serverName' => 'svc', 'headers' => ['A' => '3']]));
        self::assertNotNull($this->manager->get(['serverName' => 'svc', 'headers' => ['A' => '1']]));
        self::assertNull($this->manager->get('svc'), 'the default identity never selects an override');
    }

    public function testHeaderIdentityIgnoresNameCase(): void
    {
        $client = $this->manager->createClient('http', 'svc', $this->httpOptions(['headers' => ['X-Tenant' => 'one']]));

        self::assertSame($client, $this->manager->get(['serverName' => 'svc', 'headers' => ['x-tenant' => 'one']]));
        self::assertSame($client, $this->manager->createClient('http', 'svc', $this->httpOptions(['headers' => ['X-TENANT' => 'one']])));
        self::assertSame(1, $this->factory->count());
    }

    public function testGetWithEmptyHeadersIsTheSameIdentityAsNone(): void
    {
        $client = $this->manager->createClient('http', 'svc', $this->httpOptions());

        self::assertSame($client, $this->manager->get(['serverName' => 'svc', 'headers' => []]));
        self::assertSame($client, $this->manager->get('svc'));
    }

    public function testGetTransportReturnsTheTransportBehindAClientOrAnIdentity(): void
    {
        $client = $this->manager->createClient('stdio', 's', ['mode' => 'legacy', 'transport' => 'stdio', 'command' => 'node', 'args' => ['-e', "console.log('ok')"], 'stderr' => 'inherit']);

        $byIdentity = $this->manager->getTransport(['serverName' => 's']);
        $byClient = $this->manager->getTransport($client);

        self::assertNotNull($byIdentity);
        self::assertSame($byIdentity, $byClient);
        self::assertSame($this->transport(), $byIdentity->inner());
        self::assertSame('node', $this->transport()->options['command']);
        self::assertNull($this->manager->getTransport(['serverName' => 'other']));
    }

    public function testDeletesOneConnectionAndThenAll(): void
    {
        $this->manager->createClient('http', 'svc', $this->httpOptions(['headers' => ['A' => '1']]));
        $this->manager->createClient('http', 'other', $this->httpOptions());
        self::assertCount(2, $this->manager->getAllClients());

        $this->manager->delete(['serverName' => 'svc', 'headers' => ['A' => '1']]);
        self::assertCount(1, $this->manager->getAllClients());
        self::assertSame(1, $this->transport(0)->closes);

        $this->manager->delete();
        self::assertSame([], $this->manager->getAllClients());
        self::assertSame(1, $this->transport(1)->closes);
    }

    public function testForksAnHttpClientWithNewHeadersAndKeepsTheBaseHeaders(): void
    {
        $base = $this->manager->createClient('http', 'svc', $this->httpOptions(['headers' => ['A' => '1', 'X-Keep' => 'base']]));

        $forked = $base->fork(['A' => '2']);

        self::assertNotSame($base, $forked);
        self::assertSame(2, $this->factory->count());
        self::assertCount(2, $this->manager->getAllClients());
        // The fork overrides the header it was handed and keeps the rest; names are lower-cased.
        self::assertSame(['a' => '2', 'x-keep' => 'base'], $this->transport(1)->options['headers']);
        self::assertSame(['A' => '1', 'X-Keep' => 'base'], $this->transport(0)->options['headers']);
        self::assertSame($base, $this->manager->get(['serverName' => 'svc', 'headers' => ['A' => '1', 'X-Keep' => 'base']]));
        self::assertSame($forked, $this->manager->get(['serverName' => 'svc', 'headers' => ['A' => '2', 'X-Keep' => 'base']]));
    }

    public function testForkingWithEquivalentHeadersReusesTheExistingConnection(): void
    {
        $base = $this->manager->createClient('http', 'svc', $this->httpOptions(['headers' => ['A' => '1']]));

        // Header names are case-insensitive, so this resolves to the same identity.
        self::assertSame($base, $base->fork(['a' => '1']));
        self::assertSame(1, $this->factory->count());
        self::assertCount(1, $this->manager->getAllClients());
    }

    public function testForkingWithNoHeadersIsTheSameClient(): void
    {
        $base = $this->manager->createClient('http', 'svc', $this->httpOptions());

        self::assertSame($base, $base->fork([]));
    }

    public function testForkingAStdioClientIsNotSupported(): void
    {
        $stdio = $this->manager->createClient('stdio', 'svc', ['mode' => 'legacy', 'transport' => 'stdio', 'command' => 'python', 'args' => ['./script.py'], 'stderr' => 'inherit']);

        $error = self::thrownBy(static fn () => $stdio->fork(['A' => '2']));

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertStringContainsString('Forking stdio transport is not supported', $error->getMessage());
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function httpOptions(array $extra = []): array
    {
        return [...['mode' => 'legacy', 'transport' => 'http', 'url' => 'http://localhost:8000/mcp', 'automaticSSEFallback' => false], ...$extra];
    }
}
