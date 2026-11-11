<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\AsyncLocalStorage;
use LangChain\Utils\Await;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Func\Func;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\PregelScratchpad;
use LangGraph\Pregel\Utils\Config;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

#[CoversClass(PregelScratchpad::class)]
final class FiberTaskContextTest extends TestCase
{
    protected function tearDown(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
        PregelScratchpad::setCurrentConfig(null);
    }

    private static function graph(callable $body, array $options = []): \LangGraph\Pregel\Pregel
    {
        $schema = Annotation::root(['value' => Annotation::withReducer(static fn (array $a, array $b): array => array_merge($a, $b), static fn (): array => [])]);
        return (new StateGraph($schema))->addNode('node', $body)->addEdge(Constants::START, 'node')->compile($options);
    }

    public static function resumeOrders(): iterable
    {
        yield 'A then B' => [[0, 1]];
        yield 'B then A' => [[1, 0]];
    }

    #[DataProvider('resumeOrders')]
    public function testSharedCompiledGraphTasksAndWritersStayRunLocalAcrossSuspension(array $order): void
    {
        AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage());
        $seen = [];
        $graph = self::graph(static function (array $state, RunnableConfig $config) use (&$seen): array {
            $tenant = $config->configurable['tenant'];
            foreach (['before', 'after'] as $phase) {
                $ambient = Config::getConfig();
                $seen[$tenant][$phase] = [
                    $ambient?->configurable['tenant'] ?? null,
                    Config::getCurrentTaskInput()['value'],
                    $ambient?->configurable[Constants::CONFIG_KEY_CHECKPOINT_NS] ?? null,
                    AsyncLocalStorage::getRunnableConfig() === PregelScratchpad::currentConfig(),
                ];
                if ($phase === 'before') {
                    \Fiber::suspend();
                }
            }
            Config::getWriter()('custom-' . Config::getConfig()->configurable['tenant']);
            return ['value' => ['done-' . $tenant]];
        });
        $main = new RunnableConfig(runName: 'main');
        AsyncLocalStorage::runWithConfig($main, static function () use ($graph, $order, &$seen, $main): void {
            $fibers = [];
            foreach (['A', 'B'] as $tenant) {
                $fibers[] = new \Fiber(static function () use ($graph, $tenant): array {
                    $outer = new RunnableConfig(runName: 'outer-' . $tenant, configurable: ['tenant' => 'outer']);
                    return AsyncLocalStorage::runWithConfig($outer, static function () use ($graph, $tenant, $outer): array {
                        $run = $graph->stream(['value' => [$tenant]], new RunnableConfig(configurable: ['tenant' => $tenant], options: ['streamMode' => ['custom', 'tasks'], 'subgraphs' => true]));
                        $chunks = iterator_to_array($run);
                        self::assertSame($outer, Config::getConfig());
                        self::assertSame($outer, PregelScratchpad::currentConfig());
                        return [$chunks, $run->getReturn()];
                    });
                });
            }
            foreach ($fibers as $fiber) {
                $fiber->start();
                self::assertSame($main, Config::getConfig());
                self::assertSame($main, PregelScratchpad::currentConfig());
            }
            foreach ($order as $index) {
                $fibers[$index]->resume();
                self::assertSame($main, Config::getConfig());
            }
            foreach (['A', 'B'] as $index => $tenant) {
                [$chunks, $output] = $fibers[$index]->getReturn();
                self::assertSame(['value' => [$tenant, 'done-' . $tenant]], $output);
                self::assertSame([$tenant, [$tenant]], array_slice($seen[$tenant]['before'], 0, 2));
                self::assertSame($seen[$tenant]['before'], $seen[$tenant]['after']);
                self::assertTrue($seen[$tenant]['after'][3]);
                self::assertStringStartsWith('node:', $seen[$tenant]['after'][2]);
                $custom = array_values(array_filter($chunks, static fn (array $chunk): bool => $chunk[1] === 'custom'));
                self::assertSame([[[], 'custom', 'custom-' . $tenant]], $custom);
            }
            self::assertNotSame($seen['A']['before'][2], $seen['B']['before'][2]);
        });
        self::assertNull(AsyncLocalStorage::getRunnableConfig());
        self::assertNull(PregelScratchpad::currentConfig());
    }

    public function testManagedDescendantCanCallARealFunctionalPushTask(): void
    {
        $child = Func::task('child', static function (string $input): string {
            self::assertSame(Config::getConfig(), PregelScratchpad::currentConfig());
            self::assertSame([$input], Config::getCurrentTaskInput());
            Config::getWriter()('child-' . $input);
            return strtoupper($input);
        });
        $graph = Func::entrypoint('entry', static fn (string $input): string => Await::runFiber(static fn (): string => Await::sync($child($input))));
        $run = $graph->stream('x', new RunnableConfig(options: ['streamMode' => ['custom', 'tasks']]));
        $chunks = iterator_to_array($run);
        self::assertContains(['custom', 'child-x'], $chunks);
        self::assertSame('X', $run->getReturn());
        self::assertNull(PregelScratchpad::currentConfig());
        self::assertNull(AsyncLocalStorage::getRunnableConfig());
    }

    public function testTaskFailureRestoresOuterAmbientConfig(): void
    {
        AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage());
        $outer = new RunnableConfig(runName: 'outside');
        $graph = self::graph(static function (): never {
            self::assertSame(Config::getConfig(), AsyncLocalStorage::getRunnableConfig());
            throw new \RuntimeException('node failed');
        });
        AsyncLocalStorage::runWithConfig($outer, static function () use ($outer, $graph): void {
            try {
                $graph->invoke(['value' => []]);
                self::fail('Expected node failure');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('node failed', $error->getMessage());
            }
            self::assertSame($outer, Config::getConfig());
            self::assertSame($outer, PregelScratchpad::currentConfig());
        });
        self::assertNull(PregelScratchpad::currentConfig());
    }

    public function testInterruptAndResumePublishTheirOwnConfigAndLeaveNoAmbientTask(): void
    {
        $graph = self::graph(static fn (): array => ['value' => [interrupt('question')]], ['checkpointer' => new MemorySaver()]);
        $config = new RunnableConfig(configurable: ['thread_id' => 'fiber-context-resume']);
        self::assertSame(['value' => []], $graph->invoke(['value' => []], $config));
        self::assertNull(Config::getConfig());
        self::assertNull(AsyncLocalStorage::getRunnableConfig());
        self::assertSame(['value' => ['answer']], $graph->invoke(new Command(resume: 'answer'), $config));
        self::assertNull(Config::getConfig());
        self::assertNull(PregelScratchpad::currentConfig());
    }

    public function testTaskBootstrapInstallsOnlyAnAbsentProviderAndKeepsExplicitMock(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
        $config = new RunnableConfig(runName: 'task');
        PregelScratchpad::withConfig($config, static function () use ($config): void {
            self::assertSame($config, AsyncLocalStorage::getRunnableConfig());
            self::assertSame($config, Config::getConfig());
        });
        self::assertInstanceOf(AsyncLocalStorage::class, AsyncLocalStorage::getGlobalInstance());
        self::assertNull(Config::getConfig());
        $mock = new AsyncLocalStorage(true);
        AsyncLocalStorage::setGlobalInstance($mock);
        PregelScratchpad::withConfig($config, static function () use ($config): void {
            self::assertNull(AsyncLocalStorage::getRunnableConfig());
            self::assertSame($config, Config::getConfig());
        });
        self::assertSame($mock, AsyncLocalStorage::getGlobalInstance());
        self::assertNull(PregelScratchpad::currentConfig());
    }

    public function testAmbientObjectAndArrayHaveTheSamePrecedenceInBothConfigHelpers(): void
    {
        AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage());
        PregelScratchpad::setCurrentConfig(new RunnableConfig(runName: 'compatibility'));
        foreach ([new RunnableConfig(runName: 'ambient'), ['runName' => 'ambient', 'writer' => static fn () => null]] as $ambient) {
            AsyncLocalStorage::runWithConfig($ambient, static function (): void {
                self::assertEquals(Config::getConfig(), PregelScratchpad::currentConfig());
                self::assertSame('ambient', PregelScratchpad::currentConfig()?->runName);
            });
        }
        self::assertSame('compatibility', PregelScratchpad::currentConfig()?->runName);
    }

    public function testManualCompatibilityTaskConfigIsFiberLocal(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
        $main = new RunnableConfig(runName: 'main');
        PregelScratchpad::setCurrentConfig($main);
        $fiber = new \Fiber(static function (): ?RunnableConfig {
            self::assertNull(PregelScratchpad::currentConfig());
            PregelScratchpad::setCurrentConfig(new RunnableConfig(runName: 'fiber'));
            \Fiber::suspend();
            return PregelScratchpad::currentConfig();
        });
        $fiber->start();
        self::assertSame($main, PregelScratchpad::currentConfig());
        $fiber->resume();
        self::assertSame('fiber', $fiber->getReturn()?->runName);
    }

    public function testNullTaskScopeClearsAndRestoresBothConfigViews(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
        $outer = new RunnableConfig(runName: 'outer');
        PregelScratchpad::withConfig($outer, static function () use ($outer): void {
            PregelScratchpad::withConfig(null, static function (): void {
                self::assertNull(Config::getConfig());
                self::assertNull(PregelScratchpad::currentConfig());
                self::assertNull(AsyncLocalStorage::getRunnableConfig());
            });
            self::assertSame($outer, Config::getConfig());
            self::assertSame($outer, PregelScratchpad::currentConfig());
        });
        self::assertNull(Config::getConfig());
    }
}
