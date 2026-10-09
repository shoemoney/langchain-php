<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use LangChain\Tests\Unit\Mcp\Client\Fixtures\HttpServerProcess;
use LangGraph\Mcp\Client\McpClient;
use LangGraph\Mcp\Client\Transport\HttpStatusException;
use LangGraph\Mcp\Client\Transport\StreamableHttpTransport;
use LangGraph\Mcp\Errors;
use LangGraph\Mcp\McpClientError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpClient::class)]
#[CoversClass(StreamableHttpTransport::class)]
final class McpClientHttpTest extends TestCase
{
    private static HttpServerProcess $server;

    /** @var list<McpClient> */
    private array $clients = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = HttpServerProcess::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->clients = [];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    private function client(string $variant, array $config = [], array $options = []): McpClient
    {
        $client = new McpClient(new StreamableHttpTransport(['url' => self::$server->url($variant)] + $config), $options);
        $this->clients[] = $client;

        return $client;
    }

    /** @return iterable<string, array{string, string}> */
    public static function framings(): iterable
    {
        yield 'json bodies' => ['json', 'legacy'];
        yield 'sse framed' => ['sse', 'legacy'];
        yield 'modern json' => ['modern', 'modern'];
        yield 'modern sse' => ['modern-sse', 'modern'];
    }

    #[DataProvider('framings')]
    public function testTheHandshakeNegotiatesTheEra(string $variant, string $era): void
    {
        $client = $this->client($variant);

        self::assertSame($era, $client->getProtocolEra());
        self::assertSame("http-{$variant}", $client->getServerVersion()['name']);
    }

    #[DataProvider('framings')]
    public function testListAndCallToolsOverEitherFraming(string $variant): void
    {
        $client = $this->client($variant);

        self::assertCount(11, $client->listTools()['tools']);
        $payload = json_decode((string) $client->callTool('test_tool', ['input' => 'over-http'])['content'][0]['text'], true);

        self::assertSame('over-http', $payload['input']);
        self::assertSame("http-{$variant}", $payload['serverName']);
    }

    public function testProgressArrivesThroughAnSseStream(): void
    {
        $progress = [];
        $this->client('sse')->callTool('test_tool', ['input' => 'p'], [
            'onprogress' => static function (array $p) use (&$progress): void {
                $progress[] = $p['progress'];
            },
        ]);

        self::assertSame([1, 2, 3], $progress);
    }

    public function testRequestsAcceptJsonAndEventStreamAndCarryTheProtocolVersion(): void
    {
        $before = count(self::$server->requests());
        $client = $this->client('json');
        $client->listTools();

        $requests = array_slice(self::$server->requests(), $before);
        $initialize = $requests[0];
        $list = array_values(array_filter($requests, static fn (array $r): bool => str_contains($r['body'], 'tools/list')))[0];

        self::assertSame('application/json, text/event-stream', $initialize['headers']['accept']);
        self::assertSame('application/json', $initialize['headers']['content-type']);
        self::assertArrayNotHasKey('mcp-protocol-version', $initialize['headers']);
        self::assertSame('2025-06-18', $list['headers']['mcp-protocol-version']);
    }

    public function testTheSessionIdIsEchoedAndReleasedWithADeleteOnClose(): void
    {
        $before = count(self::$server->requests());
        $client = $this->client('json');
        $client->listTools();
        $client->close();

        $requests = array_slice(self::$server->requests(), $before);
        $sessionIds = array_unique(array_filter(array_map(static fn (array $r): ?string => $r['headers']['mcp-session-id'] ?? null, $requests)));
        $delete = array_values(array_filter($requests, static fn (array $r): bool => $r['method'] === 'DELETE'));

        self::assertCount(1, $sessionIds);
        self::assertCount(1, $delete);
        self::assertSame(reset($sessionIds), $delete[0]['headers']['mcp-session-id']);
        self::assertArrayNotHasKey('mcp-session-id', $requests[0]['headers']);
    }

    public function testInitializedIsSentAsANotificationThatTheServerAccepts(): void
    {
        $before = count(self::$server->requests());
        $this->client('json')->connect();

        $bodies = array_map(static fn (array $r): string => $r['body'], array_slice(self::$server->requests(), $before));

        self::assertStringContainsString('"method":"notifications/initialized"', $bodies[1]);
    }

    public function testCustomHeadersAreSentOnEveryRequest(): void
    {
        $before = count(self::$server->requests());
        $client = $this->client('json', ['headers' => ['X-Custom' => 'one']]);
        $client->listTools();

        foreach (array_slice(self::$server->requests(), $before) as $request) {
            self::assertSame('one', $request['headers']['x-custom']);
        }
    }

    public function testAForkedClientCarriesExtraHeadersOnItsOwnSession(): void
    {
        $client = $this->client('json', ['headers' => ['X-Base' => 'base']]);
        $client->connect();

        $fork = $client->fork(['X-Tenant' => 'acme']);
        $this->clients[] = $fork;

        self::assertSame('acme', $fork->callTool('show_header', ['name' => 'x-tenant'])['content'][0]['text']);
        self::assertSame('base', $fork->callTool('show_header', ['name' => 'x-base'])['content'][0]['text']);
        self::assertSame('NOT_SET', $client->callTool('show_header', ['name' => 'x-tenant'])['content'][0]['text']);
    }

    public function testAnHttpErrorStatusCarriesTheStatusCode(): void
    {
        $client = $this->client('auth');

        $error = $this->thrownBy(static fn () => $client->connect());

        self::assertInstanceOf(HttpStatusException::class, $error);
        self::assertSame(401, $error->status);
        self::assertSame(401, Errors::getHttpErrorCode($error));
        self::assertStringContainsString('(HTTP 401)', $error->getMessage());
    }

    public function testAuthorizationHeadersGetPastAGuardedEndpoint(): void
    {
        $client = $this->client('auth', ['headers' => ['Authorization' => 'Bearer secret']]);

        self::assertCount(11, $client->listTools()['tools']);
    }

    public function testAnExpiredSessionSurfacesAs404(): void
    {
        $client = $this->client('expired');
        $client->connect();

        $error = $this->thrownBy(static fn () => $client->listTools());

        self::assertInstanceOf(HttpStatusException::class, $error);
        self::assertSame(404, $error->status);
    }

    public function testAStreamThatDropsIsResumedWithLastEventId(): void
    {
        $before = count(self::$server->requests());
        $client = $this->client('resume', ['reconnect' => ['maxRetries' => 2, 'initialReconnectionDelay' => 10]]);

        $payload = json_decode((string) $client->callTool('test_tool', ['input' => 'resumed'])['content'][0]['text'], true);
        $gets = array_values(array_filter(array_slice(self::$server->requests(), $before), static fn (array $r): bool => $r['method'] === 'GET'));

        self::assertSame('resumed', $payload['input']);
        self::assertCount(1, $gets);
        self::assertSame('evt-1', $gets[0]['headers']['last-event-id']);
        self::assertSame('text/event-stream', $gets[0]['headers']['accept']);
    }

    public function testWithoutRetriesADroppedStreamIsAnError(): void
    {
        $client = $this->client('resume', ['reconnect' => ['maxRetries' => 0]]);

        $error = $this->thrownBy(static fn () => $client->callTool('test_tool', ['input' => 'x']));

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertStringContainsString('ended before the response arrived', $error->getMessage());
    }

    public function testASlowCallTimesOutOverHttp(): void
    {
        $client = $this->client('json');

        $error = $this->thrownBy(static fn () => $client->callTool('slow', ['seconds' => 1], ['timeout' => 300]));

        self::assertInstanceOf(McpClientError::class, $error);
    }

    public function testToolErrorsAndJsonRpcErrorsArriveOverHttp(): void
    {
        $client = $this->client('sse');

        self::assertTrue($client->callTool('fail_tool', [])['isError']);
        $this->expectException(\LangGraph\Mcp\Client\JsonRpcException::class);
        $client->callTool('rpc_error', []);
    }

    public function testAnUnreachableServerFailsWithAClientError(): void
    {
        $client = new McpClient(new StreamableHttpTransport(['url' => 'http://127.0.0.1:1/mcp']));

        $this->expectException(McpClientError::class);

        $client->connect();
    }

    public function testAModernServerReturnsInputRequiredOverHttp(): void
    {
        $result = $this->client('modern-sse')->callTool('approve', [], ['allowInputRequired' => true]);

        self::assertSame('input_required', $result['resultType']);
    }

    public function testATransportNeedsAUrl(): void
    {
        $this->expectException(McpClientError::class);

        new StreamableHttpTransport(['url' => '']);
    }

    public function testAReplyToAServerRequestMidStreamLeavesTheToolCallStreamOpen(): void
    {
        $sse = static fn (array $message): string => "event: message\ndata: " . json_encode($message) . "\n\n";
        $json = ['Content-Type' => 'application/json'];
        $events = [
            $sse(['jsonrpc' => '2.0', 'id' => 'srv-1', 'method' => 'elicitation/create', 'params' => ['message' => 'Approve?', 'requestedSchema' => ['type' => 'object', 'properties' => []]]]),
            $sse(['jsonrpc' => '2.0', 'method' => 'ping-notification-noise']),
            $sse(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'tool-result']]]]),
        ];
        $body = FnStream::decorate(Utils::streamFor(''), [
            'read' => static function () use (&$events): string {
                return array_shift($events) ?? '';
            },
            'eof' => static function () use (&$events): bool {
                return $events === [];
            },
        ]);

        $mock = new MockHandler([
            new Response(200, $json, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18', 'capabilities' => ['tools' => new \stdClass()], 'serverInfo' => ['name' => 'mock', 'version' => '1']]])),
            new Response(202),
            new Response(200, ['Content-Type' => 'text/event-stream'], $body),
            new Response(202),
        ]);
        $stack = HandlerStack::create($mock);
        $history = [];
        $stack->push(Middleware::history($history));

        $answered = [];
        $client = new McpClient(new StreamableHttpTransport(['url' => 'http://mock.test/mcp', 'client' => new Client(['handler' => $stack])]));
        $client->setElicitationHandler(static function (array $params) use (&$answered): array {
            $answered[] = $params['message'];

            return ['action' => 'accept', 'content' => []];
        });
        $this->clients[] = $client;

        $result = $client->callTool('approve', []);

        self::assertSame(['Approve?'], $answered);
        self::assertSame('tool-result', $result['content'][0]['text']);
        $reply = json_decode((string) $history[3]['request']->getBody(), true);
        self::assertSame('srv-1', $reply['id']);
        self::assertSame('accept', $reply['result']['action']);
    }

    private function thrownBy(callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('Expected a throwable.');
    }
}
