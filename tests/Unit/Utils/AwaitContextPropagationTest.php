<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Utils\AsyncLocalStorage;
use LangChain\Utils\Await;
use LangChain\Utils\ContextVariables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Await::class)]
final class AwaitContextPropagationTest extends TestCase
{
    protected function tearDown(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
    }

    public function testSpawnCapturesCreationContextBeforeAnotherScopeDrivesIt(): void
    {
        AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage());
        $seen = [];
        $id = AsyncLocalStorage::runWithConfig('A', static function () use (&$seen): int {
            ContextVariables::setContextVariable('tenant', 'A');
            return Await::spawn(static function () use (&$seen): void {
                $seen[] = [AsyncLocalStorage::getRunnableConfig(), ContextVariables::getContextVariable('tenant')];
                \Fiber::suspend();
                $seen[] = [AsyncLocalStorage::getRunnableConfig(), ContextVariables::getContextVariable('tenant')];
            });
        });
        try {
            AsyncLocalStorage::runWithConfig('B', static function (): void {
                Await::runFiber(static function (): void { \Fiber::suspend(); \Fiber::suspend(); });
                self::assertSame('B', AsyncLocalStorage::getRunnableConfig());
            });
            self::assertSame([['A', 'A'], ['A', 'A']], $seen);
            self::assertNull(AsyncLocalStorage::getRunnableConfig());
        } finally {
            Await::cancel($id);
        }
    }

    public function testNestedManagedFibersInheritWithoutMutatingParentVariables(): void
    {
        AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage());
        $result = AsyncLocalStorage::runWithConfig('parent', static function (): array {
            ContextVariables::setContextVariable('tenant', 'parent');
            $child = Await::runFiber(static function (): array {
                self::assertSame('parent', AsyncLocalStorage::getRunnableConfig());
                ContextVariables::setContextVariable('tenant', 'child');
                return Await::runFiber(static function (): array {
                    \Fiber::suspend();
                    return [AsyncLocalStorage::getRunnableConfig(), ContextVariables::getContextVariable('tenant')];
                });
            });
            return [$child, ContextVariables::getContextVariable('tenant')];
        });
        self::assertSame([['parent', 'child'], 'parent'], $result);
        self::assertNull(AsyncLocalStorage::getRunnableConfig());
    }

    public function testManagedFailureRestoresTheDrivingScope(): void
    {
        AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage());
        AsyncLocalStorage::runWithConfig('parent', static function (): void {
            try {
                Await::runFiber(static function (): void {
                    AsyncLocalStorage::getInstance()->enterWith(['extra' => [AsyncLocalStorage::CHILD_CONFIG_KEY => 'child']]);
                    \Fiber::suspend();
                    throw new \RuntimeException('managed failure');
                });
                self::fail('Expected managed failure');
            } catch (\RuntimeException $error) {
                self::assertSame('managed failure', $error->getMessage());
            }
            self::assertSame('parent', AsyncLocalStorage::getRunnableConfig());
        });
        self::assertNull(AsyncLocalStorage::getRunnableConfig());
    }

    public function testManagedFibersPreserveExplicitMockProvider(): void
    {
        $mock = new AsyncLocalStorage(true);
        AsyncLocalStorage::setGlobalInstance($mock);
        self::assertNull(Await::runFiber(static fn (): mixed => AsyncLocalStorage::runWithConfig('unused', static fn (): mixed => AsyncLocalStorage::getRunnableConfig())));
        self::assertSame($mock, AsyncLocalStorage::getGlobalInstance());
    }
}
