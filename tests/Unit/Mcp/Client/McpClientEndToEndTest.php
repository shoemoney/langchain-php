<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp\Client;

use LangChain\Messages\AIMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Mcp\Client\Fixtures\HttpServerProcess;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Func\Func;
use LangGraph\Mcp\Client\McpClient;
use LangGraph\Mcp\Client\Transport\StreamableHttpTransport;
use LangGraph\Mcp\Elicitation;
use LangGraph\Mcp\McpTools;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Prebuilt\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Real agents and graphs driving MCP tools over the in-house client against live fixture servers:
 * a child process on stdio and `php -S` on streamable HTTP. Nothing in the MCP layer is faked.
 */
#[CoversClass(McpClient::class)]
final class McpClientEndToEndTest extends ClientTestCase
{
    public function testAReactAgentCallsAnMcpToolOverStdio(): void
    {
        $client = $this->stdioClient(['stdio-e2e']);
        $tools = McpTools::loadMcpTools('stdio-e2e', $client);
        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('test_tool', 'c1', ['input' => 'from the model'])]]),
            new AIMessage('all done'),
        ]);

        $result = ReactAgent::create(['llm' => $llm, 'tools' => $tools])->invoke(['messages' => 'go']);

        $tool = $result['messages'][2];
        self::assertInstanceOf(ToolMessage::class, $tool);
        self::assertSame('c1', $tool->toolCallId);
        $payload = json_decode((string) $tool->content, true);
        self::assertSame('from the model', $payload['input']);
        self::assertSame('stdio-e2e', $payload['serverName']);
        self::assertSame('all done', end($result['messages'])->content);
    }

    public function testAReactAgentSeesAnMcpErrorResultAsAnErrorToolMessage(): void
    {
        $tools = McpTools::loadMcpTools('stdio-e2e', $this->stdioClient());
        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('fail_tool', 'f1')]]),
            new AIMessage('recovered'),
        ]);

        $result = ReactAgent::create(['llm' => $llm, 'tools' => $tools])->invoke(['messages' => 'go']);

        self::assertSame('error', $result['messages'][2]->additional_kwargs['status']);
        self::assertStringContainsString('tool failed', $result['messages'][2]->content);
        self::assertSame('recovered', end($result['messages'])->content);
    }

    public function testABeforeToolCallHookForksTheHttpConnectionWithItsHeaders(): void
    {
        $server = HttpServerProcess::start();
        $client = new McpClient(new StreamableHttpTransport(['url' => $server->url('json')]));
        try {
            $tools = McpTools::loadMcpTools('http-e2e', $client, [
                'beforeToolCall' => static fn (): array => ['headers' => ['X-Tenant' => 'acme']],
            ]);
            $llm = ReactAgentFixtures::fake([
                new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('show_header', 'h1', ['name' => 'x-tenant'])]]),
                new AIMessage('done'),
            ]);

            $result = ReactAgent::create(['llm' => $llm, 'tools' => $tools])->invoke(['messages' => 'go']);

            self::assertSame('acme', $result['messages'][2]->content);
            // Two sessions were opened: the discovery client's and the fork's.
            $initializes = array_filter($server->requests(), static fn (array $r): bool => str_contains($r['body'], '"method":"initialize"'));
            self::assertCount(2, $initializes);
        } finally {
            $client->close();
            $server->stop();
        }
    }

    public function testAnElicitingToolPausesAGraphAndResumesAgainstARealModernServer(): void
    {
        $client = $this->stdioClient(['modern-e2e', 'modern']);
        $tool = null;
        foreach (McpTools::loadMcpTools('modern-e2e', $client) as $candidate) {
            if ($candidate->name === 'approve') {
                $tool = $candidate;
            }
        }
        self::assertNotNull($tool);

        $saver = new MemorySaver();
        $graph = Func::entrypoint(['name' => 'approve-flow', 'checkpointer' => $saver], static fn (string $ignored): string => (string) $tool->invoke([]));
        $config = new RunnableConfig(configurable: ['thread_id' => 'approve']);

        $graph->invoke('go', $config);

        $pending = null;
        foreach ($saver->getTuple(['configurable' => $config->configurable])->pendingWrites ?? [] as [, $channel, $value]) {
            if ($channel === Constants::INTERRUPT) {
                $pending = is_array($value) && array_is_list($value) ? $value[0] : $value;
            }
        }
        self::assertNotNull($pending);
        self::assertSame('Approve modern?', $pending['value']['requests']['confirmation']['message']);

        $final = $graph->invoke(
            new Command(resume: Elicitation::createMCPElicitationResume($pending, ['confirmation' => ['action' => 'accept', 'content' => ['confirm' => true]]])),
            $config,
        );

        self::assertSame('accept', $final);
    }
}
