<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\AsyncLocalStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Field boundary tests; deadlines and runnable consumer adoption remain separate. */
#[CoversClass(RunnableConfig::class)]
final class RunnableConfigEnsureTest extends TestCase
{
    protected function setUp(): void { AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage()); }
    protected function tearDown(): void { AsyncLocalStorage::setGlobalInstance(null); }

    private static function normalize(RunnableConfig|array|null $config = null): RunnableConfig
    {
        self::assertTrue(method_exists(RunnableConfig::class, 'normalizeFields'), 'Field-normalization API is absent.');
        return RunnableConfig::normalizeFields($config);
    }

    public function testDefaultsAreIndependentAndDoNotInstallStorageOrScheduleDeadlines(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
        $one = self::normalize(); $two = self::normalize();
        self::assertNotSame($one, $two);
        self::assertSame([], $one->tags); self::assertSame([], $one->metadata); self::assertSame([], $one->callbacks); self::assertSame([], $one->configurable);
        self::assertSame(25, $one->recursionLimit); self::assertFalse($one->verbose); self::assertSame([], $one->runId); self::assertNull($one->runName);
        self::assertNull(AsyncLocalStorage::getGlobalInstance()); self::assertNull($one->signal); self::assertSame([], $one->options);
        self::assertTrue($one->isFieldProvided('metadata')); self::assertFalse($one->isFieldProvided('maxConcurrency')); self::assertFalse($one->isFieldProvided('signal'));
    }

    public function testOmittedFieldsInheritAndImplicitRunIdentityDoesNot(): void
    {
        $handler = new \stdClass(); $ambient = new RunnableConfig(tags: ['ambient'], metadata: ['ambient' => 'A'], callbacks: [$handler], configurable: ['tenant' => 'parent'], runId: ['parent-id'], runName: 'parent-name', recursionLimit: 100, options: ['temperature' => 0.5]);
        AsyncLocalStorage::runWithConfig($ambient, static function () use ($ambient, $handler): void {
            foreach ([null, [], new RunnableConfig()] as $call) {
                $out = self::normalize($call);
                self::assertSame(['ambient'], $out->tags); self::assertSame(['ambient' => 'A'], $out->metadata); self::assertSame([$handler], $out->callbacks); self::assertSame(['tenant' => 'parent'], $out->configurable);
                self::assertSame(100, $out->recursionLimit); self::assertSame(['temperature' => 0.5], $out->options); self::assertSame([], $out->runId); self::assertNull($out->runName);
                self::assertSame($ambient, AsyncLocalStorage::getRunnableConfig());
            }
        });
        self::assertNull(AsyncLocalStorage::getRunnableConfig());
    }

    public function testExplicitNonemptyFieldsReplaceRatherThanMergeAmbientFields(): void
    {
        $ambientHandler = new \stdClass(); $callerHandler = new \stdClass();
        $ambient = new RunnableConfig(tags: ['ambient'], metadata: ['ambient' => 'A'], callbacks: [$ambientHandler], configurable: ['parent' => 'P']);
        $caller = new RunnableConfig(tags: ['caller', 'caller'], metadata: ['caller' => 'C'], callbacks: [$callerHandler], configurable: ['call' => 'C']);
        $out = AsyncLocalStorage::runWithConfig($ambient, static fn () => self::normalize($caller));
        self::assertSame(['caller', 'caller'], $out->tags); self::assertSame(['caller' => 'C'], $out->metadata); self::assertSame([$callerHandler], $out->callbacks); self::assertSame(['call' => 'C'], $out->configurable);
        self::assertSame(['ambient'], $ambient->tags); self::assertSame(['caller', 'caller'], $caller->tags);
    }

    public static function emptyCases(): iterable
    {
        foreach (['tags', 'metadata', 'callbacks', 'configurable'] as $field) {
            yield $field . ' fromArray' => [$field, 'array'];
            yield $field . ' with' => [$field, 'with'];
        }
    }

    #[DataProvider('emptyCases')]
    public function testExplicitEmptyFieldsResetAmbientPresenceAndStayEmptyOnRepeatedNormalization(string $field, string $method): void
    {
        $ambient = new RunnableConfig(tags: ['A'], metadata: ['A' => 1], callbacks: [new \stdClass()], configurable: ['A' => 1]);
        $caller = $method === 'array' ? RunnableConfig::fromArray([$field => []]) : (new RunnableConfig())->with([$field => []]);
        AsyncLocalStorage::runWithConfig($ambient, static function () use ($field, $caller): void {
            $out = self::normalize($caller); self::assertSame([], $out->$field);
            $again = self::normalize($out); self::assertSame([], $again->$field);
            foreach (['tags', 'metadata', 'callbacks', 'configurable'] as $other) { self::assertSame($out->$other, $again->$other); }
        });
        self::assertSame([], $caller->$field);
    }

    public function testConstructorEmptyAndKnownNullRemainOmissionWhileArrayAndWithCanTrackEmpty(): void
    {
        $ambient = new RunnableConfig(tags: ['A'], metadata: ['A' => 1], callbacks: [new \stdClass()], configurable: ['A' => 1], runName: 'implicit', maxConcurrency: 9);
        AsyncLocalStorage::runWithConfig($ambient, static function (): void {
            $out = self::normalize(new RunnableConfig(tags: [], metadata: [], callbacks: [], configurable: [], maxConcurrency: null));
            self::assertSame(['A'], $out->tags); self::assertSame(['A' => 1], $out->metadata); self::assertCount(1, $out->callbacks); self::assertSame(['A' => 1], $out->configurable); self::assertSame(9, $out->maxConcurrency);
            self::assertSame(9, self::normalize(['maxConcurrency' => null])->maxConcurrency);
        });
        self::assertTrue(method_exists(RunnableConfig::class, 'isFieldProvided'));
        self::assertFalse((new RunnableConfig(tags: []))->isFieldProvided('tags'));
        self::assertTrue(RunnableConfig::fromArray(['tags' => []])->isFieldProvided('tags'));
        self::assertTrue((new RunnableConfig())->with(['tags' => []])->isFieldProvided('tags'));
        self::assertFalse(RunnableConfig::fromArray(['tags' => null])->isFieldProvided('tags'));
    }

    public function testPresenceQueryUsesProvidedStateDirectValuesAliasesAndProtectsPrivateFields(): void
    {
        self::assertTrue(method_exists(RunnableConfig::class, 'isFieldProvided'));
        $config = new RunnableConfig(); self::assertFalse($config->isFieldProvided('recursion_limit')); self::assertFalse($config->isFieldProvided('verbose')); self::assertFalse($config->isFieldProvided('unknown')); self::assertFalse($config->isFieldProvided('providedFields'));
        $config->recursionLimit = 60; $config->verbose = true; $config->metadata = ['direct' => 1]; $config->runName = 'direct';
        self::assertTrue($config->isFieldProvided('recursionLimit')); self::assertTrue($config->isFieldProvided('verbose')); self::assertTrue($config->isFieldProvided('metadata')); self::assertTrue($config->isFieldProvided('run_name'));
        self::assertTrue((new RunnableConfig(recursionLimit: 25, verbose: false))->isFieldProvided('recursion_limit'));
        self::assertTrue((new RunnableConfig(verbose: false))->isFieldProvided('verbose'));
        self::assertTrue(RunnableConfig::fromArray(['run_id' => []])->isFieldProvided('runId'));
    }

    public function testExplicitDefaultScalarsAndIdentityOverrideWithoutLeakingImplicitIdentity(): void
    {
        AsyncLocalStorage::runWithConfig(new RunnableConfig(recursionLimit: 100, verbose: true, runId: ['parent'], runName: 'parent'), static function (): void {
            $out = self::normalize(new RunnableConfig(recursionLimit: 25, verbose: false, runId: ['call'], runName: 'call'));
            self::assertSame(25, $out->recursionLimit); self::assertFalse($out->verbose); self::assertSame(['call'], $out->runId); self::assertSame('call', $out->runName);
            self::assertSame([], self::normalize(['runId' => []])->runId);
        });
    }

    public function testOptionsOverlayPerKeyIncludingNullAndEmptyArrayWithoutClearingUnmentionedExtras(): void
    {
        AsyncLocalStorage::runWithConfig(new RunnableConfig(options: ['temperature' => 0.5, 'stop' => ['old'], 'custom' => 'kept']), static function (): void {
            $out = self::normalize(['temperature' => null, 'stop' => []]);
            self::assertSame(['temperature' => null, 'stop' => [], 'custom' => 'kept'], $out->options);
            self::assertSame(['temperature' => 0.5, 'stop' => ['old'], 'custom' => 'kept'], self::normalize(['options' => []])->options);
            self::assertSame($out->options, self::normalize($out)->options);
        });
    }

    public static function modelCases(): iterable
    {
        yield 'string adds model' => [['model' => 'chosen'], [], ['model' => 'chosen']];
        yield 'null metadata model stays null' => [['model' => 'chosen'], ['model' => null], ['model' => null]];
        yield 'explicit model stays explicit' => [['model' => 'chosen'], ['model' => 'custom'], ['model' => 'custom']];
        yield 'nonstring model not mapped' => [['model' => 4, 'api_key' => 'synthetic'], [], []];
    }

    #[DataProvider('modelCases')]
    public function testOnlyStringModelFillsAnAbsentMetadataKeyAndInputsAreNotMutated(array $configurable, array $metadata, array $expected): void
    {
        $caller = new RunnableConfig(configurable: $configurable, metadata: $metadata);
        $out = self::normalize($caller); self::assertSame($expected, $out->metadata); self::assertSame($metadata, $caller->metadata); self::assertSame($configurable, $caller->configurable);
        $out->metadata['changed'] = true; self::assertSame($metadata, $caller->metadata);
    }

    public function testAmbientArrayAndModelMetadataAreCopiedWithoutMutatingProviderConfig(): void
    {
        $ambient = ['tags' => ['parent'], 'metadata' => ['parent' => 'P'], 'configurable' => ['model' => 'chosen'], 'runId' => 'parent', 'runName' => 'parent', 'temperature' => 0.5];
        AsyncLocalStorage::runWithConfig($ambient, static function () use ($ambient): void {
            $out = self::normalize(); self::assertSame(['parent' => 'P', 'model' => 'chosen'], $out->metadata); self::assertSame(['temperature' => 0.5], $out->options); self::assertSame([], $out->runId); self::assertNull($out->runName);
            self::assertSame($ambient, AsyncLocalStorage::getRunnableConfig());
        });
    }

    public function testFieldNormalizationPreservesNumericTimeoutAndSignalWithoutCallingOrCombiningIt(): void
    {
        $calls = 0; $signal = static function () use (&$calls): bool { ++$calls; return false; };
        $other = new \stdClass();
        AsyncLocalStorage::runWithConfig(new RunnableConfig(signal: $signal, options: ['timeout' => 5]), static function () use ($signal, $other): void {
            $out = self::normalize(['timeout' => 50]); self::assertSame(50, $out->options['timeout']); self::assertSame($signal, $out->signal);
            self::assertSame($other, self::normalize(new RunnableConfig(signal: $other))->signal);
            self::assertSame(-1, self::normalize(['timeout' => -1])->options['timeout']);
        });
        self::assertSame(0, $calls);
    }

    public function testNormalizationDoesNotPublishContextOrAlterMergeComposition(): void
    {
        $bound = new RunnableConfig(tags: ['B', 'same'], metadata: ['bound' => 'B'], configurable: ['bound' => 'B'], options: ['timeout' => 20]);
        $call = new RunnableConfig(tags: ['same', 'C'], metadata: ['call' => 'C'], configurable: ['call' => 'C'], options: ['timeout' => 50]);
        $merged = RunnableConfig::mergeConfigs($bound, $call);
        AsyncLocalStorage::runWithConfig(new RunnableConfig(metadata: ['ambient' => 'A']), static function () use ($merged): void {
            $out = self::normalize($merged); self::assertSame(['bound' => 'B', 'call' => 'C'], $out->metadata); self::assertSame(['B', 'same', 'C'], $out->tags); self::assertSame(20, $out->options['timeout']);
            self::assertSame(['ambient' => 'A'], AsyncLocalStorage::getRunnableConfig()->metadata);
        });
        self::assertSame(['bound' => 'B', 'call' => 'C'], $merged->metadata); self::assertSame(['bound' => 'B', 'call' => 'C'], $merged->configurable);
    }

    public function testInterleavedFibersNormalizeOnlyTheirOwnAmbientAndRestoreMainFrame(): void
    {
        $main = new RunnableConfig(configurable: ['tenant' => 'main']); $seen = [];
        $make = static function (string $tenant) use (&$seen): \Fiber { return new \Fiber(static function () use ($tenant, &$seen): void {
            self::assertNull(AsyncLocalStorage::getRunnableConfig());
            AsyncLocalStorage::runWithConfig(new RunnableConfig(configurable: ['tenant' => $tenant]), static function () use ($tenant, &$seen): void {
                $seen[$tenant][] = self::normalize()->configurable['tenant']; \Fiber::suspend();
                $seen[$tenant][] = self::normalize()->configurable['tenant'];
            });
            self::assertNull(AsyncLocalStorage::getRunnableConfig());
        }); };
        AsyncLocalStorage::runWithConfig($main, static function () use ($make, $main): void {
            $a = $make('A'); $b = $make('B'); $a->start(); $b->start(); self::assertSame('main', self::normalize()->configurable['tenant']); $b->resume(); $a->resume(); self::assertSame($main, AsyncLocalStorage::getRunnableConfig());
        });
        self::assertSame(['A' => ['A', 'A'], 'B' => ['B', 'B']], $seen);
        self::assertNull(AsyncLocalStorage::getRunnableConfig());
    }
}
