<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp\Client;

use LangGraph\Mcp\Client\JsonRpcException;
use LangGraph\Mcp\Client\McpClient;
use LangGraph\Mcp\ElicitationCapableClientInterface;
use LangGraph\Mcp\ForkableMcpClientInterface;
use LangGraph\Mcp\McpClientError;
use LangGraph\Mcp\McpClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(McpClient::class)]
final class McpClientStdioTest extends ClientTestCase
{
    public function testItImplementsTheAdapterSeamAndItsOptionalExtensions(): void
    {
        $client = $this->stdioClient();

        self::assertInstanceOf(McpClientInterface::class, $client);
        self::assertInstanceOf(ElicitationCapableClientInterface::class, $client);
        self::assertInstanceOf(ForkableMcpClientInterface::class, $client);
    }

    public function testAnOldServerNegotiatesTheLegacyEra(): void
    {
        $client = $this->stdioClient(['legacy-server']);
        $client->connect();

        self::assertSame('legacy', $client->getProtocolEra());
        self::assertSame('2025-06-18', $client->getProtocolVersion());
        self::assertSame('legacy-server', $client->getServerVersion()['name']);
        self::assertSame('fixture instructions', $client->getInstructions());
        self::assertArrayHasKey('tools', $client->getServerCapabilities());
    }

    public function testAModernServerNegotiatesTheModernEra(): void
    {
        $client = $this->stdioClient(['modern-server', 'modern']);

        self::assertSame('modern', $client->getProtocolEra());
        self::assertSame('2026-07-28', $client->getProtocolVersion());
    }

    public function testPinningModernAgainstALegacyServerFails(): void
    {
        $client = $this->stdioClient([], ['mode' => 'modern']);

        $error = self::thrownBy(static fn () => $client->connect());

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertStringContainsString('pinned to modern', $error->getMessage());
        self::assertFalse($client->isConnected());
    }

    public function testALegacyPinOffersALegacyRevisionEvenToAModernCapableServer(): void
    {
        $client = $this->stdioClient(['modern-server', 'modern'], ['mode' => 'legacy']);

        self::assertSame('legacy', $client->getProtocolEra());
        self::assertSame('2025-06-18', $client->getProtocolVersion());
    }

    public function testItConnectsLazily(): void
    {
        $client = $this->stdioClient();

        self::assertFalse($client->isConnected());
        self::assertCount(11, $client->listTools()['tools']);
        self::assertTrue($client->isConnected());
    }

    public function testListToolsFollowsCursors(): void
    {
        $names = array_column($this->stdioClient()->listTools()['tools'], 'name');

        self::assertSame('test_tool', $names[0]);
        self::assertContains('approve', $names);
        self::assertContains('log_level', $names);
    }

    public function testCallToolReturnsTheResultAndSendsMeta(): void
    {
        $client = $this->stdioClient(['stdio-a']);

        $result = $client->callTool('test_tool', ['input' => 'hello'], ['_meta' => ['trace' => 'abc']]);
        $payload = json_decode(self::textOf($result), true);

        self::assertSame('hello', $payload['input']);
        self::assertSame(['trace' => 'abc'], $payload['meta']);
        self::assertSame('stdio-a', $payload['serverName']);
    }

    public function testProgressNotificationsReachTheCallback(): void
    {
        $progress = [];
        $result = $this->stdioClient()->callTool('test_tool', ['input' => 'p'], [
            'onprogress' => static function (array $p) use (&$progress): void {
                $progress[] = $p['progress'];
            },
        ]);

        self::assertSame([1, 2, 3], $progress);
        self::assertArrayHasKey('progressToken', json_decode(self::textOf($result), true)['meta']);
    }

    public function testOtherNotificationsReachTheNotificationHandler(): void
    {
        $seen = [];
        $client = $this->stdioClient([], ['onNotification' => static function (string $method, array $params) use (&$seen): void {
            $seen[] = [$method, $params['message']];
        }]);

        $client->callTool('test_tool', ['input' => 'n']);

        self::assertSame([['notifications/message', 'test_tool invoked with n']], $seen);
    }

    public function testAToolErrorResultIsReturnedNotThrown(): void
    {
        $result = $this->stdioClient()->callTool('fail_tool', []);

        self::assertTrue($result['isError']);
        self::assertSame('tool failed', self::textOf($result));
    }

    public function testAJsonRpcErrorBecomesAnException(): void
    {
        $error = self::thrownBy(fn () => $this->stdioClient()->callTool('rpc_error', []));

        self::assertInstanceOf(JsonRpcException::class, $error);
        self::assertSame(-32602, $error->rpcCode);
        self::assertSame(['field' => 'nope'], $error->data);
        self::assertSame('Invalid params: nope', $error->getMessage());
    }

    public function testAnUnknownToolIsAJsonRpcError(): void
    {
        $this->expectException(JsonRpcException::class);

        $this->stdioClient()->callTool('missing', []);
    }

    public function testASlowCallTimesOutAndTheChildIsReapedOnClose(): void
    {
        $transport = $this->stdioTransport();
        $client = $this->track(new McpClient($transport));

        $error = self::thrownBy(static fn () => $client->callTool('slow', ['seconds' => 3], ['timeout' => 200]));
        $client->close();

        self::assertStringContainsString('timed out after 200ms', $error->getMessage());
        self::assertFalse($transport->isRunning());
    }

    public function testAnAbortedSignalStopsTheCall(): void
    {
        $signal = new \stdClass();
        $signal->aborted = true;

        $error = self::thrownBy(fn () => $this->stdioClient()->callTool('slow', ['seconds' => 3], ['signal' => $signal]));

        self::assertStringContainsString('aborted', $error->getMessage());
    }

    public function testStructuredContentIsValidatedAgainstTheToolsOutputSchema(): void
    {
        $client = $this->stdioClient();
        $definition = ['outputSchema' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'required' => ['n']]];

        $ok = $client->callTool('structured', [], ['toolDefinition' => $definition]);
        self::assertSame(['n' => 42], $ok['structuredContent']);

        $bad = self::thrownBy(static fn () => $client->callTool('structured', ['bad' => true], ['toolDefinition' => $definition]));
        self::assertStringContainsString('does not match its output schema', $bad->getMessage());

        $missing = self::thrownBy(static fn () => $client->callTool('no_structured', [], ['toolDefinition' => $definition]));
        self::assertStringContainsString('did not return structured content', $missing->getMessage());
    }

    public function testALegacyServerAsksTheElicitationHandler(): void
    {
        $asked = [];
        $client = $this->stdioClient();
        $client->setElicitationHandler(static function (array $params) use (&$asked): array {
            $asked[] = $params['message'];

            return ['action' => 'accept', 'content' => ['confirm' => true]];
        });

        $result = $client->callTool('approve', []);

        self::assertSame(['Approve legacy?'], $asked);
        self::assertSame('accept', self::textOf($result));
    }

    public function testTheElicitationCapabilityIsDeclaredOnlyWithAHandler(): void
    {
        $with = $this->stdioClient();
        $with->setElicitationHandler(static fn (): array => ['action' => 'decline']);
        $without = $this->stdioClient();

        self::assertSame('{"elicitation":{"form":{},"url":{}}}', self::textOf($with->callTool('client_caps', [])));
        self::assertSame('{}', self::textOf($without->callTool('client_caps', [])));
        self::assertSame('decline', self::textOf($with->callTool('approve', [])));
        self::assertSame('elicitation-refused', self::textOf($without->callTool('approve', [])));
    }

    public function testAThrowingElicitationHandlerBecomesAnErrorReplyNotACrash(): void
    {
        $client = $this->stdioClient();
        $client->setElicitationHandler(static function (): array {
            throw new \RuntimeException('no');
        });

        self::assertSame('elicitation-refused', self::textOf($client->callTool('approve', [])));
        self::assertCount(11, $client->listTools()['tools']);
    }

    public function testAModernServerReturnsInputRequiredWhenAllowed(): void
    {
        $client = $this->stdioClient(['modern-server', 'modern']);

        $result = $client->callTool('approve', [], ['allowInputRequired' => true]);

        self::assertSame('input_required', $result['resultType']);
        self::assertSame('fixture-state', $result['requestState']);
        self::assertSame('Approve modern?', $result['inputRequests']['confirmation']['params']['message']);
    }

    public function testAnInputRequiredResultThatWasNotAllowedFails(): void
    {
        $client = $this->stdioClient(['modern-server', 'modern']);

        $error = self::thrownBy(static fn () => $client->callTool('approve', []));

        self::assertStringContainsString('asked for input', $error->getMessage());
    }

    public function testTheInBandRetryCarriesInputResponsesAndRequestState(): void
    {
        $client = $this->stdioClient(['modern-server', 'modern']);

        $result = $client->callTool('approve', [], [
            'allowInputRequired' => true,
            'inputResponses' => ['confirmation' => ['action' => 'accept', 'content' => ['confirm' => true]]],
            'requestState' => 'fixture-state',
        ]);

        self::assertSame('accept', self::textOf($result));
    }

    public function testListAndReadResources(): void
    {
        $client = $this->stdioClient();

        self::assertSame('file:///hello.txt', $client->listResources()['resources'][0]['uri']);
        self::assertSame('hello world', $client->readResource('file:///hello.txt')['contents'][0]['text']);
    }

    public function testReadingAnUnknownResourceIsAJsonRpcError(): void
    {
        $error = self::thrownBy(fn () => $this->stdioClient()->readResource('file:///nope'));

        self::assertInstanceOf(JsonRpcException::class, $error);
        self::assertSame(-32002, $error->rpcCode);
    }

    public function testTheLoggingLevelIsSetOnLegacyServers(): void
    {
        $client = $this->stdioClient();
        $client->setLoggingLevel('debug');

        self::assertSame('debug', self::textOf($client->callTool('log_level', [])));
    }

    public function testTheLoggingLevelRequestIsLegacyOnly(): void
    {
        $client = $this->stdioClient(['m', 'modern']);

        $error = self::thrownBy(static fn () => $client->setLoggingLevel('debug'));

        self::assertStringContainsString('legacy-only', $error->getMessage());
    }

    public function testForkingNeedsATransportThatCarriesHeaders(): void
    {
        $this->expectException(McpClientError::class);

        $this->stdioClient()->fork(['X-A' => '1']);
    }

    public function testAServerThatDiesFailsTheHandshakeAndLeavesNoChild(): void
    {
        $transport = $this->stdioTransport(['dead', 'crash'], ['stderr' => 'pipe']);
        $client = $this->track(new McpClient($transport));

        $error = self::thrownBy(static fn () => $client->connect());

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertFalse($transport->isRunning());
    }

    public function testCloseIsIdempotentAndAllowsAReconnect(): void
    {
        $transport = $this->stdioTransport();
        $client = $this->track(new McpClient($transport));
        $client->connect();

        $client->close();
        $client->close();
        self::assertFalse($transport->isRunning());

        $client->connect();
        self::assertTrue($client->isConnected());
        self::assertCount(11, $client->listTools()['tools']);
    }

    public function testAnInvalidModeIsRejected(): void
    {
        $this->expectException(McpClientError::class);

        new McpClient($this->stdioTransport(), ['mode' => 'sideways']);
    }

    private static function thrownBy(callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('Expected a throwable.');
    }
}
