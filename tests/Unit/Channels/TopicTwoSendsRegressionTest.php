<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Channels;

use LangChain\Messages\AIMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangChain\Tools\Schema;
use LangGraph\Channels\Topic;
use LangGraph\Pregel\Checkpoint\MemorySaver;
use LangGraph\Checkpoint\MemorySaver as AgentMemorySaver;
use LangGraph\Prebuilt\ReactAgent;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Send;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;
use function LangGraph\Pregel\interrupt;

/**
 * A non-unique Topic holding exactly two array-shaped values (two serialised
 * Sends) must restore as a flat list, never as a `[seen, values]` pair.
 */
final class TopicTwoSendsRegressionTest extends TestCase
{
    public function testNonUniqueTopicRoundTripsTwoArrayValues(): void
    {
        $sends = [
            ['node' => 'work', 'args' => ['n' => 1]],
            ['node' => 'work', 'args' => ['n' => 2]],
        ];

        $restored = (new Topic(false, false))->fromCheckpoint($sends);

        self::assertSame($sends, $restored->get());
        self::assertSame($sends, $restored->checkpoint());
    }

    public function testUniqueTopicStillReadsTheSeenValuesShape(): void
    {
        $restored = (new Topic(true, false))->fromCheckpoint([['a'], ['a']]);

        self::assertSame(['a'], $restored->get());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function sendCounts(): array
    {
        return ['one send' => [1], 'two sends' => [2], 'three sends' => [3]];
    }

    /**
     * Control: the bare StateGraph/Send pattern does not trip the Topic bug on its own, so these only
     * guard against regressions in fan-out + interrupt + resume.
     */
    #[DataProvider('sendCounts')]
    public function testInterruptingSendSurvivesCheckpointAndResume(int $count): void
    {
        $schema = Annotation::root([
            'items' => Annotation::withReducer(
                static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
                static fn (): array => [],
            ),
        ]);

        $graph = (new StateGraph($schema))
            ->addNode('fan', static fn (array $s): array => ['items' => ['fan']])
            ->addNode('work', static function (array $s): array {
                if ($s['n'] === 1) {
                    $answer = interrupt('need input');

                    return ['items' => ['work:1:' . $answer]];
                }

                return ['items' => ['work:' . $s['n']]];
            })
            ->addEdge(Constants::START, 'fan')
            ->addConditionalEdges('fan', static function (array $s) use ($count): array {
                $out = [];
                for ($n = 1; $n <= $count; $n++) {
                    $out[] = new Send('work', ['n' => $n]);
                }

                return $out;
            })
            ->compile(['checkpointer' => new MemorySaver()]);

        $config = new RunnableConfig(configurable: ['thread_id' => 'topic-' . $count]);
        $graph->invoke(['items' => []], $config);

        self::assertNotEmpty($graph->getState($config)->next, 'paused on the interrupt');

        $result = $graph->invoke(new Command(resume: 'ok'), $config);

        self::assertContains('work:1:ok', $result['items']);
        for ($n = 2; $n <= $count; $n++) {
            self::assertContains('work:' . $n, $result['items']);
        }
    }

    /**
     * The path that actually reproduces the two-Send loss: upstream's "parallel tool calls" case with exactly
     * two tool calls under ReactAgent v2, one of which interrupts. Without the `$this->unique &&` guard the
     * flat two-packet TASKS checkpoint is misread as `[seen, values]`, `next` comes back empty and resume
     * drops the pending tool call.
     */
    public function testTwoParallelToolCallsWithAnInterruptSurviveCheckpointAndResume(): void
    {
        $humanAssistance = tool(
            static function (array $args): mixed {
                $human = interrupt(['query' => $args['query']]);

                return $human['data'];
            },
            ['name' => 'human_assistance', 'description' => 'Human assistance', 'schema' => Schema::object(['query' => ['type' => 'string']])],
        );
        $weather = tool(
            static fn (array $args): string => "It's sunny in " . $args['location'],
            ['name' => 'weather', 'description' => 'Weather tool', 'schema' => Schema::object(['location' => ['type' => 'string']])],
        );

        $agent = ReactAgent::create([
            'llm' => ReactAgentFixtures::fake([
                new AIMessage(['content' => 'ai response', 'tool_calls' => [
                    ReactAgentFixtures::toolCall('weather', 'get_weather', ['location' => 'sf']),
                    ReactAgentFixtures::toolCall('human_assistance', 'get_help', ['query' => 'help me']),
                ]]),
                new AIMessage('final response'),
            ]),
            'tools' => [$humanAssistance, $weather],
            'version' => 'v2',
            'checkpointer' => new AgentMemorySaver(),
        ]);
        $config = new RunnableConfig(configurable: ['thread_id' => 'two-tool-calls']);

        iterator_to_array($agent->stream(['messages' => 'Get user assistance and also check the weather'], $config), false);

        $state = $agent->getState($config);
        self::assertNotEmpty($state->next, 'paused on the interrupt');
        self::assertCount(2, $state->tasks);

        $resumed = $agent->invoke(new Command(resume: ['data' => 'Resumed!']), $config);

        self::assertSame(
            ['Get user assistance and also check the weather', 'ai response', "It's sunny in sf", 'Resumed!', 'final response'],
            ReactAgentFixtures::texts($resumed['messages']),
        );
    }
}
