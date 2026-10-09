<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Func;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Cache\InMemoryCache;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Func\Func;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Port of the `graph.clearCache()` cases in `langgraph-core/src/tests/func.test.ts` and
 * `pregel.test.ts`, plus the entrypoint `getState()` snapshot the functional API relies on.
 */
#[CoversClass(Pregel::class)]
final class ClearCacheTest extends FuncTestCase
{
    public function testClearCacheForcesACachedTaskToRunAgain(): void
    {
        $calls = 0;
        $task = Func::task(
            ['name' => 'slow', 'cachePolicy' => ['ttl' => 100]],
            static function (int $x) use (&$calls): int {
                ++$calls;

                return $x * 2;
            },
        );
        $graph = Func::entrypoint(
            ['name' => 'ep', 'cache' => new InMemoryCache()],
            static fn (int $in): int => self::await($task($in)),
        );

        self::assertSame(4, $graph->invoke(2, new RunnableConfig()));
        self::assertSame(4, $graph->invoke(2, new RunnableConfig()));
        self::assertSame(1, $calls);

        $graph->clearCache();

        self::assertSame(4, $graph->invoke(2, new RunnableConfig()));
        self::assertSame(2, $calls);
    }

    public function testClearCacheWithoutACacheIsANoOp(): void
    {
        $graph = Func::entrypoint(['name' => 'ep'], static fn (int $in): int => $in + 1);

        $graph->clearCache();

        self::assertSame(2, $graph->invoke(1, new RunnableConfig()));
    }

    public function testGetStateSnapshotsAnEntrypointWithItsScalarOutput(): void
    {
        $graph = Func::entrypoint(
            ['name' => 'ep', 'checkpointer' => new MemorySaver()],
            static fn (string $in): string => 'done:' . $in,
        );
        $config = new RunnableConfig(configurable: ['thread_id' => 't1']);

        self::assertSame('done:a', $graph->invoke('a', $config));

        $snapshot = $graph->getState($config);
        self::assertSame('done:a', $snapshot->values);
        self::assertSame([], $snapshot->tasks);
        self::assertSame([], $snapshot->next);
    }

    public function testGetStateHistoryListsEntrypointCheckpoints(): void
    {
        $graph = Func::entrypoint(
            ['name' => 'ep', 'checkpointer' => new MemorySaver()],
            static fn (int $in): int => $in * 10,
        );
        $config = new RunnableConfig(configurable: ['thread_id' => 'h1']);
        $graph->invoke(1, $config);
        $graph->invoke(2, $config);

        $history = $graph->getStateHistory($config);

        self::assertNotEmpty($history);
        self::assertSame(20, $history[0]['values']);
    }

    public function testClearCacheOnAStateGraphRerunsCachedNodes(): void
    {
        $calls = 0;
        $graph = (new StateGraph(Annotation::root(['query' => Annotation::last(), 'answer' => Annotation::last()])))
            ->addNode('respond', static function (array $state) use (&$calls): array {
                ++$calls;

                return ['answer' => 'a:' . $state['query']];
            }, ['cachePolicy' => ['ttl' => 60]])
            ->addEdge(Constants::START, 'respond')
            ->addEdge('respond', Constants::END)
            ->compile(['cache' => new InMemoryCache()]);

        self::assertSame('a:q', $graph->invoke(['query' => 'q'])['answer']);
        self::assertSame('a:q', $graph->invoke(['query' => 'q'])['answer']);
        self::assertSame(1, $calls);

        $graph->clearCache();

        self::assertSame('a:q', $graph->invoke(['query' => 'q'])['answer']);
        self::assertSame(2, $calls);
    }
}
