<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Utils\AsyncLocalStorage;
use LangChain\Utils\ContextVariables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `singletons/tests/async_local_storage.test.ts`.
 *
 * Upstream drives `RunnableLambda`s through `AsyncLocalStorage` from `node:async_hooks`
 * and reads the ambient config back inside nested runnables. The runnables here do not
 * read ambient state themselves (that wiring belongs to the Runnable base class), so the
 * lambdas call {@see AsyncLocalStorage::runWithConfig()} explicitly and the assertions
 * are on what the storage hands back. The "no tags before initialization, tags after"
 * contract is preserved verbatim. The two `streamEvents` upstream cases need a
 * `streamEvents` implementation and LangSmith's `RunTree`; they are skipped (see report).
 */
#[CoversClass(AsyncLocalStorage::class)]
final class AsyncLocalStorageTest extends TestCase
{
    protected function setUp(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
    }

    protected function tearDown(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(string ...$tags): array
    {
        return ['configurable' => ['sampleKey' => 'sampleValue'], 'tags' => $tags];
    }

    public function testConfigIsAutomaticallyPopulatedAfterSettingGlobalAsyncLocalStorage(): void
    {
        $inner = RunnableLambda::from(static fn (mixed $input): mixed => AsyncLocalStorage::getRunnableConfig());
        $outer = RunnableLambda::from(static fn (mixed $input): mixed => AsyncLocalStorage::runWithConfig(
            self::config('tester'),
            static fn (): mixed => $inner->invoke($input),
        ));

        $this->assertNull($outer->invoke(['hi' => true]));

        AsyncLocalStorage::initializeGlobalInstance(new AsyncLocalStorage());

        $res = $outer->invoke(['hi' => true]);
        $this->assertSame(['tester'], $res['tags']);
        $this->assertSame(['sampleKey' => 'sampleValue'], $res['configurable']);
    }

    public function testConfigReachesARunnableReturnedFromAnOuterOne(): void
    {
        AsyncLocalStorage::initializeGlobalInstance(new AsyncLocalStorage());
        $inner = RunnableLambda::from(static fn (mixed $input): mixed => AsyncLocalStorage::getRunnableConfig());
        $outer = RunnableLambda::from(static fn (): RunnableLambda => $inner);

        $res = AsyncLocalStorage::runWithConfig(
            self::config('test_recursive'),
            static fn (): mixed => $outer->invoke([])->invoke([]),
        );

        $this->assertSame(['test_recursive'], $res['tags']);
    }

    public function testRunnableConfigObjectsPassThroughUnchanged(): void
    {
        AsyncLocalStorage::initializeGlobalInstance(new AsyncLocalStorage());
        $config = new RunnableConfig(tags: ['object']);

        $seen = AsyncLocalStorage::runWithConfig($config, static fn (): mixed => AsyncLocalStorage::getRunnableConfig());

        $this->assertSame($config, $seen);
    }

    public function testNestedRunWithConfigOverridesAndThenRestoresTheParent(): void
    {
        AsyncLocalStorage::initializeGlobalInstance(new AsyncLocalStorage());

        $log = AsyncLocalStorage::runWithConfig(self::config('outer'), static function (): array {
            $before = AsyncLocalStorage::getRunnableConfig()['tags'];
            $inside = AsyncLocalStorage::runWithConfig(self::config('inner'), static fn (): array => AsyncLocalStorage::getRunnableConfig()['tags']);
            $after = AsyncLocalStorage::getRunnableConfig()['tags'];

            return [$before, $inside, $after];
        });

        $this->assertSame([['outer'], ['inner'], ['outer']], $log);
        $this->assertNull(AsyncLocalStorage::getRunnableConfig());
    }

    public function testContextVariablesSurviveANestedRunWithConfig(): void
    {
        AsyncLocalStorage::initializeGlobalInstance(new AsyncLocalStorage());

        $seen = AsyncLocalStorage::runWithConfig(self::config('a'), static function (): mixed {
            ContextVariables::setContextVariable('foo', 'bar');

            return AsyncLocalStorage::runWithConfig(self::config('b'), static fn (): mixed => ContextVariables::getContextVariable('foo'));
        });

        $this->assertSame('bar', $seen);
    }

    public function testAvoidCreatingRootRunTreeStoresNoConfigButKeepsContextVariables(): void
    {
        AsyncLocalStorage::initializeGlobalInstance(new AsyncLocalStorage());

        $result = AsyncLocalStorage::runWithConfig(self::config('a'), static function (): array {
            ContextVariables::setContextVariable('foo', 'kept');

            return AsyncLocalStorage::runWithConfig(self::config('b'), static fn (): array => [
                AsyncLocalStorage::getRunnableConfig(),
                ContextVariables::getContextVariable('foo'),
            ], true);
        });

        $this->assertSame([null, 'kept'], $result);
    }

    // ----------------------------------------------------- the storage contract

    public function testUninitializedInstanceIsAnInertMock(): void
    {
        $instance = AsyncLocalStorage::getInstance();

        $this->assertNull(AsyncLocalStorage::getGlobalInstance());
        $this->assertNull($instance->getStore());
        $this->assertSame('result', $instance->run(['x' => 1], function () use ($instance): string {
            $this->assertNull($instance->getStore());

            return 'result';
        }));
        $instance->enterWith(['y' => 2]);
        $this->assertNull($instance->getStore());
    }

    public function testInitializeGlobalInstanceOnlyInstallsTheFirst(): void
    {
        $first = new AsyncLocalStorage();
        AsyncLocalStorage::initializeGlobalInstance($first);
        AsyncLocalStorage::initializeGlobalInstance(new AsyncLocalStorage());

        $this->assertSame($first, AsyncLocalStorage::getGlobalInstance());
        $this->assertSame($first, AsyncLocalStorage::getInstance());
    }

    public function testRunScopesTheStoreToTheCallbackAndRestoresIt(): void
    {
        $als = new AsyncLocalStorage();

        $als->run('outer', function () use ($als): void {
            $this->assertSame('outer', $als->getStore());
            $als->run('inner', fn () => $this->assertSame('inner', $als->getStore()));
            $this->assertSame('outer', $als->getStore());
        });

        $this->assertNull($als->getStore());
    }

    public function testRunRestoresTheStoreWhenTheCallbackThrows(): void
    {
        $als = new AsyncLocalStorage();
        $als->enterWith('base');

        try {
            $als->run('doomed', static function (): never {
                throw new \RuntimeException('boom');
            });
            $this->fail('expected the exception');
        } catch (\RuntimeException) {
        }

        $this->assertSame('base', $als->getStore());
    }

    public function testEnterWithLastsOnlyUntilTheEnclosingRunReturns(): void
    {
        $als = new AsyncLocalStorage();
        $als->enterWith('top');

        $als->run('frame', function () use ($als): void {
            $als->enterWith('replaced');
            $this->assertSame('replaced', $als->getStore());
        });

        $this->assertSame('top', $als->getStore());
    }

    public function testEnterWithAtTopLevelPersists(): void
    {
        $als = new AsyncLocalStorage();
        $als->enterWith(['k' => 'v']);

        $this->assertSame(['k' => 'v'], $als->getStore());
    }

    public function testRunReturnsTheCallbackResult(): void
    {
        $this->assertSame(42, (new AsyncLocalStorage())->run('s', static fn (): int => 42));
    }
}
