<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Tracers\Serialized;
use LangChain\Utils\AsyncLocalStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The config a lambda receives is normalized against the ambient config at the leaf.
 *
 * Covers the argument half of upstream `RunnableLambda._invoke` only. Nothing here publishes an
 * ambient config for nested runnables, opens a run, or consumes returned generators or runnables.
 */
#[CoversClass(RunnableLambda::class)]
final class RunnableLambdaAmbientContextTest extends TestCase
{
    private static int $calls = 0;

    /** @var array{config: RunnableConfig, ambient: mixed, store: mixed}|null */
    private static ?array $seen = null;

    protected function setUp(): void
    {
        AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage());
        self::$calls = 0;
        self::$seen = null;
    }

    protected function tearDown(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
    }

    private static function recorder(): RunnableLambda
    {
        return RunnableLambda::from(static function (mixed $x, RunnableConfig $c): string {
            self::$seen = ['config' => $c, 'ambient' => AsyncLocalStorage::getRunnableConfig(), 'store' => AsyncLocalStorage::getInstance()->getStore()];

            return 'ok';
        });
    }

    private static function handler(): BaseCallbackHandler
    {
        return new class extends BaseCallbackHandler {
            /** @var list<string> */
            public array $events = [];

            public function handleChainStart(Serialized $chain, array $inputs, string $runId, ?string $runType = null, array $tags = [], array $metadata = [], ?string $runName = null, ?string $parentRunId = null, array $extra = []): void
            {
                $this->events[] = 'start';
            }

            public function handleChainEnd(array $outputs, string $runId, ?string $parentRunId = null, array $tags = [], array $kwargs = []): void
            {
                $this->events[] = 'end';
            }
        };
    }

    public function testOmittedConfigInheritsAmbientFieldsWithoutReplacingTheExistingFrame(): void
    {
        $handler = new \stdClass();
        $ambient = new RunnableConfig(
            tags: ['ambient'], metadata: ['m' => 'A'], runId: ['parent-id'], callbacks: [$handler], maxConcurrency: 3,
            recursionLimit: 100, runName: 'parent-name', runIdParent: 'synthetic-parent', configurable: ['tenant' => 'parent'],
            options: ['temperature' => 0.5],
        );
        AsyncLocalStorage::runWithConfig($ambient, static function () use ($ambient, $handler): void {
            $outer = AsyncLocalStorage::getInstance()->getStore();
            foreach ([null, new RunnableConfig()] as $call) {
                self::assertSame('ok', self::recorder()->invoke('x', $call));
                $c = self::$seen['config'];
                self::assertSame(['ambient'], $c->tags);
                self::assertSame(['m' => 'A'], $c->metadata);
                self::assertSame([$handler], $c->callbacks);
                self::assertSame(['tenant' => 'parent'], $c->configurable);
                self::assertSame(100, $c->recursionLimit);
                self::assertSame(3, $c->maxConcurrency);
                self::assertSame(['temperature' => 0.5], $c->options);
                self::assertSame([], $c->runId, 'ambient run identity is not inherited');
                self::assertNull($c->runName);
                self::assertSame('synthetic-parent', $c->runIdParent, 'retained PHP ancestry adaptation from C1');
                self::assertNotSame($ambient, $c);
                self::assertSame($ambient, self::$seen['ambient'], 'no frame is published for the callable');
                self::assertSame($outer, self::$seen['store']);
            }
            self::assertSame($outer, AsyncLocalStorage::getInstance()->getStore());
        });
        self::assertSame(['ambient'], $ambient->tags);
        self::assertNull(AsyncLocalStorage::getRunnableConfig());
    }

    public function testExplicitCallerFieldsReplaceWholeAmbientFieldsAndOptionsOverlayPerKey(): void
    {
        $ambient = new RunnableConfig(tags: ['ambient'], metadata: ['m' => 'A'], callbacks: [new \stdClass()], recursionLimit: 100, configurable: ['tenant' => 'parent', 'other' => 1], options: ['temperature' => 0.5, 'stop' => ['A']]);
        $callerHandler = new \stdClass();
        $caller = new RunnableConfig(tags: ['c', 'c'], metadata: ['m' => 'C'], runId: ['caller-id'], callbacks: [$callerHandler], recursionLimit: 10, runName: 'caller-name', configurable: ['tenant' => 'caller'], options: ['stop' => ['C'], 'max_tokens' => 9]);
        AsyncLocalStorage::runWithConfig($ambient, static fn () => self::recorder()->invoke('x', $caller));
        $c = self::$seen['config'];
        self::assertSame(['c', 'c'], $c->tags);
        self::assertSame(['m' => 'C'], $c->metadata);
        self::assertSame([$callerHandler], $c->callbacks);
        self::assertSame(['tenant' => 'caller'], $c->configurable);
        self::assertSame(10, $c->recursionLimit);
        self::assertSame(['caller-id'], $c->runId);
        self::assertSame('caller-name', $c->runName);
        self::assertSame(['temperature' => 0.5, 'stop' => ['C'], 'max_tokens' => 9], $c->options);
        self::assertNotSame($caller, $c);
        self::assertSame(['c', 'c'], $caller->tags);
        self::assertSame(['stop' => ['C'], 'max_tokens' => 9], $caller->options);
        self::assertSame($ambient, self::$seen['ambient']);
    }

    public static function emptyReplacements(): iterable
    {
        yield 'tags' => ['tags', [], []];
        yield 'metadata' => ['metadata', [], []];
        yield 'configurable' => ['configurable', [], []];
        yield 'callbacks' => ['callbacks', [], []];
        yield 'empty options keep ambient extras' => ['options', [], ['temperature' => 0.5]];
        yield 'null option replaces one key' => ['options', ['temperature' => null], ['temperature' => null]];
    }

    #[DataProvider('emptyReplacements')]
    public function testExplicitEmptyFieldsReplaceAmbientFields(string $field, array $value, array $expected): void
    {
        $ambient = new RunnableConfig(tags: ['A'], metadata: ['A' => 1], callbacks: [new \stdClass()], configurable: ['A' => 1], options: ['temperature' => 0.5]);
        AsyncLocalStorage::runWithConfig($ambient, static fn () => self::recorder()->invoke('x', RunnableConfig::fromArray([$field => $value])));
        self::assertSame($expected, self::$seen['config']->$field);
    }

    public function testConfigurableModelFillsMissingMetadataModel(): void
    {
        $ambient = new RunnableConfig(configurable: ['model' => 'ambient-model']);
        AsyncLocalStorage::runWithConfig($ambient, static fn () => self::recorder()->invoke('x'));
        self::assertSame(['model' => 'ambient-model'], self::$seen['config']->metadata);
        AsyncLocalStorage::runWithConfig($ambient, static fn () => self::recorder()->invoke('x', new RunnableConfig(metadata: ['model' => null])));
        self::assertSame(['model' => null], self::$seen['config']->metadata);
    }

    /**
     * C1 review residual: explicit default scalars must stay explicit when the normalized config is
     * normalized again, or a nondefault ambient value comes back. The inner lambda renormalizes the
     * config the outer lambda received.
     */
    public function testExplicitDefaultScalarsSurviveRenormalizationUnderNondefaultAmbient(): void
    {
        $inner = RunnableLambda::from(static fn (mixed $x, RunnableConfig $c): array => [$c->recursionLimit, $c->verbose]);
        $outer = RunnableLambda::from(static function (mixed $x, RunnableConfig $c) use ($inner): array {
            return [[$c->recursionLimit, $c->verbose], $inner->invoke($x, $c), $inner->invoke($x, RunnableConfig::normalizeFields($c))];
        });
        $ambient = new RunnableConfig(recursionLimit: 100, verbose: true);
        $result = AsyncLocalStorage::runWithConfig($ambient, static fn () => $outer->invoke('x', new RunnableConfig(recursionLimit: 25, verbose: false)));
        self::assertSame([[25, false], [25, false], [25, false]], $result);
        self::assertSame([[100, true], [100, true], [100, true]], AsyncLocalStorage::runWithConfig($ambient, static fn () => $outer->invoke('x')));
    }

    public function testNormalizedPresenceIsNotCallerSuppliedPresence(): void
    {
        $caller = new RunnableConfig();
        self::recorder()->invoke('x', $caller);
        self::assertNotSame($caller, self::$seen['config']);
        self::assertFalse($caller->isFieldProvided('metadata'));
        self::assertFalse($caller->isFieldProvided('verbose'));
        self::assertTrue(self::$seen['config']->isFieldProvided('metadata'));
        self::assertTrue(self::$seen['config']->isFieldProvided('verbose'));
    }

    public function testBindingKeepsBoundRunIdAndVerboseBecauseTheLeafNormalizesAfterTheMerge(): void
    {
        $ambient = new RunnableConfig(tags: ['ambient'], runId: ['ambient-id'], configurable: ['tenant' => 'parent']);
        $bound = self::recorder()->withConfig(['run_id' => ['bound-id'], 'verbose' => true, 'tags' => ['b']]);

        AsyncLocalStorage::runWithConfig($ambient, static fn () => $bound->invoke('x'));
        self::assertSame(['bound-id'], self::$seen['config']->runId);
        self::assertTrue(self::$seen['config']->verbose);
        self::assertSame(['b'], self::$seen['config']->tags);
        self::assertSame(['tenant' => 'parent'], self::$seen['config']->configurable);

        AsyncLocalStorage::runWithConfig($ambient, static fn () => $bound->invoke('x', new RunnableConfig(tags: ['c'], runId: ['caller-id'], verbose: false)));
        self::assertSame(['caller-id'], self::$seen['config']->runId);
        self::assertFalse(self::$seen['config']->verbose);
        self::assertSame(['b', 'c'], self::$seen['config']->tags);
    }

    public function testBindingFactoryRunsOnceOnTheMergedSnapshotBeforeLeafNormalization(): void
    {
        $snapshots = [];
        $binding = new RunnableBinding(self::recorder(), [], ['tags' => ['b']], [
            static function (RunnableConfig $merged) use (&$snapshots): array {
                $snapshots[] = [$merged->tags, $merged->configurable, $merged->isFieldProvided('metadata')];

                return ['metadata' => ['f' => 1]];
            },
        ]);
        $ambient = new RunnableConfig(tags: ['ambient'], metadata: ['ambient' => 1], configurable: ['tenant' => 'parent']);
        AsyncLocalStorage::runWithConfig($ambient, static fn () => $binding->invoke('x'));
        self::assertSame([[['b'], [], false]], $snapshots);
        self::assertSame(['b'], self::$seen['config']->tags);
        self::assertSame(['f' => 1], self::$seen['config']->metadata);
        self::assertSame(['tenant' => 'parent'], self::$seen['config']->configurable);
    }

    public static function arities(): iterable
    {
        yield 'one parameter' => [static function (mixed $x): array { self::$calls++; return [$x, func_num_args()]; }, [], ['a', 1]];
        yield 'untyped second' => [static function (mixed $x, $c): array { self::$calls++; return $c->tags; }, [], ['ambient']];
        yield 'nullable config' => [static function (mixed $x, ?RunnableConfig $c = null): array { self::$calls++; return $c->tags; }, [], ['ambient']];
        yield 'mixed second' => [static function (mixed $x, mixed $c): array { self::$calls++; return $c->tags; }, [], ['ambient']];
        yield 'string second' => [static function (string $x, string $sep = '-'): string { self::$calls++; return $x . $sep . func_num_args(); }, [], 'a-1'];
        yield 'variadic' => [static function (mixed $x, mixed ...$rest): array { self::$calls++; return [count($rest), $rest[0]->tags]; }, [], [1, ['ambient']]];
        yield 'bound kwargs fill every parameter' => [static function (int $a, int $b): int { self::$calls++; return $a + $b; }, ['a' => 1, 'b' => 2], 3];
        yield 'bound kwargs after the input' => [static function (string $x, string $suffix): string { self::$calls++; return $x . $suffix; }, ['!'], 'a!'];
    }

    #[DataProvider('arities')]
    public function testCallableRunsOnceAndKeepsItsArityDispatch(\Closure $func, array $bound, mixed $expected): void
    {
        $lambda = RunnableLambda::from($func, $bound);
        $result = AsyncLocalStorage::runWithConfig(new RunnableConfig(tags: ['ambient']), static fn () => $lambda->invoke('a'));
        self::assertSame($expected, $result);
        self::assertSame(1, self::$calls);
    }

    public function testReturnedRunnablesAndGeneratorsAreReturnedUninvoked(): void
    {
        $child = RunnableLambda::from(static fn (mixed $x): string => 'leaf');
        self::assertSame($child, RunnableLambda::from(static fn (mixed $x) => $child)->invoke('x'));
        $generator = RunnableLambda::from(static function (mixed $x): \Generator { yield 'a'; yield 'b'; })->invoke('x');
        self::assertInstanceOf(\Generator::class, $generator);
    }

    public function testCallableFailurePropagatesAndLeavesTheAmbientFrameUntouched(): void
    {
        $ambient = new RunnableConfig(tags: ['ambient']);
        $error = new \RuntimeException('boom');
        $lambda = RunnableLambda::from(static function (mixed $x, RunnableConfig $c) use ($error): never {
            self::$calls++;
            throw $error;
        });
        AsyncLocalStorage::runWithConfig($ambient, static function () use ($lambda, $error, $ambient): void {
            $outer = AsyncLocalStorage::getInstance()->getStore();
            try {
                $lambda->invoke('x');
                self::fail('expected the callable error');
            } catch (\RuntimeException $caught) {
                self::assertSame($error, $caught);
            }
            self::assertSame($outer, AsyncLocalStorage::getInstance()->getStore());
            self::assertSame($ambient, AsyncLocalStorage::getRunnableConfig());
            self::recorder()->invoke('x');
            self::assertSame(['ambient'], self::$seen['config']->tags);
        });
        self::assertSame(1, self::$calls);
        self::assertNull(AsyncLocalStorage::getInstance()->getStore());
    }

    /** Raw lambda invoke opens no run; supplied and ambient callbacks are only handed on (N16 owns tracing). */
    public function testRawInvokeHandsCallbacksOnWithoutOpeningRunsAndBindingOpensExactlyOne(): void
    {
        $supplied = self::handler();
        self::recorder()->invoke('x', new RunnableConfig(callbacks: [$supplied]));
        self::assertSame([$supplied], self::$seen['config']->callbacks);
        self::assertSame([], $supplied->events);

        $ambient = self::handler();
        AsyncLocalStorage::runWithConfig(new RunnableConfig(callbacks: [$ambient]), static fn () => self::recorder()->invoke('x'));
        self::assertSame([$ambient], self::$seen['config']->callbacks);
        self::assertSame([], $ambient->events);

        $bound = self::handler();
        self::recorder()->withConfig(['callbacks' => [$bound]])->invoke('x');
        self::assertSame(['start', 'end'], $bound->events);
        self::assertSame([$bound], self::$seen['config']->callbacks);
        self::assertIsString(self::$seen['config']->runIdParent);
    }

    public function testWithoutAProviderTheCallableGetsFreshDefaults(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
        self::recorder()->invoke('x');
        $first = self::$seen['config'];
        self::recorder()->invoke('x');
        self::assertNotSame($first, self::$seen['config']);
        self::assertSame([], $first->tags);
        self::assertSame(25, $first->recursionLimit);
        self::assertNull(AsyncLocalStorage::getGlobalInstance());
    }
}
