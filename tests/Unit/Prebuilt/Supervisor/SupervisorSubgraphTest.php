<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt\Supervisor;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Pregel\Constants;
use LangGraph\Prebuilt\ReactAgent;
use LangGraph\Prebuilt\Supervisor\Supervisor;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langgraph-supervisor/src/tests/supervisorSubgraph.test.ts`.
 *
 * "should be drawable as subgraph" draws the graph with `getGraphAsync({xray: true}).drawMermaid()`; the
 * drawing API is WP-06 and not ported, so that case asserts the half that does not need it: a compiled
 * supervisor is accepted as a node of a parent graph, shows up in `getSubgraphs()` and runs.
 */
#[CoversClass(Supervisor::class)]
final class SupervisorSubgraphTest extends TestCase
{
    public function testShouldBeUsableAsSubgraph(): void
    {
        $model = ReactAgentFixtures::spy([new AIMessage('hello from the supervisor')]);

        $mathAgent = ReactAgent::create([
            'llm' => $model,
            'tools' => [SupervisorFixtures::add()],
            'name' => 'math_expert',
            'prompt' => 'You are a math expert. Always use one tool at a time.',
        ]);
        $researchAgent = ReactAgent::create([
            'llm' => $model,
            'tools' => [SupervisorFixtures::webSearch()],
            'name' => 'research_expert',
            'prompt' => 'You are a world class researcher with access to web search. Do not do any math.',
        ]);

        $subGraph = Supervisor::create([
            'agents' => [$researchAgent, $mathAgent],
            'llm' => $model,
            'prompt' => 'You are a team supervisor managing a research expert and a math expert.',
        ])->compile(['name' => 'Test']);

        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('sub_graph', $subGraph)
            ->addEdge(Constants::START, 'sub_graph')
            ->addEdge('sub_graph', Constants::END)
            ->compile();

        $subgraphs = iterator_to_array($graph->getSubgraphs(), false);
        self::assertCount(1, $subgraphs);
        self::assertSame('sub_graph', $subgraphs[0][0]);
        self::assertSame('Test', $subgraphs[0][1]->name);

        $result = $graph->invoke(['messages' => [new HumanMessage('hi')]]);
        self::assertSame('hello from the supervisor', end($result['messages'])->content);
    }

    public function testShouldWorkWithMultipleLayersOfSupervisionArrangedAsSubgraphs(): void
    {
        // Upstream replays a recorded stream of 34 chunks; the same conversation is scripted here as the
        // six model turns it comes to, consumed in order by one shared model.
        $call = SupervisorFixtures::call(...);
        $model = ReactAgentFixtures::spy([
            SupervisorFixtures::aiCalling($call('transfer_to_research_team', [], 'call_top_to_research')),
            SupervisorFixtures::aiCalling($call('transfer_to_math_expert', [], 'call_research_to_math')),
            SupervisorFixtures::aiCalling($call('add', ['a' => 1, 'b' => 1], 'call_add')),
            new AIMessage('1 + 1 = 2'),
            new AIMessage('The research team says the answer is 2.'),
            new AIMessage('The answer is 2.'),
        ]);

        $agent = static fn (string $name, array $tools, string $prompt) => ReactAgent::create([
            'llm' => $model,
            'tools' => $tools,
            'name' => $name,
            'prompt' => $prompt,
        ]);

        $researchTeam = Supervisor::create([
            'agents' => [
                $agent('research_expert', [SupervisorFixtures::webSearch()], 'You are a world class researcher with access to web search. Do not do any math.'),
                $agent('math_expert', [SupervisorFixtures::add()], 'You are a math expert. Always use one tool at a time.'),
            ],
            'llm' => $model,
            'prompt' => 'You are a research team supervisor. Coordinate research and math tasks effectively.',
        ])->compile(['name' => 'research_team']);

        $writingTeam = Supervisor::create([
            'agents' => [
                $agent('writing_expert', [], 'You are an expert writer. Focus on creating engaging content.'),
                $agent('publishing_expert', [], 'You are a publishing expert. Handle the publication process professionally.'),
            ],
            'llm' => $model,
            'prompt' => 'You are a writing team supervisor. Coordinate content creation and publication.',
        ])->compile(['name' => 'writing_team']);

        $topLevel = Supervisor::create([
            'agents' => [$researchTeam, $writingTeam],
            'llm' => $model,
            'prompt' => 'You are an executive supervisor coordinating research and writing teams. Delegate tasks appropriately.',
        ])->compile(['name' => 'top_level_supervisor']);

        $result = $topLevel->invoke(['messages' => [['role' => 'user', 'content' => "what's 1+1 ? Don't guess the answer rely on research_team"]]]);

        $messages = $result['messages'];
        self::assertNotEmpty($messages);
        self::assertSame('The answer is 2.', end($messages)->content);

        // Every layer actually ran: the tool result of the innermost agent made it all the way up.
        $texts = array_map(static fn ($m) => (string) (is_string($m->content) ? $m->content : ''), $messages);
        self::assertContains('Successfully transferred to research_team', $texts);
        self::assertContains('Successfully transferred back to supervisor', $texts);
        self::assertContains('The research team says the answer is 2.', $texts);
    }
}
