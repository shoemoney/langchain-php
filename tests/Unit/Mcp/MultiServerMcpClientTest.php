<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangChain\Messages\AIMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Mcp\Client\Fixtures\HttpServerProcess;
use LangChain\Tests\Unit\Mcp\Connection\FakeServerTransport;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangChain\Tools\DynamicStructuredTool;
use LangGraph\Mcp\Client\JsonRpcException;
use LangGraph\Mcp\McpClientError;
use LangGraph\Mcp\MultiServerMcpClient;
use LangGraph\Mcp\ToolException;
use LangGraph\Prebuilt\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Port of `client.test.ts` ("MultiServerMCPClient Integration Tests" and the suites below it),
 * minus OAuth and SSE.
 *
 * Upstream spins up dummy HTTP and stdio servers for every case. A few cases here do the same
 * against the fixture servers in `Client/Fixtures` (a child process on stdio, `php -S` on
 * streamable HTTP); the rest run the same assertions over a scripted transport, which is faster
 * and can misbehave on demand. The `it.each(["http", "sse"])` rows collapse to the HTTP row.
 *
 * Not ported: "OAuth Authentication" (8), the SSE transport block (4) and the SSE rows of every
 * `it.each`, the SSE fallback case, `listResourceTemplates`, and "honors resource cache policy",
 * which needs the SDK's response cache.
 */
#[CoversClass(MultiServerMcpClient::class)]
final class MultiServerMcpClientTest extends MultiServerTestCase
{
    private const STDIO_SERVER = __DIR__ . '/Client/Fixtures/StdioServer.php';

    private static HttpServerProcess $http;

    public static function setUpBeforeClass(): void
    {
        self::$http = HttpServerProcess::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$http->stop();
    }

    /**
     * @param list<string>         $args
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function stdioServer(string $name, array $args = [], array $extra = []): array
    {
        return [...['command' => \PHP_BINARY, 'args' => [self::STDIO_SERVER, $name, ...$args], 'stderr' => 'ignore'], ...$extra];
    }

    /**
     * @param list<DynamicStructuredTool> $tools
     */
    private static function find(array $tools, string $partialName): DynamicStructuredTool
    {
        foreach ($tools as $tool) {
            if (str_contains($tool->name, $partialName)) {
                return $tool;
            }
        }

        self::fail("No tool named like \"{$partialName}\".");
    }

    // ------------------------------------------------------------------------ stdio transport

    public function testConnectsToAndCommunicatesWithAStdioMcpServer(): void
    {
        $client = $this->plain(['stdio-server' => self::stdioServer('stdio-test', [], ['mode' => 'legacy', 'env' => ['TEST_VAR' => 'test-value']])]);

        $tools = $client->listTools();
        self::assertNotEmpty($tools);

        $result = self::find($tools, 'test_tool')->invoke(['input' => 'test input']);
        self::assertStringContainsString('test input', $result);
        self::assertStringContainsString('stdio-test', $result);
        self::assertSame('test-value', self::find($tools, 'check_env')->invoke(['varName' => 'TEST_VAR']));
    }

    public function testAcceptsAStdioRestartConfigurationAgainstARealServer(): void
    {
        $client = $this->plain(['stdio-server' => self::stdioServer('stdio-restart', [], ['mode' => 'legacy', 'restart' => ['enabled' => true, 'maxAttempts' => 2, 'delayMs' => 100]])]);

        self::assertNotEmpty($client->listTools());
    }

    public function testASingleServerSpeakingTheModernProtocolIsDetectedAutomatically(): void
    {
        $client = $this->plain(['stdio-server' => self::stdioServer('modern-stdio', ['modern'])]);

        self::assertNotEmpty($client->listTools());
        self::assertSame('modern', $client->getClient('stdio-server')->getProtocolEra());
    }

    // -------------------------------------------------------------------------- HTTP transport

    public function testConnectsToAndCommunicatesWithAnHttpMcpServer(): void
    {
        $client = $this->plain(['servers' => ['http-server' => ['mode' => 'legacy', 'transport' => 'http', 'url' => self::$http->url('json')]]]);

        $tools = $client->listTools();
        self::assertNotEmpty($tools);

        $result = self::find($tools, 'test_tool')->invoke(['input' => 'http test']);
        self::assertStringContainsString('http test', $result);
        self::assertStringContainsString('http-json', $result);
    }

    public function testDoesNotFallBackToSseWhenStreamableHttpFails(): void
    {
        $client = $this->plain(['http-server' => ['mode' => 'legacy', 'url' => self::$http->url('auth'), 'automaticSSEFallback' => true]]);
        $before = count(self::$http->requests());

        $error = self::thrownBy(static fn () => $client->listTools());

        // The failure is the answer: nothing was retried against an SSE endpoint.
        self::assertInstanceOf(McpClientError::class, $error);
        self::assertStringContainsString('Authentication failed for HTTP server "http-server"', $error->getMessage());
        $made = array_slice(self::$http->requests(), $before);
        self::assertNotEmpty($made);
        foreach ($made as $request) {
            self::assertSame('POST', $request['method']);
            self::assertSame('/auth', $request['path']);
        }
    }

    // ----------------------------------------------------------------------- multiple servers

    /** @return array<string, array{string}> */
    public static function stdioEras(): array
    {
        return ['legacy stdio' => ['legacy'], 'modern stdio' => ['modern']];
    }

    #[DataProvider('stdioEras')]
    public function testConnectsAStdioServerAlongsideHttpServers(string $era): void
    {
        $client = $this->plain([
            'mcpServers' => [
                'stdio-server' => self::stdioServer('multi-stdio', [$era]),
                'http-server' => ['mode' => 'legacy', 'url' => self::$http->url('json')],
                'modern-server' => ['url' => self::$http->url('modern')],
            ],
            'prefixToolNameWithServerName' => true,
        ]);

        $tools = $client->listTools();

        $byServer = static fn (string $server): array => array_values(array_filter($tools, static fn ($t): bool => str_starts_with($t->name, $server . '__')));
        self::assertCount(11, $byServer('stdio-server'));
        self::assertCount(11, $byServer('http-server'));
        self::assertCount(11, $byServer('modern-server'));
        self::assertCount(33, $tools);

        self::assertStringContainsString('multi-stdio', self::find($tools, 'stdio-server__test_tool')->invoke(['input' => 'multi-server test']));
        foreach ([['http-server', 'http-json'], ['modern-server', 'http-modern']] as [$server, $label]) {
            self::assertStringContainsString($label, self::find($tools, "{$server}__test_tool")->invoke(['input' => 'routing']));
        }

        $resources = $client->listResources('stdio-server', 'http-server');
        self::assertSame(['stdio-server', 'http-server'], array_keys($resources));
        self::assertSame('file:///hello.txt', $resources['stdio-server'][0]['uri']);
        self::assertSame('hello world', $client->readResource('stdio-server', 'file:///hello.txt')[0]['text']);
    }

    public function testFiltersToolsByServerName(): void
    {
        $client = $this->plain([
            'mcpServers' => [
                'stdio-server' => self::stdioServer('filter-stdio'),
                'http-server' => ['mode' => 'legacy', 'url' => self::$http->url('json')],
            ],
            'prefixToolNameWithServerName' => true,
        ]);

        $all = $client->listTools();
        $stdio = $client->listTools('stdio-server');
        $http = $client->listTools('http-server');

        self::assertCount(count($stdio) + count($http), $all);
        foreach ($stdio as $tool) {
            self::assertStringContainsString('stdio-server', $tool->name);
        }
        foreach ($http as $tool) {
            self::assertStringContainsString('http-server', $tool->name);
        }
    }

    public function testProvidesAccessToIndividualServerClients(): void
    {
        $client = $this->plain(['test-server' => ['mode' => 'legacy', 'url' => self::$http->url('json')]]);

        $client->initializeConnections();

        self::assertNotNull($client->getClient('test-server'));
        self::assertNull($client->getClient('nonexistent'));
    }

    public function testDetectsModernHttpLegacyHttpAndLegacyStdioInOneAdapter(): void
    {
        $adapter = $this->plain(['servers' => [
            'modern' => ['url' => self::$http->url('modern')],
            'legacy' => ['url' => self::$http->url('json')],
            'stdio' => self::stdioServer('legacy-auto'),
            'modern-stdio' => self::stdioServer('modern-auto', ['modern']),
        ]]);
        $mismatch = $this->plain(['servers' => ['wrong' => ['mode' => 'modern', 'url' => self::$http->url('json')]]]);

        $tools = $adapter->listTools();

        self::assertNotEmpty($tools);
        self::assertSame('modern', $adapter->getClient('modern')->getProtocolEra());
        self::assertSame('legacy', $adapter->getClient('legacy')->getProtocolEra());
        self::assertSame('legacy', $adapter->getClient('stdio')->getProtocolEra());
        self::assertSame('modern', $adapter->getClient('modern-stdio')->getProtocolEra());
        foreach (['modern', 'legacy', 'stdio'] as $name) {
            $tool = self::find($adapter->listTools($name), 'test_tool');
            self::assertStringContainsString('routing', $tool->invoke(['input' => 'routing']));
        }

        $error = self::thrownBy(static fn () => $mismatch->listTools());
        self::assertMatchesRegularExpression('/in modern mode/', $error->getMessage());
        self::assertStringContainsString('pinned to modern', $error->getMessage());
    }

    // ---------------------------------------------------------------------- error handling

    public function testHandlesConnectionFailuresGracefully(): void
    {
        $client = $this->plain(['failing-server' => ['mode' => 'legacy', 'url' => 'http://127.0.0.1:1/mcp']]);

        $error = self::thrownBy(static fn () => $client->listTools());

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertMatchesRegularExpression('/Failed to connect to.*server.*failing-server/i', $error->getMessage());
        self::assertSame('failing-server', $error->serverName);
    }

    public function testHandlesAnInvalidStdioCommand(): void
    {
        $client = $this->plain(['failing-stdio' => ['command' => 'nonexistent-command-xyz', 'args' => [], 'stderr' => 'ignore']]);

        $error = self::thrownBy(static fn () => $client->listTools());

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertMatchesRegularExpression('/Failed to connect to stdio server.*failing-stdio/i', $error->getMessage());
        self::assertSame('failing-stdio', $error->serverName);
    }

    // ------------------------------------------------------------------------- configuration

    public function testRespectsToolNamePrefixingConfiguration(): void
    {
        $withPrefix = $this->plain([
            'mcpServers' => ['test-server' => ['mode' => 'legacy', 'url' => self::$http->url('json')]],
            'prefixToolNameWithServerName' => true,
            'additionalToolNamePrefix' => 'custom',
        ]);
        $withoutPrefix = $this->plain([
            'mcpServers' => ['test-server' => ['mode' => 'legacy', 'url' => self::$http->url('json')]],
            'prefixToolNameWithServerName' => false,
            'additionalToolNamePrefix' => '',
        ]);

        self::assertContains('custom__test-server__test_tool', self::names($withPrefix->listTools()));
        self::assertContains('test_tool', self::names($withoutPrefix->listTools()));
    }

    // ------------------------------------------------------------------- timeout configuration

    /**
     * A scripted server whose `slow_tool` never answers, so only a timeout can end the call.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $client
     */
    private function slowAdapter(array $server = [], array $client = []): MultiServerMcpClient
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->tools = [['name' => 'sleep_tool', 'inputSchema' => ['type' => 'object']]];
            $transport->hang = ['tools/call'];
        });

        return $this->adapter(['servers' => ['timeout-server' => self::http($server)], ...$client]);
    }

    public function testHonorsAPerCallTimeoutShorterThanTheToolTakes(): void
    {
        $tool = $this->slowAdapter()->listTools()[0];

        $error = self::thrownBy(static fn () => $tool->invoke([], new RunnableConfig(metadata: ['timeoutMs' => 10])));

        self::assertInstanceOf(ToolException::class, $error);
        self::assertStringContainsString('timed out after 10ms', $error->getMessage());
    }

    public function testAServerDefaultToolTimeoutEndsAHungCall(): void
    {
        $tool = $this->slowAdapter(['defaultToolTimeout' => 5])->listTools()[0];

        $error = self::thrownBy(static fn () => $tool->invoke([]));

        self::assertInstanceOf(ToolException::class, $error);
        self::assertStringContainsString('timed out after 5ms', $error->getMessage());
    }

    public function testAConstructorDefaultToolTimeoutEndsAHungCall(): void
    {
        $tool = $this->slowAdapter([], ['defaultToolTimeout' => 7])->listTools()[0];

        $error = self::thrownBy(static fn () => $tool->invoke([]));

        self::assertStringContainsString('timed out after 7ms', $error->getMessage());
    }

    public function testTheClientWideDefaultToolTimeoutWinsOverAServers(): void
    {
        $tool = $this->slowAdapter(['defaultToolTimeout' => 60000], ['defaultToolTimeout' => 5])->listTools()[0];

        $error = self::thrownBy(static fn () => $tool->invoke([]));

        self::assertStringContainsString('timed out after 5ms', $error->getMessage());
    }

    public function testAnExplicitPerCallTimeoutOverridesTheServerDefault(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->tools = [['name' => 'sleep_tool', 'inputSchema' => ['type' => 'object']]];
            $transport->hang = ['tools/call'];
        });
        $adapter = $this->adapter(['servers' => ['timeout-server' => self::http(['defaultToolTimeout' => 10000])]]);
        $tool = $adapter->listTools()[0];

        $error = self::thrownBy(static fn () => $tool->invoke([], new RunnableConfig(metadata: ['timeoutMs' => 30])));

        // Long enough default that it cannot be what aborted the call: the per-call value reached the client.
        self::assertStringContainsString('timed out after 30ms', $error->getMessage());
    }

    public function testACallThatFinishesInsideItsTimeoutSucceeds(): void
    {
        $adapter = $this->adapter(['servers' => ['timeout-server' => self::http(['defaultToolTimeout' => 1000])]]);
        $tool = $adapter->listTools()[0];

        self::assertSame('tool1 result', $tool->invoke([]));
    }

    public function testTheDefaultTimeoutReachesTheTransportAsTheRequestTimeout(): void
    {
        $adapter = $this->adapter(['servers' => ['s' => self::http(['defaultToolTimeout' => 1234])]]);

        $adapter->listTools()[0]->invoke([]);

        self::assertSame(1.234, $this->transport(0)->requestTimeouts[array_key_last($this->transport(0)->requestTimeouts)]);
    }

    public function testARealStdioToolIsCutShortByItsTimeout(): void
    {
        $client = $this->plain(['servers' => ['slow' => self::stdioServer('slow-server', [], ['mode' => 'legacy', 'defaultToolTimeout' => 100])]]);
        $slow = self::find($client->listTools(), 'slow');

        $error = self::thrownBy(static fn () => $slow->invoke(['seconds' => 0.6]));

        self::assertInstanceOf(ToolException::class, $error);
        self::assertStringContainsString('timed out after 100ms', $error->getMessage());
    }

    public function testAnAbortSignalEndsAHungCall(): void
    {
        $signal = new \stdClass();
        $signal->aborted = false;
        $this->factory->script(static function (FakeServerTransport $transport) use ($signal): void {
            $transport->tools = [['name' => 'slow', 'inputSchema' => ['type' => 'object']]];
            $transport->onRequest = static function (string $method) use ($signal): void {
                if ($method === 'tools/call') {
                    $signal->aborted = true;
                }
            };
        });
        $tool = $this->adapter(['servers' => ['s' => self::http()]])->listTools()[0];

        $error = self::thrownBy(static fn () => $tool->invoke([], new RunnableConfig(signal: $signal)));

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertStringContainsString('was aborted', $error->getMessage());
    }

    // ---------------------------------------------------------------- content and artifacts

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private const WAV = 'UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAEA';

    /** A server like upstream's dummy one: image, audio, resource and structured tools. */
    private function contentServer(string $serverName): void
    {
        $this->factory->script(static function (FakeServerTransport $transport) use ($serverName): void {
            $schema = ['type' => 'object', 'properties' => ['input' => ['type' => 'string']], 'required' => ['input']];
            $transport->tools = array_map(
                static fn (string $name): array => ['name' => $name, 'description' => $name, 'inputSchema' => $schema],
                ['image_tool', 'audio_tool', 'resource_tool', 'structured_tool'],
            );
            $transport->callHandlers = [
                'image_tool' => static fn (array $args): array => ['content' => [
                    ['type' => 'text', 'text' => "Image input was: {$args['input']}"],
                    ['type' => 'image', 'data' => self::PNG, 'mimeType' => 'image/png'],
                ]],
                'audio_tool' => static fn (array $args): array => ['content' => [
                    ['type' => 'text', 'text' => "Audio input was: {$args['input']} ({$serverName})"],
                    ['type' => 'audio', 'data' => self::WAV, 'mimeType' => 'audio/wav'],
                ]],
                'resource_tool' => static fn (array $args): array => ['content' => [
                    ['type' => 'text', 'text' => "Resource input was: {$args['input']}"],
                    ['type' => 'resource', 'resource' => ['uri' => 'mem://test.txt', 'mimeType' => 'text/plain', 'text' => 'This is a test resource.']],
                ]],
                'structured_tool' => static fn (array $args): array => [
                    'content' => [['type' => 'text', 'text' => "Structured input was: {$args['input']}"]],
                    'structuredContent' => ['type' => 'object', 'data' => ['result' => 'success', 'value' => $args['input']]],
                    '_meta' => ['toolVersion' => '1.0.0', 'serverName' => $serverName, 'executionTime' => 100],
                ],
            ];
            $transport->resources = [['uri' => 'mem://test.txt', 'name' => 'Test Resource', 'description' => 'A test resource', 'mimeType' => 'text/plain']];
            $transport->resourceContents = ['mem://test.txt' => [['uri' => 'mem://test.txt', 'mimeType' => 'text/plain', 'text' => 'This is a test resource content.', '_meta' => ['revision' => 'test-revision']]]];
        });
    }

    /**
     * @param array<string, mixed> $config
     */
    private function contentAdapter(string $serverName, array $config = [], array $server = []): MultiServerMcpClient
    {
        $this->contentServer($serverName);

        return $this->adapter(['mcpServers' => [$serverName => self::http($server)], ...$config]);
    }

    /**
     * The content of a tool message as a list of blocks. An empty list reaches the message as the
     * string `[]` (how the tool output is serialised), which is the same "nothing" upstream sees.
     *
     * @return list<array<string, mixed>>|string
     */
    private static function blocks(ToolMessage $message): array|string
    {
        return $message->content === '[]' ? [] : $message->content;
    }

    private static function call(DynamicStructuredTool $tool): ToolMessage
    {
        $message = $tool->invoke(['type' => 'tool_call', 'id' => 'fake-tool-call-id', 'name' => $tool->name, 'args' => ['input' => 'test output handling']]);
        self::assertInstanceOf(ToolMessage::class, $message);

        return $message;
    }

    public function testHandlesToolsReturningAudioContent(): void
    {
        $tool = self::find($this->contentAdapter('http-audio-test')->listTools(), 'audio_tool');

        $message = self::call($tool);

        self::assertSame([], $message->artifact);
        self::assertIsArray($message->content);
        $text = array_values(array_filter($message->content, static fn (array $b): bool => $b['type'] === 'text'))[0];
        self::assertStringContainsString('Audio input was: test output handling', $text['text']);
        self::assertStringContainsString('http-audio-test', $text['text']);
        $audio = array_values(array_filter($message->content, static fn (array $b): bool => $b['type'] === 'audio'))[0];
        self::assertArrayNotHasKey('source_type', $audio);
        self::assertSame('audio/wav', $audio['mimeType']);
        self::assertGreaterThan(10, strlen($audio['data']));
    }

    public function testUsesStandardContentBlocksByDefault(): void
    {
        $tools = $this->contentAdapter('content-block-test-server')->listTools();

        $image = self::call(self::find($tools, 'image_tool'));
        self::assertSame([], $image->artifact);
        self::assertSame('text', $image->content[0]['type']);
        self::assertStringContainsString('Image input was: test output handling', $image->content[0]['text']);
        self::assertSame(['type' => 'image', 'data' => self::PNG, 'mimeType' => 'image/png'], $image->content[1]);

        $audio = self::call(self::find($tools, 'audio_tool'));
        self::assertSame([], $audio->artifact);
        self::assertSame('audio', $audio->content[1]['type']);
        self::assertSame('audio/wav', $audio->content[1]['mimeType']);
        self::assertArrayNotHasKey('source_type', $audio->content[1]);
    }

    public function testUsesDefaultOutputHandlingWhenNotSpecified(): void
    {
        $tools = $this->contentAdapter('output-handling-test-default')->listTools();

        $image = self::call(self::find($tools, 'image_tool'));
        self::assertCount(2, $image->content);
        self::assertSame([], $image->artifact);

        $resource = self::call(self::find($tools, 'resource_tool'));
        self::assertStringContainsString('test output handling', $resource->content);
        self::assertCount(1, $resource->artifact);
        self::assertSame('resource', $resource->artifact[0]['type']);
        self::assertSame('mem://test.txt', $resource->artifact[0]['resource']['uri']);
        self::assertSame('This is a test resource.', $resource->artifact[0]['resource']['text']);
    }

    public function testSendsAllOutputToTheArtifactWhenClientOutputHandlingIsArtifact(): void
    {
        $tools = $this->contentAdapter('output-handling-test-client-artifact', ['outputHandling' => 'artifact'])->listTools();

        $image = self::call(self::find($tools, 'image_tool'));
        self::assertSame([], self::blocks($image));
        self::assertCount(2, $image->artifact);
        self::assertSame(self::PNG, $image->artifact[1]['data']);

        $resource = self::call(self::find($tools, 'resource_tool'));
        self::assertSame([], self::blocks($resource));
        self::assertSame(['text', 'resource'], array_column($resource->artifact, 'type'));

        $audio = self::call(self::find($tools, 'audio_tool'));
        self::assertSame([], self::blocks($audio));
        self::assertSame(self::WAV, $audio->artifact[1]['data']);
    }

    public function testSendsAllOutputToContentWhenClientOutputHandlingIsContent(): void
    {
        $tools = $this->contentAdapter('output-handling-test-client-content', ['outputHandling' => 'content'])->listTools();

        $image = self::call(self::find($tools, 'image_tool'));
        self::assertSame([], $image->artifact);
        self::assertSame(['text', 'image'], array_column($image->content, 'type'));

        $resource = self::call(self::find($tools, 'resource_tool'));
        self::assertSame(
            [['type' => 'mcp_content', 'data' => ['type' => 'resource', 'resource' => ['uri' => 'mem://test.txt', 'mimeType' => 'text/plain', 'text' => 'This is a test resource.']]]],
            $resource->artifact,
        );
        self::assertCount(2, $resource->content);
        self::assertSame(['type' => 'text', 'text' => 'This is a test resource.', 'metadata' => ['uri' => 'mem://test.txt']], $resource->content[1]);
    }

    public function testUsesClientLevelDetailedOutputHandling(): void
    {
        $tools = $this->contentAdapter('output-handling-test-client-detailed', ['outputHandling' => ['text' => 'content', 'image' => 'artifact', 'audio' => 'content', 'resource' => 'content']])->listTools();

        $image = self::call(self::find($tools, 'image_tool'));
        self::assertStringContainsString('test output handling', $image->content);
        self::assertSame([['type' => 'image', 'data' => self::PNG, 'mimeType' => 'image/png']], $image->artifact);

        $resource = self::call(self::find($tools, 'resource_tool'));
        self::assertCount(2, $resource->content);
        self::assertSame(['uri' => 'mem://test.txt'], $resource->content[1]['metadata']);
        self::assertSame('mcp_content', $resource->artifact[0]['type']);
    }

    public function testAllowsServerSpecificOutputHandlingToOverrideClientLevel(): void
    {
        $tools = $this->contentAdapter(
            'output-handling-test-server-override',
            ['outputHandling' => 'artifact'],
            ['outputHandling' => ['image' => 'content', 'resource' => 'artifact']],
        )->listTools();

        $image = self::call(self::find($tools, 'image_tool'));
        self::assertEquals([['type' => 'image', 'mimeType' => 'image/png', 'data' => self::PNG]], $image->content);
        self::assertCount(1, $image->artifact);
        self::assertSame('text', $image->artifact[0]['type']);
        self::assertStringContainsString('test output handling', $image->artifact[0]['text']);

        $resource = self::call(self::find($tools, 'resource_tool'));
        self::assertSame([], self::blocks($resource));
        self::assertSame(['text', 'resource'], array_column($resource->artifact, 'type'));
    }

    public function testRespectsOutputHandlingWithStandardContent(): void
    {
        $tools = $this->contentAdapter('output-handling-test-std-blocks', ['outputHandling' => ['text' => 'content', 'image' => 'artifact', 'audio' => 'content', 'resource' => 'artifact']])->listTools();

        $image = self::call(self::find($tools, 'image_tool'));
        self::assertStringContainsString('test output handling', $image->content);
        self::assertCount(1, $image->artifact);
        self::assertSame('image/png', $image->artifact[0]['mimeType']);

        $audio = self::call(self::find($tools, 'audio_tool'));
        self::assertSame([], $audio->artifact);
        self::assertSame(['text', 'audio'], array_column($audio->content, 'type'));
        self::assertSame('audio/wav', $audio->content[1]['mimeType']);

        $resource = self::call(self::find($tools, 'resource_tool'));
        self::assertStringContainsString('test output handling', $resource->content);
        self::assertSame(
            ['type' => 'resource', 'resource' => ['uri' => 'mem://test.txt', 'mimeType' => 'text/plain', 'text' => 'This is a test resource.']],
            array_intersect_key($resource->artifact[0], ['type' => 1, 'resource' => 1]),
        );
    }

    // ---------------------------------------------------------------------- resources

    public function testListsResourcesFromAServer(): void
    {
        $serverName = 'resource-test-http';
        $resources = $this->contentAdapter($serverName)->listResources();

        self::assertArrayHasKey($serverName, $resources);
        $found = array_values(array_filter($resources[$serverName], static fn (array $r): bool => $r['uri'] === 'mem://test.txt'))[0];
        self::assertSame('Test Resource', $found['name']);
        self::assertStringContainsString('test resource', $found['description']);
        self::assertSame('text/plain', $found['mimeType']);
    }

    public function testListedResourcesPreferTheTitleOverTheName(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->resources = [['uri' => 'mem://a', 'name' => 'a', 'title' => 'A Title']];
        });
        $adapter = $this->adapter(['servers' => ['svc' => self::http()]]);

        self::assertSame('A Title', $adapter->listResources('svc')['svc'][0]['name']);
    }

    public function testReadsAResourceFromAServer(): void
    {
        $serverName = 'read-resource-test-http';
        $content = $this->contentAdapter($serverName)->readResource($serverName, 'mem://test.txt');

        self::assertNotEmpty($content);
        self::assertSame('mem://test.txt', $content[0]['uri']);
        self::assertSame('text/plain', $content[0]['mimeType']);
        self::assertSame('This is a test resource content.', $content[0]['text']);
        self::assertSame(['revision' => 'test-revision'], $content[0]['_meta']);
    }

    public function testReadingANonExistentResourceFails(): void
    {
        $serverName = 'read-resource-error-test-http';
        $adapter = $this->contentAdapter($serverName);

        $error = self::thrownBy(static fn () => $adapter->readResource($serverName, 'mem://nonexistent.txt'));

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertMatchesRegularExpression('#Resource not found: mem://nonexistent.txt#', $error->getMessage());
        self::assertSame($serverName, $error->serverName);
        self::assertInstanceOf(JsonRpcException::class, $error->cause);
        self::assertSame(-32002, $error->cause->rpcCode);
    }

    public function testReadingFromAnUnknownServerFails(): void
    {
        $adapter = $this->contentAdapter('known');

        $error = self::thrownBy(static fn () => $adapter->readResource('unknown', 'mem://test.txt'));

        self::assertSame('Server "unknown" not found or not connected', $error->getMessage());
        self::assertSame('unknown', $error->serverName);
    }

    // ------------------------------------------------------------ structured content and meta

    public function testParsesStructuredContentAndMetaFromAToolResult(): void
    {
        $serverName = 'structured-test-http';
        $message = self::call(self::find($this->contentAdapter($serverName)->listTools(), 'structured_tool'));

        // The text block still reaches the model unchanged...
        self::assertSame('Structured input was: test output handling', $message->content);
        // ...while structuredContent and _meta are preserved as artifacts.
        self::assertSame(['mcp_structured_content', 'mcp_meta'], array_column($message->artifact, 'type'));
        self::assertSame(['type' => 'object', 'data' => ['result' => 'success', 'value' => 'test output handling']], $message->artifact[0]['data']);
        self::assertSame(['toolVersion' => '1.0.0', 'serverName' => $serverName, 'executionTime' => 100], $message->artifact[1]['data']);
    }

    /** @return array<string, array{mixed}> */
    public static function jsonValues(): array
    {
        return ['zero' => [0], 'false' => [false], 'list' => [[1, 'value']], 'text' => ['text'], 'object' => [['a' => ['b' => null]]]];
    }

    #[DataProvider('jsonValues')]
    public function testPreservesAnyJsonStructuredContent(mixed $value): void
    {
        $this->factory->script(static function (FakeServerTransport $transport) use ($value): void {
            $transport->tools = [['name' => 'json', 'inputSchema' => ['type' => 'object']]];
            $transport->callHandlers['json'] = static fn (): array => ['content' => [], 'structuredContent' => $value];
        });
        $tool = $this->adapter(['servers' => ['s' => self::http()]])->listTools()[0];

        $message = $tool->invoke(['type' => 'tool_call', 'id' => 'c', 'name' => 'json', 'args' => []]);

        self::assertContains(['type' => 'mcp_structured_content', 'data' => $value], $message->artifact);
    }

    // ------------------------------------------------------------------ client integration

    public function testForwardsTheCallersSignalAndTimeoutToTheClient(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->tools = [['name' => 'echo', 'inputSchema' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]]]];
            $transport->callHandlers['echo'] = static fn (array $args): array => ['content' => [['type' => 'text', 'text' => $args['value']]]];
        });
        $tool = $this->adapter(['servers' => ['external' => self::http()]])->listTools()[0];
        $signal = new \stdClass();
        $signal->aborted = false;

        $result = $tool->invoke(['value' => 'hello'], new RunnableConfig(signal: $signal, metadata: ['timeoutMs' => 1000]));

        self::assertSame('hello', $result);
        self::assertSame(['name' => 'echo', 'arguments' => ['value' => 'hello']], array_intersect_key($this->transport(0)->lastParams('tools/call'), ['name' => 1, 'arguments' => 1]));
    }

    public function testCompletesLegacyWireResultsWithoutAResultType(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->tools = [['name' => 'legacy_result', 'inputSchema' => ['type' => 'object']]];
            $transport->callHandlers['legacy_result'] = static fn (): array => ['content' => [['type' => 'text', 'text' => 'legacy complete']]];
        });
        $adapter = $this->adapter(['servers' => ['legacy' => self::http()]]);

        self::assertSame('legacy', $adapter->getClient('legacy')->getProtocolEra());
        self::assertSame('legacy complete', $adapter->listTools()[0]->invoke([]));
    }

    public function testPreservesFractionalSchemaBoundsAndDefaults(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->tools = [['name' => 'fraction', 'inputSchema' => [
                'type' => 'object',
                'properties' => ['value' => ['type' => 'number', 'minimum' => 0.25, 'maximum' => 0.75, 'default' => 0.5]],
            ]]];
            $transport->callHandlers['fraction'] = static fn (array $args): array => ['content' => [['type' => 'text', 'text' => (string) ($args['value'] ?? 0.5)]]];
        });
        $adapter = $this->adapter(['servers' => ['fraction' => self::http()]]);
        $tool = $adapter->listTools()[0];

        self::assertSame('0.25', $tool->invoke(['value' => 0.25]));
        self::assertSame('0.75', $tool->invoke(['value' => 0.75]));
        $calls = count(array_keys($this->transport(0)->methods(), 'tools/call', true));
        foreach ([0.1, 0.9] as $outOfBounds) {
            $error = self::thrownBy(static fn () => $tool->invoke(['value' => $outOfBounds]));
            self::assertMatchesRegularExpression('/did not match expected schema|Invalid arguments/', $error->getMessage());
        }
        self::assertCount($calls, array_keys($this->transport(0)->methods(), 'tools/call', true), 'nothing reached the wire');
    }

    public function testPreservesOutputSchemaValidation(): void
    {
        $this->factory->script(static function (FakeServerTransport $transport): void {
            $transport->tools = [['name' => 'validated', 'inputSchema' => ['type' => 'object'], 'outputSchema' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string']], 'required' => ['value']]]];
            $transport->callHandlers['validated'] = static fn (): array => ['content' => [], 'structuredContent' => ['value' => 123]];
        });
        $tool = $this->adapter(['servers' => ['external' => self::http()]])->listTools()[0];

        $error = self::thrownBy(static fn () => $tool->invoke([]));

        self::assertMatchesRegularExpression('/schema|validat/i', $error->getMessage());
    }

    public function testForwardsProgressCallbacksFromTheServerConfiguration(): void
    {
        $progress = [];
        $this->factory->script(function (FakeServerTransport $transport): void {
            $transport->tools = [['name' => 'progress', 'inputSchema' => ['type' => 'object']]];
            $transport->callHandlers['progress'] = static function (array $args, array $params) use ($transport): array {
                $transport->queue(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progressToken' => $params['_meta']['progressToken'], 'progress' => 1, 'total' => 1]]);

                return ['content' => [['type' => 'text', 'text' => 'done']]];
            };
        });
        // The notification is queued before the answer, so it is read first.
        $adapter = $this->adapter(['servers' => ['external' => self::http(['onProgress' => static function (array $update, array $source) use (&$progress): void {
            $progress[] = [$update, $source];
        }])]]);

        self::assertSame('done', $adapter->listTools()[0]->invoke([]));
        self::assertSame(1, $progress[0][0]['progress']);
        self::assertSame(1, $progress[0][0]['total']);
        self::assertSame('progress', $progress[0][1]['name']);
        self::assertSame('external', $progress[0][1]['server']);
    }

    public function testDeliversServerNotificationsToTheConfiguredCallbacksOnlyWhenValid(): void
    {
        $heard = [];
        $adapter = $this->adapter(['servers' => ['s' => self::http(['onMessage' => static function (array $params, array $source) use (&$heard): void {
            $heard[] = [$params['data'], $source['server']];
        }])]]);
        $adapter->listTools();
        $this->transport(0)->queue(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'info', 'data' => 'valid']]);

        $adapter->getClient('s')->listResources();

        self::assertSame([['valid', 's']], $heard);
    }

    // ------------------------------------------------------------------ server tool schemas

    /**
     * @param array<string, mixed> $inputSchema
     */
    private function schemaAdapter(array $inputSchema, array $config = []): MultiServerMcpClient
    {
        $this->factory->script(static function (FakeServerTransport $transport) use ($inputSchema): void {
            $transport->tools = [['name' => 'echo', 'inputSchema' => $inputSchema]];
            $transport->callHandlers['echo'] = static fn (): array => ['content' => [['type' => 'text', 'text' => 'ok']]];
        });

        return $this->adapter(['servers' => ['test' => self::http()], ...$config]);
    }

    private function toolCalls(int $transport = 0): int
    {
        return count(array_keys($this->transport($transport)->methods(), 'tools/call', true));
    }

    public function testPreservesRecursiveReferencesAndCompositions(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['node' => ['$ref' => '#/$defs/node']],
            '$defs' => ['node' => ['type' => 'object', 'properties' => ['next' => ['$ref' => '#/$defs/node']]]],
            'allOf' => [['required' => ['node']]],
            'x-provider' => ['retained' => true],
        ];

        $tool = $this->schemaAdapter($schema)->listTools()[0];

        self::assertSame($schema, $tool->schema->toJsonSchema());
    }

    public function testDoesNotMutateDescriptorsWithoutProperties(): void
    {
        $schema = ['type' => 'object'];

        $tools = $this->schemaAdapter($schema)->listTools();

        self::assertCount(1, $tools);
        self::assertSame(['type' => 'object'], $this->transport(0)->tools[0]['inputSchema']);
    }

    public function testValidatesHookOverridesAgainstTheOriginalServerSchema(): void
    {
        $adapter = $this->schemaAdapter(
            ['type' => 'object', 'properties' => ['value' => ['type' => 'number']], 'required' => ['value'], 'not' => ['properties' => ['value' => ['const' => 2]]]],
            ['beforeToolCall' => static fn (): array => ['args' => ['value' => 2]]],
        );
        $tool = $adapter->listTools()[0];

        $error = self::thrownBy(static fn () => $tool->invoke(['value' => 1]));

        self::assertMatchesRegularExpression('/arguments/', $error->getMessage());
        self::assertSame(0, $this->toolCalls());
    }

    public function testAcceptsValidEffectiveArgumentsWithLocalReferences(): void
    {
        $adapter = $this->schemaAdapter(
            ['type' => 'object', '$defs' => ['value' => ['type' => 'integer', 'minimum' => 1]], 'properties' => ['value' => ['$ref' => '#/$defs/value']], 'required' => ['value'], 'additionalProperties' => false],
            ['beforeToolCall' => static fn (): array => ['args' => ['value' => 3]]],
        );

        self::assertSame('ok', $adapter->listTools()[0]->invoke(['value' => 1]));
        self::assertSame(['value' => 3], $this->transport(0)->lastParams('tools/call')['arguments']);
    }

    /** @return array<string, array{array<string, mixed>, int}> */
    public static function constraintRows(): array
    {
        return [
            'anyOf' => [['anyOf' => [['properties' => ['value' => ['const' => 1]]], ['properties' => ['value' => ['const' => 3]]]]], 2],
            'oneOf' => [['oneOf' => [['properties' => ['value' => ['minimum' => 1]]], ['properties' => ['value' => ['minimum' => 2]]]]], 3],
            'allOf' => [['allOf' => [['properties' => ['value' => ['minimum' => 1]]], ['properties' => ['value' => ['maximum' => 2]]]]], 3],
            'if/then' => [['if' => ['properties' => ['value' => ['minimum' => 2]]], 'then' => ['properties' => ['value' => ['minimum' => 4]]]], 3],
        ];
    }

    /** @param array<string, mixed> $constraint */
    #[DataProvider('constraintRows')]
    public function testValidatesEffectiveArgumentsAgainstCompositionKeywords(array $constraint, int $value): void
    {
        $adapter = $this->schemaAdapter(
            ['type' => 'object', 'properties' => ['value' => ['type' => 'number']], ...$constraint],
            ['beforeToolCall' => static fn (): array => ['args' => ['value' => $value]]],
        );
        $tool = $adapter->listTools()[0];

        $error = self::thrownBy(static fn () => $tool->invoke(['value' => 1]));

        self::assertMatchesRegularExpression('/arguments/', $error->getMessage());
        self::assertSame(0, $this->toolCalls());
    }

    public function testRejectsAdditionalPropertiesAddedByAHook(): void
    {
        $adapter = $this->schemaAdapter(
            ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ['beforeToolCall' => static fn (): array => ['args' => ['injected' => true]]],
        );
        $tool = $adapter->listTools()[0];

        $error = self::thrownBy(static fn () => $tool->invoke([]));

        self::assertMatchesRegularExpression('/additional propert/i', $error->getMessage());
        self::assertSame(0, $this->toolCalls());
    }

    public function testRejectsARequiredValueRemovedByAHook(): void
    {
        $adapter = $this->schemaAdapter(
            ['type' => 'object', 'properties' => ['value' => ['type' => 'string']], 'required' => ['value']],
            ['beforeToolCall' => static fn (): array => ['args' => ['value' => null]]],
        );
        $tool = $adapter->listTools()[0];

        $error = self::thrownBy(static fn () => $tool->invoke(['value' => 'original']));

        self::assertMatchesRegularExpression('/arguments/', $error->getMessage());
        self::assertSame(0, $this->toolCalls());
    }

    private static function sharedIdSchema(int $forbidden): array
    {
        return [
            '$id' => 'https://example.com/schema/shared',
            'type' => 'object',
            'properties' => ['value' => ['type' => 'number']],
            '$defs' => ['forbidden' => ['properties' => ['value' => ['const' => $forbidden]]]],
            'not' => ['$ref' => '#/$defs/forbidden'],
        ];
    }

    public function testIsolatesServersAdvertisingDifferentConstraintsUnderTheSameSchemaId(): void
    {
        foreach ([2, 1] as $forbidden) {
            $this->factory->script(static function (FakeServerTransport $transport) use ($forbidden): void {
                $transport->tools = [['name' => 'echo', 'inputSchema' => self::sharedIdSchema($forbidden)]];
                $transport->callHandlers['echo'] = static fn (): array => ['content' => [['type' => 'text', 'text' => 'ok']]];
            });
        }
        $adapter = $this->adapter(['servers' => ['first' => self::http(), 'second' => self::http(['url' => 'http://localhost:8001/mcp'])]]);
        [$first] = $adapter->listTools('first');
        [$second] = $adapter->listTools('second');

        self::assertInstanceOf(\LangChain\Tools\ToolException::class, self::thrownBy(static fn () => $second->invoke(['value' => 1])));
        self::assertSame('ok', $second->invoke(['value' => 2]));
        self::assertSame('ok', $first->invoke(['value' => 1]));
        self::assertInstanceOf(\LangChain\Tools\ToolException::class, self::thrownBy(static fn () => $first->invoke(['value' => 2])));
    }

    public function testRecompilesChangedConstraintsOnRediscoveryWithoutChangingExistingTools(): void
    {
        $adapter = $this->schemaAdapter(self::sharedIdSchema(2));
        [$original] = $adapter->listTools();
        $this->transport(0)->tools = [['name' => 'echo', 'inputSchema' => self::sharedIdSchema(1)]];

        [$refreshed] = $adapter->listTools();

        self::assertInstanceOf(\LangChain\Tools\ToolException::class, self::thrownBy(static fn () => $refreshed->invoke(['value' => 1])));
        self::assertSame('ok', $refreshed->invoke(['value' => 2]));
        self::assertSame('ok', $original->invoke(['value' => 1]));
        self::assertInstanceOf(\LangChain\Tools\ToolException::class, self::thrownBy(static fn () => $original->invoke(['value' => 2])));
    }

    // ------------------------------------------------------------------- wire boundaries

    /** @return array<string, array{int}> */
    public static function protocolErrorCodes(): array
    {
        return ['-32020' => [-32020], '-32021' => [-32021], '-32022' => [-32022], '-32602' => [-32602]];
    }

    #[DataProvider('protocolErrorCodes')]
    public function testPreservesProtocolErrorsFromAToolCall(int $code): void
    {
        $this->factory->script(static function (FakeServerTransport $transport) use ($code): void {
            $transport->tools = [['name' => 'raw', 'inputSchema' => ['type' => 'object']]];
            $transport->onRequest = static function (string $method) use ($transport, $code): void {
                if ($method === 'tools/call') {
                    $transport->failOn['tools/call'] = new JsonRpcException('Fixture protocol rejection', $code);
                }
            };
        });
        $tool = $this->adapter(['servers' => ['test' => self::http()]])->listTools()[0];

        $error = self::thrownBy(static fn () => $tool->invoke([]));

        self::assertInstanceOf(ToolException::class, $error);
        self::assertInstanceOf(JsonRpcException::class, $error->cause);
        self::assertSame($code, $error->cause->rpcCode);
        self::assertStringContainsString('Fixture protocol rejection', $error->getMessage());
    }

    // ------------------------------------------------------------------------- end to end

    public function testAReactAgentCallsToolsFromTwoServersLoadedByTheMultiServerClient(): void
    {
        foreach ([0, 1] as $index) {
            $name = ['search', 'lookup'][$index];
            $this->factory->script(static function (FakeServerTransport $transport) use ($name): void {
                $transport->tools = [['name' => $name, 'description' => $name, 'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]]];
                $transport->callHandlers[$name] = static fn (array $args): array => ['content' => [['type' => 'text', 'text' => "{$name}:{$args['q']}"]]];
            });
        }
        $adapter = $this->adapter(['servers' => ['one' => self::stdio(), 'two' => self::http()], 'prefixToolNameWithServerName' => true]);
        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [
                ReactAgentFixtures::toolCall('one__search', 'c1', ['q' => 'cats']),
                ReactAgentFixtures::toolCall('two__lookup', 'c2', ['q' => 'dogs']),
            ]]),
            new AIMessage('both answered'),
        ]);

        $result = ReactAgent::create(['llm' => $llm, 'tools' => $adapter->listTools()])->invoke(['messages' => 'go']);

        $answers = [];
        foreach ($result['messages'] as $message) {
            if ($message instanceof ToolMessage) {
                $answers[$message->toolCallId] = $message->content;
            }
        }
        self::assertSame(['c1' => 'search:cats', 'c2' => 'lookup:dogs'], $answers);
        self::assertSame('both answered', end($result['messages'])->content);
    }

    public function testAReactAgentRunsAgainstARealStdioServerThroughTheMultiServerClient(): void
    {
        $client = $this->plain(['servers' => ['stdio-e2e' => self::stdioServer('stdio-e2e', [], ['mode' => 'legacy'])], 'prefixToolNameWithServerName' => true]);
        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('stdio-e2e__test_tool', 'c1', ['input' => 'from the model'])]]),
            new AIMessage('all done'),
        ]);

        $result = ReactAgent::create(['llm' => $llm, 'tools' => $client->listTools()])->invoke(['messages' => 'go']);

        $tool = $result['messages'][2];
        self::assertInstanceOf(ToolMessage::class, $tool);
        $payload = json_decode((string) $tool->content, true);
        self::assertSame('from the model', $payload['input']);
        self::assertSame('stdio-e2e', $payload['serverName']);
        self::assertSame('all done', end($result['messages'])->content);
    }

    public function testAReactAgentSeesAFailingMcpToolAsAnErrorToolMessage(): void
    {
        $client = $this->plain(['servers' => ['stdio-e2e' => self::stdioServer('stdio-e2e')]]);
        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('fail_tool', 'f1')]]),
            new AIMessage('recovered'),
        ]);

        $result = ReactAgent::create(['llm' => $llm, 'tools' => $client->listTools()])->invoke(['messages' => 'go']);

        self::assertSame('error', $result['messages'][2]->additional_kwargs['status']);
        self::assertStringContainsString('tool failed', $result['messages'][2]->content);
        self::assertSame('recovered', end($result['messages'])->content);
    }
}
