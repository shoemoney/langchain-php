<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\ToolRuntime;
use LangGraph\Cache\BaseCache;
use LangGraph\Cache\InMemoryCache;
use LangGraph\Pregel\Algorithm;
use LangGraph\Pregel\Checkpoint\MemorySaver;
use LangGraph\Pregel\CompiledStateGraph;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\PregelLoop;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use LangGraph\Store\AsyncBatchedStore;
use LangGraph\Store\BaseStore;
use LangGraph\Store\InMemoryStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Real graphs run end to end with a long-term store and a node cache: the
 * plumbing WP-01 threads through `Pregel`, `StateGraph::compile`, `PregelLoop`
 * and `Algorithm`.
 */
#[CoversClass(Pregel::class)]
#[CoversClass(PregelLoop::class)]
#[CoversClass(Algorithm::class)]
#[CoversClass(StateGraph::class)]
#[CoversClass(CompiledStateGraph::class)]
#[CoversClass(ToolRuntime::class)]
final class StoreAndCacheTest extends TestCase
{
    private static function schema(): \LangGraph\State\AnnotationRoot
    {
        return Annotation::root([
            'user' => Annotation::last(),
            'greeting' => Annotation::last(),
            'runs' => Annotation::withReducer(
                static fn ($a, $b) => ($a ?? 0) + ($b ?? 0),
                static fn (): int => 0,
            ),
        ]);
    }

    // ---- store --------------------------------------------------------------

    public function testANodeReadsAndWritesTheStoreItWasCompiledWith(): void
    {
        $store = new InMemoryStore();
        $store->put(['users', 'ada'], 'prefs', ['language' => 'French']);

        $graph = (new StateGraph(self::schema()))
            ->addNode('remember', static function (array $state, RunnableConfig $config): array {
                $store = $config->configurable[Constants::CONFIG_KEY_STORE];
                $prefs = $store->get(['users', $state['user']], 'prefs');
                $store->put(['users', $state['user']], 'last_greeting', ['text' => 'hello ' . $state['user']]);

                return ['greeting' => $prefs?->value['language']];
            })
            ->addEdge(Constants::START, 'remember')
            ->addEdge('remember', Constants::END)
            ->compile(['store' => $store]);

        $result = $graph->invoke(['user' => 'ada']);

        self::assertSame('French', $result['greeting']);
        self::assertSame($store, $graph->store);
        self::assertSame(['text' => 'hello ada'], $store->get(['users', 'ada'], 'last_greeting')?->value);
    }

    public function testTheStoreOutlivesAThreadAndIsSharedAcrossThem(): void
    {
        $store = new InMemoryStore();
        $graph = (new StateGraph(self::schema()))
            ->addNode('count', static function (array $state, RunnableConfig $config): array {
                $store = $config->configurable[Constants::CONFIG_KEY_STORE];
                $seen = $store->get(['visits'], $state['user'])?->value['n'] ?? 0;
                $store->put(['visits'], $state['user'], ['n' => $seen + 1]);

                return ['runs' => $seen + 1];
            })
            ->addEdge(Constants::START, 'count')
            ->addEdge('count', Constants::END)
            ->compile(['store' => $store, 'checkpointer' => new MemorySaver()]);

        $first = $graph->invoke(['user' => 'ada'], new RunnableConfig(configurable: ['thread_id' => 't1']));
        $second = $graph->invoke(['user' => 'ada'], new RunnableConfig(configurable: ['thread_id' => 't2']));

        self::assertSame(1, $first['runs']);
        self::assertSame(2, $second['runs']);
    }

    public function testTheNodeSeesTheBatchedWrapperNotTheRawStore(): void
    {
        $store = new InMemoryStore();
        $seen = null;
        $graph = (new StateGraph(self::schema()))
            ->addNode('peek', static function (array $state, RunnableConfig $config) use (&$seen): array {
                $seen = $config->configurable[Constants::CONFIG_KEY_STORE];

                return [];
            })
            ->addEdge(Constants::START, 'peek')
            ->addEdge('peek', Constants::END)
            ->compile(['store' => $store]);

        $graph->invoke(['user' => 'ada']);

        self::assertInstanceOf(AsyncBatchedStore::class, $seen);
        self::assertSame($store, $seen->unwrap());
    }

    public function testAGraphWithoutAStoreGivesNodesNone(): void
    {
        $present = null;
        $graph = (new StateGraph(self::schema()))
            ->addNode('peek', static function (array $state, RunnableConfig $config) use (&$present): array {
                $present = array_key_exists(Constants::CONFIG_KEY_STORE, $config->configurable);

                return [];
            })
            ->addEdge(Constants::START, 'peek')
            ->addEdge('peek', Constants::END)
            ->compile();

        $graph->invoke(['user' => 'ada']);

        self::assertFalse($present);
    }

    public function testACallersConfigIsNeverGivenTheStore(): void
    {
        $graph = (new StateGraph(self::schema()))
            ->addNode('noop', static fn (array $state): array => [])
            ->addEdge(Constants::START, 'noop')
            ->addEdge('noop', Constants::END)
            ->compile(['store' => new InMemoryStore()]);

        $config = new RunnableConfig();
        $graph->invoke(['user' => 'ada'], $config);

        self::assertArrayNotHasKey(Constants::CONFIG_KEY_STORE, $config->configurable);
    }

    public function testASubgraphRunAsANodeInheritsTheParentsStore(): void
    {
        $store = new InMemoryStore();

        $child = (new StateGraph(self::schema()))
            ->addNode('write', static function (array $state, RunnableConfig $config): array {
                $config->configurable[Constants::CONFIG_KEY_STORE]->put(['child'], 'k', ['user' => $state['user']]);

                return ['greeting' => 'from child'];
            })
            ->addEdge(Constants::START, 'write')
            ->addEdge('write', Constants::END)
            ->compile();

        $parent = (new StateGraph(self::schema()))
            ->addNode('child', $child)
            ->addEdge(Constants::START, 'child')
            ->addEdge('child', Constants::END)
            ->compile(['store' => $store]);

        $result = $parent->invoke(['user' => 'ada']);

        self::assertSame('from child', $result['greeting']);
        self::assertSame(['user' => 'ada'], $store->get(['child'], 'k')?->value);
    }

    public function testACallTimeStoreOverridesTheGraphsOwn(): void
    {
        $compiled = new InMemoryStore();
        $override = new InMemoryStore();
        $graph = (new StateGraph(self::schema()))
            ->addNode('write', static function (array $state, RunnableConfig $config): array {
                $config->configurable[Constants::CONFIG_KEY_STORE]->put(['w'], 'k', ['v' => 1]);

                return [];
            })
            ->addEdge(Constants::START, 'write')
            ->addEdge('write', Constants::END)
            ->compile(['store' => $compiled]);

        $graph->invoke(['user' => 'ada'], new RunnableConfig(configurable: [Constants::CONFIG_KEY_STORE => $override]));

        self::assertNull($compiled->get(['w'], 'k'));
        self::assertNotNull($override->get(['w'], 'k'));
    }

    public function testAToolRuntimeCarriesTheGraphsStore(): void
    {
        $store = new InMemoryStore();
        $config = new RunnableConfig(
            configurable: [Constants::CONFIG_KEY_STORE => new AsyncBatchedStore($store)],
            toolCall: ['id' => 'call_1', 'name' => 'lookup', 'args' => []],
        );

        $runtime = ToolRuntime::fromConfig($config);

        self::assertNotNull($runtime);
        self::assertInstanceOf(BaseStore::class, $runtime->store);
        self::assertSame(Constants::CONFIG_KEY_STORE, '__pregel_store');
        $runtime->store->put(['t'], 'k', ['v' => 1]);
        self::assertSame(['v' => 1], $store->get(['t'], 'k')?->value);
    }

    public function testAToolRuntimeWithoutAStoreHasNone(): void
    {
        $runtime = ToolRuntime::fromConfig(new RunnableConfig(toolCall: ['id' => 'call_1', 'name' => 'x', 'args' => []]));

        self::assertNotNull($runtime);
        self::assertNull($runtime->store);
    }

    public function testGetStatePreparesTasksWithTheStore(): void
    {
        $store = new InMemoryStore();
        $graph = (new StateGraph(self::schema()))
            ->addNode('a', static fn (array $state): array => ['greeting' => 'a'])
            ->addNode('b', static fn (array $state): array => ['greeting' => 'b'])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->addEdge('b', Constants::END)
            ->compile(['store' => $store, 'checkpointer' => new MemorySaver(), 'interruptBefore' => ['b']]);

        $config = new RunnableConfig(configurable: ['thread_id' => 't']);
        $graph->invoke(['user' => 'ada'], $config);

        self::assertSame(['b'], $graph->getState($config)->next);
    }

    // ---- cache --------------------------------------------------------------

    /**
     * @param list<string> $calls
     */
    private function cachedGraph(array &$calls, ?BaseCache $cache, int $ttl = 60): CompiledStateGraph
    {
        return (new StateGraph(self::schema()))
            ->addNode('expensive', static function (array $state) use (&$calls): array {
                $calls[] = $state['user'];

                return ['greeting' => 'hello ' . $state['user'], 'runs' => 1];
            }, ['cachePolicy' => ['ttl' => $ttl]])
            ->addEdge(Constants::START, 'expensive')
            ->addEdge('expensive', Constants::END)
            ->compile(['cache' => $cache]);
    }

    public function testACachedNodeRunsOncePerDistinctInput(): void
    {
        $calls = [];
        $graph = $this->cachedGraph($calls, new InMemoryCache());

        $first = $graph->invoke(['user' => 'ada']);
        $second = $graph->invoke(['user' => 'ada']);
        $third = $graph->invoke(['user' => 'grace']);

        self::assertSame(['ada', 'grace'], $calls);
        self::assertSame($first, $second);
        self::assertSame('hello ada', $second['greeting']);
        self::assertSame('hello grace', $third['greeting']);
        self::assertSame(1, $second['runs']);
    }

    public function testACachedNodeKeepsItsStateEffectsOnAHit(): void
    {
        $calls = [];
        $graph = $this->cachedGraph($calls, new InMemoryCache());

        $graph->invoke(['user' => 'ada']);
        $hit = $graph->invoke(['user' => 'ada']);

        self::assertSame(['user' => 'ada', 'greeting' => 'hello ada', 'runs' => 1], $hit);
    }

    public function testWithoutACacheTheNodeAlwaysRuns(): void
    {
        $calls = [];
        $graph = $this->cachedGraph($calls, null);

        $graph->invoke(['user' => 'ada']);
        $graph->invoke(['user' => 'ada']);

        self::assertSame(['ada', 'ada'], $calls);
    }

    public function testAnExpiredEntryRunsTheNodeAgain(): void
    {
        $calls = [];
        $graph = $this->cachedGraph($calls, new InMemoryCache(), ttl: 0);

        $graph->invoke(['user' => 'ada']);
        usleep(2000);
        $graph->invoke(['user' => 'ada']);

        self::assertSame(['ada', 'ada'], $calls);
    }

    public function testTheCacheIsSharedBetweenGraphsThatShareIt(): void
    {
        $cache = new InMemoryCache();
        $callsA = [];
        $callsB = [];

        $this->cachedGraph($callsA, $cache)->invoke(['user' => 'ada']);
        $this->cachedGraph($callsB, $cache)->invoke(['user' => 'ada']);

        self::assertSame(['ada'], $callsA);
        self::assertSame([], $callsB);
    }

    public function testAFailedNodeIsNotCached(): void
    {
        $cache = new InMemoryCache();
        $attempts = 0;
        $graph = (new StateGraph(self::schema()))
            ->addNode('flaky', static function (array $state) use (&$attempts): array {
                $attempts++;
                if ($attempts === 1) {
                    throw new \RuntimeException('boom');
                }

                return ['greeting' => 'ok'];
            }, ['cachePolicy' => ['ttl' => 60]])
            ->addEdge(Constants::START, 'flaky')
            ->addEdge('flaky', Constants::END)
            ->compile(['cache' => $cache]);

        try {
            $graph->invoke(['user' => 'ada']);
            self::fail('expected the first run to fail');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame('ok', $graph->invoke(['user' => 'ada'])['greeting']);
        self::assertSame(2, $attempts);
    }

    public function testACallTimeCacheOverridesTheGraphsOwn(): void
    {
        $calls = [];
        $graph = $this->cachedGraph($calls, new InMemoryCache());
        $override = new InMemoryCache();
        $config = static fn (): RunnableConfig => new RunnableConfig(configurable: [Constants::CONFIG_KEY_CACHE => $override]);

        $graph->invoke(['user' => 'ada'], $config());
        $graph->invoke(['user' => 'ada'], $config());
        $graph->invoke(['user' => 'ada']);

        self::assertSame(['ada', 'ada'], $calls);
    }
}
