<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt\Supervisor;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangGraph\Prebuilt\AgentName;
use LangGraph\Prebuilt\ReactAgent;
use LangGraph\Prebuilt\Supervisor\Handoff;
use LangGraph\Prebuilt\Supervisor\Supervisor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langgraph-supervisor/src/tests/supervisor.test.ts` (the whitespace-normalisation case, the
 * four-way `basic supervisor workflow` table and the two `addHandoffMessages` cases).
 */
#[CoversClass(Supervisor::class)]
#[CoversClass(Handoff::class)]
final class SupervisorTest extends TestCase
{
    public function testNormalizesAllWhitespaceInGeneratedHandoffToolNames(): void
    {
        $handoffTool = Handoff::createHandoffTool(['agentName' => 'travel booking assistant']);

        self::assertSame('transfer_to_travel_booking_assistant', $handoffTool->name);
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function nameModes(): array
    {
        return [
            'without agent name configuration' => [null, null],
            'with supervisor agent name' => ['inline', null],
            'with individual agent names' => [null, 'inline'],
            'with both supervisor and individual agent names' => ['inline', 'inline'],
        ];
    }

    #[DataProvider('nameModes')]
    public function testBasicSupervisorWorkflow(?string $includeAgentName, ?string $includeIndividualAgentName): void
    {
        $call = SupervisorFixtures::call(...);
        $supervisorMessages = [
            SupervisorFixtures::aiCalling($call('transfer_to_research_expert', [], 'call_gyQSgJQm5jJtPcF5ITe8GGGF')),
            SupervisorFixtures::aiCalling($call('transfer_to_math_expert', [], 'call_zCExWE54g4B4oFZcwBh3Wumg')),
            new AIMessage('The combined headcount of the FAANG companies in 2024 is 1,977,586 employees.'),
        ];
        $researchAgentMessages = [
            SupervisorFixtures::aiCalling($call('web_search', ['query' => 'FAANG headcount 2024'], 'call_4sLYp7usFcIZBFcNsOGQiFzV')),
            new AIMessage("The headcount for the FAANG companies in 2024 is as follows:\n\n1. **Facebook (Meta)**: 67,317 employees\n2. **Amazon**: 1,551,000 employees\n3. **Apple**: 164,000 employees\n4. **Netflix**: 14,000 employees\n5. **Google (Alphabet)**: 181,269 employees\n\nTo find the combined headcount, simply add these numbers together."),
        ];
        $mathAgentMessages = [
            SupervisorFixtures::aiCalling(
                $call('add', ['a' => 67317, 'b' => 1551000], 'call_BRvA6oAlgMA1whIkAn9gE3AS'),
                $call('add', ['a' => 164000, 'b' => 14000], 'call_OLVb4v0pNDlsBsKBwDK4wb1W'),
                $call('add', ['a' => 181269, 'b' => 0], 'call_5VEHaInDusJ9MU3i3tVJN6Hr'),
            ),
            SupervisorFixtures::aiCalling(
                $call('add', ['a' => 1618317, 'b' => 178000], 'call_FdfUz8Gm3S5OQaVq2oQpMxeN'),
                $call('add', ['a' => 181269, 'b' => 0], 'call_j5nna1KwGiI60wnVHM2319r6'),
            ),
            SupervisorFixtures::aiCalling($call('add', ['a' => 1796317, 'b' => 181269], 'call_4fNHtFvfOvsaSPb8YK1qNAiR')),
            new AIMessage('The combined headcount of the FAANG companies in 2024 is 1,977,586 employees.'),
        ];

        $mathModel = ReactAgentFixtures::spy($mathAgentMessages);
        $researchModel = ReactAgentFixtures::spy($researchAgentMessages);
        $supervisorModel = ReactAgentFixtures::spy($supervisorMessages);

        $add = SupervisorFixtures::add();
        $webSearch = SupervisorFixtures::webSearch();

        $mathLlm = $mathModel;
        $researchLlm = $researchModel;
        if ($includeIndividualAgentName !== null) {
            $mathLlm = AgentName::withAgentName($mathModel->bindTools([$add]), $includeIndividualAgentName);
            $researchLlm = AgentName::withAgentName($researchModel->bindTools([$webSearch]), $includeIndividualAgentName);
        }

        $mathAgent = ReactAgent::create([
            'llm' => $mathLlm,
            'tools' => [$add],
            'name' => 'math_expert',
            'description' => 'Math expert.',
            'prompt' => 'You are a math expert. Always use one tool at a time.',
        ]);
        $researchAgent = ReactAgent::create([
            'llm' => $researchLlm,
            'tools' => [$webSearch],
            'name' => 'research_expert',
            'description' => 'World class researcher with access to web search.',
            'prompt' => 'You are a world class researcher with access to web search. Do not do any math.',
        ]);

        $workflow = Supervisor::create([
            'agents' => [$mathAgent, $researchAgent],
            'llm' => $supervisorModel,
            'prompt' => 'You are a team supervisor managing a research expert and a math expert.',
            'includeAgentName' => $includeAgentName,
        ]);

        $handoffTools = $supervisorModel->bindToolsCalls[0];
        self::assertSame(['transfer_to_math_expert', 'transfer_to_research_expert'], array_map(static fn ($t) => $t->name, $handoffTools));
        self::assertSame('Math expert.', $handoffTools[0]->description);
        self::assertSame('World class researcher with access to web search.', $handoffTools[1]->description);

        $input = ['messages' => [new HumanMessage("what's the combined headcount of the FAANG companies in 2024?")]];

        $result = $workflow->compile()->invoke($input);
        $messages = $result['messages'];

        self::assertCount(12, $messages);
        SupervisorFixtures::assertSameMessage($supervisorMessages[0], $messages[1]);
        SupervisorFixtures::assertSameMessage($researchAgentMessages[1], $messages[3]);
        SupervisorFixtures::assertSameMessage($supervisorMessages[1], $messages[6]);
        SupervisorFixtures::assertSameMessage($mathAgentMessages[3], $messages[8]);
        SupervisorFixtures::assertSameMessage($supervisorMessages[2], $messages[11]);

        $full = Supervisor::create([
            'agents' => [$mathAgent, $researchAgent],
            'llm' => $supervisorModel,
            'prompt' => 'You are a team supervisor managing a research expert and a math expert.',
            'outputMode' => 'full_history',
            'includeAgentName' => $includeAgentName,
        ])->compile()->invoke($input);
        $messages = $full['messages'];

        self::assertCount(23, $messages);
        SupervisorFixtures::assertSameMessage($supervisorMessages[0], $messages[1]);
        SupervisorFixtures::assertSameMessage($researchAgentMessages[0], $messages[3]);
        SupervisorFixtures::assertSameMessage($researchAgentMessages[1], $messages[5]);
        SupervisorFixtures::assertSameMessage($supervisorMessages[1], $messages[8]);
        SupervisorFixtures::assertSameMessage($mathAgentMessages[0], $messages[10]);
        SupervisorFixtures::assertSameMessage($mathAgentMessages[1], $messages[14]);
        SupervisorFixtures::assertSameMessage($mathAgentMessages[2], $messages[17]);
        SupervisorFixtures::assertSameMessage($supervisorMessages[2], end($messages));
    }

    /** @return array{0: \LangGraph\State\StateGraph, 1: \LangChain\Tests\Unit\Prebuilt\SpyingToolCallingChatModel} */
    private function buildWorkflow(?bool $addHandoffMessages = null): array
    {
        $supervisorModel = ReactAgentFixtures::spy([
            SupervisorFixtures::aiCalling(SupervisorFixtures::call('transfer_to_research_expert', [], 'call_transfer_research')),
            new AIMessage('All done.'),
        ]);
        $researchModel = ReactAgentFixtures::spy([new AIMessage('Here is the research result.')]);

        $researchAgent = ReactAgent::create([
            'llm' => $researchModel,
            'tools' => [],
            'name' => 'research_expert',
            'description' => 'World class researcher.',
        ]);

        $params = [
            'agents' => [$researchAgent],
            'llm' => $supervisorModel,
            'prompt' => 'You are a team supervisor managing a research expert.',
        ];
        if ($addHandoffMessages !== null) {
            $params['addHandoffMessages'] = $addHandoffMessages;
        }

        return [Supervisor::create($params), $researchModel];
    }

    public function testOmitsSupervisorToAgentHandoffMessagesWhenAddHandoffMessagesIsFalse(): void
    {
        [$workflow, $researchModel] = $this->buildWorkflow(false);
        $result = $workflow->compile()->invoke(['messages' => [new HumanMessage('do some research')]]);

        $workerMessages = SupervisorFixtures::withoutSystem($researchModel->generateCalls[0]);
        self::assertCount(1, $workerMessages);
        self::assertSame('human', $workerMessages[0]->getType());
        self::assertSame('do some research', $workerMessages[0]->content);

        $handoffToolMessages = array_filter($result['messages'], static fn ($m): bool => $m instanceof ToolMessage && str_starts_with((string) $m->content, 'Successfully transferred'));
        self::assertCount(0, $handoffToolMessages);

        $handoffAiMessages = array_filter($result['messages'], static fn ($m): bool => $m instanceof AIMessage
            && array_filter($m->toolCalls ?? [], static fn (array $tc): bool => str_starts_with($tc['name'], 'transfer_')) !== []);
        self::assertCount(0, $handoffAiMessages);
    }

    public function testKeepsSupervisorToAgentHandoffMessagesByDefault(): void
    {
        [$workflow, $researchModel] = $this->buildWorkflow();
        $result = $workflow->compile()->invoke(['messages' => [new HumanMessage('do some research')]]);

        $workerMessages = SupervisorFixtures::withoutSystem($researchModel->generateCalls[0]);
        self::assertSame(['human', 'ai', 'tool'], array_map(static fn ($m) => $m->getType(), $workerMessages));
        self::assertSame('transfer_to_research_expert', $workerMessages[1]->toolCalls[0]['name']);

        $handoffToolMessages = array_filter($result['messages'], static fn ($m): bool => $m instanceof ToolMessage && $m->content === 'Successfully transferred to research_expert');
        self::assertCount(1, $handoffToolMessages);
    }
}
