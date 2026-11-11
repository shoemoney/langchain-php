<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\AsyncLocalStorage;
use LangChain\Tracers\CallbackHandler;
use LangChain\Tools\DynamicTool;
use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\Messages\AIMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Checkpoint\Checkpoint;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\Utils\Config;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pregel::class)]
#[CoversClass(Config::class)]
final class AmbientChildGraphContextTest extends TestCase
{
    protected function setUp(): void { AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage()); }
    protected function tearDown(): void { AsyncLocalStorage::setGlobalInstance(null); }

    private static function graph(string $name, mixed $body, array $options = []): Pregel
    {
        $schema = Annotation::root(['value' => Annotation::withReducer(static fn ($a, $b) => $b, static fn () => 0)]);
        return (new StateGraph($schema))->addNode($name, $body)->addEdge(Constants::START, $name)->compile($options);
    }

    public static function childCases(): iterable
    {
        yield 'implicit' => [false, false, false, 'parent', ['ambient' => 'P']];
        yield 'caller' => [false, true, false, 'caller', ['caller' => 'C']];
        yield 'bound' => [true, false, false, 'parent', ['bound' => 'B', 'ambient' => 'P']];
        yield 'bound caller' => [true, true, false, 'caller', ['bound' => 'B', 'caller' => 'C']];
        yield 'bound chained' => [true, false, true, 'parent', ['bound' => 'B', 'second' => 'S', 'ambient' => 'P']];
    }

    #[DataProvider('childCases')]
    public function testImperativeChildPreservesNestingAndPublicBoundCallerPrecedence(bool $bound, bool $caller, bool $chain, string $tenant, array $metadata): void
    {
        $seen = null;
        $child = self::graph('leaf', static function (array $state, RunnableConfig $config) use (&$seen): array {
            $seen = $config;
            self::assertSame(0, Config::getCurrentTaskInput()['value']);
            Config::getWriter()('leaf');
            return ['value' => 1];
        });
        if ($bound) { $child = $child->withConfig(['configurable' => ['bound' => 'B', 'tenant' => 'bound', 'thread_id' => 'bound-thread'], 'metadata' => ['bound' => 'B']]); }
        if ($chain) { $child = $child->withConfig(['configurable' => ['tenant' => 'second'], 'metadata' => ['second' => 'S']]); }
        $call = $caller ? new RunnableConfig(configurable: ['tenant' => 'caller', 'call' => 'C'], metadata: ['caller' => 'C']) : null;
        $graph = self::graph('parent', static fn (array $state): array => $child->invoke($state, $call));
        $run = $graph->stream(['value' => 0], new RunnableConfig(configurable: ['tenant' => 'parent'], metadata: ['ambient' => 'P'], options: ['streamMode' => ['tasks', 'updates', 'custom'], 'subgraphs' => true]));
        $chunks = iterator_to_array($run);
        self::assertSame(['value' => 1], $run->getReturn());
        self::assertSame($tenant, $seen->configurable['tenant']);
        self::assertEquals($metadata, array_intersect_key($seen->metadata, array_flip(['ambient', 'bound', 'caller', 'second'])));
        self::assertSame($bound ? 'B' : null, $seen->configurable['bound'] ?? null);
        self::assertSame(['parent', 'leaf'], array_map(static fn ($s) => explode(':', $s)[0], explode('|', $seen->configurable['checkpoint_ns'])));
        $leafTasks = array_filter($chunks, static fn ($c) => $c[1] === 'tasks' && $c[2]['name'] === 'leaf');
        self::assertCount(2, $leafTasks);
        $custom = array_values(array_filter($chunks, static fn ($c) => $c[1] === 'custom'));
        self::assertSame('leaf', $custom[0][2]);
        self::assertStringStartsWith('parent:', $custom[0][0][0]);
        self::assertNull(Config::getConfig());
        if ($call !== null) { self::assertSame(['tenant' => 'caller', 'call' => 'C'], $call->configurable); self::assertSame(['caller' => 'C'], $call->metadata); }
    }

    public function testFreshThreadRootDropsAllStaleConfigurableButKeepsAmbientMetadataAndTags(): void
    {
        $outer = new RunnableConfig(configurable: ['tenant' => 'stale', '__pregel_scratchpad' => 'stale'], metadata: ['ambient' => 'S'], tags: ['outer']);
        $graph = self::graph('leaf', static function (array $state, RunnableConfig $config): array {
            self::assertArrayNotHasKey('tenant', $config->configurable);
            self::assertSame('C', $config->configurable['call']);
            self::assertSame('S', $config->metadata['ambient']);
            self::assertContains('outer', $config->tags);
            return ['value' => 1];
        });
        AsyncLocalStorage::runWithConfig($outer, static function () use ($graph, $outer): void {
            foreach (['A', 'B'] as $thread) { self::assertSame(['value' => 1], $graph->invoke(['value' => 0], new RunnableConfig(configurable: ['thread_id' => $thread, 'call' => 'C']))); }
            self::assertSame($outer, Config::getConfig());
        });
        self::assertSame('stale', $outer->configurable['tenant']);
    }

    public function testBoundOnlyThreadDoesNotDropAmbientUserConfig(): void
    {
        $graph = self::graph('leaf', static function (array $s, RunnableConfig $c): array {
            self::assertSame('stale', $c->configurable['tenant']);
            self::assertSame('bound', $c->configurable['thread_id']);
            return ['value' => 1];
        })->withConfig(['configurable' => ['thread_id' => 'bound']]);
        AsyncLocalStorage::runWithConfig(new RunnableConfig(configurable: ['tenant' => 'stale']), static fn () => self::assertSame(['value' => 1], $graph->invoke(['value' => 0])));
    }

    public function testExplicitDefaultRecursionIsPresentButOmittedDefaultIsNot(): void
    {
        AsyncLocalStorage::runWithConfig(new RunnableConfig(recursionLimit: 100), static function (): void {
            self::assertSame(25, Config::ensureLangGraphConfig(['recursionLimit' => 25])->recursionLimit);
            self::assertSame(25, Config::ensureLangGraphConfig(new RunnableConfig(recursionLimit: 25))->recursionLimit);
            self::assertSame(100, Config::ensureLangGraphConfig(new RunnableConfig())->recursionLimit);
        });
    }

    public function testInternalHelperStillComposesMapsWhilePublicCallReplacesThem(): void
    {
        AsyncLocalStorage::runWithConfig(new RunnableConfig(metadata: ['ambient' => 'S']), static function (): void {
            self::assertSame(['ambient' => 'S', 'caller' => 'C'], Config::ensureLangGraphConfig(['metadata' => ['caller' => 'C']])->metadata);
        });
    }
    public function testThreeLevelImperativeCallsKeepTaskInputsAndIndependentNamespaceAncestry(): void
    {
        $seen = [];
        $leaf = self::graph('leaf', static function (array $state, RunnableConfig $config) use (&$seen): array {
            $seen[] = [$config->configurable['tenant'], Config::getCurrentTaskInput()['value'], $config->configurable['checkpoint_ns']];
            return ['value' => $state['value'] + 1];
        });
        $middle = self::graph('middle', static function (array $state, RunnableConfig $config) use ($leaf, &$seen): array {
            $seen[] = [$config->configurable['tenant'], Config::getCurrentTaskInput()['value'], $config->configurable['checkpoint_ns']];
            return $leaf->invoke(['value' => $state['value'] + 1]);
        });
        $outer = self::graph('outer', static fn (array $state): array => $middle->invoke(['value' => $state['value'] + 1]));
        self::assertSame(['value' => 3], $outer->invoke(['value' => 0], new RunnableConfig(configurable: ['tenant' => 'outer'])));
        self::assertSame(['outer', 1], array_slice($seen[0], 0, 2));
        self::assertSame(['outer', 2], array_slice($seen[1], 0, 2));
        self::assertSame(['outer', 'middle'], array_map(static fn ($s) => explode(':', $s)[0], explode('|', $seen[0][2])));
        self::assertSame(['outer', 'middle', 'leaf'], array_map(static fn ($s) => explode(':', $s)[0], explode('|', $seen[1][2])));
        self::assertNull(Config::getConfig());
    }

    public function testCallerThreadUnderActiveReadStaysNestedAndExistingReadIsNotReplaced(): void
    {
        $child = self::graph('leaf', static function (array $s, RunnableConfig $c): array {
            self::assertSame('child-thread', $c->configurable['thread_id']);
            self::assertSame('parent', $c->configurable['tenant']);
            self::assertStringStartsWith('parent:', $c->configurable['checkpoint_ns']);
            self::assertSame(0, Config::getCurrentTaskInput()['value']);
            return ['value' => 1];
        });
        $parent = self::graph('parent', static function (array $state, RunnableConfig $config) use ($child): array {
            $ambient = Config::getConfig();
            $read = $config->configurable[Constants::CONFIG_KEY_READ];
            $map = $config->configurable[Constants::CONFIG_KEY_CHECKPOINT_MAP] ?? [];
            $existing = $config->with(['configurable' => array_replace($config->configurable, ['thread_id' => 'child-thread'])]);
            self::assertSame(['value' => 1], $child->invoke($state, $existing));
            self::assertSame($read, $existing->configurable[Constants::CONFIG_KEY_READ]);
            self::assertSame($map, $existing->configurable[Constants::CONFIG_KEY_CHECKPOINT_MAP] ?? []);
            $result = $child->invoke($state, new RunnableConfig(configurable: ['thread_id' => 'child-thread']));
            self::assertSame($ambient, Config::getConfig());
            self::assertSame($read, $config->configurable[Constants::CONFIG_KEY_READ]);
            self::assertSame($map, $config->configurable[Constants::CONFIG_KEY_CHECKPOINT_MAP] ?? []);
            return $result;
        });
        self::assertSame(['value' => 1], $parent->invoke(['value' => 0], new RunnableConfig(configurable: ['tenant' => 'parent', 'thread_id' => 'parent-thread'])));
    }

    public function testBoundClonesReuseWithoutMutatingOriginalOrCallerAndListenersRunOnce(): void
    {
        $seen = [];
        $base = self::graph('leaf', static function (array $s, RunnableConfig $c) use (&$seen): array {
            $seen[] = [$c->configurable['bound'] ?? null, $c->configurable['tenant'] ?? null, $c->metadata['bound'] ?? null, $c->metadata['second'] ?? null];
            return ['value' => 1];
        });
        $bound = $base->withConfig(['configurable' => ['bound' => 'B', 'tenant' => 'first'], 'metadata' => ['bound' => 'B']]);
        $chain = $bound->withConfig(['configurable' => ['tenant' => 'second'], 'metadata' => ['second' => 'S']]);
        $call = new RunnableConfig(configurable: ['tenant' => 'caller']);
        $base->invoke(['value' => 0]);
        $bound->invoke(['value' => 0]);
        $chain->invoke(['value' => 0]);
        $chain->invoke(['value' => 0], $call);
        $bound->invoke(['value' => 0]);
        self::assertSame([[null, null, null, null], ['B', 'first', 'B', null], ['B', 'second', 'B', 'S'], ['B', 'caller', 'B', 'S'], ['B', 'first', 'B', null]], $seen);
        self::assertSame(['tenant' => 'caller'], $call->configurable);
        $events = [];
        $listened = $chain->withListeners([
            'onStart' => static function () use (&$events): void { $events[] = 'start'; },
            'onEnd' => static function () use (&$events): void { $events[] = 'end'; },
        ]);
        self::assertSame(['value' => 1], $listened->invoke(['value' => 0]));
        self::assertSame(['start', 'end'], $events);
        $events = [];
        iterator_to_array($listened->stream(['value' => 0]));
        self::assertSame(['start', 'end'], $events);
    }

    public function testNestedErrorRestoresActualParentThenOuterContext(): void
    {
        $failure = new \RuntimeException('nested failure');
        $child = self::graph('leaf', static function () use ($failure): never { throw $failure; });
        $parent = self::graph('parent', static function (array $s, RunnableConfig $c) use ($child, $failure): array {
            $ambient = Config::getConfig();
            try { $child->invoke($s); self::fail('Expected child failure.'); }
            catch (\RuntimeException $error) { self::assertSame($failure, $error); self::assertSame($ambient, Config::getConfig()); }
            return ['value' => 1];
        });
        $outer = new RunnableConfig(configurable: ['outer' => true]);
        AsyncLocalStorage::runWithConfig($outer, static function () use ($parent, $outer): void {
            self::assertSame(['value' => 1], $parent->invoke(['value' => 0]));
            self::assertSame($outer, Config::getConfig());
        });
        self::assertNull(Config::getConfig());
    }

    public static function callbackMethods(): iterable
    {
        yield 'invoke' => ['invoke'];
        yield 'stream' => ['stream'];
        yield 'batch' => ['batch'];
        yield 'events v2' => ['v2'];
        yield 'events v1' => ['v1'];
    }

    #[DataProvider('callbackMethods')]
    public function testBoundAndCallerCallbacksRetainExactlyOneRootTrace(string $method): void
    {
        $boundEvents = []; $callerEvents = [];
        $boundHandler = new CallbackHandler([
            'handleChainStart' => static function () use (&$boundEvents): void { $boundEvents[] = 'start'; },
            'handleChainEnd' => static function () use (&$boundEvents): void { $boundEvents[] = 'end'; },
        ]);
        $callerHandler = new CallbackHandler([
            'handleChainStart' => static function () use (&$callerEvents): void { $callerEvents[] = 'start'; },
            'handleChainEnd' => static function () use (&$callerEvents): void { $callerEvents[] = 'end'; },
        ]);
        $graph = self::graph('leaf', static function (array $s, RunnableConfig $c): array {
            self::assertArrayNotHasKey('__pregel_binding_call_config', $c->options);
            return ['value' => 1];
        })->withConfig(['callbacks' => [$boundHandler]]);
        $call = new RunnableConfig(callbacks: [$callerHandler]);
        if ($method === 'invoke') { self::assertSame(['value' => 1], $graph->invoke(['value' => 0], $call)); }
        elseif ($method === 'stream') { iterator_to_array($graph->stream(['value' => 0], $call)); }
        elseif ($method === 'batch') { self::assertSame([['value' => 1], ['value' => 1]], $graph->batch([['value' => 0], ['value' => 1]], $call)); }
        else { iterator_to_array($graph->streamEvents(['value' => 0], $call, $method)); }
        $expected = $method === 'batch' ? ['start', 'end', 'start', 'end'] : ['start', 'end'];
        self::assertSame($expected, $boundEvents);
        self::assertSame($expected, $callerEvents);
        self::assertSame([$callerHandler], $call->callbacks);
    }

    public function testGraphBoundToolCallbacksAndInjectedEventHandlerReachToolOnce(): void
    {
        $boundStarts = 0; $callerStarts = 0;
        $bound = new CallbackHandler(['handleToolStart' => static function () use (&$boundStarts): void { ++$boundStarts; }]);
        $caller = new CallbackHandler(['handleToolStart' => static function () use (&$callerStarts): void { ++$callerStarts; }]);
        $tool = new DynamicTool(['name' => 'echo', 'description' => 'Echo input'], static fn (array $input): string => $input['input']);
        $graph = self::graph('leaf', static function (array $s, RunnableConfig $c) use ($tool): array {
            self::assertSame('hello', $tool->invoke('hello', $c));
            return ['value' => 1];
        })->withConfig(['callbacks' => [$bound]]);
        $events = iterator_to_array($graph->streamEvents(['value' => 0], new RunnableConfig(callbacks: [$caller]), 'v2'));
        self::assertSame(1, $boundStarts);
        self::assertSame(1, $callerStarts);
        self::assertCount(1, array_filter($events, static fn ($e) => $e->event === 'on_tool_start'));
        self::assertCount(1, array_filter($events, static fn ($e) => $e->event === 'on_tool_end'));
    }

    public function testGraphBindingFactoriesCanModifyCallerMapsAndRecursionWithoutFlatteningBoundThread(): void
    {
        $graph = self::graph('leaf', static function (array $s, RunnableConfig $c): array {
            self::assertSame('outer', $c->configurable['tenant']);
            self::assertSame('bound-thread', $c->configurable['thread_id']);
            self::assertSame('F', $c->configurable['factory']);
            self::assertSame(25, $c->recursionLimit);
            self::assertSame('F', $c->metadata['factory']);
            return ['value' => 1];
        })->withConfig(['configurable' => ['thread_id' => 'bound-thread'], 'recursionLimit' => 100]);
        $graph->configFactories = [static function (RunnableConfig $c): array {
            self::assertArrayNotHasKey('__pregel_binding_call_config', $c->options);
            return ['configurable' => ['factory' => 'F'], 'metadata' => ['factory' => 'F'], 'recursionLimit' => 25];
        }];
        AsyncLocalStorage::runWithConfig(new RunnableConfig(configurable: ['tenant' => 'outer']), static fn () => self::assertSame(['value' => 1], $graph->invoke(['value' => 0])));
    }

    public function testGraphCallExplicitRecursionTwentyFiveOverridesBoundHundred(): void
    {
        $seen = [];
        $graph = self::graph('leaf', static function (array $s, RunnableConfig $c) use (&$seen): array { $seen[] = $c->recursionLimit; return ['value' => 1]; })
            ->withConfig(['recursionLimit' => 100]);
        $graph->invoke(['value' => 0]);
        $graph->invoke(['value' => 0], new RunnableConfig());
        $graph->invoke(['value' => 0], new RunnableConfig(recursionLimit: 25));
        self::assertSame([100, 100, 25], $seen);
    }

    public function testInternalBindingSnapshotDoesNotEscapeGraphProviderCheckpointOrSerialization(): void
    {
        $saver = new class extends MemorySaver {
            public int $puts = 0;
            public function put(array $config, Checkpoint $checkpoint, array $metadata = [], array $newVersions = []): array
            {
                ++$this->puts;
                AmbientChildGraphContextTest::assertStringNotContainsString('__pregel_binding_call_config', json_encode([$config, $metadata], JSON_THROW_ON_ERROR));
                return parent::put($config, $checkpoint, $metadata, $newVersions);
            }
        };
        $model = new class extends BaseChatModel {
            public int $calls = 0;
            public function llmType(): string { return 'binding-snapshot-probe'; }
            protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
            {
                ++$this->calls;
                AmbientChildGraphContextTest::assertStringNotContainsString('__pregel_binding_call_config', json_encode([$messages, $options], JSON_THROW_ON_ERROR));
                return new ChatResult([new ChatGeneration(new AIMessage('answer'), 'answer')]);
            }
        };
        $ended = [];
        $graph = self::graph('leaf', static function (array $s, RunnableConfig $c) use ($model): array {
            foreach ([$c->options, $c->configurable, $c->metadata] as $map) { self::assertArrayNotHasKey('__pregel_binding_call_config', $map); }
            self::assertSame('answer', $model->invoke('question', $c)->content);
            return ['value' => 1];
        }, ['checkpointer' => $saver])->withConfig(['configurable' => ['thread_id' => 'snapshot-thread'], 'metadata' => ['bound' => 'B']])
            ->withListeners([
                'onStart' => static function ($run, RunnableConfig $config): void {
                    self::assertStringNotContainsString('__pregel_binding_call_config', json_encode($config, JSON_THROW_ON_ERROR));
                },
                'onEnd' => static function ($run) use (&$ended): void { $ended[] = $run->toArray(); },
            ]);
        $call = new RunnableConfig(metadata: ['caller' => 'C']);
        self::assertSame(['value' => 1], $graph->invoke(['value' => 0], $call));
        self::assertSame(1, $model->calls);
        self::assertGreaterThan(0, $saver->puts);
        self::assertCount(1, $ended);
        foreach ($saver->list(['configurable' => ['thread_id' => 'snapshot-thread']]) as $tuple) {
            self::assertStringNotContainsString('__pregel_binding_call_config', json_encode([$tuple->config, $tuple->metadata], JSON_THROW_ON_ERROR));
        }
        self::assertStringNotContainsString('__pregel_binding_call_config', json_encode([$graph, $call, $ended], JSON_THROW_ON_ERROR));
        self::assertSame(['caller' => 'C'], $call->metadata);
        self::assertSame([], $call->options);
    }

    public static function invocationOptions(): iterable
    {
        yield 'interrupt before' => ['interruptBefore', ['leaf']];
        yield 'interrupt after' => ['interruptAfter', ['leaf']];
        yield 'input keys' => ['inputKeys', Constants::START];
        yield 'output keys' => ['outputKeys', ['value']];
        yield 'debug' => ['debug', true];
    }

    #[DataProvider('invocationOptions')]
    public function testParentInvocationOptionsDoNotBecomeImplicitChildOptions(string $key, mixed $value): void
    {
        $runs = 0;
        $schema = Annotation::root(['other' => Annotation::withReducer(static fn ($a, $b) => $b, static fn () => 0)]);
        $child = (new StateGraph($schema))->addNode('leaf', static function (array $state, RunnableConfig $config) use (&$runs): array {
            ++$runs;
            self::assertSame(['other' => 0], $state);
            self::assertSame(['other' => 0], Config::getCurrentTaskInput());
            self::assertSame([], array_intersect_key($config->options, array_flip(['interruptBefore', 'interruptAfter', 'inputKeys', 'outputKeys', 'debug'])));
            return ['other' => 1];
        })->addEdge(Constants::START, 'leaf')->compile();
        $saver = new MemorySaver();
        $parent = self::graph('parent', static function (array $state, RunnableConfig $config) use ($child, $key, $value): array {
            self::assertSame($value, $config->options[$key]);
            $options = $config->options;
            $ambient = Config::getConfig();
            self::assertSame(['other' => 1], $child->invoke(['other' => 0]));
            self::assertSame($options, $config->options);
            self::assertSame($ambient, Config::getConfig());
            return ['value' => 1];
        }, ['checkpointer' => $saver]);
        $call = new RunnableConfig(configurable: ['thread_id' => 'option-' . $key], options: [$key => $value]);
        self::assertSame(['value' => 1], $parent->invoke(['value' => 0], $call));
        self::assertSame(1, $runs);
        self::assertSame([$key => $value], $call->options);
        self::assertNotEmpty(iterator_to_array($saver->list(['configurable' => ['thread_id' => 'option-' . $key]])));
        self::assertNull(Config::getConfig());
    }

    public function testOwnExplicitGraphOptionsAndAmbientOriginalRemainIntact(): void
    {
        $ambient = new RunnableConfig(options: ['interruptBefore' => ['unrelated'], 'interruptAfter' => ['unrelated'], 'inputKeys' => ['missing'], 'outputKeys' => ['missing'], 'debug' => false]);
        $own = new RunnableConfig(options: ['inputKeys' => Constants::START, 'outputKeys' => ['value'], 'debug' => true]);
        $graph = self::graph('leaf', static function (array $state, RunnableConfig $config): array {
            self::assertSame(['inputKeys' => Constants::START, 'outputKeys' => ['value'], 'debug' => true], array_intersect_key($config->options, array_flip(['interruptBefore', 'interruptAfter', 'inputKeys', 'outputKeys', 'debug'])));
            return ['value' => 1];
        });
        $before = $ambient->options;
        AsyncLocalStorage::runWithConfig($ambient, static function () use ($graph, $own, $ambient): void {
            self::assertSame(['value' => 1], $graph->invoke(['value' => 0], $own));
            self::assertSame($ambient, Config::getConfig());
        });
        self::assertSame($before, $ambient->options);
        self::assertSame(['inputKeys' => Constants::START, 'outputKeys' => ['value'], 'debug' => true], $own->options);
    }

    public function testExplicitInterruptOptionsStillControlTheGraphThatReceivesThem(): void
    {
        $runs = 0;
        $graph = self::graph('leaf', static function () use (&$runs): array { ++$runs; return ['value' => 1]; }, ['checkpointer' => new MemorySaver()]);
        $call = new RunnableConfig(configurable: ['thread_id' => 'own-before'], options: ['interruptBefore' => ['leaf']]);
        $graph->invoke(['value' => 0], $call);
        self::assertSame(0, $runs);
        $graph->invoke(['value' => 0], new RunnableConfig(configurable: ['thread_id' => 'own-after'], options: ['interruptAfter' => ['leaf']]));
        self::assertSame(1, $runs);
        self::assertSame(['interruptBefore' => ['leaf']], $call->options);
    }

    public function testCallerAndBoundVerboseSurviveGraphNormalization(): void
    {
        $seen = [];
        $base = self::graph('leaf', static function (array $state, RunnableConfig $config) use (&$seen): array { $seen[] = $config->verbose; return ['value' => 1]; });
        $direct = new RunnableConfig(); $direct->verbose = true;
        $base->invoke(['value' => 0], $direct);
        $bound = $base->withConfig(['verbose' => true]);
        $bound->invoke(['value' => 0]);
        $bound->invoke(['value' => 0], new RunnableConfig(verbose: false));
        self::assertSame([true, true, false], $seen);
        self::assertTrue($direct->verbose);
    }

    public function testDuplicateCallerTagsRetainGraphPublicProvenanceWithPhpNodeListAdaptation(): void
    {
        $seen = [];
        $base = self::graph('leaf', static function (array $state, RunnableConfig $config) use (&$seen): array {
            $seen[] = array_values(array_filter($config->tags, static fn ($tag) => !str_starts_with($tag, 'seq:')));
            return ['value' => 1];
        });
        $call = new RunnableConfig(tags: ['same', 'same']);
        self::assertSame(['same', 'same', 'same'], Config::ensureLangGraphConfig(['tags' => ['same']], $call)->tags);
        $base->invoke(['value' => 0], $call);
        $base->withConfig(['tags' => ['same']])->invoke(['value' => 0], $call);
        self::assertSame([['same', 'same'], ['same', 'same', 'same']], $seen);
        self::assertSame(['same', 'same'], $call->tags);
    }
}
