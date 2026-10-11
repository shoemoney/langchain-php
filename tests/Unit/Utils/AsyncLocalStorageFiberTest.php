<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Utils\AsyncLocalStorage;
use LangChain\Utils\ContextVariables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsyncLocalStorage::class)]
final class AsyncLocalStorageFiberTest extends TestCase
{
    protected function tearDown(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
    }

    public static function resumeOrders(): iterable
    {
        yield 'A then B' => [[0, 1]];
        yield 'B then A' => [[1, 0]];
    }

    #[DataProvider('resumeOrders')]
    public function testSuspendedFramesAndContextVariablesStayWithinTheirFiber(array $order): void
    {
        $storage = new AsyncLocalStorage();
        AsyncLocalStorage::setGlobalInstance($storage);
        $storage->run('main', static function () use ($storage, $order): void {
            $fibers = [];
            foreach (['A', 'B'] as $name) {
                $fibers[] = new \Fiber(static fn (): mixed => AsyncLocalStorage::runWithConfig($name, static function () use ($name): array {
                    ContextVariables::setContextVariable('tenant', $name);
                    \Fiber::suspend();
                    return [AsyncLocalStorage::getRunnableConfig(), ContextVariables::getContextVariable('tenant')];
                }));
            }
            foreach ($fibers as $fiber) {
                $fiber->start();
                self::assertSame('main', $storage->getStore());
            }
            foreach ($order as $index) {
                $fibers[$index]->resume();
                self::assertSame('main', $storage->getStore());
            }
            self::assertSame(['A', 'A'], $fibers[0]->getReturn());
            self::assertSame(['B', 'B'], $fibers[1]->getReturn());
            self::assertNull(ContextVariables::getContextVariable('tenant'));
        });
        self::assertNull($storage->getStore());
    }

    public function testRawFiberStartsEmptyAndEnterWithDoesNotChangeMain(): void
    {
        $storage = new AsyncLocalStorage();
        $storage->enterWith('main');
        $fiber = new \Fiber(static function () use ($storage): array {
            $before = $storage->getStore();
            $storage->enterWith('fiber');
            \Fiber::suspend();
            return [$before, $storage->getStore()];
        });
        $fiber->start();
        self::assertSame('main', $storage->getStore());
        $fiber->resume();
        self::assertSame([null, 'fiber'], $fiber->getReturn());
        self::assertSame('main', $storage->getStore());
    }

    public function testNestedFramesRestoreAfterInjectedFailure(): void
    {
        $storage = new AsyncLocalStorage();
        $fiber = new \Fiber(static fn (): mixed => $storage->run('outer', static function () use ($storage): mixed {
            try {
                $storage->run('inner', static function () use ($storage): void { $storage->enterWith('changed'); \Fiber::suspend(); });
            } catch (\RuntimeException $error) {
                self::assertSame('stop', $error->getMessage());
            }
            return $storage->getStore();
        }));
        $fiber->start();
        self::assertNull($storage->getStore());
        $fiber->throw(new \RuntimeException('stop'));
        self::assertSame('outer', $fiber->getReturn());
        self::assertNull($storage->getStore());
    }

    public function testStorageDoesNotRetainCompletedFibersAndInstancesAreIndependent(): void
    {
        $one = new AsyncLocalStorage();
        $two = new AsyncLocalStorage();
        $fiber = new \Fiber(static function () use ($one, $two): void {
            $one->enterWith('one');
            self::assertNull($two->getStore());
            $two->enterWith('two');
            self::assertSame('one', $one->getStore());
        });
        $reference = \WeakReference::create($fiber);
        $fiber->start();
        unset($fiber);
        gc_collect_cycles();
        self::assertNull($reference->get());
        self::assertNull($one->getStore());
        self::assertNull($two->getStore());
    }

    public function testExplicitMockRemainsANoOpAcrossFibers(): void
    {
        $mock = new AsyncLocalStorage(true);
        AsyncLocalStorage::setGlobalInstance($mock);
        $fiber = new \Fiber(static fn (): mixed => AsyncLocalStorage::runWithConfig('ignored', static fn (): mixed => AsyncLocalStorage::getRunnableConfig()));
        $fiber->start();
        self::assertNull($fiber->getReturn());
        self::assertSame($mock, AsyncLocalStorage::getGlobalInstance());
    }
}
