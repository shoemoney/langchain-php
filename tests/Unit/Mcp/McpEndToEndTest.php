<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Func\Func;
use LangGraph\Mcp\Elicitation;
use LangGraph\Mcp\McpTools;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Prebuilt\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Real graphs driving MCP tools converted over a {@see FakeMcpClient}: a ReactAgent calling an MCP tool through
 * ToolNode (`hooks.test.ts` "hooks have access to state and runtime with LangGraph"), and a checkpointed
 * graph that pauses on an elicitation and resumes it.
 *
 * There is NO live MCP server here: `client.ts` / `connection.ts` are not ported, so this adapter is a
 * conversion layer over {@see \LangGraph\Mcp\McpClientInterface}, not a client.
 */
#[CoversClass(McpTools::class)]
final class McpEndToEndTest extends McpTestCase
{
    public function testAReactAgentCallsAnMcpToolThroughToolNodeWithHooksSeeingStateAndRuntime(): void
    {
        $client = (new FakeMcpClient([[
            'name' => 'test_tool',
            'description' => 'Echoes its input',
            'inputSchema' => ['type' => 'object', 'properties' => ['input' => ['type' => 'string']], 'required' => ['input']],
        ]]))->respondWith(static fn (string $name, array $args): array => self::text(
            (string) json_encode([...$args, 'serverName' => 'http-interceptor'], JSON_UNESCAPED_SLASHES),
        ));

        $states = [];
        $runtimes = [];
        $tools = McpTools::loadMcpTools('http-interceptor', $client, [
            'beforeToolCall' => static function (array $request, mixed $state, RunnableConfig $runtime) use (&$states, &$runtimes): array {
                $states[] = $state;
                $runtimes[] = $runtime;

                return ['args' => ['input' => 'I changed the input']];
            },
            'afterToolCall' => static function (array $request, mixed $state, RunnableConfig $runtime) use (&$states, &$runtimes): void {
                $states[] = $state;
                $runtimes[] = $runtime;
            },
        ]);

        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('test_tool', '1', ['input' => 'orig'])]]),
            new AIMessage('all done'),
        ]);
        $agent = ReactAgent::create(['llm' => $llm, 'tools' => $tools]);

        $result = $agent->invoke(['messages' => [new HumanMessage('orig')]]);

        // The server saw the hook's arguments, not the model's.
        self::assertSame(['input' => 'I changed the input'], $client->calls[0]['arguments']);
        self::assertCount(1, $client->calls);

        $messages = $result['messages'];
        self::assertSame('orig', $messages[0]->content);
        self::assertInstanceOf(ToolMessage::class, $messages[2]);
        self::assertSame('1', $messages[2]->toolCallId);
        self::assertSame('{"input":"I changed the input","serverName":"http-interceptor"}', $messages[2]->content);
        self::assertSame('all done', end($messages)->content);

        // Hooks ran once before and once after, with the task's state and the tool-call runtime.
        self::assertCount(2, $states);
        self::assertCount(2, $runtimes);
        foreach ($states as $state) {
            self::assertCount(2, $state['messages']);
        }
        foreach ($runtimes as $runtime) {
            self::assertSame('test_tool', $runtime->toolCall['name']);
            self::assertSame('1', $runtime->toolCall['id']);
        }
    }

    public function testAnAgentTurnsAnMcpErrorResultIntoAnErrorToolMessageTheModelCanRead(): void
    {
        $client = (new FakeMcpClient([self::echoTool(['name' => 'flaky'])]))
            ->willReturn(['isError' => true, 'content' => [['type' => 'text', 'text' => 'upstream exploded']]]);
        $tools = McpTools::loadMcpTools('srv', $client);
        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('flaky', 't1')]]),
            new AIMessage('recovered'),
        ]);

        $result = ReactAgent::create(['llm' => $llm, 'tools' => $tools])->invoke(['messages' => 'go']);

        self::assertSame('error', $result['messages'][2]->additional_kwargs['status']);
        self::assertStringContainsString('upstream exploded', $result['messages'][2]->content);
        self::assertSame('recovered', end($result['messages'])->content);
    }

    public function testAnAgentRoutesOutputHandlingIntoContentAndArtifact(): void
    {
        $client = (new FakeMcpClient([self::echoTool(['name' => 'lookup'])]))->willReturn(['content' => [
            ['type' => 'text', 'text' => 'summary'],
            ['type' => 'resource', 'resource' => ['uri' => 'memory://big', 'text' => 'huge payload']],
        ]]);
        $tools = McpTools::loadMcpTools('srv', $client);
        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('lookup', 't1')]]),
            new AIMessage('ok'),
        ]);

        $result = ReactAgent::create(['llm' => $llm, 'tools' => $tools])->invoke(['messages' => 'go']);

        // The model sees the text; the resource stays in the artifact, out of its context.
        self::assertSame('summary', $result['messages'][2]->content);
        self::assertSame(
            [['type' => 'resource', 'resource' => ['uri' => 'memory://big', 'text' => 'huge payload']]],
            $result['messages'][2]->artifact,
        );
    }

    public function testAnElicitingMcpToolPausesAGraphAndResumesWithTheHumansAnswer(): void
    {
        $client = new FakeMcpClient([self::echoTool(['name' => 'deploy', 'inputSchema' => ['type' => 'object', 'properties' => ['env' => ['type' => 'string']]]])], 'modern');
        $client->respondWith(static function (string $name, array $args, array $options): array {
            $answer = $options['inputResponses']['approve'] ?? null;
            if ($answer === null) {
                return [
                    'resultType' => 'input_required',
                    'requestState' => 'state-1',
                    'inputRequests' => ['approve' => ['method' => 'elicitation/create', 'params' => [
                        'mode' => 'form',
                        'message' => 'Deploy to ' . $args['env'] . '?',
                        'requestedSchema' => ['type' => 'object', 'properties' => ['go' => ['type' => 'boolean']], 'required' => ['go']],
                    ]]],
                ];
            }

            return self::text($answer['action'] === 'accept' && $answer['content']['go'] ? 'deployed to ' . $args['env'] : 'aborted');
        });
        $tool = self::firstTool($client);

        $saver = new MemorySaver();
        $graph = Func::entrypoint(['name' => 'deploy-flow', 'checkpointer' => $saver], static fn (string $env): string => (string) $tool->invoke(['env' => $env]));
        $config = new RunnableConfig(configurable: ['thread_id' => 'deploy']);

        $graph->invoke('prod', $config);

        // Paused: the human is asked, the deploy has not happened.
        $pending = null;
        foreach ($saver->getTuple(['configurable' => $config->configurable])->pendingWrites ?? [] as [, $channel, $value]) {
            if ($channel === Constants::INTERRUPT) {
                $pending = is_array($value) && array_is_list($value) ? $value[0] : $value;
            }
        }
        self::assertNotNull($pending);
        self::assertSame('Deploy to prod?', $pending['value']['requests']['approve']['message']);

        $final = $graph->invoke(
            new Command(resume: Elicitation::createMCPElicitationResume($pending, ['approve' => ['action' => 'accept', 'content' => ['go' => true]]])),
            $config,
        );

        self::assertSame('deployed to prod', $final);
        $last = end($client->calls);
        self::assertSame('state-1', $last['options']['requestState']);
        self::assertSame(['action' => 'accept', 'content' => ['go' => true]], $last['options']['inputResponses']['approve']);
    }
}
