<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangGraph\Agents\Agent;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Mcp\Elicitation;
use LangGraph\Mcp\McpTools;
use LangGraph\Pregel\Command;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The task targeting and positional replay contracts in upstream agent.elicitation.test.ts,
 * driven through a real createAgent graph with a scripted MCP client rather than a live server.
 */
#[CoversClass(Elicitation::class)]
final class McpAgentElicitationTest extends McpTestCase
{
    /** @return array{agent: \LangGraph\Agents\ReactAgent, config: array<string, mixed>, server: \stdClass} */
    private function environment(array $toolCalls): array
    {
        $server = new \stdClass();
        $server->calls = [];
        $server->completed = [];
        $client = new FakeMcpClient([self::echoTool([
            'name' => 'ask',
            'inputSchema' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'rounds' => ['type' => 'integer']]],
        ])], 'modern');
        $client->respondWith(static function (string $name, array $args, array $options) use ($server): array {
            $round = isset($options['inputResponses']) ? (int) $options['requestState'] + 1 : 0;
            $server->calls[] = ['label' => $args['label'], 'round' => $round, 'answer' => $options['inputResponses']['confirmation'] ?? null];
            if ($round < ($args['rounds'] ?? 1)) {
                return [
                    'resultType' => 'input_required',
                    'requestState' => (string) $round,
                    'inputRequests' => ['confirmation' => ['method' => 'elicitation/create', 'params' => [
                        'mode' => 'form',
                        'message' => $args['label'],
                        'requestedSchema' => ['type' => 'object', 'properties' => ['confirm' => ['type' => 'boolean']], 'required' => ['confirm']],
                    ]]],
                ];
            }

            $server->completed[] = ['label' => $args['label'], 'action' => $options['inputResponses']['confirmation']['action']];

            return self::text('done');
        });

        return [
            'agent' => Agent::create([
                'model' => new FakeToolCallingModel(['toolCalls' => $toolCalls]),
                'tools' => McpTools::loadMcpTools('modern', $client),
                'checkpointer' => new MemorySaver(),
            ]),
            'config' => ['configurable' => ['thread_id' => 'mcp-agent-' . bin2hex(random_bytes(4))]],
            'server' => $server,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function responses(string $action = 'accept'): array
    {
        return ['confirmation' => $action === 'accept' ? ['action' => 'accept', 'content' => ['confirm' => true]] : ['action' => $action]];
    }

    /** @param list<array<string, mixed>> $pending */
    private static function answeringOnly(array $pending, string $label): Command
    {
        foreach ($pending as $interrupt) {
            if (($interrupt['value']['requests']['confirmation']['message'] ?? null) === $label) {
                return new Command(resume: Elicitation::createMCPElicitationResume($interrupt, self::responses()));
            }
        }

        self::fail('No pending MCP question for ' . $label);
    }

    public function testAnswersOneSiblingWithoutAnsweringTheOther(): void
    {
        $h = $this->environment([[
            ['id' => 'a', 'name' => 'ask', 'args' => ['label' => 'alpha']],
            ['id' => 'b', 'name' => 'ask', 'args' => ['label' => 'beta']],
        ], []]);
        $first = AgentAssertions::interrupts($h['agent'], ['messages' => [new HumanMessage('go')]], $h['config']);
        self::assertCount(2, $first);
        self::assertNotSame($first[0]['id'], $first[1]['id']);

        $second = AgentAssertions::interrupts($h['agent'], self::answeringOnly($first, 'alpha'), $h['config']);
        self::assertSame([['label' => 'alpha', 'action' => 'accept']], $h['server']->completed);
        self::assertCount(1, $second);
        self::assertSame('beta', $second[0]['value']['requests']['confirmation']['message']);

        $done = AgentAssertions::interrupts($h['agent'], self::answeringOnly($second, 'beta'), $h['config']);
        self::assertSame([], $done);
        self::assertSame([['label' => 'alpha', 'action' => 'accept'], ['label' => 'beta', 'action' => 'accept']], $h['server']->completed);
    }

    public function testTwoRoundsReplayTheEarlierAnswerByPositionBeforeConsumingTheNextAnswer(): void
    {
        $h = $this->environment([[
            ['id' => 'c1', 'name' => 'ask', 'args' => ['label' => 'q', 'rounds' => 2]],
        ], []]);
        $first = AgentAssertions::interrupts($h['agent'], ['messages' => [new HumanMessage('go')]], $h['config']);
        self::assertCount(1, $first);

        $second = AgentAssertions::interrupts($h['agent'], new Command(resume: Elicitation::createMCPElicitationResume($first[0], self::responses('decline'))), $h['config']);
        self::assertCount(1, $second);
        self::assertSame($first[0]['id'], $second[0]['id'], 'rounds in the same task share an interrupt id');
        self::assertSame([], $h['server']->completed);

        $done = AgentAssertions::interrupts($h['agent'], new Command(resume: Elicitation::createMCPElicitationResume($second[0], self::responses('cancel'))), $h['config']);
        self::assertSame([], $done);
        self::assertSame([['label' => 'q', 'action' => 'cancel']], $h['server']->completed);
        self::assertSame([0, 0, 1, 0, 1, 2], array_column($h['server']->calls, 'round'));
        self::assertSame([null, null, ['action' => 'decline'], null, ['action' => 'decline'], ['action' => 'cancel']], array_column($h['server']->calls, 'answer'));
    }
}
