<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\CallbackHandler;
use LangChain\Utils\AsyncLocalStorage;
use LangChain\Utils\Await;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Errors\GraphInterrupt;
use LangGraph\Func\Func;
use LangGraph\Pregel\Algorithm;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\RunControl;
use LangGraph\Pregel\Send;
use LangGraph\Pregel\Utils\Config;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Parent invocation options are consumed before the task config reaches any child. */
#[CoversClass(Algorithm::class)]
final class ForwardedChildGraphOptionsTest extends TestCase
{
    private const INVOCATION_KEYS = ['interruptBefore', 'interruptAfter', 'inputKeys', 'outputKeys', 'debug'];

    protected function setUp(): void { AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage()); }
    protected function tearDown(): void { AsyncLocalStorage::setGlobalInstance(null); }

    private static function graph(string $name, string $key, mixed $body, array $options = []): Pregel
    {
        return (new StateGraph(Annotation::root([$key => Annotation::withReducer(static fn ($a, $b) => $b, static fn () => 0)])))
            ->addNode($name, $body)->addEdge(Constants::START, $name)->compile($options);
    }

    private static function invocationOptions(RunnableConfig $config): array
    {
        return array_intersect_key($config->options, array_flip(self::INVOCATION_KEYS));
    }

    public static function forwardingCases(): iterable
    {
        foreach (['forwarded', 'declared'] as $mode) {
            foreach (['interruptBefore' => ['leaf'], 'interruptAfter' => ['leaf'], 'inputKeys' => Constants::START, 'outputKeys' => ['value'], 'debug' => true] as $key => $value) {
                yield $mode . '-' . $key => [$mode, $key, $value];
            }
        }
    }

    #[DataProvider('forwardingCases')]
    public function testParentInvocationOptionsNeverBecomeExplicitChildControls(string $mode, string $key, mixed $value): void
    {
        $runs = 0; $seen = null; $parentSeen = null; $childResult = null;
        $childKey = $mode === 'forwarded' ? 'other' : 'value';
        $child = self::graph('leaf', $childKey, static function (array $state, RunnableConfig $config) use (&$runs, &$seen, $childKey): array {
            ++$runs; $seen = $config;
            self::assertSame([$childKey => 0], $state);
            return [$childKey => 1];
        });
        $saver = new MemorySaver();
        $body = $mode === 'forwarded' ? static function (array $state, RunnableConfig $config) use ($child, &$parentSeen, &$childResult): array {
            $parentSeen = $config;
            $childResult = $child->invoke(['other' => 0], $config);
            return ['value' => 1];
        } : $child;
        $parent = self::graph('parent', 'value', $body, ['checkpointer' => $saver]);
        $call = new RunnableConfig(configurable: ['thread_id' => $mode . '-' . $key], options: [$key => $value]);
        self::assertSame(['value' => 1], $parent->invoke(['value' => 0], $call));
        self::assertSame(1, $runs);
        self::assertSame([], self::invocationOptions($seen));
        if ($mode === 'forwarded') {
            self::assertSame(['other' => 1], $childResult);
            self::assertSame([], self::invocationOptions($parentSeen));
        }
        self::assertNotEmpty(iterator_to_array($saver->list(['configurable' => ['thread_id' => $mode . '-' . $key]])));
        self::assertSame([$key => $value], $call->options);
        self::assertSame(['thread_id' => $mode . '-' . $key], $call->configurable);
        self::assertNull(Config::getConfig());
    }

    public static function interruptCases(): iterable
    {
        yield 'before' => ['interruptBefore', 0, ['value' => 0], ['parent']];
        yield 'after' => ['interruptAfter', 1, ['value' => 1], ['tail']];
    }

    #[DataProvider('interruptCases')]
    public function testParentOwnInterruptOptionsStillControlItsLoop(string $key, int $expectedRuns, array $expectedResult, array $expectedNext): void
    {
        $runs = 0; $tailRuns = 0;
        $parent = (new StateGraph(Annotation::root(['value' => Annotation::withReducer(static fn ($a, $b) => $b, static fn () => 0)])))
            ->addNode('parent', static function () use (&$runs): array { ++$runs; return ['value' => 1]; })
            ->addNode('tail', static function () use (&$tailRuns): array { ++$tailRuns; return ['value' => 2]; })
            ->addEdge(Constants::START, 'parent')->addEdge('parent', 'tail')->compile(['checkpointer' => new MemorySaver()]);
        $call = new RunnableConfig(configurable: ['thread_id' => 'parent-' . $key], options: [$key => ['parent']]);
        self::assertSame($expectedResult, $parent->invoke(['value' => 0], $call));
        self::assertSame($expectedRuns, $runs);
        self::assertSame(0, $tailRuns);
        self::assertSame($expectedNext, $parent->getState($call)->next);
        self::assertSame([$key => ['parent']], $call->options);
    }

    public static function explicitChildCases(): iterable
    {
        foreach (['fresh', 'forwarded overlay', 'bound'] as $mode) {
            foreach (['interruptBefore' => 0, 'interruptAfter' => 1] as $key => $runs) {
                yield $mode . '-' . $key => [$mode, $key, $runs];
            }
        }
    }

    #[DataProvider('explicitChildCases')]
    public function testNewExplicitOrBoundChildOptionsRemainChildControls(string $mode, string $key, int $expectedRuns): void
    {
        $runs = 0; $forwardedOptions = null; $childResult = 'not returned'; $interrupted = false;
        $child = self::graph('leaf', 'value', static function () use (&$runs): array { ++$runs; return ['value' => 1]; });
        if ($mode === 'bound') { $child = $child->withConfig([$key => ['leaf']]); }
        $parent = self::graph('parent', 'value', static function (array $state, RunnableConfig $config) use ($child, $mode, $key, &$forwardedOptions, &$childResult, &$interrupted): array {
            $forwardedOptions = $config->options;
            $call = match ($mode) {
                'fresh' => new RunnableConfig(options: [$key => ['leaf']]),
                'forwarded overlay' => $config->with(['options' => array_merge($config->options, [$key => ['leaf']])]),
                default => $config,
            };
            try {
                $childResult = $child->invoke($state, $call);
            } catch (GraphInterrupt $e) {
                $interrupted = true;
                throw $e;
            } finally {
                self::assertSame($forwardedOptions, $config->options);
            }
            return ['value' => 1];
        }, ['checkpointer' => new MemorySaver()]);
        $call = new RunnableConfig(configurable: ['thread_id' => $mode . '-' . $key], options: ['debug' => true]);
        self::assertSame(['value' => 0], $parent->invoke(['value' => 0], $call));
        self::assertSame($expectedRuns, $runs);
        self::assertTrue($interrupted);
        self::assertSame('not returned', $childResult);
        self::assertSame(['parent'], $parent->getState($call)->next);
    }

    public function testRootAndExplicitChildOutputSelectionStillApplyToTheirOwnGraphs(): void
    {
        $schema = Annotation::root(['value' => Annotation::withReducer(static fn ($a, $b) => $b, static fn () => 0), 'extra' => Annotation::withReducer(static fn ($a, $b) => $b, static fn () => 0)]);
        $child = (new StateGraph($schema))->addNode('leaf', static function (array $state, RunnableConfig $config): array {
            self::assertSame([], self::invocationOptions($config));
            return ['value' => 2, 'extra' => 3];
        })->addEdge(Constants::START, 'leaf')->compile();
        $parent = (new StateGraph($schema))->addNode('parent', static function (array $state, RunnableConfig $config) use ($child): array {
            self::assertSame([], self::invocationOptions($config));
            $childConfig = $config->with(['options' => array_merge($config->options, ['inputKeys' => Constants::START, 'outputKeys' => ['extra']])]);
            self::assertSame(['extra' => 3], $child->invoke($state, $childConfig));
            return ['value' => 4, 'extra' => 5];
        })->addEdge(Constants::START, 'parent')->compile();
        $call = new RunnableConfig(options: ['inputKeys' => Constants::START, 'outputKeys' => ['value'], 'debug' => true]);
        self::assertSame(['value' => 4], $parent->invoke(['value' => 0, 'extra' => 0], $call));
        self::assertSame(['inputKeys' => Constants::START, 'outputKeys' => ['value'], 'debug' => true], $call->options);
    }

    public function testForwardingPreservesControlCallbacksPrivateContextWriterAndCheckpointLineage(): void
    {
        $control = new RunControl(); $marker = new \stdClass(); $handler = new CallbackHandler(); $saver = new MemorySaver(); $parentConfig = null; $leafConfig = null;
        $child = self::graph('leaf', 'other', static function (array $state, RunnableConfig $config) use (&$leafConfig, $control, $marker, $handler, $saver): array {
            $leafConfig = $config;
            self::assertSame($control, $config->options['control']);
            self::assertSame($marker, $config->options['customOption']);
            self::assertSame([$handler], $config->callbacks);
            self::assertSame($marker, $config->metadata['marker']);
            self::assertContains('forward', $config->tags);
            self::assertSame('tenant', $config->configurable['tenant']);
            self::assertSame($saver, $config->configurable[Constants::CONFIG_KEY_CHECKPOINTER]);
            self::assertIsCallable($config->configurable[Constants::CONFIG_KEY_READ]);
            self::assertIsCallable($config->configurable[Constants::CONFIG_KEY_SEND]);
            self::assertSame(['other' => 0], Config::getCurrentTaskInput());
            self::assertSame(0, ($config->configurable[Constants::CONFIG_KEY_READ])('other'));
            Config::getWriter()('leaf-write');
            return ['other' => 1];
        });
        $parent = self::graph('parent', 'value', static function (array $state, RunnableConfig $config) use ($child, &$parentConfig, $control, $marker, $handler): array {
            $parentConfig = $config;
            self::assertSame($control, $config->options['control']);
            self::assertSame($marker, $config->options['customOption']);
            self::assertSame([$handler], $config->callbacks);
            self::assertSame([], self::invocationOptions($config));
            self::assertSame(['value' => 0], Config::getCurrentTaskInput());
            self::assertSame(0, ($config->configurable[Constants::CONFIG_KEY_READ])('value'));
            $read = $config->configurable[Constants::CONFIG_KEY_READ];
            $scratchpad = $config->configurable[Constants::CONFIG_KEY_SCRATCHPAD];
            Config::getWriter()('parent-write');
            self::assertSame(['other' => 1], $child->invoke(['other' => 0], $config));
            self::assertSame($read, $config->configurable[Constants::CONFIG_KEY_READ]);
            self::assertSame($scratchpad, $config->configurable[Constants::CONFIG_KEY_SCRATCHPAD]);
            self::assertSame(['value' => 0], Config::getCurrentTaskInput());
            return ['value' => 1];
        }, ['checkpointer' => $saver]);
        $call = new RunnableConfig(tags: ['forward'], metadata: ['marker' => $marker], callbacks: [$handler], configurable: ['thread_id' => 'preserve', 'tenant' => 'tenant'], options: ['control' => $control, 'customOption' => $marker, 'debug' => true, 'outputKeys' => ['value'], 'streamMode' => ['tasks', 'custom', 'updates'], 'subgraphs' => true]);
        $originalOptions = $call->options;
        $run = $parent->stream(['value' => 0], $call); $chunks = iterator_to_array($run);
        self::assertSame(['value' => 1], $run->getReturn());
        self::assertSame(['parent-write', 'leaf-write'], array_values(array_map(static fn ($chunk) => $chunk[2], array_filter($chunks, static fn ($chunk) => $chunk[1] === 'custom'))));
        self::assertNotSame($parentConfig->configurable[Constants::CONFIG_KEY_TASK_ID], $leafConfig->configurable[Constants::CONFIG_KEY_TASK_ID]);
        self::assertNotSame($parentConfig->configurable[Constants::CONFIG_KEY_READ], $leafConfig->configurable[Constants::CONFIG_KEY_READ]);
        self::assertSame('parent|leaf', Config::recastCheckpointNamespace($leafConfig->configurable['checkpoint_ns']));
        self::assertArrayHasKey('', $leafConfig->configurable[Constants::CONFIG_KEY_CHECKPOINT_MAP]);
        self::assertArrayHasKey($parentConfig->configurable['checkpoint_ns'], $leafConfig->configurable[Constants::CONFIG_KEY_CHECKPOINT_MAP]);
        self::assertSame($originalOptions, $call->options);
        self::assertSame([$handler], $call->callbacks);
        self::assertNull(Config::getConfig());
    }

    public function testSendPushTaskReceivesFilteredOptionsAndPreservesItsInputAndCustomWriter(): void
    {
        $control = new RunControl(); $seen = []; $schema = Annotation::root(['items' => Annotation::withReducer(static fn ($a, $b) => array_merge($a, $b), static fn () => [])]);
        $graph = (new StateGraph($schema))->addNode('fan', static fn (): array => ['items' => ['fan']])
            ->addNode('work', static function (array $state, RunnableConfig $config) use (&$seen, $control): array {
                $seen[] = $state; self::assertSame([], self::invocationOptions($config));
                self::assertSame($control, $config->options['control']); self::assertSame('kept', $config->options['customOption']);
                self::assertSame($state, Config::getCurrentTaskInput()); Config::getWriter()($state['topic']);
                return ['items' => [$state['topic']]];
            })->addEdge(Constants::START, 'fan')->addConditionalEdges('fan', static fn (): array => [new Send('work', ['topic' => 'x']), new Send('work', ['topic' => 'y'])])->compile();
        $run = $graph->stream(['items' => []], new RunnableConfig(options: ['interruptBefore' => ['unrelated'], 'interruptAfter' => ['unrelated'], 'inputKeys' => Constants::START, 'debug' => true, 'outputKeys' => ['items'], 'streamMode' => 'custom', 'control' => $control, 'customOption' => 'kept']));
        self::assertSame([['custom', 'x'], ['custom', 'y']], iterator_to_array($run));
        self::assertSame([['topic' => 'x'], ['topic' => 'y']], $seen);
        self::assertSame(['items' => ['fan', 'x', 'y']], $run->getReturn());
    }

    public function testFunctionalCallTaskGetsFilteredOptionsAndRetainsCallContext(): void
    {
        $control = new RunControl(); $seen = null;
        $task = Func::task('child', static function (string $input) use (&$seen, $control): string {
            $seen = Config::getConfig(); self::assertSame([], self::invocationOptions($seen));
            self::assertSame($control, $seen->options['control']); self::assertSame('kept', $seen->options['customOption']);
            self::assertIsCallable($seen->configurable[Constants::CONFIG_KEY_CALL]);
            self::assertSame([$input], Config::getCurrentTaskInput()); Config::getWriter()('call-write');
            return strtoupper($input);
        });
        $graph = Func::entrypoint('entry', static fn (string $input): string => Await::sync($task($input)));
        $call = new RunnableConfig(options: ['interruptBefore' => ['unrelated'], 'interruptAfter' => ['unrelated'], 'inputKeys' => Constants::START, 'debug' => true, 'outputKeys' => Constants::END, 'streamMode' => 'custom', 'control' => $control, 'customOption' => 'kept']);
        $run = $graph->stream('world', $call);
        self::assertSame([['custom', 'call-write']], iterator_to_array($run));
        self::assertSame('WORLD', $run->getReturn());
        self::assertNotNull($seen);
        self::assertSame(true, $call->options['debug']);
    }
}
