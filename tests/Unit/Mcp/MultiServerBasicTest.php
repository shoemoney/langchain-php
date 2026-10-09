<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangChain\Tests\Unit\Mcp\Connection\FakeServerTransport;
use LangGraph\Mcp\Client\Transport\HttpStatusException;
use LangGraph\Mcp\Connection\ManagedClient;
use LangGraph\Mcp\Connection\ObservedTransport;
use LangGraph\Mcp\McpClientError;
use LangGraph\Mcp\MultiServerMcpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Port of `client.basic.test.ts` and `client.list_tools.test.ts`: connection management, restart,
 * tool listing and naming, closing, connection error policies. The SDK mock upstream builds with
 * `vi.mock` is a scripted transport behind the client's factory seam.
 *
 * Not ported: SSE transport cases (fallback, explicit SSE, SSE reconnect), OAuth provider parsing
 * and `onclose` events of SSE transports. A stdio server that dies is observed through its broken
 * channel instead of an `onclose` event; see {@see MultiServerMcpClient}.
 */
#[CoversClass(MultiServerMcpClient::class)]
final class MultiServerBasicTest extends MultiServerTestCase
{
    // ----------------------------------------------------------------- initializeConnections

    public function testInitializesStdioConnectionsCorrectly(): void
    {
        $client = $this->adapter(['test-server' => self::stdio()]);

        $client->initializeConnections();

        $transport = $this->transport();
        self::assertSame('stdio', $transport->type);
        self::assertSame('python', $transport->options['command']);
        self::assertSame(['./script.py'], $transport->options['args']);
        self::assertSame('inherit', $transport->options['stderr']);
        self::assertSame(['initialize', 'notifications/initialized', 'tools/list'], $transport->methods());
        self::assertSame('@langchain/mcp-adapters', $transport->lastParams('initialize')['clientInfo']['name']);
        self::assertSame('2025-11-25', $transport->lastParams('initialize')['protocolVersion'], 'legacy mode pins the legacy protocol');
    }

    public function testInitializesStreamableHttpConnectionsCorrectly(): void
    {
        $client = $this->adapter(['test-server' => self::http(['url' => 'http://localhost:8000/mcp'])]);

        $client->initializeConnections();

        self::assertSame('http', $this->transport()->type);
        self::assertSame('http://localhost:8000/mcp', $this->transport()->options['url']);
        self::assertSame([], $this->transport()->options['headers']);
        self::assertContains('tools/list', $this->transport()->methods());
    }

    public function testThrowsOnAConnectionFailure(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->startError = new McpClientError('Connection failed');
        });
        $client = $this->adapter(['test-server' => self::stdio()]);

        $error = self::thrownBy(static fn () => $client->initializeConnections());

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertSame('test-server', $error->serverName);
        self::assertSame('Failed to connect to stdio server "test-server" in legacy mode: McpClientError: Connection failed', $error->getMessage());
        self::assertSame(1, $this->transport()->starts);
    }

    public function testThrowsOnToolLoadingFailures(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->failOn['tools/list'] = new McpClientError('Failed to list tools');
        });
        $client = $this->adapter(['test-server' => self::stdio()]);

        $error = self::thrownBy(static fn () => $client->initializeConnections());

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertSame('Failed to load tools from server "test-server": McpClientError: Failed to list tools', $error->getMessage());
        self::assertSame('test-server', $error->serverName);
    }

    // ------------------------------------------------------------------------ restart policy

    /** Break a stdio server's channel and trigger the discovery that notices. */
    private function killServer(MultiServerMcpClient $client, FakeServerTransport $transport, string $serverName = 'test-server'): ?\Throwable
    {
        $transport->broken = true;

        try {
            $client->getClient($serverName);
        } catch (\Throwable $error) {
            return $error;
        }

        return null;
    }

    public function testRestartsAStdioServerWhenEnabled(): void
    {
        $client = $this->adapter(['test-server' => self::stdio(['restart' => ['enabled' => true, 'maxAttempts' => 3, 'delayMs' => 100]])]);
        $client->initializeConnections();
        $before = $client->getClient('test-server');
        self::assertSame(1, $this->factory->count());

        $error = $this->killServer($client, $this->transport());

        self::assertInstanceOf(McpClientError::class, $error, 'the call that found the dead server still fails');
        self::assertSame(2, $this->factory->count(), 'a new transport was created');
        self::assertSame([100], $this->sleeps, 'after the configured delay');
        self::assertContains('initialize', $this->transport(1)->methods(), 'and connected');
        self::assertNotSame($before, $client->getClient('test-server'));
        self::assertCount(2, $client->listTools());
    }

    public function testDoesNotRestartWhenNotEnabled(): void
    {
        $client = $this->adapter(['test-server' => self::stdio()]);
        $client->initializeConnections();

        $this->killServer($client, $this->transport());

        self::assertSame(1, $this->factory->count());
        self::assertSame([], $this->sleeps);
    }

    public function testRestartIsOffWhenTheRestartPolicyIsDisabled(): void
    {
        $client = $this->adapter(['test-server' => self::stdio(['restart' => ['enabled' => false]])]);
        $client->initializeConnections();

        $this->killServer($client, $this->transport());

        self::assertSame(1, $this->factory->count());
    }

    public function testRespectsTheMaxAttemptsSettingForRestarts(): void
    {
        $client = $this->adapter(['test-server' => self::stdio(['restart' => ['enabled' => true, 'maxAttempts' => 2, 'delayMs' => 10]])]);
        $client->initializeConnections();
        $this->factory->failNext(new McpClientError('reconnect fail 1'))->failNext(new McpClientError('reconnect fail 2'));

        $this->killServer($client, $this->transport());

        // The original connection plus exactly maxAttempts failed rebuilds, one backoff each.
        self::assertSame(3, $this->factory->attempts);
        self::assertSame([10, 10], $this->sleeps);
        // The budget is spent, but the client is not wedged: the next discovery connects afresh.
        self::assertNotNull($client->getClient('test-server'));
        self::assertSame(4, $this->factory->attempts);
    }

    public function testUsesTheDefaultsWhenTheRestartPolicyOmitsThem(): void
    {
        $client = $this->adapter(['test-server' => self::stdio(['restart' => ['enabled' => true]])]);
        $client->initializeConnections();
        $this->factory->failNext(new McpClientError('a'))->failNext(new McpClientError('b'))->failNext(new McpClientError('c'));

        $this->killServer($client, $this->transport());

        self::assertSame(1 + 3, $this->factory->attempts, 'three attempts by default');
        self::assertSame([1000, 1000, 1000], $this->sleeps, 'one second apart by default');
    }

    public function testACloseDuringTheRestartBackoffCancelsTheRestart(): void
    {
        $adapter = null;
        $adapter = $this->adapter(
            ['test-server' => self::stdio(['restart' => ['enabled' => true, 'maxAttempts' => 3, 'delayMs' => 100]])],
            null,
            static function () use (&$adapter): void {
                $adapter->close();
            },
        );
        $adapter->initializeConnections();

        $this->killServer($adapter, $this->transport());

        // The aborted epoch cancelled the restart: no connection is rebuilt behind a closed client.
        self::assertSame(1, $this->factory->count());
        self::assertSame([100], $this->sleeps, 'it gave up after the first backoff');
    }

    public function testReportsAnExhaustedRestartBudgetThroughOnConnectionError(): void
    {
        $reports = [];
        $client = $this->adapter([
            'servers' => ['test-server' => self::stdio(['restart' => ['enabled' => true, 'maxAttempts' => 2, 'delayMs' => 10]])],
            'onConnectionError' => static function (array $report) use (&$reports): void {
                $reports[] = $report;
            },
        ]);
        $client->initializeConnections();
        $this->factory->failNext(new McpClientError('reconnect fail 1'))->failNext(new McpClientError('reconnect fail 2'));

        $this->killServer($client, $this->transport());

        // Restarting has no caller to throw at, so a server that never comes back reaches the handler.
        $restart = array_values(array_filter($reports, static fn (array $report): bool => str_contains($report['error']->getMessage(), 'reconnect fail 2')));
        self::assertCount(1, $restart);
        self::assertSame('test-server', $restart[0]['serverName']);
        self::assertInstanceOf(McpClientError::class, $restart[0]['error']);
    }

    public function testAZeroAttemptBudgetIsReportedAsSuch(): void
    {
        $reports = [];
        $client = $this->adapter([
            'servers' => ['test-server' => self::stdio(['restart' => ['enabled' => true, 'maxAttempts' => 0, 'delayMs' => 0]])],
            'onConnectionError' => static function (array $report) use (&$reports): void {
                $reports[] = $report;
            },
        ]);
        $client->initializeConnections();

        $this->killServer($client, $this->transport());

        $messages = array_map(static fn (array $report): string => $report['error']->getMessage(), $reports);
        self::assertContains('Failed to reconnect to MCP server "test-server" after 0 attempts', $messages);
    }

    public function testASuccessfulRestartRestoresDiscoveryAfterAnOverlappingFailure(): void
    {
        $adapter = null;
        $overlap = null;
        $adapter = $this->adapter(
            [
                'servers' => ['test' => self::stdio(['restart' => ['enabled' => true, 'maxAttempts' => 1, 'delayMs' => 100]])],
                'onConnectionError' => 'ignore',
            ],
            null,
            function () use (&$adapter, &$overlap): void {
                // A discovery that races the restart backoff fails and marks the identity failed...
                $this->factory->failNext(new McpClientError('overlapping discovery failed'));
                $overlap = $adapter->listTools();
            },
        );
        $adapter->listTools();

        $this->transport()->broken = true;
        $adapter->getClient('test');

        self::assertSame([], $overlap);
        // ...and the restart that finishes after it rebuilds that exact identity.
        self::assertCount(2, $adapter->listTools());
        self::assertSame(3, $this->factory->attempts);
    }

    public function testASwallowedCleanupFailureDuringARestartDoesNotEscapeTheCloseHandler(): void
    {
        $client = $this->adapter(['test-server' => self::stdio(['restart' => ['enabled' => true, 'delayMs' => 0]])]);
        $client->initializeConnections();
        $transport = $this->transport();
        $transport->closeError = new \RuntimeException('cleanup failed');

        $observed = $this->observedTransport($client, 'test-server');

        self::assertNull(($observed->onclose)());
        self::assertSame(1, $this->factory->count(), 'cleanup failed first, so nothing was rebuilt');
    }

    // ------------------------------------------------------------------------------ listTools

    public function testGetsAllToolsAsAFlattenedArray(): void
    {
        $client = $this->adapter(['servers' => ['server1' => self::stdio(['args' => ['./script1.py']]), 'server2' => self::stdio(['args' => ['./script2.py']])]]);

        $tools = $client->listTools();

        self::assertSame(['tool1', 'tool2', 'tool1', 'tool2'], self::names($tools));
    }

    public function testGetsToolsFromSpecificServers(): void
    {
        $this->serversWithTools(['alpha1'], ['beta1', 'beta2']);
        $client = $this->adapter(['alpha' => self::stdio(), 'beta' => self::stdio()]);

        // A single server name keeps that server's tools and drops the rest.
        self::assertSame(['beta1', 'beta2'], self::names($client->listTools('beta')));
        self::assertSame(['alpha1'], self::names($client->listTools('alpha')));
        // The array overload filters and preserves the requested order.
        self::assertSame(['beta1', 'beta2', 'alpha1'], self::names($client->listTools(['beta', 'alpha'])));
        // Unfiltered discovery still returns every server's tools.
        self::assertSame(['alpha1', 'beta1', 'beta2'], self::names($client->listTools()));
        // An unknown server name contributes nothing instead of throwing.
        self::assertSame([], $client->listTools('missing'));
    }

    public function testHandlesEmptyToolListsCorrectly(): void
    {
        $this->serversWithTools([], ['beta1']);
        $client = $this->adapter(['empty' => self::stdio(), 'beta' => self::stdio()]);

        $toolsets = $client->listToolsets();

        self::assertEqualsCanonicalizing(['beta', 'empty'], array_keys($toolsets));
        self::assertSame([], $toolsets['empty']);
        self::assertSame(['beta1'], self::names($client->listTools()));
        self::assertSame([], $client->listTools('empty'));
        self::assertSame(['beta1'], self::names($client->listTools(['empty', 'beta'])));
        self::assertSame(['beta1'], self::names($client->getTools(['empty', 'beta'])));
    }

    public function testToolsetsAreGroupedByServerNameAndMatchTheFlatList(): void
    {
        $this->serversWithTools(['tool1'], ['tool2', 'tool3']);
        $client = $this->adapter(['server1' => self::stdio(), 'server2' => self::stdio()]);

        $toolsets = $client->initializeConnections();

        self::assertSame(['server1', 'server2'], array_keys($toolsets));
        self::assertSame(['tool2', 'tool3'], self::names($toolsets['server2']));
        self::assertSame(['tool1', 'tool2', 'tool3'], self::names($client->listTools()));
        self::assertSame($toolsets, $client->listToolsets());
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function prefixCases(): array
    {
        return [
            'prefixToolNameWithServerName' => [['prefixToolNameWithServerName' => true], ['test-server__tool1', 'test-server__tool2']],
            'additionalToolNamePrefix' => [['additionalToolNamePrefix' => 'mcp'], ['mcp__tool1', 'mcp__tool2']],
            'both prefixes' => [['prefixToolNameWithServerName' => true, 'additionalToolNamePrefix' => 'mcp'], ['mcp__test-server__tool1', 'mcp__test-server__tool2']],
            'no prefixes by default' => [[], ['tool1', 'tool2']],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $expected
     */
    #[DataProvider('prefixCases')]
    public function testAppliesToolNamePrefixesCorrectly(array $options, array $expected): void
    {
        $client = $this->adapter(['mcpServers' => ['test-server' => self::stdio()], ...$options]);

        self::assertSame($expected, self::names($client->listTools()));
    }

    public function testExposesTheClientDefaultsForPrefixing(): void
    {
        $client = $this->adapter(['server1' => self::stdio()]);

        self::assertSame('', $client->config()['additionalToolNamePrefix']);
        self::assertFalse($client->config()['prefixToolNameWithServerName']);
    }

    public function testSelectsToolsAcrossServersAndInvokesThem(): void
    {
        foreach (['first', 'second'] as $name) {
            $this->factory->script(static function (FakeServerTransport $transport) use ($name): void {
                $transport->tools = [['name' => $name, 'inputSchema' => ['type' => 'object']]];
                $transport->callHandlers[$name] = static fn (): array => ['content' => [['type' => 'text', 'text' => $name]]];
            });
        }
        $client = $this->adapter(['servers' => ['first' => ['mode' => 'legacy', 'command' => 'node', 'args' => []], 'second' => ['mode' => 'legacy', 'command' => 'node', 'args' => []]]]);

        self::assertSame(['first', 'second'], self::names($client->listTools()));
        [$selected] = $client->listTools('second');
        $toolsets = $client->listToolsets();
        self::assertSame(['first', 'second'], array_keys($toolsets));
        self::assertEquals(array_merge(...array_values($toolsets)), $client->listTools());
        self::assertEquals($toolsets, $client->initializeConnections());
        self::assertSame('second', $toolsets['second'][0]->invoke([]));
        self::assertSame('second', $selected->name);
        self::assertSame('second', $selected->invoke([]));
        self::assertSame(['second'], self::names($client->listTools(['second'])));
        self::assertSame(['first'], self::names($client->listTools(['first'], ['headers' => []])));
    }

    // -------------------------------------------------------------------------------- close

    public function testClosesAllConnectionsProperly(): void
    {
        $client = $this->adapter(['servers' => [
            'server1' => self::stdio(['args' => ['./script1.py']]),
            'server2' => self::http(['url' => 'http://localhost:8000/mcp']),
            'server3' => self::http(['url' => 'http://localhost:8001/mcp']),
        ]]);

        $client->initializeConnections();
        $client->close();

        self::assertSame(3, $this->factory->count());
        foreach ($this->factory->transports as $transport) {
            self::assertSame(1, $transport->closes);
        }
    }

    public function testHandlesErrorsDuringCleanupGracefully(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->closeError = new \RuntimeException('Close failed');
        });
        $client = $this->adapter(['test-server' => self::stdio()]);
        $client->initializeConnections();

        $error = self::thrownBy(static fn () => $client->close());

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertMatchesRegularExpression('/Failed to close MCP connections/', $error->getMessage());
        self::assertSame('Close failed', $error->cause[0]->getMessage());
        self::assertSame(1, $this->transport()->closes);
    }

    public function testHandlesErrorsDuringStreamableHttpCleanupGracefully(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->closeError = new \RuntimeException('Close failed');
        });
        $client = $this->adapter(['test-server' => self::http()]);
        $client->initializeConnections();

        $this->expectException(McpClientError::class);
        $this->expectExceptionMessage('Failed to close MCP connections');

        $client->close();
    }

    public function testCleansUpAllResourcesEvenIfSomeFail(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->closeError = new \RuntimeException('Close failed');
        });
        $client = $this->adapter(['stdio-server' => self::stdio(), 'http-server' => self::http()]);
        $client->initializeConnections();

        self::assertInstanceOf(McpClientError::class, self::thrownBy(static fn () => $client->close()));

        self::assertSame(1, $this->transport(0)->closes);
        self::assertSame(1, $this->transport(1)->closes, 'the second connection still closed');
    }

    public function testClearsInternalStateAfterClose(): void
    {
        $client = $this->adapter(['test-server' => self::stdio()]);
        $client->initializeConnections();

        $client->close();

        self::assertSame(1, $this->transport()->closes);
        // Nothing is left to answer from: discovery builds a new connection.
        self::assertSame(['tool1', 'tool2'], self::names($client->listTools()));
        self::assertSame(2, $this->factory->count());
    }

    public function testClosingTwiceIsHarmless(): void
    {
        $client = $this->adapter(['test-server' => self::stdio()]);
        $client->initializeConnections();

        $client->close();
        $client->close();

        self::assertSame(1, $this->transport()->closes);
    }

    // ------------------------------------------------------------------ streamable HTTP & errors

    public function testHandlesMixedTransportTypes(): void
    {
        $client = $this->adapter(['servers' => [
            'stdio-server' => self::stdio(),
            'streamable-server' => self::http(),
            'second-http' => self::http(['url' => 'http://localhost:8001/mcp']),
        ]]);

        $client->initializeConnections();

        self::assertSame(['stdio', 'http', 'http'], array_map(static fn (FakeServerTransport $t): string => $t->type, $this->factory->transports));
        self::assertCount(6, $client->listTools());
    }

    public function testThrowsOnStreamableHttpConnectionFailure(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->failOn['initialize'] = new McpClientError('Connection failed');
        });
        $client = $this->adapter(['test-server' => self::http()]);

        $error = self::thrownBy(static fn () => $client->initializeConnections());

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertSame('Failed to connect to streamable HTTP server "test-server, url: http://localhost:8000/mcp" in legacy mode: McpClientError: Connection failed', $error->getMessage());
    }

    /** @return array<string, array{\Throwable, string}> */
    public static function connectionFailures(): array
    {
        return [
            'plain failure' => [new \RuntimeException('Connection failed'), 'in legacy mode: RuntimeException: Connection failed'],
            'message carries a status' => [new \RuntimeException('Connection failed (HTTP 404)'), 'in legacy mode: RuntimeException: Connection failed (HTTP 404)'],
            'status 404' => [new HttpStatusException(404, 'Error POSTing to endpoint'), 'in legacy mode: HttpStatusException: Error POSTing to endpoint (HTTP 404)'],
            'status 503' => [new HttpStatusException(503, 'unavailable'), 'in legacy mode: HttpStatusException: unavailable (HTTP 503)'],
            'status 403' => [new HttpStatusException(403, 'forbidden'), 'in legacy mode: HttpStatusException: forbidden (HTTP 403)'],
        ];
    }

    #[DataProvider('connectionFailures')]
    public function testHandlesAConnectionFailureWithoutLosingTheOriginal(\Throwable $failure, string $rendered): void
    {
        $this->factory->failNext($failure);
        $client = $this->adapter(['remote' => ['mode' => 'legacy', 'transport' => 'http', 'url' => 'https://example.com/mcp']]);

        $error = self::thrownBy(static fn () => $client->initializeConnections());

        // There is no SSE fallback: the one attempt is the only attempt.
        self::assertSame(1, $this->factory->attempts);
        self::assertInstanceOf(McpClientError::class, $error);
        // This row's failure survives verbatim as the cause...
        self::assertSame($failure, $error->cause);
        self::assertSame($failure, $error->getPrevious());
        // ...and is rendered into the message with the server context.
        self::assertSame('remote', $error->serverName);
        self::assertSame('Failed to connect to streamable HTTP server "remote, url: https://example.com/mcp" ' . $rendered, $error->getMessage());
    }

    #[DataProvider('authenticationFailures')]
    public function testOnlyA401IsTreatedAsAnAuthenticationFailure(int $status, bool $authentication): void
    {
        $this->factory->failNext(new HttpStatusException($status, 'rejected'));
        $client = $this->adapter(['servers' => ['remote' => ['url' => 'https://example.com/mcp']]]);

        $error = self::thrownBy(static fn () => $client->listTools());

        self::assertSame($authentication, str_starts_with($error->getMessage(), 'Authentication failed for HTTP server "remote" at https://example.com/mcp.'));
        if (!$authentication) {
            self::assertStringContainsString('in auto mode', $error->getMessage());
        }
        self::assertSame(1, $this->factory->attempts, 'automatic negotiation is not retried over SSE');
    }

    /** @return array<string, array{int, bool}> */
    public static function authenticationFailures(): array
    {
        return ['401' => [401, true], '403' => [403, false], '503' => [503, false]];
    }

    public function testAnExplicitModernConnectionNamesItsModeInTheFailure(): void
    {
        $this->factory->failNext(new HttpStatusException(404, 'not found'));
        $client = $this->adapter(['servers' => ['remote' => ['mode' => 'modern', 'url' => 'https://example.com/mcp']]]);

        $error = self::thrownBy(static fn () => $client->listTools());

        self::assertMatchesRegularExpression('/in modern mode/', $error->getMessage());
        self::assertSame(1, $this->factory->attempts);
    }

    public function testNoFallbackIsAttemptedForAnHttp404EvenWithTheFallbackFlagOn(): void
    {
        $this->factory->failNext(new HttpStatusException(404, 'not found'));
        $client = $this->adapter(['servers' => ['remote' => ['mode' => 'legacy', 'url' => 'https://example.com/mcp', 'automaticSSEFallback' => true]]]);

        $error = self::thrownBy(static fn () => $client->listTools());

        self::assertStringContainsString('in legacy mode', $error->getMessage());
        self::assertSame(1, $this->factory->attempts);
    }

    public function testIgnoresConnectionErrorsWhenOnConnectionErrorIsIgnore(): void
    {
        $this->factory->failNext(new McpClientError('Connection failed'));
        $client = $this->adapter(['mcpServers' => [
            'failing-server' => self::http(['url' => 'http://localhost:8123/mcp']),
            'working-server' => self::http(['url' => 'http://localhost:8001/mcp']),
        ], 'onConnectionError' => 'ignore']);

        $tools = $client->initializeConnections();

        self::assertSame(['working-server'], array_keys($tools));
        self::assertNotNull($client->getClient('working-server'));
        self::assertNull($client->getClient('failing-server'));
    }

    public function testThrowsOnConnectionFailureWhenOnConnectionErrorIsThrow(): void
    {
        $this->factory->failNext(new McpClientError('Connection failed'));
        $client = $this->adapter(['mcpServers' => ['failing-server' => self::http()], 'onConnectionError' => 'throw']);

        $this->expectException(McpClientError::class);

        $client->initializeConnections();
    }

    public function testDoesNotThrowWhenAllServersFailAndOnConnectionErrorIsIgnore(): void
    {
        $this->factory->failNext(new McpClientError('one'))->failNext(new McpClientError('two'));
        $client = $this->adapter(['mcpServers' => [
            'server-1' => self::http(),
            'server-2' => self::http(['url' => 'http://localhost:8001/mcp']),
        ], 'onConnectionError' => 'ignore']);

        self::assertSame([], $client->listTools());
        self::assertNull($client->getClient('server-1'));
        self::assertNull($client->getClient('server-2'));
    }

    public function testCallsACustomErrorHandlerAndIgnoresTheServerIfItDoesNotThrow(): void
    {
        $this->factory->failNext(new McpClientError('Connection failed'));
        $seen = [];
        $client = $this->adapter(['mcpServers' => [
            'failing-server' => self::http(),
            'working-server' => self::http(['url' => 'http://localhost:8001/mcp']),
        ], 'onConnectionError' => static function (array $report) use (&$seen): void {
            $seen[] = $report;
        }]);

        $client->initializeConnections();

        self::assertCount(1, $seen);
        self::assertSame('failing-server', $seen[0]['serverName']);
        self::assertInstanceOf(\Throwable::class, $seen[0]['error']);
        self::assertSame('Connection failed', $seen[0]['error']->cause->getMessage());
        self::assertNull($client->getClient('failing-server'));
        self::assertNotNull($client->getClient('working-server'));
    }

    public function testThrowsIfTheCustomErrorHandlerThrows(): void
    {
        $custom = new \RuntimeException('Custom error from handler');
        $calls = 0;
        $this->factory->failNext(new McpClientError('Connection failed'));
        $client = $this->adapter(['mcpServers' => ['failing-server' => self::http()], 'onConnectionError' => static function () use ($custom, &$calls): void {
            ++$calls;

            throw $custom;
        }]);

        self::assertSame($custom, self::thrownBy(static fn () => $client->initializeConnections()));
        self::assertSame(1, $calls);
    }

    public function testSkipsFailedServersOnSubsequentCallsWhenUsingACustomHandler(): void
    {
        $calls = 0;
        $this->factory->failNext(new McpClientError('Connection failed'));
        $client = $this->adapter(['mcpServers' => [
            'failing-server' => self::http(),
            'working-server' => self::http(['url' => 'http://localhost:8001/mcp']),
        ], 'onConnectionError' => static function () use (&$calls): void {
            ++$calls;
        }]);

        $client->initializeConnections();
        self::assertSame(1, $calls);

        // The failing server is skipped, so the handler is not called again.
        $client->initializeConnections();
        self::assertSame(1, $calls);
        self::assertNull($client->getClient('failing-server'));
    }

    public function testInitializeConnectionsIsIdempotentUnderIgnore(): void
    {
        $this->factory->failNext(new McpClientError('Connection failed'));
        $client = $this->adapter(['mcpServers' => [
            'failing-server' => self::http(),
            'working-server' => self::http(['url' => 'http://localhost:8001/mcp']),
        ], 'onConnectionError' => 'ignore']);

        $first = $client->initializeConnections();
        $second = $client->initializeConnections();

        self::assertSame(array_keys($first), array_keys($second));
        self::assertSame($client->getClient('working-server'), $client->getClient('working-server'));
        self::assertSame(2, $this->factory->attempts, 'the failed server was attempted exactly once');
        self::assertSame(1, $this->factory->count());
    }

    public function testAnAuthenticationFailureDoesNotBlockTheServerForever(): void
    {
        $this->factory->failNext(new HttpStatusException(401, 'unauthorized'));
        $client = $this->adapter(['mcpServers' => ['svc' => self::http()], 'onConnectionError' => 'ignore']);

        self::assertSame([], $client->listTools());
        // A login completed in the meantime: the next discovery tries again and succeeds.
        self::assertCount(2, $client->listTools());
    }

    // ---------------------------------------------------------------- clients and error cases

    public function testGetsTheClientForASpecificServer(): void
    {
        $client = $this->adapter(['test-server' => self::stdio()]);
        $client->initializeConnections();

        $serverClient = $client->getClient('test-server');

        self::assertInstanceOf(ManagedClient::class, $serverClient);
        self::assertSame($serverClient, $client->getClient('test-server'));
        self::assertNull($client->getClient('non-existent'));
    }

    public function testGetClientDiscoversOnDemand(): void
    {
        $client = $this->adapter(['test-server' => self::stdio()]);

        self::assertSame(0, $this->factory->count());
        self::assertNotNull($client->getClient('test-server'));
        self::assertSame(1, $this->factory->count());
    }

    public function testHandlesAnInvalidServerNameWhenGettingTools(): void
    {
        $client = $this->adapter(['test-server' => self::stdio()]);

        self::assertNull($client->getClient('non-existent'));
        self::assertSame([], $client->listTools('non-existent'));
    }

    public function testThrowsOnTransportCreationErrors(): void
    {
        $this->factory->failNext(new \RuntimeException('Transport creation failed'));
        $client = $this->adapter(['test-server' => self::stdio()]);

        $error = self::thrownBy(static fn () => $client->initializeConnections());

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertMatchesRegularExpression('/Transport creation failed/', $error->getMessage());
        // The factory was asked, but no client came of it.
        self::assertSame(1, $this->factory->attempts);
        self::assertSame([], $this->factory->transports);
    }

    public function testThrowsOnStreamableHttpTransportCreationErrors(): void
    {
        $this->factory->failNext(new \RuntimeException('Streamable HTTP transport creation failed'));
        $client = $this->adapter(['test-server' => self::http()]);

        $error = self::thrownBy(static fn () => $client->initializeConnections());

        self::assertMatchesRegularExpression('/Streamable HTTP transport creation failed/', $error->getMessage());
        self::assertSame([], $this->factory->transports);
    }

    public function testAHandshakeFailureLeavesNoHalfOpenConnection(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->failOn['initialize'] = new McpClientError('refused');
        });
        $client = $this->adapter(['servers' => ['test-server' => self::stdio()], 'onConnectionError' => 'ignore']);

        self::assertSame([], $client->listTools());
        self::assertSame(1, $this->transport()->closes, 'the failed transport was closed');
    }

    // ------------------------------------------------------------------------- logging level

    public function testSetsTheLoggingLevelOnEveryLegacyServer(): void
    {
        $client = $this->adapter(['servers' => ['one' => self::stdio(), 'two' => self::http()]]);
        $client->initializeConnections();

        $client->setLoggingLevel('debug');

        self::assertSame(['level' => 'debug'], $this->transport(0)->lastParams('logging/setLevel'));
        self::assertSame(['level' => 'debug'], $this->transport(1)->lastParams('logging/setLevel'));
    }

    public function testSetsTheLoggingLevelOnOneServer(): void
    {
        $client = $this->adapter(['servers' => ['one' => self::stdio(), 'two' => self::http()]]);
        $client->initializeConnections();

        $client->setLoggingLevel('two', 'warning');

        self::assertNull($this->transport(0)->lastParams('logging/setLevel'));
        self::assertSame(['level' => 'warning'], $this->transport(1)->lastParams('logging/setLevel'));
    }

    public function testSetLoggingLevelIsLegacyOnly(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->version = '2026-07-28';
        });
        $client = $this->adapter(['servers' => ['modern' => ['url' => 'https://example.com/mcp']]]);
        $client->initializeConnections();

        $error = self::thrownBy(static fn () => $client->setLoggingLevel('debug'));

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertSame('setLoggingLevel is legacy-only; configure logLevel for modern tool requests', $error->getMessage());
    }

    public function testSetLoggingLevelValidatesItsArguments(): void
    {
        $client = $this->adapter(['servers' => ['one' => self::stdio()]]);

        self::assertInstanceOf(McpClientError::class, self::thrownBy(static fn () => $client->setLoggingLevel('shouting')));
        self::assertInstanceOf(McpClientError::class, self::thrownBy(static fn () => $client->setLoggingLevel()));
        self::assertInstanceOf(McpClientError::class, self::thrownBy(static fn () => $client->setLoggingLevel('a', 'b', 'c')));
    }

    // ----------------------------------------------------------------------------- helpers

    private function observedTransport(MultiServerMcpClient $client, string $serverName): \LangGraph\Mcp\Connection\ObservedTransport
    {
        $reflection = new \ReflectionProperty($client, 'clientConnections');
        $manager = $reflection->getValue($client);
        $observed = $manager->getTransport(['serverName' => $serverName]);
        self::assertNotNull($observed);

        return $observed;
    }
}
