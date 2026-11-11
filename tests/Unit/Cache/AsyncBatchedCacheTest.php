<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Cache;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Cache\AsyncBatchedCache;
use LangGraph\Cache\BaseCache;
use LangGraph\Cache\InMemoryCache;
use LangGraph\Checkpoint\Serde\BaseCheckpointSerializer;
use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use LangGraph\Pregel\Checkpoint\Checkpoint;
use LangGraph\Pregel\Checkpoint\MemorySaver;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\PregelLoop;
use LangGraph\Pregel\StreamProtocol;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * LG loop.ts's borrowed cache facade and the REAL Pregel producer cleanup.
 * PHP operations settle synchronously; no Promise/Fiber queue fidelity is claimed.
 */
#[CoversClass(AsyncBatchedCache::class)]
#[CoversClass(PregelLoop::class)]
#[CoversClass(Pregel::class)]
final class AsyncBatchedCacheTest extends TestCase
{
    public function testExactBatchesEmptyCallsAndReturnedObjectIdentityArePreserved(): void
    {
        $backend = new RecordingFacadeBackend();
        $object = (object) ['value' => 'SYNTH_OBJECT'];
        $pairs = [
            ['key' => [['n', 'nested'], 'k'], 'value' => $object, 'ttl' => 0],
            ['key' => [['n'], 'other'], 'value' => ['lc' => 1], 'ttl' => null],
        ];
        $keys = [[['n', 'nested'], 'k'], [['missing'], 'absent']];
        $namespaces = [['n', 'nested'], ['other']];
        $backend->result = [['key' => $pairs[0]['key'], 'value' => $object]];
        $facade = new AsyncBatchedCache($backend);
        $before = [$pairs, $keys, $namespaces];
        $facade->set($pairs);
        $found = $facade->get($keys);
        $facade->clear($namespaces);
        $facade->set([]);
        $facade->get([]);
        $facade->clear([]);
        self::assertSame([
            ['set', $pairs], ['get', $keys], ['clear', $namespaces],
            ['set', []], ['get', []], ['clear', []],
        ], $backend->calls);
        self::assertSame($object, $found[0]['value']);
        self::assertSame([$pairs, $keys, $namespaces], $before);
        self::assertSame($object, $pairs[0]['value']);
    }

    public function testOnlyTheBackendSerializesAndMissingAndTtlValuesKeepTheirSemantics(): void
    {
        $serde = new CountingFacadeSerde();
        $backend = new InMemoryCache($serde);
        $facade = new AsyncBatchedCache($backend);
        self::assertInstanceOf(JsonPlusSerializer::class, $facade->serde);
        self::assertNotSame($serde, $facade->serde);
        $facade->serde = new class () extends BaseCheckpointSerializer {
            public function dumpsTyped(mixed $data): array
            {
                throw new \LogicException('Facade must not serialize');
            }
            public function loadsTyped(string $type, string $data): mixed
            {
                throw new \LogicException('Facade must not deserialize');
            }
        };
        $object = (object) ['literal' => ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_LITERAL']]];
        $facade->set([
            ['key' => [['n'], 'forever'], 'value' => $object, 'ttl' => null],
            ['key' => [['n'], 'default'], 'value' => null],
            ['key' => [['n'], 'expired'], 'value' => 'SYNTH_EXPIRED', 'ttl' => 0],
        ]);
        $found = $facade->get([[['n'], 'forever'], [['n'], 'default'], [['n'], 'expired'], [['n'], 'missing']]);
        self::assertSame(3, $serde->dumps);
        self::assertSame(2, $serde->loads);
        self::assertSame([
            ['key' => [['n'], 'forever'], 'value' => $object],
            ['key' => [['n'], 'default'], 'value' => null],
        ], $found);
        self::assertSame($object, $found[0]['value']);
        $facade->clear([['n']]);
        self::assertSame([], $facade->get([[['n'], 'forever']]));
    }

    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        foreach (['get', 'set', 'clear'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('operations')]
    public function testFailureKeepsItsIdentityAndDoesNotPoisonLaterCallsOrStop(string $operation): void
    {
        $backend = new RecordingFacadeBackend();
        $backend->failure = new \RuntimeException('SYNTH_FAILURE');
        $backend->failOperation = $operation;
        $facade = new AsyncBatchedCache($backend);
        try {
            $facade->{$operation}([]);
            self::fail('Expected the synthetic backend failure');
        } catch (\RuntimeException $e) {
            self::assertSame($backend->failure, $e);
        }
        $backend->failOperation = null;
        $facade->stop();
        $facade->stop();
        $facade->set([]);
        self::assertSame([], $facade->get([]));
        $facade->clear([]);
        self::assertSame([[$operation, []], ['set', []], ['get', []], ['clear', []]], $backend->calls);
        self::assertSame(0, $backend->lifecycleCalls);
    }

    public function testStopIsRepeatableAndWrappingAnotherFacadeStillDelegates(): void
    {
        $backend = new RecordingFacadeBackend();
        $inner = new AsyncBatchedCache($backend);
        $outer = new AsyncBatchedCache($inner);
        self::assertNotSame($inner, $outer);
        $outer->stop();
        $outer->stop();
        $outer->set([]);
        $outer->get([]);
        $outer->clear([]);
        self::assertSame([['set', []], ['get', []], ['clear', []]], $backend->calls);
        self::assertSame(0, $backend->lifecycleCalls);
    }

    private static function directLoop(?BaseCache $cache, bool $positional = false): PregelLoop
    {
        $config = new RunnableConfig();
        $args = [null, $config, null, new Checkpoint(), [], [], [], $config, [], 0, 3];
        if ($positional) {
            foreach (array_slice((new \ReflectionMethod(PregelLoop::class, '__construct'))->getParameters(), 11) as $parameter) {
                $args[] = $parameter->getName() === 'cache' ? $cache : $parameter->getDefaultValue();
            }

            return new PregelLoop(...$args);
        }

        return new PregelLoop(
            input: null, config: $config, checkpointer: null, checkpoint: new Checkpoint(),
            checkpointMetadata: [], checkpointPreviousVersions: [], checkpointPendingWrites: [],
            checkpointConfig: $config, channels: [], step: 0, stop: 3, cache: $cache,
        );
    }

    public function testDirectNamedPositionalAndInitializeInstallAFacadeAndKeepThePublicAbi(): void
    {
        $backend = new RecordingFacadeBackend();
        $loops = [self::directLoop($backend), self::directLoop($backend, true), PregelLoop::initialize([
            'config' => new RunnableConfig(), 'channelSpecs' => [], 'cache' => $backend,
        ])];
        foreach ($loops as $loop) {
            self::assertNotSame($backend, $loop->cache);
            self::assertInstanceOf(AsyncBatchedCache::class, $loop->cache);
            $loop->cache->set([]);
        }
        self::assertSame([['set', []], ['set', []], ['set', []]], $backend->calls);
        self::assertNull(self::directLoop(null)->cache);
        self::assertNull(PregelLoop::initialize(['config' => new RunnableConfig(), 'channelSpecs' => []])->cache);
        $type = (new \ReflectionProperty(PregelLoop::class, 'cache'))->getType();
        self::assertSame(BaseCache::class, $type?->getName());
        self::assertTrue($type?->allowsNull());
        $loops[0]->cache = $backend;
        self::assertSame($backend, $loops[0]->cache, 'The existing public mutable BaseCache ABI is retained');
        $inner = new AsyncBatchedCache($backend);
        $again = self::directLoop($inner);
        self::assertInstanceOf(AsyncBatchedCache::class, $again->cache);
        self::assertNotSame($inner, $again->cache);
    }

    public function testRealStateGraphHitsClearSharingOverridesAndFailureAreUnchanged(): void
    {
        $backend = new InMemoryCache();
        $calls = 0;
        $make = static fn (): StateGraph => new StateGraph(Annotation::root(['x' => Annotation::last()]));
        $builder = static function () use ($make, &$calls): StateGraph {
            return $make()->addNode('cached', static function (array $state) use (&$calls): array {
                ++$calls;

                return ['x' => $state['x'] + 1];
            }, ['cachePolicy' => ['ttl' => 60]])->addEdge(Constants::START, 'cached')->addEdge('cached', Constants::END);
        };
        $graph = $builder()->compile(['cache' => $backend]);
        self::assertSame($backend, $graph->cache);
        self::assertSame(['x' => 2], $graph->invoke(['x' => 1]));
        self::assertSame(['x' => 2], $graph->invoke(['x' => 1]));
        self::assertSame(1, $calls);
        $shared = $builder()->compile(['cache' => $backend]);
        self::assertSame(['x' => 2], $shared->invoke(['x' => 1]));
        self::assertSame(1, $calls);
        $override = new InMemoryCache();
        $config = new RunnableConfig(configurable: [Constants::CONFIG_KEY_CACHE => $override]);
        self::assertSame(['x' => 2], $graph->invoke(['x' => 1], $config));
        self::assertSame(['x' => 2], $graph->invoke(['x' => 1], $config));
        self::assertSame(2, $calls);
        self::assertSame([Constants::CONFIG_KEY_CACHE => $override], $config->configurable);
        $graph->clearCache();
        self::assertSame(['x' => 2], $graph->invoke(['x' => 1]));
        self::assertSame(3, $calls);
        self::assertSame($backend, $graph->cache);
        $failure = new \RuntimeException('SYNTH_NODE_FAILURE');
        $attempts = 0;
        $flaky = $make()->addNode('flaky', static function (array $state) use (&$attempts, $failure): array {
            if (++$attempts === 1) {
                throw $failure;
            }

            return ['x' => 3];
        }, ['cachePolicy' => ['ttl' => 60]])->addEdge(Constants::START, 'flaky')->addEdge('flaky', Constants::END)->compile(['cache' => new InMemoryCache()]);
        try {
            $flaky->invoke(['x' => 1]);
            self::fail('Expected node failure');
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame(['x' => 3], $flaky->invoke(['x' => 1]));
        self::assertSame(['x' => 3], $flaky->invoke(['x' => 1]));
        self::assertSame(2, $attempts);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function fanoutCases(): iterable
    {
        yield 'cached' => [true, false];
        yield 'cached slow synchronous' => [true, true];
        yield 'uncached' => [false, false];
    }

    /**
     * Acyclic adaptation of LG pregel.test.ts3118–3240 cache controls.
     * The exact cyclic waiting-edge fixture is retained as a failing scratch
     * comparison: the existing PHP join never schedules its second decider.
     * This positive control exercises one fanout/join/qa pass; scheduling and
     * the source's 100ms concurrent delay are not asserted (PHP uses 1ms).
     */
    #[DataProvider('fanoutCases')]
    public function testSourceSlowCacheFanoutResultsAndCachedStreamMetadata(bool $cached, bool $slow): void
    {
        $cache = $slow ? new SlowFacadeCache() : new InMemoryCache();
        $count = 0;
        $state = Annotation::root([
            'query' => Annotation::last(), 'answer' => Annotation::last(),
            'docs' => Annotation::withReducer(static function (array $a, array $b): array {
                $result = [...$a, ...$b];
                sort($result);

                return $result;
            }, static fn (): array => []),
        ]);
        $graph = (new StateGraph($state))
            ->addNode('rewrite_query', static function (array $state) use (&$count): array {
                ++$count;

                return ['query' => 'query: ' . $state['query']];
            }, $cached ? ['cachePolicy' => ['ttl' => 60]] : [])
            ->addNode('analyzer_one', static fn (array $state): array => ['query' => 'analyzed: ' . $state['query']])
            ->addNode('retriever_one', static fn (): array => ['docs' => ['doc1', 'doc2']])
            ->addNode('retriever_two', static function (): array {
                usleep(1000);

                return ['docs' => ['doc3', 'doc4']];
            })
            ->addNode('qa', static fn (array $state): array => ['answer' => implode(',', $state['docs'])])
            ->addNode('decider', static fn (): array => [])
            ->addEdge(Constants::START, 'rewrite_query')->addEdge('rewrite_query', 'analyzer_one')
            ->addEdge('analyzer_one', 'retriever_one')->addEdge('rewrite_query', 'retriever_two')
            ->addEdge(['retriever_one', 'retriever_two'], 'decider')
            ->addEdge('decider', 'qa')->addEdge('qa', Constants::END)
            ->compile(['cache' => $cache]);
        $input = ['query' => 'what is weather in sf'];
        $expected = [
            'query' => 'analyzed: query: what is weather in sf',
            'answer' => 'doc1,doc2,doc3,doc4',
            'docs' => ['doc1', 'doc2', 'doc3', 'doc4'],
        ];
        self::assertEquals($expected, $graph->invoke($input));
        $updates = [];
        foreach ($graph->stream($input, new RunnableConfig(options: ['streamMode' => 'updates'])) as [, $update]) {
            $updates[] = $update;
        }
        $rewrites = array_values(array_filter($updates, static fn (array $u): bool => isset($u['rewrite_query'])));
        self::assertCount(1, $rewrites);
        foreach ($rewrites as $rewrite) {
            self::assertSame($cached, $rewrite['__metadata__']['cached'] ?? false);
        }
        self::assertSame($cached ? 1 : 2, $count);
        if ($cached) {
            $graph->clearCache();
            self::assertEquals($expected, $graph->invoke($input));
            self::assertSame(2, $count);
        }
        self::assertSame($cache, $graph->cache);
        if ($slow) {
            self::assertGreaterThan(0, $cache->gets);
            self::assertGreaterThan(0, $cache->sets);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function terminalScenarios(): iterable
    {
        foreach (['success', 'node error', 'cache error', 'interrupt', 'early disposal', 'stop error', 'null cache', 'mutable replacement'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    /** Alias instrumentation is isolated from the REAL facade tests above. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('terminalScenarios')]
    public function testRealProducerStopsItsFacadeAndClosesBothStreams(string $scenario): void
    {
        self::assertFalse(class_exists(AsyncBatchedCache::class, false));
        class_alias(CacheLifecycleSentinel::class, AsyncBatchedCache::class);
        $backend = new RecordingFacadeBackend();
        $error = new \RuntimeException('SYNTH_TERMINAL_FAILURE');
        if ($scenario === 'cache error') {
            $backend->failure = $error;
            $backend->failOperation = 'get';
        }
        if ($scenario === 'stop error') {
            CacheLifecycleSentinel::$stopError = $error;
        }
        $calls = 0;
        $builder = (new StateGraph(Annotation::root(['x' => Annotation::last()])))
            ->addNode('work', static function (array $state) use (&$calls, $scenario, $error): array {
                ++$calls;
                if ($scenario === 'node error') {
                    throw $error;
                }

                return ['x' => $state['x'] + 1];
            }, ['cachePolicy' => ['ttl' => 60]])
            ->addEdge(Constants::START, 'work')->addEdge('work', Constants::END);
        $params = ['cache' => $scenario === 'null cache' ? null : $backend];
        if ($scenario === 'interrupt') {
            $params += ['checkpointer' => new MemorySaver(), 'interruptBefore' => ['work']];
        }
        $graph = $builder->compile($params);
        $parent = new StreamProtocol(['values', 'updates'], static function (array $chunk): void {});
        $config = new RunnableConfig(
            configurable: [Constants::CONFIG_KEY_STREAM => $parent, 'thread_id' => 'SYNTH_THREAD'],
            options: ['streamMode' => ['values', 'updates']],
        );
        $generator = $graph->stream(['x' => 1], $config);
        $captures = (new \ReflectionGenerator($generator))->getFunction()->getStaticVariables();
        $stream = $captures['stream'];
        $protocol = $captures['protocol'];
        self::assertNotSame($stream, $protocol, 'Actual producer uses its local stream plus the duplex protocol');
        self::assertFalse($stream->isClosed());
        self::assertFalse($protocol->isClosed());
        if ($scenario === 'mutable replacement') {
            $captures['loop']->cache = $backend;
        }
        $caught = null;
        if ($scenario === 'early disposal') {
            $generator->rewind();
            while ($calls === 0 && $generator->valid()) {
                $generator->next();
            }
            self::assertSame(1, $calls);
            self::assertTrue($generator->valid());
            self::assertFalse($stream->isClosed());
            unset($generator);
            gc_collect_cycles();
        } else {
            try {
                foreach ($generator as $chunk) {
                }
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
        }
        self::assertSame(in_array($scenario, ['node error', 'cache error', 'stop error'], true) ? $error : null, $caught);
        self::assertSame($scenario === 'null cache' ? 0 : 1, CacheLifecycleSentinel::$constructed);
        self::assertSame(in_array($scenario, ['null cache', 'mutable replacement'], true) ? 0 : 1, CacheLifecycleSentinel::$stops);
        self::assertTrue($stream->isClosed());
        self::assertTrue($protocol->isClosed(), 'A stop exception must not bypass stream closure');
        self::assertFalse($parent->isClosed(), 'A borrowed parent stream stays open');
        self::assertSame(0, $backend->lifecycleCalls);
        self::assertSame($params['cache'], $graph->cache);
        if ($scenario === 'interrupt') {
            self::assertSame(0, $calls);
        }
    }
}

/** File-local backend with intentionally observable ownership and exact-array forwarding. */
class RecordingFacadeBackend extends BaseCache
{
    public array $calls = [];
    public array $result = [];
    public ?string $failOperation = null;
    public ?\RuntimeException $failure = null;
    public int $lifecycleCalls = 0;

    private function record(string $operation, array $args): void
    {
        $this->calls[] = [$operation, $args];
        if ($operation === $this->failOperation) {
            throw $this->failure;
        }
    }

    public function get(array $keys): array
    {
        $this->record('get', $keys);

        return $this->result;
    }
    public function set(array $pairs): void
    {
        $this->record('set', $pairs);
    }
    public function clear(array $namespaces): void
    {
        $this->record('clear', $namespaces);
    }
    public function stop(): void
    {
        ++$this->lifecycleCalls;

        throw new \LogicException('Must not stop borrowed backend');
    }
    public function close(): void
    {
        ++$this->lifecycleCalls;

        throw new \LogicException('Must not close borrowed backend');
    }
}

final class CountingFacadeSerde extends BaseCheckpointSerializer
{
    public int $dumps = 0;
    public int $loads = 0;
    private array $values = [];

    public function dumpsTyped(mixed $data): array
    {
        $payload = (string) ++$this->dumps;
        $this->values[$payload] = $data;

        return ['SYNTH_TYPE', $payload];
    }
    public function loadsTyped(string $type, string $data): mixed
    {
        ++$this->loads;

        return $this->values[$data];
    }
}

/** Synchronous delays deliberately do not replicate the source's Promise/fanout schedule. */
final class SlowFacadeCache extends InMemoryCache
{
    public int $gets = 0;
    public int $sets = 0;
    public function get(array $keys): array
    {
        ++$this->gets;
        usleep(1000);

        return parent::get($keys);
    }
    public function set(array $pairs): void
    {
        ++$this->sets;
        usleep(1000);
        parent::set($pairs);
    }
}

/** Test-only shadow used in fresh processes; does not stand in for real-facade correctness. */
final class CacheLifecycleSentinel extends BaseCache
{
    public static int $constructed = 0;
    public static int $stops = 0;
    public static ?\RuntimeException $stopError = null;
    public function __construct(private BaseCache $backend)
    {
        parent::__construct();
        ++self::$constructed;
    }
    public function get(array $keys): array
    {
        return $this->backend->get($keys);
    }
    public function set(array $pairs): void
    {
        $this->backend->set($pairs);
    }
    public function clear(array $namespaces): void
    {
        $this->backend->clear($namespaces);
    }
    public function stop(): void
    {
        ++self::$stops;
        if (self::$stopError !== null) {
            throw self::$stopError;
        }
    }
}
