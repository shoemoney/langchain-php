<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Mcp\Connection\FakeTransportFactory;
use LangGraph\Mcp\Connection\ConnectionManager;
use LangGraph\Mcp\McpClientError;
use LangGraph\Mcp\MultiServerMcpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Port of `connection.lifecycle.test.ts` (OAuth provider identity excluded): who owns a connection,
 * what a failed or interrupted acquisition leaves behind, and how tool catalogs follow the
 * identity of the connection they came from.
 *
 * Upstream races promises (concurrent acquisitions, a discovery parked behind an in-flight close).
 * PHP is synchronous, so each race becomes its re-entrant equivalent: the competing call is made
 * from inside the transport, while the first is mid-flight.
 */
#[CoversClass(ConnectionManager::class)]
#[CoversClass(MultiServerMcpClient::class)]
final class ConnectionLifecycleTest extends MultiServerTestCase
{
    public function testReusesAConnectionAndMatchingForks(): void
    {
        $manager = new ConnectionManager(null, $this->factory);
        $options = self::http();

        $first = $manager->createClient('http', 'test', $options);
        $second = $manager->createClient('http', 'test', $options);

        self::assertSame($first, $second);
        self::assertSame(1, $this->factory->count());
        self::assertSame($first, $first->fork([]));
        $fork = $first->fork(['tenant' => 'one']);
        self::assertSame($fork, $first->fork(['Tenant' => 'one']));
        self::assertSame($fork, $first->fork(['tenant' => 'one']));
        self::assertSame($first, $manager->get('test'));
        self::assertNull($manager->get(['serverName' => 'test', 'headers' => ['tenant' => 'two']]));

        $manager->delete();
        self::assertSame(1, $this->transport(0)->closes);
        self::assertSame(1, $this->transport(1)->closes);
    }

    public function testRegistersNotificationHandlersBeforeConnectAndCleansUpAFailedHandshake(): void
    {
        $manager = new ConnectionManager(null, $this->factory);
        $failure = new McpClientError('handshake failed');
        $heard = [];

        $this->factory->script(function ($transport) use ($failure): void {
            // A notification that is waiting when the handshake starts reaches a handler only if
            // the handler was registered before connect.
            $transport->queue(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'info', 'data' => 'early']]);
            $transport->failOn['initialize'] = $failure;
        });

        $thrown = self::thrownBy(fn () => $manager->createClient('http', 'test', self::http(['onMessage' => function (array $params) use (&$heard): void {
            $heard[] = $params;
        }])));

        self::assertSame($failure, $thrown);
        self::assertSame(1, $this->transport(0)->closes);
        self::assertSame([], $manager->getAllClients());

        $manager->createClient('http', 'test', self::http());
        self::assertSame(2, $this->factory->count());
        $manager->delete();

        // The failure came from send(), before the queued notification could be read, so prove the
        // ordering with a handshake that succeeds.
        $order = new ConnectionManager(null, $factory = new FakeTransportFactory());
        $factory->script(static function ($transport): void {
            $transport->queue(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'info', 'data' => 'early']]);
        });
        $order->createClient('http', 'test', self::http(['onMessage' => function (array $params) use (&$heard): void {
            $heard[] = $params;
        }]));
        self::assertSame([['level' => 'info', 'data' => 'early']], $heard);
        $order->delete();
    }

    public function testSettlesAllClosesClearsOwnershipOnFailureAndToleratesARepeatedClose(): void
    {
        $manager = new ConnectionManager(null, $this->factory);
        $manager->createClient('http', 'one', self::http());
        $manager->createClient('http', 'two', self::http());
        $this->transport(0)->closeError = new \RuntimeException('close failed');

        $error = self::thrownBy(static fn () => $manager->delete());

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertSame('Failed to close MCP connections', $error->getMessage());
        self::assertIsArray($error->cause);
        self::assertCount(1, $error->cause);
        self::assertSame('close failed', $error->cause[0]->getMessage());
        self::assertSame(1, $this->transport(0)->closes);
        self::assertSame(1, $this->transport(1)->closes);
        self::assertSame([], $manager->getAllClients());

        $manager->delete();
        self::assertSame(1, $this->transport(0)->closes);
    }

    public function testACloseDuringAnAcquisitionWaitsForItAndRefusesNewAcquisitionsWhileDraining(): void
    {
        $manager = new ConnectionManager(null, $this->factory);
        $refused = null;

        $this->factory->script(function ($transport) use ($manager, &$refused): void {
            $transport->onRequest = function (string $method) use ($manager, &$refused): void {
                if ($method !== 'initialize') {
                    return;
                }
                $manager->delete();
                $refused = self::thrownBy(fn () => $manager->createClient('http', 'other', self::http()));
            };
        });

        $client = $manager->createClient('http', 'test', self::http());

        self::assertNotNull($client);
        self::assertInstanceOf(McpClientError::class, $refused);
        self::assertStringContainsString('closing', $refused->getMessage());
        self::assertSame([], $manager->getAllClients());
        self::assertSame(1, $this->transport(0)->closes);
        self::assertSame(1, $this->factory->count(), 'the refused acquisition built nothing');
    }

    public function testADiscoveryStartedMidCloseIsRefusedInsteadOfServedFromTheDyingEpoch(): void
    {
        $adapter = $this->adapter(['servers' => ['test' => self::http()]]);
        $adapter->listTools(['test']);

        $refusal = null;
        $this->transport(0)->onClose = function () use ($adapter, &$refusal): void {
            $refusal = self::thrownBy(static fn () => $adapter->listTools(['test']));
        };
        $adapter->close();

        self::assertInstanceOf(McpClientError::class, $refusal);
        self::assertStringContainsString('closing', $refusal->getMessage());
        self::assertCount(2, $adapter->listTools(['test']), 'after the close finishes the client works again');
    }

    public function testACloseDuringDiscoveryCannotResolveAsAPartialCatalog(): void
    {
        // "ignore" is the policy that swallows a per-server failure, so an interrupted discovery
        // must not be reported as a successful (empty) catalog.
        $adapter = $this->adapter(['servers' => ['test' => self::http()], 'onConnectionError' => 'ignore']);
        $this->factory->script(function ($transport) use ($adapter): void {
            $transport->onRequest = static function (string $method) use ($adapter): void {
                if ($method === 'tools/list') {
                    $adapter->close();
                }
            };
        });

        $error = self::thrownBy(static fn () => $adapter->listTools(['test']));

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertMatchesRegularExpression('/clos/i', $error->getMessage());
    }

    public function testAClosedAdapterIsReusableAndRebuildsItsClients(): void
    {
        $adapter = $this->adapter(['servers' => ['test' => self::http()]]);

        [$before] = $adapter->listTools(['test']);
        $firstClient = $adapter->getClient('test');
        $adapter->close();

        // close() clears the caches but keeps the server configuration, so discovery runs again
        // against fresh clients rather than restoring the old ones.
        [$after] = $adapter->listTools(['test']);
        $secondClient = $adapter->getClient('test');

        self::assertSame(2, $this->factory->count());
        self::assertNotSame($firstClient, $secondClient);
        self::assertNotSame($before, $after);
        self::assertSame(1, $this->transport(0)->closes);
    }

    public function testIsolatesSameServerToolsAcrossContextsAndKeepsConfiguredHeaderPrecedence(): void
    {
        $adapter = $this->adapter(['servers' => ['test' => self::http(['headers' => ['fixed' => 'configured']])]]);

        [$first] = $adapter->listTools(['test'], ['headers' => ['tenant' => 'one', 'fixed' => 'override']]);
        [$second] = $adapter->listTools(['test'], ['headers' => ['tenant' => 'two']]);

        self::assertNotSame($first, $second);
        self::assertSame($first, $adapter->listTools(['test'], ['headers' => ['tenant' => 'one']])[0]);
        self::assertSame(2, $this->factory->count());
        $listings = array_sum(array_map(static fn ($t): int => count(array_keys($t->methods(), 'tools/list', true)), $this->factory->transports));
        self::assertSame(3, $listings);
        // The configured value wins over a per-request one.
        self::assertSame(['tenant' => 'one', 'fixed' => 'configured'], $this->transport(0)->options['headers']);

        $first->invoke([]);
        $second->invoke([]);
        self::assertCount(1, array_keys($this->transport(0)->methods(), 'tools/call', true));
        self::assertCount(1, array_keys($this->transport(1)->methods(), 'tools/call', true));

        $default = $adapter->getClient('test');
        self::assertSame(3, $this->factory->count());
        self::assertNotSame($default, $adapter->getClient('test', ['headers' => ['tenant' => 'one']]));
    }

    public function testAFailedContextDoesNotSuppressAnotherIdentityOnTheSameServer(): void
    {
        $this->factory->failNext(new McpClientError('unavailable'));
        $adapter = $this->adapter(['servers' => ['test' => self::http()], 'onConnectionError' => 'ignore']);

        self::assertSame([], $adapter->listTools(['test'], ['headers' => ['tenant' => 'failed']]));
        self::assertCount(2, $adapter->listTools(['test'], ['headers' => ['tenant' => 'working']]));
    }

    public function testToolCatalogNotificationsInvalidateOnlyTheirConnectionIdentity(): void
    {
        $adapter = $this->adapter(['servers' => ['test' => self::http()]]);

        [$first] = $adapter->listTools(['test'], ['headers' => ['tenant' => 'one']]);
        [$second] = $adapter->listTools(['test'], ['headers' => ['tenant' => 'two']]);

        // The first connection announces a changed tool list; it arrives with the next answer.
        $this->transport(0)->queue(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']);
        $adapter->getClient('test', ['headers' => ['tenant' => 'one']])->listResources();

        self::assertNotSame($first, $adapter->listTools(['test'], ['headers' => ['tenant' => 'one']])[0]);
        self::assertSame($second, $adapter->listTools(['test'], ['headers' => ['tenant' => 'two']])[0]);
    }

    public function testAFailedDiscoveryReleasesItsClientAndCanBeRetried(): void
    {
        $this->factory->script(static function ($transport): void {
            $transport->failOn['tools/list'] = new McpClientError('discovery failed');
        });
        $adapter = $this->adapter(['servers' => ['test' => self::http()]]);

        $error = self::thrownBy(static fn () => $adapter->listTools());

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertStringContainsString('discovery failed', $error->getMessage());
        self::assertSame(1, $this->transport(0)->closes);

        self::assertCount(2, $adapter->listTools());
        self::assertSame(2, $this->factory->count());
    }

    public function testAResourceDiscoveryFailureIsNotAnEmptyCatalog(): void
    {
        $error = new McpClientError('discovery failed');
        $this->factory->script(static function ($transport) use ($error): void {
            $transport->failOn['resources/list'] = $error;
        });
        $adapter = $this->adapter(['servers' => ['test' => self::http()]]);

        self::assertSame($error, self::thrownBy(static fn () => $adapter->listResources()));
    }

    public function testConsultsTheServerAgainAndPreservesAlreadyIssuedTools(): void
    {
        $this->factory->script(static function ($transport): void {
            $transport->tools = [['name' => 'before', 'inputSchema' => ['type' => 'object']]];
        });
        $adapter = $this->adapter(['servers' => ['test' => self::http()]]);

        [$before] = $adapter->listTools();
        $this->transport(0)->tools = [['name' => 'after', 'inputSchema' => ['type' => 'object']]];
        [$after] = $adapter->listTools();

        self::assertSame('after', $after->name);
        self::assertSame('before', $before->name);
    }

    /** @return array<string, array{string}> */
    public static function issuedDiscoveries(): array
    {
        return ['cached' => ['cached'], 'bypass' => ['bypass'], 'invalidated' => ['invalidated']];
    }

    #[DataProvider('issuedDiscoveries')]
    public function testKeepsAnIssuedToolAliveThroughAFailedRefresh(string $discovery): void
    {
        $adapter = $this->adapter(['servers' => ['test' => self::http()]]);

        [$issued] = $adapter->listTools([], $discovery === 'bypass' ? ['cacheMode' => 'bypass'] : []);
        $captured = $adapter->getClient('test');
        if ($discovery === 'invalidated') {
            $this->transport(0)->queue(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']);
            $captured->listResources();
        }

        $this->transport(0)->failOn['tools/list'] = new McpClientError('transient catalog failure');
        $error = self::thrownBy(static fn () => $adapter->listTools([], ['cacheMode' => 'refresh']));
        self::assertStringContainsString('transient catalog failure', $error->getMessage());
        self::assertSame(0, $this->transport(0)->closes, 'the failed refresh must not take the connection down');

        unset($this->transport(0)->failOn['tools/list']);
        $message = $issued->invoke(self::call($issued->name));
        self::assertInstanceOf(ToolMessage::class, $message);
        self::assertSame("{$issued->name} result", $message->content);
        self::assertSame($captured, $adapter->getClient('test'));
        if ($discovery === 'cached') {
            self::assertSame($issued, $adapter->listTools()[0]);
        }
    }

    public function testBypassKeepsAResultOutOfTheToolCacheWhileOtherModesCacheIt(): void
    {
        $adapter = $this->adapter(['servers' => ['test' => self::http()]]);

        $bypassed = $adapter->listTools([], ['cacheMode' => 'bypass'])[0];
        self::assertNotSame($bypassed, $adapter->listTools([], ['cacheMode' => 'bypass'])[0]);

        $cached = $adapter->listTools([], ['cacheMode' => 'use'])[0];
        self::assertSame($cached, $adapter->listTools([], ['cacheMode' => 'use'])[0]);
        self::assertSame($cached, $adapter->listTools([], ['cacheMode' => 'refresh'])[0]);
        self::assertCount(5, array_keys($this->transport(0)->methods(), 'tools/list', true));
    }

    /**
     * @return array{type: string, id: string, name: string, args: array<string, mixed>}
     */
    private static function call(string $name): array
    {
        return ['type' => 'tool_call', 'id' => 'call_1', 'name' => $name, 'args' => []];
    }
}
