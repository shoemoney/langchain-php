<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\State;

use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Tools\DynamicTool;
use LangChain\Tracers\CallbackHandler;
use LangChain\Utils\AsyncLocalStorage;
use LangChain\Utils\Testing\FakeStreamingChatModel;
use LangGraph\Channels\AnyValue;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Graph\Graph;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\PregelScratchpad;
use LangGraph\Pregel\Retry\RetryPolicy;
use LangGraph\Pregel\RunControl;
use LangGraph\Pregel\Utils\Config;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use LangGraph\Utils\RunnableCallable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/**
 * Function nodes on a `StateGraph` follow `StateGraph.addNode` in `langgraph-core/src/graph/state.ts`:
 * a runnable is kept as is, and a raw function becomes `RunnableCallable({func, name: key, trace: false})`
 * (recurse defaults to true). So the function is always called as `func($input, $config)` and runs with
 * that same config published as the current one, which is the frame `getWriter()`, `interrupt()` and the
 * context helpers read. A plain {@see Graph} still coerces through `RunnableLambda`, as upstream's
 * `Graph.addNode` does through `_coerceToRunnable`.
 *
 * **PHP difference:** `RunnableCallable` has no signature inspection, so a callable that rejects a second
 * argument (an internal builtin, or a typed non-config second parameter) throws; an explicit
 * `RunnableLambda` keeps Lambda's arity handling.
 *
 * Not covered here: `trace: true` is inert in this port and ordinary callbacks see no graph or node runs
 * (N16), so these tests only pin that the wrapper adds no lifecycle of its own. Picked Lambda publication
 * is held; the returned-Lambda case pins today's frame.
 */
#[CoversClass(StateGraph::class)]
final class StateGraphFunctionNodeContextTest extends TestCase
{
    protected function setUp(): void
    {
        AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage());
    }

    protected function tearDown(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
        PregelScratchpad::setCurrentConfig(null);
    }

    private static function schema(): \LangGraph\State\AnnotationRoot
    {
        return Annotation::root(['value' => Annotation::last()]);
    }

    private static function oneNode(mixed $body, array $options = [], array $compile = []): Pregel
    {
        return (new StateGraph(self::schema()))
            ->addNode('leaf', $body, $options)
            ->addEdge(Constants::START, 'leaf')
            ->compile($compile);
    }

    private static function assertWrapped(StateGraph $graph, string $name, mixed $body): RunnableCallable
    {
        $bound = $graph->nodes[$name]->bound;
        self::assertInstanceOf(RunnableCallable::class, $bound);
        self::assertSame($name, $bound->getName());
        self::assertFalse($bound->trace);
        self::assertTrue($bound->recurse);
        self::assertSame($body, $bound->func);
        self::assertNull($bound->config);

        return $bound;
    }

    public static function entryShapes(): iterable
    {
        yield 'single' => ['single'];
        yield 'record' => ['record'];
        yield 'tuple with options' => ['tuple'];
        yield 'tuple without options' => ['tuple-bare'];
    }

    #[DataProvider('entryShapes')]
    public function testEveryEntryShapeWrapsARawFunctionAsAnUntracedRecursingCallableNamedAfterItsNode(string $shape): void
    {
        $first = static fn (array $state): array => ['value' => 'first'];
        $second = static fn (array $state): array => ['value' => 'second'];
        $graph = new StateGraph(self::schema());
        match ($shape) {
            'single' => $graph->addNode('first', $first, ['metadata' => ['m' => 1]])->addNode('second', $second),
            'record' => $graph->addNode(['first' => $first, 'second' => $second]),
            'tuple' => $graph->addNode([['first', $first, ['metadata' => ['m' => 1]]], ['second', $second, []]]),
            'tuple-bare' => $graph->addNode([['first', $first], ['second', $second]]),
        };

        self::assertWrapped($graph, 'first', $first);
        self::assertWrapped($graph, 'second', $second);
        self::assertSame(in_array($shape, ['single', 'tuple'], true) ? ['m' => 1] : [], $graph->nodes['first']->metadata);
    }

    public static function phpCallables(): iterable
    {
        yield 'closure' => [static fn (array $state): array => ['value' => 'closure:' . func_num_args()], 'closure:2'];
        yield 'first-class callable' => [StateGraphFunctionNodeFixtures::staticNode(...), 'static:2'];
        yield 'named function' => [__NAMESPACE__ . '\\state_graph_function_node_named', 'named:2'];
        yield 'static method string' => [StateGraphFunctionNodeFixtures::class . '::staticNode', 'static:2'];
        yield 'static method array' => [[StateGraphFunctionNodeFixtures::class, 'staticNode'], 'static:2'];
        yield 'instance method array' => [[new StateGraphFunctionNodeFixtures(), 'instanceNode'], 'instance:2'];
        yield 'invokable object' => [new StateGraphFunctionNodeFixtures(), 'invokable:2'];
    }

    #[DataProvider('phpCallables')]
    public function testEverySupportedPhpCallableFormIsWrappedAndCalledWithTheConfig(callable $body, string $expected): void
    {
        $graph = (new StateGraph(self::schema()))->addNode('leaf', $body)->addEdge(Constants::START, 'leaf');
        self::assertWrapped($graph, 'leaf', $body);

        self::assertSame(['value' => $expected], $graph->compile()->invoke(['value' => 'start']));
    }

    public function testExplicitRunnablesAreKeptAsTheExactSuppliedObjectEvenWhenAlsoCallable(): void
    {
        $lambda = RunnableLambda::from(static fn (array $state): array => ['value' => 'lambda']);
        $callable = new RunnableCallable(
            static fn (array $state, RunnableConfig $config): array => ['value' => 'callable'],
            name: 'custom',
            trace: true,
            recurse: false,
        );
        $dual = new StateGraphInvokableRunnableNode();
        self::assertTrue(is_callable($dual));

        $graph = (new StateGraph(self::schema()))
            ->addNode('lambda', $lambda)
            ->addNode(['callable' => $callable])
            ->addNode([['dual', $dual]]);

        self::assertSame($lambda, $graph->nodes['lambda']->bound);
        self::assertSame($callable, $graph->nodes['callable']->bound);
        self::assertSame($dual, $graph->nodes['dual']->bound);
        self::assertSame('custom', $callable->getName());
        self::assertTrue($callable->trace);
        self::assertFalse($callable->recurse);

        $compiled = $graph->addEdge(Constants::START, 'dual')->compile();
        self::assertSame(['value' => 'invoke'], $compiled->invoke(['value' => 'start']));
    }

    public function testPlainGraphKeepsLambdaCoercionForRawFunctions(): void
    {
        $body = static fn (array $state): array => $state;
        $lambda = RunnableLambda::from($body);
        $graph = (new Graph())->addNode('raw', $body)->addNode('supplied', $lambda);

        self::assertInstanceOf(RunnableLambda::class, $graph->nodes['raw']->bound);
        self::assertNotInstanceOf(RunnableCallable::class, $graph->nodes['raw']->bound);
        self::assertSame($lambda, $graph->nodes['supplied']->bound);
    }

    /**
     * Upstream every case below sees `['same']` once per run. Callable merges dedupe tags like source
     * `mergeConfigs`; a Lambda node (explicit, or plain Graph coercion) still keeps PHP's tag list, and a
     * direct Lambda call sees the unmerged caller list, which matches source.
     */
    public function testARawFunctionNodeDeduplicatesTagsLikeSourceWhileLambdaNodesKeepTheirList(): void
    {
        $seenBy = static function (array &$seen): \Closure {
            return static function (mixed $state, RunnableConfig $config) use (&$seen): mixed {
                $seen[] = array_values(array_filter($config->tags, static fn (string $tag): bool => !str_starts_with($tag, 'seq:')));
                return \is_array($state) ? ['value' => 1] : 1;
            };
        };
        $runTwice = static function (Runnable $runnable, mixed $input): array {
            $call = new RunnableConfig(tags: ['same', 'same']);
            $runnable->invoke($input, $call);
            $runnable->withConfig(['tags' => ['same']])->invoke($input, $call);
            return $call->tags;
        };
        $seen = ['raw' => [], 'callable' => [], 'lambda' => [], 'plain' => [], 'directCallable' => [], 'directLambda' => []];

        self::assertSame(['same', 'same'], $runTwice(self::oneNode($seenBy($seen['raw'])), ['value' => 0]));
        $runTwice(self::oneNode(new RunnableCallable($seenBy($seen['callable']), name: 'leaf', trace: false)), ['value' => 0]);
        $runTwice(self::oneNode(RunnableLambda::from($seenBy($seen['lambda']))), ['value' => 0]);
        $plain = (new Graph())->addNode('leaf', $seenBy($seen['plain']))
            ->addEdge(Constants::START, 'leaf')->addEdge('leaf', Constants::END)->compile();
        $runTwice($plain, 0);
        $runTwice(new RunnableCallable($seenBy($seen['directCallable']), name: 'leaf', trace: false), 0);
        $runTwice(RunnableLambda::from($seenBy($seen['directLambda'])), 0);

        self::assertSame([
            'raw' => [['same'], ['same']],
            'callable' => [['same'], ['same']],
            'lambda' => [['same', 'same'], ['same', 'same', 'same']],
            'plain' => [['same', 'same'], ['same', 'same', 'same']],
            'directCallable' => [['same'], ['same']],
            'directLambda' => [['same', 'same'], ['same']],
        ], $seen);
    }

    public function testRawFunctionsAlwaysReceiveTheConfigAsTheirSecondArgument(): void
    {
        $seen = [];
        $graph = (new StateGraph(self::schema()))
            ->addNode('one', static function (array $state) use (&$seen): array {
                $seen['one'] = [func_num_args(), func_get_arg(1) === PregelScratchpad::currentConfig()];
                return ['value' => 'one'];
            })
            ->addNode('variadic', static function (mixed ...$args) use (&$seen): array {
                $seen['variadic'] = [count($args), $args[1] === PregelScratchpad::currentConfig()];
                return ['value' => 'variadic'];
            })
            ->addNode('untyped', static function ($state, $config) use (&$seen): array {
                $seen['untyped'] = [2, $config === PregelScratchpad::currentConfig()];
                return ['value' => 'untyped'];
            })
            ->addEdge(Constants::START, 'one')->addEdge('one', 'variadic')->addEdge('variadic', 'untyped')
            ->compile();

        self::assertSame(['value' => 'untyped'], $graph->invoke(['value' => 'start']));
        self::assertSame(['one' => [2, true], 'variadic' => [2, true], 'untyped' => [2, true]], $seen);
    }

    public function testCallablesThatRejectTheConfigArgumentThrowUnlessWrappedInAnExplicitLambda(): void
    {
        $typed = static fn (array $state, int $bonus = 7): array => ['value' => $bonus];

        $raw = (new StateGraph(self::schema()))->addNode('typed', $typed)->addNode('builtin', 'array_sum');
        try {
            $raw->nodes['typed']->bound->invoke(['value' => 1], new RunnableConfig());
            self::fail('a typed non-config second parameter must reject the config');
        } catch (\TypeError $e) {
            self::assertStringContainsString('RunnableConfig', $e->getMessage());
        }
        try {
            $raw->nodes['builtin']->bound->invoke([1, 2], new RunnableConfig());
            self::fail('an internal builtin must reject the extra config argument');
        } catch (\ArgumentCountError $e) {
            self::assertStringContainsString('array_sum', $e->getMessage());
        }

        $escaped = (new StateGraph(self::schema()))
            ->addNode('typed', RunnableLambda::from($typed))
            ->addNode('builtin', RunnableLambda::from('array_sum'));
        self::assertSame(['value' => 7], self::oneNode($escaped->nodes['typed']->bound)->invoke(['value' => 1]));
        self::assertSame(3, $escaped->nodes['builtin']->bound->invoke([1, 2], new RunnableConfig()));
    }

    public function testDirectInvocationPublishesTheMergedConfigItHandsTheFunctionAndRestoresTheCaller(): void
    {
        $writer = static function (): void {};
        $context = new \stdClass();
        $caller = new RunnableConfig(
            tags: ['caller'],
            metadata: ['m' => 1],
            runName: 'caller',
            configurable: ['tenant' => 'A'],
            options: ['writer' => $writer],
            context: $context,
        );
        $callerBefore = [$caller->tags, $caller->metadata, $caller->runName, $caller->configurable, $caller->options, $caller->context];
        $seen = null;
        $graph = (new StateGraph(self::schema()))->addNode('leaf', static function (array $state, RunnableConfig $config) use (&$seen): array {
            $seen = [
                'argument' => $config,
                'current' => PregelScratchpad::currentConfig(),
                'als' => AsyncLocalStorage::getRunnableConfig(),
                'config' => Config::getConfig(),
                'writer' => Config::getWriter(),
            ];
            return ['value' => 1];
        });
        $outer = new RunnableConfig(runName: 'outer', configurable: ['tenant' => 'outer']);

        AsyncLocalStorage::runWithConfig($outer, static function () use ($graph, $caller, $outer): void {
            self::assertSame(['value' => 1], $graph->nodes['leaf']->bound->invoke(['value' => 0], $caller));
            self::assertSame($outer, PregelScratchpad::currentConfig());
            self::assertSame($outer, Config::getConfig());
        });

        self::assertSame($seen['argument'], $seen['current']);
        self::assertSame($seen['argument'], $seen['als']);
        self::assertSame($seen['argument'], $seen['config']);
        self::assertSame($writer, $seen['writer']);
        self::assertSame($context, $seen['argument']->context);
        self::assertSame(['tenant' => 'A'], $seen['argument']->configurable);
        self::assertSame('caller', $seen['argument']->runName);
        self::assertSame(['caller'], $seen['argument']->tags);
        self::assertSame($callerBefore, [$caller->tags, $caller->metadata, $caller->runName, $caller->configurable, $caller->options, $caller->context]);
        self::assertNull(PregelScratchpad::currentConfig());
    }

    public function testANestedFailureRestoresEachEnclosingFrameExactly(): void
    {
        $inner = (new StateGraph(self::schema()))->addNode('inner', static function (array $state, RunnableConfig $config): array {
            self::assertSame($config, PregelScratchpad::currentConfig());
            throw new \RuntimeException('inner failed');
        });
        $restored = null;
        $outerNode = (new StateGraph(self::schema()))->addNode('outer', static function (array $state, RunnableConfig $config) use ($inner, &$restored): array {
            try {
                $inner->nodes['inner']->bound->invoke($state, $config->with(['configurable' => ['tenant' => 'inner']]));
            } catch (\RuntimeException $e) {
                $restored = [PregelScratchpad::currentConfig() === $config, AsyncLocalStorage::getRunnableConfig() === $config];
                throw new \LogicException('outer failed', 0, $e);
            }
            return ['value' => 'unreachable'];
        });
        $outer = new RunnableConfig(runName: 'outer');

        AsyncLocalStorage::runWithConfig($outer, static function () use ($outerNode, $outer): void {
            try {
                $outerNode->nodes['outer']->bound->invoke(['value' => 0], new RunnableConfig(configurable: ['tenant' => 'outer']));
                self::fail('the nested failure must propagate');
            } catch (\LogicException $e) {
                self::assertSame('inner failed', $e->getPrevious()?->getMessage());
            }
            self::assertSame($outer, PregelScratchpad::currentConfig());
            self::assertSame($outer, AsyncLocalStorage::getRunnableConfig());
        });

        self::assertSame([true, true], $restored);
        self::assertNull(PregelScratchpad::currentConfig());
    }

    public function testCompiledFunctionNodeRunsUnderTheFullTaskFrame(): void
    {
        $saver = new MemorySaver();
        $context = new \stdClass();
        $seen = null;
        $graph = self::oneNode(static function (array $state, RunnableConfig $config) use (&$seen): array {
            $current = PregelScratchpad::currentConfig();
            $scratchpad = $config->configurable[Constants::CONFIG_KEY_SCRATCHPAD] ?? null;
            $seen = [
                'same frame' => $config === $current && $config === AsyncLocalStorage::getRunnableConfig() && $config === Config::getConfig(),
                'writer' => Config::getWriter() !== null && Config::getWriter() === ($config->options['writer'] ?? null),
                'control' => ($config->options['control'] ?? null) instanceof RunControl,
                'context' => $config->context,
                'scratchpad' => $scratchpad instanceof PregelScratchpad && $scratchpad === $current?->configurable[Constants::CONFIG_KEY_SCRATCHPAD],
                'read' => is_callable($config->configurable[Constants::CONFIG_KEY_READ] ?? null),
                'send' => is_callable($config->configurable[Constants::CONFIG_KEY_SEND] ?? null),
                'checkpointer' => $config->configurable[Constants::CONFIG_KEY_CHECKPOINTER] ?? null,
                'namespace' => str_starts_with((string) ($config->configurable[Constants::CONFIG_KEY_CHECKPOINT_NS] ?? ''), 'leaf:'),
                'task input' => Config::getCurrentTaskInput() === $state,
                'tenant' => $config->configurable['tenant'] ?? null,
            ];
            Config::getWriter()('W');
            return ['value' => 1];
        }, compile: ['checkpointer' => $saver]);
        $call = new RunnableConfig(configurable: ['thread_id' => 't1', 'tenant' => 'A'], options: ['streamMode' => ['custom', 'values']], context: $context);

        $chunks = iterator_to_array($graph->stream(['value' => 0], $call), false);

        self::assertSame([['values', ['value' => 0]], ['custom', 'W'], ['values', ['value' => 1]]], $chunks);
        self::assertSame([
            'same frame' => true,
            'writer' => true,
            'control' => true,
            'context' => $context,
            'scratchpad' => true,
            'read' => true,
            'send' => true,
            'checkpointer' => $saver,
            'namespace' => true,
            'task input' => true,
            'tenant' => 'A',
        ], $seen);
        self::assertSame(['thread_id' => 't1', 'tenant' => 'A'], $call->configurable);
        self::assertSame(['streamMode' => ['custom', 'values']], $call->options);
        self::assertNull(Config::getConfig());
    }

    public function testExplicitFibersInterleavedAcrossSuspensionKeepTheirOwnNodeFrames(): void
    {
        $seen = [];
        $graph = self::oneNode(static function (array $state, RunnableConfig $config) use (&$seen): array {
            $tenant = $config->configurable['tenant'];
            foreach (['before', 'after'] as $phase) {
                $seen[$tenant][$phase] = [$config === PregelScratchpad::currentConfig(), Config::getConfig()?->configurable['tenant']];
                if ($phase === 'before') {
                    \Fiber::suspend();
                }
            }
            Config::getWriter()('custom-' . $tenant);
            return ['value' => 'done-' . $tenant];
        });
        $fibers = [];
        foreach (['A', 'B'] as $tenant) {
            $fibers[$tenant] = new \Fiber(static function () use ($graph, $tenant): array {
                $outer = new RunnableConfig(runName: 'outer-' . $tenant);
                return AsyncLocalStorage::runWithConfig($outer, static function () use ($graph, $tenant, $outer): array {
                    $chunks = iterator_to_array($graph->stream(['value' => $tenant], new RunnableConfig(
                        configurable: ['tenant' => $tenant],
                        options: ['streamMode' => ['custom']],
                    )), false);
                    self::assertSame($outer, PregelScratchpad::currentConfig());
                    return $chunks;
                });
            });
        }

        $fibers['A']->start();
        $fibers['B']->start();
        $fibers['B']->resume();
        $fibers['A']->resume();

        self::assertSame([['custom', 'custom-A']], $fibers['A']->getReturn());
        self::assertSame([['custom', 'custom-B']], $fibers['B']->getReturn());
        self::assertSame([
            'A' => ['before' => [true, 'A'], 'after' => [true, 'A']],
            'B' => ['before' => [true, 'B'], 'after' => [true, 'B']],
        ], $seen);
        self::assertNull(PregelScratchpad::currentConfig());
    }

    public function testInterruptAndResumeRunUnderTheFunctionNodeFrame(): void
    {
        $frames = [];
        $graph = self::oneNode(static function (array $state, RunnableConfig $config) use (&$frames): array {
            $frames[] = [$config === PregelScratchpad::currentConfig(), $config->configurable['thread_id'] ?? null];
            return ['value' => interrupt('approve?')];
        }, compile: ['checkpointer' => new MemorySaver()]);
        $thread = new RunnableConfig(configurable: ['thread_id' => 'approval']);

        $graph->invoke(['value' => 'start'], $thread);
        self::assertSame(['leaf'], $graph->getState($thread)->next);
        self::assertSame(['value' => 'yes'], $graph->invoke(new Command(resume: 'yes'), $thread));
        self::assertSame([[true, 'approval'], [true, 'approval']], $frames);
    }

    public function testAReturnedRunnableIsInvokedWithTheSameInputUnderTheNodeConfig(): void
    {
        $seen = null;
        $graph = self::oneNode(static function (array $state, RunnableConfig $config) use (&$seen): RunnableCallable {
            return new RunnableCallable(static function (array $input, RunnableConfig $inner) use ($state, $config, &$seen): array {
                $seen = [
                    'input' => $input === $state,
                    'writer' => ($inner->options['writer'] ?? null) === ($config->options['writer'] ?? false),
                    'scratchpad' => ($inner->configurable[Constants::CONFIG_KEY_SCRATCHPAD] ?? null) === $config->configurable[Constants::CONFIG_KEY_SCRATCHPAD],
                    'published' => $inner === PregelScratchpad::currentConfig(),
                ];
                return ['value' => $input['value'] + 1];
            }, trace: false);
        });

        self::assertSame(['value' => 2], $graph->invoke(['value' => 1], new RunnableConfig(options: ['streamMode' => ['custom']])));
        self::assertSame(['input' => true, 'writer' => true, 'scratchpad' => true, 'published' => true], $seen);
    }

    public function testAReturnedSubgraphRunsOnTheSameInput(): void
    {
        $child = self::oneNode(static fn (array $state): array => ['value' => $state['value'] . '+child']);
        $graph = self::oneNode(static fn (array $state): Pregel => $child);

        self::assertSame(['value' => 'in+child'], $graph->invoke(['value' => 'in']));
    }

    public function testAReturnedLambdaStillSeesTheNodeFrameWhilePickedPublicationIsHeld(): void
    {
        $seen = null;
        $graph = self::oneNode(static function (array $state, RunnableConfig $config) use (&$seen): RunnableLambda {
            return RunnableLambda::from(static function (array $input) use ($config, &$seen): array {
                // Upstream's Lambda would publish a picked frame (no writer, runName null). That
                // publication is held, so today the Callable's full frame is still current here.
                $seen = [PregelScratchpad::currentConfig() === $config, Config::getWriter() !== null];
                return ['value' => 'lambda:' . $input['value']];
            });
        });

        self::assertSame(['value' => 'lambda:x'], $graph->invoke(['value' => 'x'], new RunnableConfig(options: ['streamMode' => ['custom']])));
        self::assertSame([true, true], $seen);
    }

    public function testAnExplicitNonRecursingCallableReturnsItsRunnableUninvoked(): void
    {
        $runs = 0;
        $returned = RunnableLambda::from(static function () use (&$runs): array {
            ++$runs;
            return ['value' => 'ran'];
        });
        $explicit = new RunnableCallable(static fn (array $state, RunnableConfig $config): RunnableLambda => $returned, recurse: false);
        $graph = (new StateGraph(self::schema()))->addNode('leaf', $explicit);

        self::assertSame($explicit, $graph->nodes['leaf']->bound);
        self::assertSame($returned, $graph->nodes['leaf']->bound->invoke(['value' => 0], new RunnableConfig()));
        self::assertSame(0, $runs);
    }

    public function testNodeOptionsAndTheErrorHandlerAreUnchangedByTheWrapper(): void
    {
        $retry = new RetryPolicy(maxAttempts: 1);
        $handler = static fn (array $state, $error, ?RunnableConfig $config): array => ['value' => 'handled'];
        $input = Annotation::root(['value' => Annotation::last()]);
        $body = static function (array $state): array {
            throw new \RuntimeException('boom');
        };
        $graph = (new StateGraph(self::schema()))->addNode('leaf', $body, [
            'metadata' => ['m' => 1],
            'tags' => ['t'],
            'subgraphs' => [],
            'ends' => ['x'],
            'retryPolicy' => $retry,
            'cachePolicy' => ['ttl' => 5],
            'timeout' => 9,
            'defer' => true,
            'input' => $input,
            'errorHandler' => $handler,
        ]);

        $node = $graph->nodes['leaf'];
        self::assertWrapped($graph, 'leaf', $body);
        self::assertSame(['m' => 1], $node->metadata);
        self::assertSame(['t'], $node->tags);
        self::assertSame(['x'], $node->ends);
        self::assertSame($retry, $node->retryPolicy);
        self::assertSame(['ttl' => 5], $node->cachePolicy);
        self::assertSame(9, $node->timeout);
        self::assertSame('__error_handler__leaf', $node->errorHandlerNode);
        $spec = $graph->nodeSpecs['leaf'];
        self::assertSame(['ttl' => 5], $spec->cachePolicy);
        self::assertSame(9, $spec->timeout);
        self::assertTrue($spec->defer);
        self::assertSame($handler, $spec->errorHandler);
        $handlerNode = $graph->nodes['__error_handler__leaf'];
        self::assertTrue($handlerNode->isErrorHandler);
        self::assertInstanceOf(RunnableCallable::class, $handlerNode->bound);
        self::assertFalse($handlerNode->bound->recurse);

        $plain = (new StateGraph(self::schema()))->addNode('leaf', $body, ['retryPolicy' => $retry, 'errorHandler' => $handler])
            ->addEdge(Constants::START, 'leaf')->compile();
        self::assertSame(['value' => 'handled'], $plain->invoke(['value' => 0]));
    }

    public function testValidationAndNonCallableFallThroughAreUnchanged(): void
    {
        $body = static fn (array $state): array => $state;
        $graph = (new StateGraph(self::schema()))->addNode('leaf', $body);

        $cases = [
            'duplicate' => [static fn () => $graph->addNode('leaf', $body), 'Node `leaf` already present.'],
            'reserved' => [static fn () => $graph->addNode(Constants::START, $body), 'Node `__start__` is reserved.'],
            'separator' => [static fn () => $graph->addNode('a|b', $body), '"|" is a reserved character and is not allowed in node names.'],
            'not callable' => [static fn () => $graph->addNode(['missing' => null]), 'Expected a RunnableInterface or callable for "missing", got null.'],
        ];
        foreach ($cases as $label => [$add, $message]) {
            try {
                $add();
                self::fail($label . ' must be rejected');
            } catch (\InvalidArgumentException $e) {
                self::assertSame($message, $e->getMessage(), $label);
            }
        }
        self::assertSame(['leaf'], array_keys($graph->nodes));
    }

    public function testTheUntracedWrapperAddsNoCallbackOrEventLifecycle(): void
    {
        $events = [];
        $handler = new CallbackHandler([
            'handleChainStart' => static function () use (&$events): void { $events[] = 'chain start'; },
            'handleChainEnd' => static function () use (&$events): void { $events[] = 'chain end'; },
            'handleToolStart' => static function () use (&$events): void { $events[] = 'tool start'; },
        ]);
        $tool = new DynamicTool(['name' => 'echo', 'description' => 'Echo input'], static fn (array $input): string => $input['input']);
        $graph = self::oneNode(static function (array $state, RunnableConfig $config) use ($tool): array {
            self::assertSame('x', $tool->invoke('x', $config));
            return ['value' => 1];
        });

        $graph->invoke(['value' => 0], new RunnableConfig(callbacks: [$handler]));
        $names = array_map(
            static fn ($event): string => $event->event . ':' . $event->name,
            iterator_to_array($graph->streamEvents(['value' => 0], new RunnableConfig(), 'v2'), false),
        );

        self::assertSame(['tool start'], $events);
        self::assertSame([
            'on_chain_start:Pregel',
            'on_chain_stream:Pregel',
            'on_tool_start:leaf',
            'on_tool_end:leaf',
            'on_chain_stream:Pregel',
            'on_chain_stream:Pregel',
            'on_chain_end:Pregel',
        ], $names);
    }

    public function testMessagesStreamSeesEachModelTokenOnceFromAFunctionNode(): void
    {
        $model = new FakeStreamingChatModel([
            'chunks' => array_map(static fn (string $p): AIMessageChunk => new AIMessageChunk(['content' => $p]), ['Hel', 'lo']),
        ]);
        $graph = (new StateGraph(['messages' => new AnyValue()]))
            ->addNode('agent', static fn (array $state, RunnableConfig $config): array => ['messages' => [$model->invoke([new HumanMessage('hi')], $config)]])
            ->addEdge(Constants::START, 'agent')
            ->compile(['streamMode' => ['messages']]);

        $chunks = iterator_to_array($graph->stream(['messages' => []]), false);

        self::assertSame(['Hel', 'lo'], array_map(static fn (array $c): mixed => $c[1][0]->content, $chunks));
        self::assertSame(['agent', 'agent'], array_map(static fn (array $c): mixed => $c[1][1]['langgraph_node'], $chunks));
    }
}

if (!\function_exists(__NAMESPACE__ . '\\state_graph_function_node_named')) {
    function state_graph_function_node_named(array $state): array
    {
        return ['value' => 'named:' . func_num_args()];
    }
}

final class StateGraphFunctionNodeFixtures
{
    public static function staticNode(array $state): array
    {
        return ['value' => 'static:' . func_num_args()];
    }

    public function instanceNode(array $state): array
    {
        return ['value' => 'instance:' . func_num_args()];
    }

    public function __invoke(array $state): array
    {
        return ['value' => 'invokable:' . func_num_args()];
    }
}

final class StateGraphInvokableRunnableNode extends Runnable
{
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        return ['value' => 'invoke'];
    }

    public function __invoke(mixed $input): array
    {
        return ['value' => '__invoke'];
    }
}
