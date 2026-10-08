<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\PregelScratchpad;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use LangGraph\Utils\RunnableCallable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `RunnableCallable` from `langgraph-core/src/utils.ts`.
 */
#[CoversClass(RunnableCallable::class)]
final class RunnableCallableTest extends TestCase
{
    public function testInvokeCallsTheFunctionWithTheInputAndAConfig(): void
    {
        $seen = [];
        $callable = new RunnableCallable(function (mixed $input, RunnableConfig $config) use (&$seen): string {
            $seen = [$input, $config];

            return 'out';
        });

        self::assertSame('out', $callable->invoke('in'));
        self::assertSame('in', $seen[0]);
        self::assertInstanceOf(RunnableConfig::class, $seen[1], 'a config is supplied even when the caller passed none');
    }

    public function testTheFunctionAlwaysReceivesTheConfigEvenWhenItsSecondParameterIsNotTyped(): void
    {
        $received = null;
        $callable = new RunnableCallable(function ($input, $second = null) use (&$received): mixed {
            $received = $second;

            return $input;
        });

        $callable->invoke(1, new RunnableConfig(runName: 'r'));

        self::assertInstanceOf(RunnableConfig::class, $received);
        self::assertSame('r', $received->runName);
    }

    public function testTagsAreMergedIntoTheConfigTheFunctionSees(): void
    {
        $tags = null;
        $callable = new RunnableCallable(
            function (mixed $input, RunnableConfig $config) use (&$tags): mixed {
                $tags = $config->tags;

                return $input;
            },
            tags: ['own'],
        );

        $callable->invoke(1, new RunnableConfig(tags: ['caller']));

        self::assertSame(['own', 'caller'], $tags);
    }

    public function testTheMergedConfigIsPublishedAsTheCurrentConfigWhileTheFunctionRuns(): void
    {
        $current = null;
        $callable = new RunnableCallable(function () use (&$current): mixed {
            $current = PregelScratchpad::currentConfig();

            return null;
        });

        self::assertNull(PregelScratchpad::currentConfig());
        $callable->invoke(1, new RunnableConfig(runName: 'published'));

        self::assertSame('published', $current?->runName);
        self::assertNull(PregelScratchpad::currentConfig(), 'and it is restored afterwards');
    }

    public function testTheCurrentConfigIsRestoredWhenTheFunctionThrows(): void
    {
        $callable = new RunnableCallable(static function (): never {
            throw new \RuntimeException('boom');
        });

        try {
            $callable->invoke(1, new RunnableConfig(runName: 'x'));
            self::fail('expected the exception');
        } catch (\RuntimeException) {
            self::assertNull(PregelScratchpad::currentConfig());
        }
    }

    public function testWithNoConfigItInheritsTheCurrentTaskConfig(): void
    {
        $seen = null;
        $callable = new RunnableCallable(function ($input, RunnableConfig $config) use (&$seen): mixed {
            $seen = $config->runName;

            return $input;
        });

        PregelScratchpad::withConfig(new RunnableConfig(runName: 'ambient'), fn () => $callable->invoke(1));

        self::assertSame('ambient', $seen);
    }

    public function testARunnableReturnedByTheFunctionIsInvokedWithTheSameInputWhenRecursing(): void
    {
        $callable = new RunnableCallable(
            static fn (mixed $input): RunnableLambda => RunnableLambda::from(static fn (mixed $x): string => "inner:{$x}"),
        );

        self::assertSame('inner:7', $callable->invoke(7));
    }

    public function testARunnableReturnedByTheFunctionIsReturnedAsIsWhenRecurseIsOff(): void
    {
        $lambda = RunnableLambda::from(static fn (mixed $x): mixed => $x);
        $callable = new RunnableCallable(static fn (): RunnableLambda => $lambda, recurse: false);

        self::assertSame($lambda, $callable->invoke(7));
    }

    public function testTheRecursedRunnableRunsUnderThePublishedConfig(): void
    {
        $current = null;
        $inner = RunnableLambda::from(function (mixed $x) use (&$current): mixed {
            $current = PregelScratchpad::currentConfig()?->runName;

            return $x;
        });
        $callable = new RunnableCallable(static fn (): RunnableLambda => $inner);

        $callable->invoke(1, new RunnableConfig(runName: 'outer'));

        self::assertSame('outer', $current);
    }

    public function testNameDefaultsToTheFunctionNameAndCanBeOverridden(): void
    {
        self::assertSame('strtoupper', (new RunnableCallable('strtoupper'))->getName());
        self::assertSame('Closure-ish', (new RunnableCallable(static fn () => 1, name: 'Closure-ish'))->getName());
        self::assertSame('plainMethod', (new RunnableCallable([self::class, 'plainMethod']))->getName());
    }

    public static function plainMethod(): int
    {
        return 1;
    }

    public function testTraceAndRecurseDefaultToTrue(): void
    {
        $callable = new RunnableCallable(static fn () => 1);

        self::assertTrue($callable->trace);
        self::assertTrue($callable->recurse);
    }

    public function testItRunsAsAGraphNodeAndInterruptSeesItsConfig(): void
    {
        // End to end: a RunnableCallable as a real node, reading the task config the engine published.
        $seenNs = null;
        $node = new RunnableCallable(function (mixed $state, RunnableConfig $config) use (&$seenNs): array {
            $seenNs = $config->configurable[Constants::CONFIG_KEY_CHECKPOINT_NS] ?? null;

            return ['out' => 'done:' . $state['in']];
        });

        $graph = (new StateGraph(Annotation::root(['in' => Annotation::last(), 'out' => Annotation::last()])))
            ->addNode('n', $node)
            ->addEdge(Constants::START, 'n')
            ->addEdge('n', Constants::END)
            ->compile();

        self::assertSame('done:x', $graph->invoke(['in' => 'x'])['out']);
        self::assertIsString($seenNs);
        self::assertStringStartsWith('n:', $seenNs, 'the node saw its own task namespace');
    }
}
