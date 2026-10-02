<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Checkpoint\SqliteSaver;
use LangGraph\Pregel\Constants;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The checkpointer, driven by a real graph.
 *
 * Every other file here tests a saver in isolation. This one tests the thing that
 * actually matters: that a saver from this namespace satisfies the interface the
 * Pregel loop calls, and that a run through it is resumable.
 *
 * A saver can pass every contract test and still never be handed to the engine —
 * a type mismatch, a method the loop never calls, a config shape the loop does
 * not produce. None of that shows up until a graph runs.
 */
#[CoversNothing]
final class PregelCheckpointerIntegrationTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: callable(): MemorySaver|SqliteSaver}>
     */
    public static function savers(): array
    {
        return [
            ['memory', static fn (): MemorySaver => new MemorySaver()],
            ['sqlite', static fn (): SqliteSaver => SqliteSaver::fromConnString(':memory:')],
        ];
    }

    /**
     * A run writes a checkpoint per superstep, and a resume picks up from the
     * last one.
     */
    #[DataProvider('savers')]
    public function testARunIsPersistedAndResumable(string $name, callable $make): void
    {
        $saver = $make();
        $runs = [];

        $builder = new StateGraph(['value' => 'int', 'log' => 'list']);
        $builder->addNode('double', static function (array $state) use (&$runs): array {
            $runs[] = 'double';

            return ['value' => $state['value'] * 2];
        });
        $builder->addNode('increment', static function (array $state) use (&$runs): array {
            $runs[] = 'increment';

            return ['value' => $state['value'] + 1, 'log' => ['incremented']];
        });
        $builder->addEdge(Constants::START, 'double');
        $builder->addEdge('double', 'increment');
        $builder->addEdge('increment', Constants::END);
        $graph = $builder->compile(['checkpointer' => $saver]);

        $config = self::config('integration-1');
        $result = $graph->invoke(['value' => 5], $config);

        self::assertSame(11, $result['value'], $name);
        self::assertSame(['double', 'increment'], $runs, $name);

        // The engine must have called the saver, not merely held it.
        $history = $saver->list(['thread_id' => 'integration-1']);
        self::assertNotEmpty($history, $name . ': the run wrote no checkpoints');
        self::assertGreaterThanOrEqual(2, count($history), $name);

        // The thread is a chain: the head names a parent, and walking parents
        // reaches a root without leaving the thread. A final save keeps the
        // head's own id and rewrites that row, so the head names itself — the
        // walk stops there rather than looping.
        $onThread = array_map(static fn ($t): string => $t->checkpoint->id, $history);
        self::assertNotNull($history[0]->parentConfig, $name . ': the head has no parent');

        $cursor = $history[0]->config['configurable'];
        $seen = [];
        while (!isset($seen[$cursor['checkpoint_id']])) {
            $seen[$cursor['checkpoint_id']] = true;
            self::assertContains($cursor['checkpoint_id'], $onThread, $name . ': walked off the thread');

            $tuple = $saver->getTuple($cursor);
            if ($tuple === null || $tuple->parentConfig === null) {
                break;
            }
            $parent = $tuple->parentConfig['configurable']['checkpoint_id'];
            if ($parent === $tuple->checkpoint->id) {
                break;
            }
            $cursor = $tuple->parentConfig['configurable'];
        }

        // Resuming the same thread continues from the saved state rather than
        // starting over.
        $runs = [];
        $resumed = $graph->invoke(['value' => 100], $config);

        self::assertSame(201, $resumed['value'], $name);
        self::assertSame(['double', 'increment'], $runs, $name);
    }

    /**
     * `getState` and `getStateHistory` read through the saver, so a saver that
     * stored the wrong shape would make them lie.
     */
    #[DataProvider('savers')]
    public function testGetStateAndHistoryReadThroughTheSaver(string $name, callable $make): void
    {
        $saver = $make();

        $builder = new StateGraph(['value' => 'int']);
        $builder->addNode('bump', static fn (array $s): array => ['value' => $s['value'] + 1]);
        $builder->addEdge(Constants::START, 'bump');
        $builder->addEdge('bump', Constants::END);
        $graph = $builder->compile(['checkpointer' => $saver]);

        $config = self::config('state-1');
        $graph->invoke(['value' => 1], $config);

        $snapshot = $graph->getState(['configurable' => ['thread_id' => 'state-1']]);
        self::assertSame(2, $snapshot->values['value'], $name);
        self::assertSame([], $snapshot->next, $name);
        self::assertArrayHasKey('checkpoint_id', $snapshot->config['configurable'], $name);

        $history = $graph->getStateHistory(['configurable' => ['thread_id' => 'state-1']]);
        self::assertNotEmpty($history, $name);
        self::assertSame(2, $history[0]['values']['value'], $name);
    }

    /**
     * An interrupted run leaves a checkpoint a resume can pick up, which is the
     * whole reason a task's writes are saved before the superstep commits.
     */
    #[DataProvider('savers')]
    public function testAnInterruptedRunResumesFromTheSavedCheckpoint(string $name, callable $make): void
    {
        $saver = $make();
        $runs = [];

        $builder = new StateGraph(['value' => 'int']);
        $builder->addNode('a', static function (array $s) use (&$runs): array {
            $runs[] = 'a';

            return ['value' => $s['value'] + 1];
        });
        $builder->addNode('b', static function (array $s) use (&$runs): array {
            $runs[] = 'b';

            return ['value' => $s['value'] + 10];
        });
        $builder->addEdge(Constants::START, 'a');
        $builder->addEdge('a', 'b');
        $builder->addEdge('b', Constants::END);
        $graph = $builder->compile(['checkpointer' => $saver, 'interruptBefore' => ['b']]);

        $config = self::config('interrupt-1');
        $graph->invoke(['value' => 0], $config);

        self::assertSame(['a'], $runs, $name);

        $paused = $graph->getState(['configurable' => ['thread_id' => 'interrupt-1']]);
        self::assertSame(1, $paused->values['value'], $name);
        self::assertSame(['b'], $paused->next, $name);

        $result = $graph->invoke(null, $config);

        self::assertSame(11, $result['value'], $name);
        self::assertSame(['a', 'b'], $runs, $name);
    }

    private static function config(string $threadId): RunnableConfig
    {
        $config = new RunnableConfig();
        $config->configurable = ['thread_id' => $threadId];

        return $config;
    }
}
