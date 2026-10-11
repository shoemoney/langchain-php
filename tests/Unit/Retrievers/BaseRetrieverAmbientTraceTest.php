<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Retrievers;

use LangChain\Embeddings\Embeddings;
use LangChain\ExampleSelectors\SemanticSimilarityExampleSelector;
use LangChain\Retrievers\BaseRetriever;
use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;
use LangChain\Schema\Document;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Tracers\CallbackHandler;
use LangChain\Tracers\CallbackManagerForRetrieverRun;
use LangChain\Tracers\Serialized;
use LangChain\Utils\AsyncLocalStorage;
use LangChain\VectorStores\MemoryVectorStore;
use LangGraph\Pregel\Constants;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Field adoption only. Graph/sequence invoke ancestry is held N18b/N16,
 * including unopened caller parents; these tests do not claim graph parity.
 */
#[CoversClass(BaseRetriever::class)]
final class BaseRetrieverAmbientTraceTest extends TestCase
{
    private const R = '00000000-0000-4000-8000-000000000001';
    private const P = 'SYNTH_PARENT';
    private ?AsyncLocalStorage $previousStorage;
    private AsyncLocalStorage $storage;
    private static array $graphLeafIds = [];

    protected function setUp(): void
    {
        $this->previousStorage = AsyncLocalStorage::getGlobalInstance();
        $this->storage = new AsyncLocalStorage();
        AsyncLocalStorage::setGlobalInstance($this->storage);
    }

    protected function tearDown(): void
    {
        AsyncLocalStorage::setGlobalInstance($this->previousStorage);
    }

    /** Capture every run kind, not just chains, so unexpected ancestry cannot hide. */
    private static function recorder(array &$events, string $name): CallbackHandler
    {
        $methods = [];
        foreach ((new \ReflectionClass(BaseCallbackHandler::class))->getMethods() as $method) {
            $kind = $method->name;
            if (!str_starts_with($kind, 'handle')) {
                continue;
            }
            if (str_ends_with($kind, 'Start')) {
                $methods[$kind] = static function (...$args) use (&$events, $kind): void {
                    $events[] = [
                        'kind' => $kind,
                        'id' => $args[2],
                        'parent' => $args[$kind === 'handleChainStart' ? 7 : 3] ?? null,
                        'serialized' => $args[0],
                        'input' => $args[1],
                        'tags' => $args[$kind === 'handleChainStart' ? 4 : ($kind === 'handleLLMStart' || $kind === 'handleChatModelStart' ? 5 : 4)] ?? [],
                        'metadata' => $args[$kind === 'handleChainStart' ? 5 : ($kind === 'handleLLMStart' || $kind === 'handleChatModelStart' ? 6 : 5)] ?? [],
                        'name' => $args[$kind === 'handleChainStart' ? 6 : ($kind === 'handleLLMStart' || $kind === 'handleChatModelStart' ? 7 : 6)] ?? null,
                    ];
                };
            } elseif (str_ends_with($kind, 'End') || str_ends_with($kind, 'Error')) {
                $methods[$kind] = static function (...$args) use (&$events, $kind): void {
                    $events[] = ['kind' => $kind, 'id' => $args[1], 'parent' => $args[2] ?? null, 'value' => $args[0]];
                };
            }
        }

        return new CallbackHandler($methods, ['name' => $name, 'raiseError' => true]);
    }

    private static function retriever(array $fields = []): BaseRetriever
    {
        return new class($fields) extends BaseRetriever {
            public ?\Throwable $failure = null;
            public array $managers = [];

            public static function lcName(): string
            {
                return 'SYNTHRetriever';
            }

            public static function lcNamespace(): array
            {
                return ['SYNTH', 'retriever'];
            }

            protected function getRelevantDocuments(string $query, ?CallbackManagerForRetrieverRun $runManager = null): array
            {
                $this->managers[] = $runManager;
                if ($query === 'failure') {
                    throw $this->failure;
                }

                return [new Document('d:' . $query, ['query' => $query])];
            }
        };
    }

    private function assertPair(array $events, ?string $parent, ?\Throwable $failure = null): string
    {
        self::assertSame(['handleRetrieverStart', $failure === null ? 'handleRetrieverEnd' : 'handleRetrieverError'], array_column($events, 'kind'));
        self::assertNotSame(self::R, $events[0]['id']);
        self::assertNotEmpty($events[0]['id']);
        self::assertSame($parent, $events[0]['parent']);
        self::assertSame($events[0]['id'], $events[1]['id']);
        self::assertSame($parent, $events[1]['parent']);
        if ($failure !== null) {
            self::assertSame($failure, $events[1]['value']);
        }

        return $events[0]['id'];
    }

    public static function omittedCallers(): array
    {
        return ['omitted' => [null], 'constructor empty omission' => [new RunnableConfig()], 'loose empty' => [RunnableConfig::fromArray([])]];
    }

    #[DataProvider('omittedCallers')]
    public function testOmittedCallerAdoptsAmbientFieldsWithFreshLeafIdentity(?RunnableConfig $caller): void
    {
        $events = [];
        $handler = self::recorder($events, 'SYNTH_ambient');
        $ambient = new RunnableConfig(callbacks: [$handler], tags: ['ambient'], metadata: ['ambient' => 1], runId: [self::R], runName: 'implicit-name', runIdParent: self::P);
        $before = get_object_vars($ambient);
        $callerBefore = $caller === null ? null : get_object_vars($caller);
        $retriever = self::retriever();
        $ids = [];
        foreach (['first', 'second'] as $query) {
            $events = [];
            $docs = AsyncLocalStorage::runWithConfig($ambient, fn () => $retriever->invoke($query, $caller));
            $ids[] = $this->assertPair($events, self::P);
            self::assertSame($query, $events[0]['input']);
            self::assertInstanceOf(Serialized::class, $events[0]['serialized']);
            self::assertSame($retriever->kwargs(), $events[0]['serialized']->kwargs);
            self::assertSame(['ambient'], $events[0]['tags']);
            self::assertSame(['ambient' => 1], $events[0]['metadata']);
            self::assertNotSame('implicit-name', $events[0]['name']);
            self::assertSame('d:' . $query, $docs[0]->pageContent);
            self::assertSame($docs, $events[1]['value']);
            self::assertSame($events[0]['id'], $retriever->managers[array_key_last($retriever->managers)]->runId);
            self::assertSame(self::P, $retriever->managers[array_key_last($retriever->managers)]->parentRunId);
            self::assertNull($this->storage->getStore());
        }
        self::assertNotSame($ids[0], $ids[1]);
        self::assertSame($before, get_object_vars($ambient));
        self::assertSame($callerBefore, $caller === null ? null : get_object_vars($caller));
    }

    public static function callerFieldShapes(): array
    {
        return [
            'constructor empty inherits' => ['constructor', true, ['ambient', 'local'], ['ambient' => 1, 'shared' => 'local', 'local' => 1]],
            'tracked with empty clears' => ['with-clear', false, ['local'], ['shared' => 'local', 'local' => 1]],
            'tracked empty clears' => ['clear', false, ['local'], ['shared' => 'local', 'local' => 1]],
            'whole caller replacement' => ['replace', false, ['caller', 'local'], ['caller' => 1, 'shared' => 'local', 'local' => 1]],
            'known null cannot clear parent' => ['null', true, ['ambient', 'local'], ['ambient' => 1, 'shared' => 'local', 'local' => 1]],
        ];
    }

    #[DataProvider('callerFieldShapes')]
    public function testCallerWholeFieldsAndTrackedEmptyPreserveLocalHandlers(string $shape, bool $ambientExpected, array $tags, array $metadata): void
    {
        $ambientEvents = [];
        $localEvents = [];
        $callerEvents = [];
        $ambientHandler = self::recorder($ambientEvents, 'SYNTH_ambient');
        $localHandler = self::recorder($localEvents, 'SYNTH_local');
        $callerHandler = self::recorder($callerEvents, 'SYNTH_caller');
        $ambient = new RunnableConfig(callbacks: [$ambientHandler], tags: ['ambient'], metadata: ['ambient' => 1, 'shared' => 'ambient'], runIdParent: self::P);
        $caller = match ($shape) {
            'with-clear' => (new RunnableConfig())->with(['callbacks' => [], 'tags' => [], 'metadata' => []]),
            'clear' => RunnableConfig::fromArray(['callbacks' => [], 'tags' => [], 'metadata' => []]),
            'replace' => new RunnableConfig(callbacks: [$callerHandler], tags: ['caller'], metadata: ['caller' => 1, 'shared' => 'caller']),
            'null' => RunnableConfig::fromArray(['callbacks' => null, 'tags' => null, 'metadata' => null, 'runIdParent' => null]),
            default => new RunnableConfig(callbacks: [], tags: [], metadata: []),
        };
        $retriever = self::retriever(['callbacks' => [$localHandler], 'tags' => ['local'], 'metadata' => ['shared' => 'local', 'local' => 1]]);
        $before = get_object_vars($ambient);
        $callerBefore = get_object_vars($caller);
        $localBefore = [$retriever->callbacks, $retriever->tags, $retriever->metadata];
        AsyncLocalStorage::runWithConfig($ambient, fn () => $retriever->invoke('query', $caller));
        $id = $this->assertPair($localEvents, self::P);
        self::assertSame($tags, $localEvents[0]['tags']);
        self::assertSame($metadata, $localEvents[0]['metadata']);
        if ($ambientExpected) {
            self::assertSame($id, $this->assertPair($ambientEvents, self::P));
        } else {
            self::assertSame([], $ambientEvents);
        }
        if ($shape === 'replace') {
            // PHP's separate typed parent survives caller callback-array replacement.
            self::assertSame($id, $this->assertPair($callerEvents, self::P));
        } else {
            self::assertSame([], $callerEvents);
        }
        self::assertSame($before, get_object_vars($ambient));
        self::assertSame($callerBefore, get_object_vars($caller));
        self::assertSame($localBefore, [$retriever->callbacks, $retriever->tags, $retriever->metadata]);
        self::assertSame([$localHandler], $retriever->callbacks);
        self::assertNull($this->storage->getStore());
    }

    public function testExplicitCallerIdentityNameAndNonNullParentWin(): void
    {
        $ambientEvents = [];
        $events = [];
        $ambient = new RunnableConfig(callbacks: [self::recorder($ambientEvents, 'SYNTH_ambient')], runId: ['implicit'], runName: 'implicit-name', runIdParent: self::P);
        $caller = new RunnableConfig(callbacks: [self::recorder($events, 'SYNTH_caller')], runId: ['SYNTH_EXPLICIT'], runName: 'explicit-name', runIdParent: 'explicit-parent');
        $before = get_object_vars($caller);
        AsyncLocalStorage::runWithConfig($ambient, fn () => self::retriever()->invoke(123, $caller));
        self::assertSame(['handleRetrieverStart', 'handleRetrieverEnd'], array_column($events, 'kind'));
        foreach ($events as $event) {
            self::assertSame('SYNTH_EXPLICIT', $event['id']);
            self::assertSame('explicit-parent', $event['parent']);
        }
        self::assertSame('123', $events[0]['input']);
        self::assertSame('explicit-name', $events[0]['name']);
        self::assertSame([], $ambientEvents);
        self::assertSame($before, get_object_vars($caller));
        self::assertNull($this->storage->getStore());
    }

    public function testOriginalFailureRestoresOuterScopeAndDefaultStreamIsConsumedInScope(): void
    {
        $events = [];
        $local = [];
        $ambient = new RunnableConfig(callbacks: [self::recorder($events, 'SYNTH_ambient')], runIdParent: self::P);
        $before = get_object_vars($ambient);
        $retriever = self::retriever(['callbacks' => [self::recorder($local, 'SYNTH_local')]]);
        $failure = new \RuntimeException('SYNTH_FAILURE');
        $retriever->failure = $failure;
        $outer = ['SYNTH_outer' => true];
        $caught = null;
        $this->storage->run($outer, function () use ($ambient, $retriever, $failure, $outer, &$caught): void {
            try {
                AsyncLocalStorage::runWithConfig($ambient, fn () => $retriever->invoke('failure'));
            } catch (\Throwable $error) {
                $caught = $error;
            }
            self::assertSame($failure, $caught);
            self::assertSame($outer, $this->storage->getStore());
        });
        $id = $this->assertPair($events, self::P, $failure);
        self::assertSame($id, $this->assertPair($local, self::P, $failure));
        self::assertNull($this->storage->getStore());
        $events = [];
        $local = [];
        $chunks = AsyncLocalStorage::runWithConfig($ambient, fn () => iterator_to_array($retriever->stream('recovered'), false));
        $id = $this->assertPair($events, self::P);
        self::assertSame($id, $this->assertPair($local, self::P));
        self::assertCount(1, $chunks);
        self::assertSame('d:recovered', $chunks[0][1][0]->pageContent);
        self::assertSame($before, get_object_vars($ambient));
        self::assertNull($this->storage->getStore());
        self::assertNull(AsyncLocalStorage::getRunnableConfig());
    }

    public function testNoAmbientDirectLocalLifecycleRemainsAvailable(): void
    {
        $events = [];
        $handler = self::recorder($events, 'SYNTH_local');
        $retriever = self::retriever(['callbacks' => [$handler], 'tags' => ['local']]);
        $documents = $retriever->invoke('standalone');
        $this->assertPair($events, null);
        self::assertSame(['local'], $events[0]['tags']);
        self::assertSame('d:standalone', $documents[0]->pageContent);
        self::assertSame([$handler], $retriever->callbacks);
        self::assertNull($this->storage->getStore());
    }

    public static function graphShapes(): array
    {
        $cases = [];
        foreach (['raw', 'lambda'] as $kind) {
            foreach (['none', 'parent', 'id'] as $shape) {
                foreach ([false, true] as $error) {
                    $cases[$kind . '-' . $shape . '-' . ($error ? 'error' : 'success')] = [$kind, $shape, $error];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('graphShapes')]
    public function testHeldDirectGraphInvokeLeafCanUseNullParentOuterParentOrUnopenedCallerRoot(string $kind, string $shape, bool $error): void
    {
        $this->assertGraphCase('invoke', $kind, $shape, $error);
    }

    #[DataProvider('graphShapes')]
    public function testHeldN18bN16SequenceGraphLeafUsesNullParentOuterParentOrUnopenedCallerRoot(string $kind, string $shape, bool $error): void
    {
        $this->assertGraphCase('sequence', $kind, $shape, $error);
    }

    public static function streamGraphShapes(): array
    {
        $cases = [];
        foreach (['v1', 'v2'] as $version) {
            foreach (self::graphShapes() as $name => $args) {
                $cases[$version . '-' . $name] = [$version, ...$args];
            }
        }

        return $cases;
    }

    #[DataProvider('streamGraphShapes')]
    public function testGraphEventStreamsHaveOneActualRootAndDirectLeafWithOriginalFailure(string $version, string $kind, string $shape, bool $error): void
    {
        $this->assertGraphCase($version, $kind, $shape, $error);
    }

    /** These public graph calls do not seed a node scope or normalize at the caller. */
    private function assertGraphCase(string $mode, string $kind, string $shape, bool $error): void
    {
        $events = [];
        $retriever = self::retriever();
        $failure = new \RuntimeException('SYNTH_FAILURE');
        $retriever->failure = $failure;
        $node = static fn (array $state): array => ['ctx' => count($retriever->invoke($state['q']))];
        $graph = (new StateGraph(['q' => Annotation::last(), 'ctx' => Annotation::last()]))
            ->addNode('retrieve', $kind === 'raw' ? $node : RunnableLambda::from($node))
            ->addEdge(Constants::START, 'retrieve')
            ->addEdge('retrieve', Constants::END)
            ->compile();
        $config = new RunnableConfig(callbacks: [self::recorder($events, 'SYNTH_caller')], tags: ['SYNTH_caller'], metadata: ['SYNTH_key' => 'value']);
        $config = $config->with(match ($shape) {
            'id' => ['runId' => [self::R]],
            'parent' => ['runIdParent' => self::P],
            default => [],
        });
        $before = get_object_vars($config);
        $caught = null;
        $result = null;
        $wire = [];
        try {
            $input = ['q' => $error ? 'failure' : 'query'];
            if ($mode === 'invoke') {
                $result = $graph->invoke($input, $config);
            } elseif ($mode === 'sequence') {
                $result = (new RunnableSequence([RunnableLambda::from(static fn ($value) => $value), $graph]))->invoke($input, $config);
            } else {
                foreach ($graph->streamEvents($input, $config, $mode) as $event) {
                    $wire[] = $event->toArray();
                }
            }
        } catch (\Throwable $thrown) {
            $caught = $thrown;
        }
        self::assertSame($error ? $failure : null, $caught);
        self::assertSame($before, get_object_vars($config));
        self::assertNull($this->storage->getStore());
        $leaves = array_values(array_filter($events, static fn ($event) => str_starts_with($event['kind'], 'handleRetriever')));
        $other = array_values(array_filter($events, static fn ($event) => !str_starts_with($event['kind'], 'handleRetriever')));
        if ($mode === 'invoke' || $mode === 'sequence') {
            self::assertSame([], $other, 'Held invoke opens no sequence/graph/node or other run kind');
            $parent = $shape === 'parent' ? self::P : ($shape === 'id' && ($mode === 'sequence' || $kind === 'raw') ? self::R : null);
            if (!$error) {
                self::assertSame(['q' => 'query', 'ctx' => 1], $result);
            }
        } else {
            self::assertSame(['handleChainStart', $error ? 'handleChainError' : 'handleChainEnd'], array_column($other, 'kind'), 'Exactly one actual Pregel root and no node/Lambda/other frame');
            $root = $other[0];
            self::assertSame($shape === 'parent' ? self::P : null, $root['parent']);
            if ($shape === 'id') {
                self::assertSame(self::R, $root['id']);
            }
            self::assertSame($root['id'], $other[1]['id']);
            self::assertSame($root['parent'], $other[1]['parent']);
            if ($error) {
                self::assertSame($failure, $other[1]['value']);
            }
            $parent = $root['id'];
            foreach ($wire as $event) {
                self::assertArrayNotHasKey('parent_ids', $event);
            }
            if (!$error) {
                self::assertSame(['on_retriever_start', 'on_retriever_end'], array_column(array_values(array_filter($wire, static fn ($event) => str_starts_with($event['event'], 'on_retriever_'))), 'event'));
            }
        }
        $id = $this->assertPair($leaves, $parent, $error ? $failure : null);
        self::assertNotContains($id, self::$graphLeafIds, 'Independently fresh across the graph matrix');
        self::$graphLeafIds[] = $id;
        self::assertCount(1, $retriever->managers);
        self::assertSame($id, $retriever->managers[0]->runId);
        self::assertSame($parent, $retriever->managers[0]->parentRunId);
        self::assertSame(['SYNTH_caller', ...($mode === 'sequence' ? ['seq:step:2'] : []), ...($kind === 'raw' ? ['seq:step:1'] : [])], $leaves[0]['tags']);
        self::assertSame('retrieve', $leaves[0]['metadata']['langgraph_node']);
        self::assertSame(1, $leaves[0]['metadata']['langgraph_step']);
        self::assertSame(['branch:to:retrieve'], $leaves[0]['metadata']['langgraph_triggers']);
        self::assertSame(['__pregel_pull', 'retrieve'], $leaves[0]['metadata']['langgraph_path']);
        self::assertSame('value', $leaves[0]['metadata']['SYNTH_key']);
        self::assertNotEmpty($leaves[0]['metadata']['langgraph_checkpoint_ns']);
        self::assertSame($error ? 'failure' : 'query', $leaves[0]['input']);
        if (!$error) {
            self::assertCount(1, $leaves[1]['value']);
            self::assertSame('d:query', $leaves[1]['value'][0]->pageContent);
            self::assertSame(['query' => 'query'], $leaves[1]['value'][0]->metadata);
        }
    }

    public static function nullOrExplicitStreamCallers(): array
    {
        $cases = [];
        foreach (['v1', 'v2'] as $version) {
            foreach (['raw', 'lambda'] as $kind) {
                foreach ([false, true] as $explicit) {
                    $cases[$version . '-' . $kind . '-' . ($explicit ? 'explicit' : 'null')] = [$version, $kind, $explicit];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('nullOrExplicitStreamCallers')]
    public function testGraphStreamsRetainNullCallerAndExplicitCallbackControls(string $version, string $kind, bool $explicit): void
    {
        $events = [];
        $retriever = self::retriever();
        $node = static fn (array $state): array => ['ctx' => count($retriever->invoke($state['q']))];
        $graph = (new StateGraph(['q' => Annotation::last(), 'ctx' => Annotation::last()]))
            ->addNode('retrieve', $kind === 'raw' ? $node : RunnableLambda::from($node))
            ->addEdge(Constants::START, 'retrieve')
            ->addEdge('retrieve', Constants::END)
            ->compile();
        $config = $explicit ? new RunnableConfig(callbacks: [self::recorder($events, 'SYNTH_caller')]) : null;
        $wire = [];
        foreach ($graph->streamEvents(['q' => 'query'], $config, $version) as $event) {
            $wire[] = $event->toArray();
        }
        $roots = array_values(array_filter($wire, static fn ($event) => $event['event'] === 'on_chain_start'));
        self::assertCount(1, $roots);
        $leafWire = array_values(array_filter($wire, static fn ($event) => str_starts_with($event['event'], 'on_retriever_')));
        self::assertSame(['on_retriever_start', 'on_retriever_end'], array_column($leafWire, 'event'));
        self::assertCount(1, $retriever->managers);
        self::assertSame($roots[0]['run_id'], $retriever->managers[0]->parentRunId);
        self::assertSame($leafWire[0]['run_id'], $retriever->managers[0]->runId);
        self::assertSame($leafWire[0]['run_id'], $leafWire[1]['run_id']);
        foreach ($wire as $event) {
            self::assertArrayNotHasKey('parent_ids', $event);
        }
        if ($explicit) {
            self::assertSame(['handleChainStart', 'handleRetrieverStart', 'handleRetrieverEnd', 'handleChainEnd'], array_column($events, 'kind'));
            self::assertNull($events[0]['parent']);
            self::assertNull($events[3]['parent']);
            self::assertSame($events[0]['id'], $events[3]['id']);
            $this->assertPair([$events[1], $events[2]], $events[0]['id']);
        } else {
            self::assertSame([], $events);
        }
        self::assertNull($this->storage->getStore());
    }

    public function testActualSemanticSelectorOmittedConfigPreservesStoreProviderCallerAndLocalFields(): void
    {
        $events = [];
        $local = [];
        $embedding = new class extends Embeddings {
            public array $queries = [];
            public function embedDocuments(array $documents): array
            {
                return array_map(static fn ($text) => $text === 'apple' ? [1.0, 0.0] : [0.0, 1.0], $documents);
            }
            public function embedQuery(string $document): array
            {
                $this->queries[] = $document;

                return [1.0, 0.0];
            }
        };
        $store = new MemoryVectorStore($embedding);
        $selector = new SemanticSimilarityExampleSelector(['vectorStore' => $store, 'k' => 1, 'inputKeys' => ['input'], 'exampleKeys' => ['output']]);
        $selector->addExample(['input' => 'apple', 'output' => 'fruit']);
        $selector->addExample(['input' => 'stone', 'output' => 'mineral']);
        $leaf = $selector->vectorStoreRetriever;
        $handler = self::recorder($local, 'SYNTH_local');
        $leaf->callbacks = [$handler];
        $leaf->tags = ['local'];
        $leaf->metadata = ['shared' => 'local'];
        $ambient = new RunnableConfig(callbacks: [self::recorder($events, 'SYNTH_ambient')], tags: ['ambient'], metadata: ['shared' => 'ambient'], runIdParent: self::P);
        $before = get_object_vars($ambient);
        $storeBefore = $store->memoryVectors;
        $result = AsyncLocalStorage::runWithConfig($ambient, fn () => $selector->selectExamples(['input' => 'apple', 'ignored' => 'not in query']));
        self::assertSame([['output' => 'fruit']], $result);
        self::assertSame(['apple'], $embedding->queries);
        $id = $this->assertPair($events, self::P);
        self::assertSame($id, $this->assertPair($local, self::P));
        self::assertSame(['ambient', 'local'], $events[0]['tags']);
        self::assertSame(['shared' => 'local'], $events[0]['metadata']);
        self::assertSame('apple', $events[0]['input']);
        self::assertSame($storeBefore, $store->memoryVectors);
        self::assertSame($store, $leaf->vectorStore);
        self::assertSame([$handler], $leaf->callbacks);
        self::assertSame($before, get_object_vars($ambient));
        self::assertNull($this->storage->getStore());
    }

    public function testBindingComposesBoundCallerAndFactoriesBeforeLeafFieldAdoption(): void
    {
        $events = [];
        $factoryInputs = [];
        $retriever = self::retriever();
        $handler = self::recorder($events, 'SYNTH_bound');
        $binding = new RunnableBinding($retriever, config: ['callbacks' => [$handler], 'runId' => ['SYNTH_BOUND'], 'runName' => 'bound-name', 'verbose' => true, 'metadata' => ['bound' => 1]], configFactories: [static function (RunnableConfig $config) use (&$factoryInputs): array {
            $factoryInputs[] = get_object_vars($config);

            return ['metadata' => ['factory' => 1]];
        }]);
        $ambient = new RunnableConfig(tags: ['ambient'], metadata: ['ambient' => 1], runId: [self::R]);
        $before = get_object_vars($ambient);
        $caller = new RunnableConfig();
        $callerBefore = get_object_vars($caller);
        AsyncLocalStorage::runWithConfig($ambient, fn () => $binding->invoke('query', $caller));
        self::assertCount(1, $factoryInputs);
        self::assertSame(['SYNTH_BOUND'], $factoryInputs[0]['runId']);
        self::assertTrue($factoryInputs[0]['verbose']);
        self::assertSame('SYNTH_BOUND', $events[0]['id']);
        self::assertSame('bound-name', $events[0]['name']);
        self::assertSame(['bound' => 1, 'factory' => 1], $events[0]['metadata']);
        self::assertSame(['ambient'], $events[0]['tags']);
        self::assertSame(['handleRetrieverStart', 'handleRetrieverEnd'], array_column($events, 'kind'));
        self::assertSame($before, get_object_vars($ambient));
        self::assertSame($callerBefore, get_object_vars($caller));
        self::assertNull($this->storage->getStore());
        $events = [];
        $bound = $retriever->withConfig(['callbacks' => [$handler], 'runId' => ['SYNTH_BOUND'], 'runName' => 'bound-name']);
        $explicit = new RunnableConfig(runId: ['SYNTH_CALLER'], runName: 'caller-name');
        $bound->invoke('query', $explicit);
        self::assertSame('SYNTH_CALLER', $events[0]['id']);
        self::assertSame('caller-name', $events[0]['name']);
        self::assertSame($events[0]['id'], $events[1]['id']);
        self::assertNull($this->storage->getStore());
    }
}
