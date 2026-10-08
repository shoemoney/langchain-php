<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\AsyncLocalStorage;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\PregelScratchpad;
use LangGraph\Pregel\Utils\Config;
use LangGraph\Store\InMemoryStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `pregel/utils/config.test.ts`.
 *
 * Upstream mocks `AsyncLocalStorageProviderSingleton.getRunnableConfig`; here the real
 * {@see AsyncLocalStorage} is installed and a config is made ambient with `runWithConfig`,
 * which is what a graph does around a node.
 *
 * Not ported: the three `CallbackManager` cases ("merge tags and metadata ... when both
 * callbacks are managers", the two handler-dedupe cases). Callbacks are plain handler lists in
 * this port, so there is no manager to merge; the list/list case is covered by
 * `testCallbackListsConcatenateAcrossConfigs`.
 */
#[CoversClass(Config::class)]
final class LangGraphConfigTest extends TestCase
{
    protected function setUp(): void
    {
        AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage());
    }

    protected function tearDown(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
    }

    /**
     * @template T
     * @param array<string, mixed> $ambient
     * @param callable(): T        $callback
     * @return T
     */
    private static function withAmbient(array $ambient, callable $callback): mixed
    {
        return AsyncLocalStorage::runWithConfig($ambient, $callback);
    }

    // ---- ensureLangGraphConfig -------------------------------------------------------

    public function testItReturnsADefaultConfigWhenNoArgumentsAreProvided(): void
    {
        $result = Config::ensureLangGraphConfig();

        self::assertSame([], $result->tags);
        self::assertSame([], $result->metadata);
        self::assertSame([], $result->callbacks);
        self::assertSame(25, $result->recursionLimit);
        self::assertSame([], $result->configurable);
    }

    public function testItMergesMultipleConfigsPerKeyPreservingDistinctKeys(): void
    {
        $result = Config::ensureLangGraphConfig(
            ['tags' => ['tag1'], 'metadata' => ['key1' => 'value1'], 'configurable' => ['option1' => 'value1']],
            ['tags' => ['tag2'], 'metadata' => ['key2' => 'value2'], 'configurable' => ['option2' => 'value2']],
        );

        self::assertSame(['tag1', 'tag2'], $result->tags);
        self::assertSame(['key1' => 'value1', 'key2' => 'value2'], $result->metadata);
        self::assertSame(['option1' => 'value1', 'option2' => 'value2'], $result->configurable);
    }

    public function testConfigurableMergesWithLaterConfigsWinningPerKey(): void
    {
        $bound = ['configurable' => ['ls_agent_type' => 'root', 'shared' => 'from_a']];
        $invoke = ['configurable' => ['thread_id' => 'T1', 'shared' => 'from_b']];

        $result = Config::ensureLangGraphConfig($bound, $invoke);

        self::assertSame(
            ['ls_agent_type' => 'root', 'shared' => 'from_b', 'thread_id' => 'T1'],
            $result->configurable,
        );
    }

    public function testMetadataMergesWithLaterConfigsWinningPerKey(): void
    {
        $result = Config::ensureLangGraphConfig(
            ['metadata' => ['user_id' => 'U1', 'shared' => 'from_a']],
            ['metadata' => ['correlation_id' => 'C1', 'shared' => 'from_b']],
        );

        self::assertSame(['user_id' => 'U1', 'shared' => 'from_b', 'correlation_id' => 'C1'], $result->metadata);
    }

    public function testTagsConcatenateAcrossConfigsKeepingOrderAndDuplicates(): void
    {
        $result = Config::ensureLangGraphConfig(['tags' => ['shared', 'alpha']], ['tags' => ['shared', 'beta']]);

        self::assertSame(['shared', 'alpha', 'shared', 'beta'], $result->tags);
    }

    public function testCallbackListsConcatenateAcrossConfigs(): void
    {
        $cbA = new \stdClass();
        $cbB = new \stdClass();

        $result = Config::ensureLangGraphConfig(['callbacks' => [$cbA]], ['callbacks' => [$cbB]]);

        self::assertSame([$cbA, $cbB], $result->callbacks);
    }

    public function testRunnableConfigObjectsMergeLikeArrays(): void
    {
        $result = Config::ensureLangGraphConfig(
            new RunnableConfig(tags: ['a'], metadata: ['x' => 1], recursionLimit: 100, configurable: ['k' => 'v']),
            new RunnableConfig(tags: ['b'], metadata: ['y' => 2], configurable: ['k2' => 'v2']),
        );

        self::assertSame(['a', 'b'], $result->tags);
        self::assertSame(['x' => 1, 'y' => 2], $result->metadata);
        // The second object left recursionLimit at the default, so the bound 100 survives.
        self::assertSame(100, $result->recursionLimit);
        self::assertSame(['k' => 'v', 'k2' => 'v2'], $result->configurable);
    }

    public function testLangGraphOnlyKeysAreCarriedInOptionsAndSnakeCaseIsAccepted(): void
    {
        $store = new InMemoryStore();

        $result = Config::ensureLangGraphConfig([
            'run_name' => 'named',
            'recursion_limit' => 7,
            'store' => $store,
            'durability' => 'exit',
            'checkpointDuring' => false,
            'not_a_config_key' => 'dropped',
        ]);

        self::assertSame('named', $result->runName);
        self::assertSame(7, $result->recursionLimit);
        self::assertSame($store, $result->options['store']);
        self::assertSame('exit', $result->options['durability']);
        self::assertFalse($result->options['checkpointDuring']);
        self::assertArrayNotHasKey('not_a_config_key', $result->options);
    }

    public function testItDoesNotMutateTheInputConfigs(): void
    {
        $bound = new RunnableConfig(
            tags: ['bound'],
            metadata: ['user_id' => 'U1'],
            configurable: ['ls_agent_type' => 'root'],
        );
        $invoke = new RunnableConfig(
            tags: ['invoke'],
            metadata: ['correlation_id' => 'C1'],
            // `thread_id` is propagated into metadata; it must not leak back into the inputs.
            configurable: ['thread_id' => 'T1'],
        );

        Config::ensureLangGraphConfig($bound, $invoke);

        self::assertSame(['user_id' => 'U1'], $bound->metadata);
        self::assertSame(['ls_agent_type' => 'root'], $bound->configurable);
        self::assertSame(['bound'], $bound->tags);
        self::assertSame(['correlation_id' => 'C1'], $invoke->metadata);
        self::assertSame(['thread_id' => 'T1'], $invoke->configurable);
    }

    public function testItCopiesValuesFromTheAmbientConfig(): void
    {
        $result = self::withAmbient(
            ['tags' => ['storage-tag'], 'metadata' => ['storage' => 'value'], 'configurable' => ['storageOption' => 'value']],
            static fn (): RunnableConfig => Config::ensureLangGraphConfig(),
        );

        self::assertSame(['storage-tag'], $result->tags);
        self::assertSame(['storage' => 'value'], $result->metadata);
        self::assertSame(['storageOption' => 'value'], $result->configurable);
    }

    public function testUndefinedConfigValuesAreSkipped(): void
    {
        $result = Config::ensureLangGraphConfig(null, ['tags' => ['tag2'], 'metadata' => null]);

        self::assertSame(['tag2'], $result->tags);
        self::assertSame([], $result->metadata);
    }

    public function testOnlyAllowlistedConfigurableValuesAreCopiedToMetadata(): void
    {
        $result = Config::ensureLangGraphConfig(['configurable' => [
            'thread_id' => 'thread-1',
            'checkpoint_id' => 'checkpoint-1',
            'checkpoint_ns' => 'checkpoint-ns',
            'task_id' => 'task-1',
            'run_id' => 'run-1',
            'assistant_id' => 'assistant-1',
            'graph_id' => 'graph-1',
            'stringValue' => 'string',
            'objectValue' => ['should' => 'not be copied'],
            '__privateValue' => 'should not be copied',
        ]]);

        self::assertSame([
            'thread_id' => 'thread-1',
            'checkpoint_id' => 'checkpoint-1',
            'checkpoint_ns' => 'checkpoint-ns',
            'task_id' => 'task-1',
            'run_id' => 'run-1',
            'assistant_id' => 'assistant-1',
            'graph_id' => 'graph-1',
        ], $result->metadata);
    }

    public function testConfigurableNeverOverwritesExistingMetadata(): void
    {
        $result = Config::ensureLangGraphConfig([
            'metadata' => ['thread_id' => 'original value'],
            'configurable' => ['thread_id' => 'should not overwrite'],
        ]);

        self::assertSame('original value', $result->metadata['thread_id']);
    }

    public function testAnEmptyCheckpointNamespacePropagatesToMetadata(): void
    {
        $result = Config::ensureLangGraphConfig(['configurable' => ['thread_id' => 'thread-1', 'checkpoint_ns' => '']]);

        self::assertSame(['thread_id' => 'thread-1', 'checkpoint_ns' => ''], $result->metadata);
    }

    public function testItDoesNotInheritImplicitConfigurableOnARootLevelInvokeWithAThreadId(): void
    {
        $ambient = ['configurable' => [
            'thread_id' => 'stale-thread',
            Constants::CONFIG_KEY_SCRATCHPAD => ['currentTaskInput' => ['secret' => 'leaked']],
        ]];

        $result = self::withAmbient($ambient, static fn (): RunnableConfig => Config::ensureLangGraphConfig(
            ['configurable' => ['ls_agent_type' => 'chatbot']],
            ['configurable' => ['thread_id' => 'fresh-thread']],
        ));

        self::assertSame(['ls_agent_type' => 'chatbot', 'thread_id' => 'fresh-thread'], $result->configurable);
        self::assertArrayNotHasKey(Constants::CONFIG_KEY_SCRATCHPAD, $result->configurable);
    }

    public function testStaleUserKeysFromTheAmbientConfigurableAreDroppedOnARootInvoke(): void
    {
        // The ambient `configurable` may belong to another concurrent invocation, so arbitrary
        // user keys (tenant_id / user_id) must not leak into this run.
        $ambient = ['configurable' => [
            'thread_id' => 'stale-thread',
            'tenant_id' => 'tenant-42',
            'user_id' => 'user-A',
        ]];

        $result = self::withAmbient($ambient, static fn (): RunnableConfig => Config::ensureLangGraphConfig(
            ['configurable' => ['ls_agent_type' => 'chatbot']],
            ['configurable' => ['thread_id' => 'fresh-thread']],
        ));

        self::assertSame(['ls_agent_type' => 'chatbot', 'thread_id' => 'fresh-thread'], $result->configurable);
    }

    public function testItDoesNotStripImplicitConfigurableDuringNodeExecution(): void
    {
        $read = static fn (): mixed => null;
        $ambient = ['configurable' => [
            'thread_id' => 'stale-thread',
            Constants::CONFIG_KEY_SCRATCHPAD => ['currentTaskInput' => ['secret' => 'leaked']],
        ]];
        $taskConfig = ['configurable' => [
            'thread_id' => 'task-thread',
            Constants::CONFIG_KEY_READ => $read,
            Constants::CONFIG_KEY_SCRATCHPAD => ['currentTaskInput' => ['ok' => true]],
        ]];

        $result = self::withAmbient($ambient, static fn (): RunnableConfig => Config::ensureLangGraphConfig($taskConfig));

        self::assertEquals($taskConfig['configurable'], $result->configurable);
    }

    public function testItDoesNotStripWhenStreamEventsStyleOptionsIncludeAmbientNestingKeys(): void
    {
        $read = static fn (): mixed => null;
        $ambient = ['configurable' => [
            'thread_id' => 'parent-thread',
            Constants::CONFIG_KEY_READ => $read,
            'checkpoint_ns' => 'parent:1',
            Constants::CONFIG_KEY_SCRATCHPAD => ['currentTaskInput' => ['from' => 'parent']],
        ]];
        $bound = ['configurable' => ['thread_id' => 'bound-thread']];
        $streamEventsOptions = ['configurable' => [
            Constants::CONFIG_KEY_READ => $read,
            'checkpoint_ns' => 'parent:1',
            Constants::CONFIG_KEY_SCRATCHPAD => ['currentTaskInput' => ['from' => 'parent']],
            ...$bound['configurable'],
            'ls_agent_type' => 'sub-agent',
        ]];

        $result = self::withAmbient($ambient, static fn (): RunnableConfig => Config::ensureLangGraphConfig($bound, $streamEventsOptions));

        self::assertSame('bound-thread', $result->configurable['thread_id']);
        self::assertSame($read, $result->configurable[Constants::CONFIG_KEY_READ]);
        self::assertSame('parent:1', $result->configurable['checkpoint_ns']);
        self::assertSame(['currentTaskInput' => ['from' => 'parent']], $result->configurable[Constants::CONFIG_KEY_SCRATCHPAD]);
        self::assertSame('sub-agent', $result->configurable['ls_agent_type']);
    }

    public function testItInheritsAmbientNestingWhenBoundConfigHasAThreadIdButInvokeDoesNot(): void
    {
        $read = static fn (): mixed => null;
        $ambient = ['configurable' => [
            'thread_id' => 'parent-thread',
            Constants::CONFIG_KEY_READ => $read,
            'checkpoint_ns' => 'parent:1',
            Constants::CONFIG_KEY_SCRATCHPAD => ['currentTaskInput' => ['from' => 'parent']],
        ]];

        $result = self::withAmbient($ambient, static fn (): RunnableConfig => Config::ensureLangGraphConfig(
            ['configurable' => ['thread_id' => 'bound-thread']],
            ['configurable' => ['ls_agent_type' => 'sub-agent']],
        ));

        self::assertSame('bound-thread', $result->configurable['thread_id']);
        self::assertSame($read, $result->configurable[Constants::CONFIG_KEY_READ]);
        self::assertSame('parent:1', $result->configurable['checkpoint_ns']);
        self::assertSame('sub-agent', $result->configurable['ls_agent_type']);
    }

    public function testItStillInheritsImplicitConfigurableForNestedInvokes(): void
    {
        $read = static fn (): mixed => null;
        $ambient = ['configurable' => [
            'thread_id' => 'parent-thread',
            Constants::CONFIG_KEY_READ => $read,
            'checkpoint_ns' => 'parent:1',
        ]];

        $result = self::withAmbient($ambient, static fn (): RunnableConfig => Config::ensureLangGraphConfig(
            ['configurable' => ['ls_agent_type' => 'sub-agent']],
        ));

        self::assertSame([
            'thread_id' => 'parent-thread',
            Constants::CONFIG_KEY_READ => $read,
            'checkpoint_ns' => 'parent:1',
            'ls_agent_type' => 'sub-agent',
        ], $result->configurable);
    }

    // ---- getStore, getWriter, getConfig ----------------------------------------------

    public function testGetStoreReturnsTheStoreFromTheAmbientConfig(): void
    {
        $store = new InMemoryStore();

        $result = self::withAmbient(['store' => $store], static fn (): ?object => Config::getStore());

        self::assertSame($store, $result);
    }

    public function testGetStoreReadsAnExplicitConfigAndTheEngineKey(): void
    {
        $store = new InMemoryStore();

        self::assertSame($store, Config::getStore(new RunnableConfig(options: ['store' => $store])));
        self::assertSame($store, Config::getStore(new RunnableConfig(configurable: [Constants::CONFIG_KEY_STORE => $store])));
        self::assertNull(Config::getStore(new RunnableConfig()));
    }

    public function testGetStoreThrowsWhenNoConfigIsRetrievable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config not retrievable.');
        Config::getStore();
    }

    public function testGetWriterReturnsTheWriterFromConfigurable(): void
    {
        $writer = static function (mixed $chunk): void {
        };

        $result = self::withAmbient(['configurable' => ['writer' => $writer]], static fn (): ?callable => Config::getWriter());

        self::assertSame($writer, $result);
        self::assertSame($writer, Config::getWriter(new RunnableConfig(options: ['writer' => $writer])));
        self::assertNull(Config::getWriter(new RunnableConfig()));
    }

    public function testGetConfigReturnsTheAmbientConfig(): void
    {
        $ambient = new RunnableConfig(tags: ['x']);

        self::assertNull(Config::getConfig());

        $seen = AsyncLocalStorage::runWithConfig($ambient, static fn (): ?RunnableConfig => Config::getConfig());
        self::assertSame($ambient, $seen);
    }

    public function testGetConfigFallsBackToTheRunningTasksConfig(): void
    {
        $taskConfig = new RunnableConfig(tags: ['task']);

        $seen = PregelScratchpad::withConfig($taskConfig, static fn (): ?RunnableConfig => Config::getConfig());

        self::assertSame($taskConfig, $seen);
    }

    public function testGetCurrentTaskInputReadsTheTaskScratchpad(): void
    {
        $scratchpad = new PregelScratchpad(currentTaskInput: ['n' => 3]);
        $config = new RunnableConfig(configurable: [Constants::CONFIG_KEY_SCRATCHPAD => $scratchpad]);

        self::assertSame(['n' => 3], Config::getCurrentTaskInput($config));
    }

    public function testGetCurrentTaskInputThrowsOutsideATask(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BUG: internal scratchpad not initialized.');
        Config::getCurrentTaskInput(new RunnableConfig());
    }

    // ---- recastCheckpointNamespace / getParentCheckpointNamespace --------------------

    public function testRecastFiltersOutNumericParts(): void
    {
        self::assertSame('parent|child', Config::recastCheckpointNamespace('parent|123|child'));
    }

    public function testRecastRemovesPartsAfterTheNamespaceEnd(): void
    {
        self::assertSame('part1|part2', Config::recastCheckpointNamespace('part1|part2:extra'));
    }

    public function testRecastHandlesNumericPartsAndNamespaceEndTogether(): void
    {
        self::assertSame('root|child', Config::recastCheckpointNamespace('root|123|child:extra|456'));
    }

    public function testRecastReturnsTheOriginalNamespaceWhenNoFilteringIsNeeded(): void
    {
        self::assertSame('part1|part2', Config::recastCheckpointNamespace('part1|part2'));
    }

    public function testParentNamespaceRemovesTheLastPart(): void
    {
        self::assertSame('parent', Config::getParentCheckpointNamespace('parent|child'));
    }

    public function testParentNamespaceSkipsTrailingNumericParts(): void
    {
        self::assertSame('parent', Config::getParentCheckpointNamespace('parent|child|123|456'));
    }

    public function testParentNamespaceOfATopLevelNamespaceIsEmpty(): void
    {
        self::assertSame('', Config::getParentCheckpointNamespace('singlePart'));
    }

    public function testParentNamespaceStopsAtTheFirstNonNumericTrailingPart(): void
    {
        // Only the TRAILING run of digits is skipped, so the middle `123` stays.
        self::assertSame('root|sub1|123', Config::getParentCheckpointNamespace('root|sub1|123|sub2'));
    }

    // ---- propagateConfigurableToMetadata / filterToUserTags --------------------------

    public function testPropagateReturnsMetadataUntouchedWithoutConfigurable(): void
    {
        self::assertSame(['a' => 1], Config::propagateConfigurableToMetadata(null, ['a' => 1]));
        self::assertNull(Config::propagateConfigurableToMetadata(null, null));
    }

    public function testPropagateSkipsNullConfigurableValues(): void
    {
        // `null` is how this port spells JS `undefined` (Algorithm nulls `checkpoint_id` this way).
        self::assertSame(
            ['thread_id' => 't'],
            Config::propagateConfigurableToMetadata(['thread_id' => 't', 'checkpoint_id' => null], []),
        );
    }

    public function testFilterToUserTagsDropsSequenceStepTags(): void
    {
        self::assertNull(Config::filterToUserTags(null));
        self::assertNull(Config::filterToUserTags([]));
        self::assertNull(Config::filterToUserTags(['seq:step:1']));
        self::assertSame(['a', 'langsmith:hidden'], Config::filterToUserTags(['seq:step:2', 'a', 'langsmith:hidden']));
    }
}
