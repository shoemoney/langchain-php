<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Channels;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Channels\Topic;
use LangGraph\Pregel\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Send;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
}
