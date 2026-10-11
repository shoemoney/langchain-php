<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Cache\InMemoryCache;
use LangGraph\Channels\NamedBarrierValue;
use LangGraph\Pregel\Checkpoint\MemorySaver;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Actual waiting-edge topology from pinned pregel.test.ts3118–3240.
 * PHP node/cache bodies settle synchronously; delays do not assert Promise ordering.
 */
#[CoversClass(NamedBarrierValue::class)]
final class CyclicWaitingEdgeTest extends TestCase
{
    private const JOIN = 'join:retriever_one+retriever_two:decider';
    private const INPUT = ['query' => 'what is weather in sf'];

    #[DataProvider('graphCases')]
    public function testCyclicInvokeUpdatesAndClearProduceTheFullAnswer(bool $cached, bool $slow, int $rounds): void
    {
        $counts = $this->counts();
        $backend = $slow ? new class extends InMemoryCache {
            public function get(array $keys): array
            {
                usleep(1000);

                return parent::get($keys);
            }

            public function set(array $pairs): void
            {
                usleep(1000);
                parent::set($pairs);
            }
        } : new InMemoryCache();
        $graph = $this->graph($cached, $rounds, $counts, $backend);
        $config = new RunnableConfig(tags: ['SYNTH_CYCLE'], metadata: ['source' => 'SYNTH_TEST'],
            configurable: ['thread_id' => 'SYNTH_CYCLE_THREAD']);
        $original = clone $config;
        $input = self::INPUT;
        self::assertSame($backend, $graph->cache);
        self::assertSame($this->expected($rounds), $graph->invoke($input, $config));
        self::assertSame(['rewrite' => $rounds, 'decider' => $rounds, 'qa' => 1,
            'retriever_one' => $rounds, 'retriever_two' => $rounds], $counts);
        $updates = [];
        foreach ($graph->stream($input, $config->with(['options' => ['streamMode' => 'updates']])) as [$mode, $update]) {
            self::assertSame('updates', $mode);
            $updates[] = $update;
        }
        $names = [];
        foreach ($updates as $update) {
            $nodes = array_values(array_diff(array_keys($update), ['__metadata__']));
            self::assertCount(1, $nodes);
            $names[] = $nodes[0];
            if ($cached && $nodes[0] === 'rewrite_query') {
                self::assertTrue($update['__metadata__']['cached']);
            } else {
                self::assertArrayNotHasKey('__metadata__', $update);
            }
        }
        self::assertSame(array_merge(...array_fill(0, $rounds,
            ['rewrite_query', 'analyzer_one', 'retriever_two', 'retriever_one', 'decider'])), array_slice($names, 0, -1));
        self::assertSame('qa', $names[array_key_last($names)]);
        self::assertSame(['qa' => ['answer' => $this->expected($rounds)['answer']]], $updates[array_key_last($updates)]);
        self::assertSame($cached ? $rounds : 2 * $rounds, $counts['rewrite']);
        self::assertSame(2 * $rounds, $counts['decider']);
        self::assertSame(2, $counts['qa']);
        if ($cached) {
            $graph->clearCache();
            self::assertSame($this->expected($rounds), $graph->invoke($input, $config));
            self::assertSame(2 * $rounds, $counts['rewrite']);
            self::assertSame(3 * $rounds, $counts['decider']);
            self::assertSame(3, $counts['qa']);
        }
        self::assertSame($backend, $graph->cache);
        self::assertSame(self::INPUT, $input);
        self::assertEquals($original, $config);
    }

    public static function graphCases(): array
    {
        return ['cached two' => [true, false, 2], 'slow cached two' => [true, true, 2],
            'uncached two' => [false, false, 2], 'cached three' => [true, false, 3]];
    }

    #[DataProvider('interruptCases')]
    public function testInterruptedPartialReadyAndConsumedBarriersResume(bool $cached, string $boundary): void
    {
        $counts = $this->counts();
        $saver = new MemorySaver();
        $backend = new InMemoryCache();
        $before = $boundary === 'ready' ? ['decider'] : [];
        $after = $boundary === 'partial' ? ['retriever_two'] : ($boundary === 'consumed' ? ['decider'] : []);
        $graph = $this->graph($cached, 2, $counts, $backend, $saver, $before, $after);
        $config = new RunnableConfig(configurable: ['thread_id' => 'SYNTH_INTERRUPT_' . $boundary]);
        $original = clone $config;
        $initial = $graph->invoke(self::INPUT, $config);
        self::assertArrayNotHasKey('answer', $initial);
        $tuple = $saver->getTuple(['configurable' => $config->configurable]);
        self::assertNotNull($tuple);
        self::assertArrayHasKey(self::JOIN, $tuple->checkpoint->channelValues);
        $seen = $tuple->checkpoint->channelValues[self::JOIN];
        if ($boundary === 'partial') {
            self::assertSame(['retriever_two'], $seen);
            self::assertSame(0, $counts['decider']);
            self::assertSame(0, $counts['retriever_one']);
        } elseif ($boundary === 'ready') {
            self::assertEqualsCanonicalizing(['retriever_one', 'retriever_two'], $seen);
            self::assertSame(0, $counts['decider']);
        } else {
            self::assertSame([], $seen);
            self::assertSame(1, $counts['decider']);
            self::assertGreaterThan($tuple->checkpoint->versionsSeen['decider'][self::JOIN],
                $tuple->checkpoint->channelVersions[self::JOIN]);
        }
        self::assertSame($this->expected(2), $graph->invoke(null,
            $config->with(['options' => ['interruptBefore' => [], 'interruptAfter' => []]])));
        self::assertSame(['rewrite' => 2, 'decider' => 2, 'qa' => 1,
            'retriever_one' => 2, 'retriever_two' => 2], $counts);
        $final = $saver->getTuple(['configurable' => $config->configurable]);
        self::assertSame([], $final->checkpoint->channelValues[self::JOIN]);
        self::assertSame($backend, $graph->cache);
        self::assertEquals($original, $config);
    }

    public static function interruptCases(): array
    {
        return ['partial uncached' => [false, 'partial'], 'partial cached' => [true, 'partial'],
            'ready uncached' => [false, 'ready'], 'ready cached' => [true, 'ready'],
            'consumed uncached' => [false, 'consumed'], 'consumed cached' => [true, 'consumed']];
    }

    public function testAcyclicWaitingEdgeStillJoinsOnce(): void
    {
        $counts = $this->counts();
        $graph = $this->graph(false, 1, $counts, new InMemoryCache());
        self::assertSame($this->expected(1), $graph->invoke(self::INPUT));
        self::assertSame(1, $counts['decider']);
        self::assertSame(1, $counts['qa']);
    }

    private function counts(): array
    {
        return ['rewrite' => 0, 'decider' => 0, 'qa' => 0, 'retriever_one' => 0, 'retriever_two' => 0];
    }

    private function expected(int $rounds): array
    {
        $docs = array_merge(...array_fill(0, $rounds, ['doc1', 'doc2', 'doc3', 'doc4']));
        sort($docs);

        return ['query' => str_repeat('analyzed: query: ', $rounds) . self::INPUT['query'],
            'answer' => implode(',', $docs), 'docs' => $docs];
    }

    private function graph(bool $cached, int $rounds, array &$counts, InMemoryCache $cache,
        ?MemorySaver $saver = null, array $before = [], array $after = []): Pregel
    {
        $schema = Annotation::root(['query' => Annotation::last(), 'answer' => Annotation::last(),
            'docs' => Annotation::withReducer(static function (array $a, array $b): array {
                $docs = [...$a, ...$b];
                sort($docs);

                return $docs;
            }, static fn (): array => [])]);

        return (new StateGraph($schema))
            ->addNode('rewrite_query', static function (array $state) use (&$counts): array {
                ++$counts['rewrite'];

                return ['query' => 'query: ' . $state['query']];
            }, $cached ? ['cachePolicy' => true] : [])
            ->addNode('analyzer_one', static fn (array $state): array => ['query' => 'analyzed: ' . $state['query']])
            ->addNode('retriever_one', static function () use (&$counts): array {
                ++$counts['retriever_one'];

                return ['docs' => ['doc1', 'doc2']];
            })
            ->addNode('retriever_two', static function () use (&$counts): array {
                ++$counts['retriever_two'];
                usleep(1000);

                return ['docs' => ['doc3', 'doc4']];
            })
            ->addNode('qa', static function (array $state) use (&$counts): array {
                ++$counts['qa'];

                return ['answer' => implode(',', $state['docs'])];
            })
            ->addNode('decider', static function () use (&$counts): array {
                ++$counts['decider'];

                return [];
            })
            ->addEdge(Constants::START, 'rewrite_query')
            ->addEdge('rewrite_query', 'analyzer_one')
            ->addEdge('analyzer_one', 'retriever_one')
            ->addEdge('rewrite_query', 'retriever_two')
            ->addEdge(['retriever_one', 'retriever_two'], 'decider')
            ->addConditionalEdges('decider', static fn (array $state): string =>
                substr_count($state['query'], 'analyzed') >= $rounds ? 'qa' : 'rewrite_query', ['qa', 'rewrite_query'])
            ->compile(['cache' => $cache, 'checkpointer' => $saver, 'interruptBefore' => $before, 'interruptAfter' => $after]);
    }
}
