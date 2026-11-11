<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use PHPUnit\Framework\TestCase;

final class RunnableConfigMergeTest extends TestCase
{
    public function testAbsentAndNullObjectFieldsPreserveBoundDefaults(): void
    {
        $bound = ['runName' => 'bound', 'recursionLimit' => 7, 'verbose' => true, 'runId' => ['root'], 'context' => ['key' => 'bound']];
        $merged = RunnableConfig::mergeConfigs($bound, new RunnableConfig(tags: ['call']));
        self::assertSame('bound', $merged->runName);
        self::assertSame(7, $merged->recursionLimit);
        self::assertTrue($merged->verbose);
        self::assertSame(['root'], $merged->runId);
        self::assertSame(['key' => 'bound'], $merged->context);
        self::assertSame('bound', RunnableConfig::mergeConfigs($bound, ['runName' => null])->runName);
        $explicit = RunnableConfig::mergeConfigs($bound, new RunnableConfig(recursionLimit: 25, verbose: false));
        self::assertSame(25, $explicit->recursionLimit);
        self::assertFalse($explicit->verbose);
        self::assertSame([], RunnableConfig::mergeConfigs($bound, ['run_id' => []])->runId);
    }

    public function testRawNullStandardFieldsPreserveEarlierNonDefaultValues(): void
    {
        foreach ([['recursion_limit', 'verbose', 'run_id', 'run_name'], ['recursionLimit', 'verbose', 'runId', 'runName']] as $keys) {
            $bound = array_combine($keys, [10, true, ['abc'], 'bound']);
            $nulls = array_fill_keys($keys, null);
            $merged = RunnableConfig::mergeConfigs($bound, $nulls);
            self::assertSame(10, $merged->recursionLimit);
            self::assertTrue($merged->verbose);
            self::assertSame(['abc'], $merged->runId);
            self::assertSame('bound', $merged->runName);
        }
    }

    public function testSourceFieldRulesAndIndependentDuplicateCallbacks(): void
    {
        $first = new \stdClass();
        $second = new \stdClass();
        $merged = RunnableConfig::mergeConfigs(
            ['tags' => ['a', 'b'], 'metadata' => ['x' => 1], 'configurable' => ['x' => 1], 'callbacks' => [$first], 'stop' => ['bound']],
            ['tags' => ['b', 'c'], 'metadata' => ['x' => 2, 'y' => 3], 'configurable' => ['x' => 2], 'callbacks' => [$first, $second], 'stop' => null, 'temperature' => 0],
        );
        self::assertSame(['a', 'b', 'c'], $merged->tags);
        self::assertSame(['x' => 2, 'y' => 3], $merged->metadata);
        self::assertSame(['x' => 2], $merged->configurable);
        self::assertSame([$first, $first, $second], $merged->callbacks);
        self::assertSame(['stop' => ['bound'], 'temperature' => 0], $merged->options);
    }

    public function testFactoriesRunPerCallWithSameInitialConfigAndFinalPrecedence(): void
    {
        $calls = [];
        $received = [];
        $base = new RunnableLambda(static function ($input, RunnableConfig $config) use (&$received) {
            $received[] = $config;

            return $input;
        });
        $binding = new RunnableBinding($base, ['temperature' => 3, 'metadata' => ['key' => 'kwargs']],
            ['temperature' => 1, 'tags' => ['bound']], [
                static function (RunnableConfig $config) use (&$calls): array {
                    $calls[] = [$config->options, $config->metadata];
                    $config->metadata['mutated'] = true;

                    return ['temperature' => 4, 'tags' => ['factory']];
                },
                static function (RunnableConfig $config) use (&$calls): array {
                    $calls[] = [$config->options, $config->metadata];

                    return ['metadata' => ['factory' => true]];
                },
            ]);
        $config = new RunnableConfig(options: ['temperature' => 2], metadata: ['key' => 'call']);
        $binding->invoke('one', $config);
        $binding->invoke('two', $config);
        self::assertCount(4, $calls);
        self::assertSame($calls[0], $calls[1]);
        self::assertSame([['temperature' => 3], ['key' => 'kwargs']], $calls[0]);
        self::assertSame(['temperature' => 4], $received[0]->options);
        self::assertSame(['key' => 'kwargs', 'factory' => true], $received[0]->metadata);
        self::assertSame(['bound', 'factory'], $received[0]->tags);
        self::assertSame(['temperature' => 2], $config->options);
        self::assertSame(['key' => 'call'], $config->metadata);
        self::assertNotSame($received[0], $received[1]);
    }

    public function testWithConfigUsesShallowReplacementAndDropsFactoriesAsSourceDoes(): void
    {
        $factoryCalls = 0;
        $received = null;
        $binding = new RunnableBinding(new RunnableLambda(static function ($input, $config) use (&$received) {
            $received = $config;

            return $input;
        }), config: ['tags' => ['old'], 'metadata' => ['old' => true]], configFactories: [
            static function () use (&$factoryCalls): array { ++$factoryCalls; return []; },
        ]);
        $binding->withConfig(['tags' => ['new'], 'metadata' => ['new' => true]])->invoke('hi');
        self::assertSame(0, $factoryCalls);
        self::assertSame(['new'], $received->tags);
        self::assertSame(['new' => true], $received->metadata);
    }

    public function testMergedCallableSignalsAbortWhenEitherAbortsAndStayAborted(): void
    {
        $abort = false;
        $config = RunnableConfig::mergeConfigs(new RunnableConfig(signal: static fn (): bool => false),
            new RunnableConfig(signal: static function () use (&$abort): bool { return $abort; }));
        self::assertFalse(($config->signal)());
        $abort = true;
        self::assertTrue(($config->signal)());
        $abort = false;
        self::assertTrue(($config->signal)());
    }

    public function testFactoriesAreEvaluatedOnceForEveryExecutionEntryPoint(): void
    {
        foreach (['invoke', 'stream', 'batch', 'events-v1', 'events-v2', 'log'] as $method) {
            $calls = 0;
            $binding = new RunnableBinding(new RunnableLambda(static fn ($input) => $input), configFactories: [
                static function () use (&$calls): array { ++$calls; return []; },
            ]);
            match ($method) {
                'invoke' => $binding->invoke('input'),
                'stream' => iterator_to_array($binding->stream('input')),
                'batch' => $binding->batch(['one', 'two']),
                'events-v1' => iterator_to_array($binding->streamEvents('input', version: 'v1')),
                'events-v2' => iterator_to_array($binding->streamEvents('input')),
                'log' => iterator_to_array($binding->streamLog('input')),
            };
            self::assertSame(1, $calls, $method);
        }
    }

    public function testNumericTimeoutMergeKeepsTheShortestDeadline(): void
    {
        self::assertSame(50, RunnableConfig::mergeConfigs(['timeout' => 100], ['timeout' => 50], ['timeout' => 200])->options['timeout']);
    }
}
