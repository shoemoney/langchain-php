<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt\Supervisor;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangGraph\Errors\ParentCommand;
use LangGraph\Pregel\Command;
use LangGraph\Prebuilt\ReactAgent;
use LangGraph\Prebuilt\Supervisor\Handoff;
use LangGraph\Prebuilt\Supervisor\Supervisor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** What the upstream suite leaves unchecked: tool shape, handoff-back messages, output modes and validation. */
#[CoversClass(Handoff::class)]
#[CoversClass(Supervisor::class)]
final class SupervisorHandoffTest extends TestCase
{
    public function testHandoffToolShape(): void
    {
        $tool = Handoff::createHandoffTool(['agentName' => 'Research  Expert']);
        self::assertSame('transfer_to_research_expert', $tool->name);
        self::assertSame('Ask another agent for help.', $tool->description);

        self::assertSame('Deprecated spelling', Handoff::createHandoffTool(['agentName' => 'x', 'agentDescription' => 'Deprecated spelling'])->description);
        self::assertSame('Preferred', Handoff::createHandoffTool(['agentName' => 'x', 'description' => 'Preferred', 'agentDescription' => 'Old'])->description);
    }

    public function testHandoffBackMessages(): void
    {
        [$ai, $tool] = Handoff::createHandoffBackMessages('math_expert', 'Team Lead');

        self::assertInstanceOf(AIMessage::class, $ai);
        self::assertSame('Transferring back to Team Lead', $ai->content);
        self::assertSame('math_expert', $ai->name);
        self::assertCount(1, $ai->toolCalls);
        self::assertSame('transfer_back_to_team_lead', $ai->toolCalls[0]['name']);
        self::assertSame([], $ai->toolCalls[0]['args']);

        self::assertInstanceOf(ToolMessage::class, $tool);
        self::assertSame('Successfully transferred back to Team Lead', $tool->content);
        self::assertSame('transfer_back_to_team_lead', $tool->name);
        self::assertSame($ai->toolCalls[0]['id'], $tool->toolCallId);

        [$other] = Handoff::createHandoffBackMessages('math_expert', 'Team Lead');
        self::assertNotSame($ai->toolCalls[0]['id'], $other->toolCalls[0]['id'], 'every pair gets a fresh tool call id');
    }

    /** @return array{0: \LangGraph\Pregel\CompiledStateGraph, 1: \LangChain\Tests\Unit\Prebuilt\SpyingToolCallingChatModel} */
    private function researcher(): array
    {
        $model = ReactAgentFixtures::spy([
            SupervisorFixtures::aiCalling(SupervisorFixtures::call('web_search', ['query' => 'q'], 'call_search')),
            new AIMessage('researched'),
        ]);

        return [ReactAgent::create(['llm' => $model, 'tools' => [SupervisorFixtures::webSearch()], 'name' => 'research_expert']), $model];
    }

    private function supervisorModel(): \LangChain\Tests\Unit\Prebuilt\SpyingToolCallingChatModel
    {
        return ReactAgentFixtures::spy([
            SupervisorFixtures::aiCalling(SupervisorFixtures::call('transfer_to_research_expert', [], 'call_go')),
            new AIMessage('final'),
        ]);
    }

    /** @return list<string> */
    private static function summary(array $messages): array
    {
        return array_map(static fn ($m): string => $m->getType() . ':' . (is_string($m->content) ? $m->content : ''), $messages);
    }

    public function testLastMessageModeKeepsOnlyTheAgentsFinalMessageAndTheHandoffBackPair(): void
    {
        [$agent] = $this->researcher();
        $result = Supervisor::create(['agents' => [$agent], 'llm' => $this->supervisorModel()])
            ->compile()->invoke(['messages' => [new HumanMessage('go')]]);

        $summary = self::summary($result['messages']);
        self::assertSame([
            'human:go',
            'ai:',
            'tool:Successfully transferred to research_expert',
            'ai:researched',
            'ai:Transferring back to supervisor',
            'tool:Successfully transferred back to supervisor',
            'ai:final',
        ], $summary);
        self::assertSame('research_expert', $result['messages'][4]->name);
    }

    public function testFullHistoryModeKeepsEveryAgentMessage(): void
    {
        [$agent] = $this->researcher();
        $result = Supervisor::create(['agents' => [$agent], 'llm' => $this->supervisorModel(), 'outputMode' => 'full_history'])
            ->compile()->invoke(['messages' => [new HumanMessage('go')]]);

        $summary = self::summary($result['messages']);
        self::assertNotEmpty(array_filter($summary, static fn (string $line): bool => str_starts_with($line, 'tool:Here are the headcounts')));
        self::assertContains('ai:researched', $summary);
        self::assertCount(9, $summary);
        self::assertSame('ai:final', end($summary));
    }

    public function testAddHandoffBackMessagesCanBeSwitchedOffIndependently(): void
    {
        [$agent] = $this->researcher();
        $result = Supervisor::create(['agents' => [$agent], 'llm' => $this->supervisorModel(), 'addHandoffBackMessages' => false])
            ->compile()->invoke(['messages' => [new HumanMessage('go')]]);

        $summary = self::summary($result['messages']);
        self::assertContains('tool:Successfully transferred to research_expert', $summary);
        self::assertNotContains('tool:Successfully transferred back to supervisor', $summary);
    }

    public function testSupervisorNameShapesTheBackMessagesAndTheNode(): void
    {
        [$agent] = $this->researcher();
        $workflow = Supervisor::create(['agents' => [$agent], 'llm' => $this->supervisorModel(), 'supervisorName' => 'Team Lead']);
        self::assertArrayHasKey('Team Lead', $workflow->nodes);

        $summary = self::summary($workflow->compile()->invoke(['messages' => [new HumanMessage('go')]])['messages']);
        self::assertContains('ai:Transferring back to Team Lead', $summary);
    }

    public function testRejectsAnInvalidOutputMode(): void
    {
        [$agent] = $this->researcher();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid agent output mode: everything');

        Supervisor::create(['agents' => [$agent], 'llm' => $this->supervisorModel(), 'outputMode' => 'everything']);
    }

    public function testRejectsUnnamedAndDuplicateAgents(): void
    {
        [$named] = $this->researcher();
        $anonymous = ReactAgent::create(['llm' => ReactAgentFixtures::spy([new AIMessage('x')]), 'tools' => []]);

        try {
            Supervisor::create(['agents' => [$anonymous], 'llm' => $this->supervisorModel()]);
            self::fail('expected an unnamed agent to be refused');
        } catch (\Exception $e) {
            self::assertStringContainsString('Please specify a name', $e->getMessage());
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Agent with name 'research_expert' already exists. Agent names must be unique.");
        Supervisor::create(['agents' => [$named, $named], 'llm' => $this->supervisorModel()]);
    }

    public function testTheHandoffCommandReallyLeavesTheSupervisorAgentSubgraph(): void
    {
        // Run only the supervisor's own agent: its tool's Command(graph: PARENT) must escape as a
        // ParentCommand addressed to the graph holding the agent, carrying the target and the messages.
        $model = $this->supervisorModel();
        $agent = ReactAgent::create([
            'llm' => $model,
            'tools' => [Handoff::createHandoffTool(['agentName' => 'research_expert'])],
            'name' => 'supervisor',
        ]);

        try {
            $agent->invoke(['messages' => [new HumanMessage('go')]]);
            self::fail('expected the handoff to leave the agent graph');
        } catch (ParentCommand $e) {
            // The runner re-addressed it from PARENT to the (root) parent namespace, as upstream does.
            self::assertSame('', $e->command->graph);
            self::assertSame('research_expert', $e->command->goto);
            self::assertSame(['human', 'ai', 'tool'], array_map(static fn ($m) => $m->getType(), $e->command->update['messages']));
        }
    }

    public function testAnAgentRoutedToByTheSupervisorReceivesTheHandoffMessagesInItsInput(): void
    {
        [$agent, $researchModel] = $this->researcher();
        Supervisor::create(['agents' => [$agent], 'llm' => $this->supervisorModel()])
            ->compile()->invoke(['messages' => [new HumanMessage('go')]]);

        $first = SupervisorFixtures::withoutSystem($researchModel->generateCalls[0]);
        self::assertSame(['human', 'ai', 'tool'], array_map(static fn ($m) => $m->getType(), $first));
    }
}
