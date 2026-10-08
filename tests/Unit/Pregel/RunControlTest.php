<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Errors\GraphBubbleUp;
use LangGraph\Errors\GraphDrained;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\RunControl;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langgraph-core/src/tests/run_control.test.ts`: cooperative draining.
 *
 * `control` rides on the config as `options['control']` (the TS puts it on the config object; the
 * port's `RunnableConfig` is a closed class). Not ported, and why:
 *  - "drain requested in a node stops future steps (async node)": the same case as the sync one;
 *    a PHP node has no async flavour;
 *  - "an external concurrent drain stops the graph at the next boundary" and "drain then cancel via
 *    AbortSignal after a graceful timeout": both need a node suspended while another party acts
 *    (a timer, an abort). Nothing runs concurrently with a PHP node, so there is no "mid-flight"
 *    to request a drain in. The synchronous in-node request is the PHP form of the same signal;
 *  - "Functional API draining": `entrypoint` / `task` are WP-07.
 */
#[CoversClass(RunControl::class)]
#[CoversClass(GraphDrained::class)]
#[CoversClass(Pregel::class)]
final class RunControlTest extends TestCase
{
    private static function state(): \LangGraph\State\AnnotationRoot
    {
        return Annotation::root([
            'first' => Annotation::last(),
            'second' => Annotation::last(),
            'value' => Annotation::last(),
        ]);
    }

    private static function withControl(RunControl $control, array $configurable = [], array $options = []): RunnableConfig
    {
        return new RunnableConfig(configurable: $configurable, options: ['control' => $control] + $options);
    }

    /**
     * stepA (calls $onA) -> stepB, the shape most upstream cases use.
     */
    private static function twoStep(callable $onA, MemorySaver|null $saver = null, ?\Closure $onB = null): Pregel
    {
        $builder = (new StateGraph(self::state()))
            ->addNode('stepA', $onA)
            ->addNode('stepB', $onB ?? static fn (): array => ['second' => 'should-not-run'])
            ->addEdge(Constants::START, 'stepA')
            ->addEdge('stepA', 'stepB')
            ->addEdge('stepB', Constants::END);

        return $builder->compile($saver === null ? [] : ['checkpointer' => $saver]);
    }

    public function testRequestDrainSetsTheFlagAndTheReason(): void
    {
        $control = new RunControl();
        self::assertFalse($control->drainRequested());
        self::assertNull($control->drainReason());

        $control->requestDrain();
        self::assertTrue($control->drainRequested());
        self::assertSame('shutdown', $control->drainReason());

        $other = new RunControl();
        $other->requestDrain('sigterm');
        self::assertSame('sigterm', $other->drainReason());
    }

    public function testADrainRequestedInANodeStopsFutureSteps(): void
    {
        $control = new RunControl();
        $ran = [];
        $graph = self::twoStep(
            static function () use ($control): array {
                $control->requestDrain();

                return ['first' => 'done'];
            },
            null,
            static function () use (&$ran): array {
                $ran[] = 'stepB';

                return ['second' => 'should-not-run'];
            },
        );

        try {
            $graph->invoke([], self::withControl($control));
            self::fail('expected GraphDrained');
        } catch (GraphDrained $e) {
            self::assertSame('Graph drained: shutdown', $e->getMessage());
            self::assertSame('shutdown', $e->reason);
            self::assertInstanceOf(GraphBubbleUp::class, $e);
        }
        self::assertSame([], $ran);
    }

    public function testADrainRequestedInTheTerminalStepFinishesNormally(): void
    {
        $control = new RunControl();
        $graph = (new StateGraph(self::state()))
            ->addNode('only', static function () use ($control): array {
                $control->requestDrain();

                return ['value' => 'done'];
            })
            ->addEdge(Constants::START, 'only')
            ->addEdge('only', Constants::END)
            ->compile();

        $result = $graph->invoke([], self::withControl($control));

        self::assertSame('done', $result['value']);
        self::assertTrue($control->drainRequested());
    }

    public function testANodeCanRequestADrainThroughItsConfig(): void
    {
        $control = new RunControl();
        $sawControl = false;
        $graph = self::twoStep(static function (array $state, RunnableConfig $config) use ($control, &$sawControl): array {
            $sawControl = ($config->options['control'] ?? null) === $control;
            $config->options['control']->requestDrain('from-node');

            return ['first' => 'done'];
        });

        try {
            $graph->invoke([], self::withControl($control));
            self::fail('expected GraphDrained');
        } catch (GraphDrained $e) {
            self::assertSame('from-node', $e->reason);
        }
        self::assertTrue($sawControl);
    }

    public function testAFreshRunControlIsProvidedToNodesWhenNoneIsPassed(): void
    {
        $seen = null;
        $graph = (new StateGraph(self::state()))
            ->addNode('only', static function (array $state, RunnableConfig $config) use (&$seen): array {
                $seen = $config->options['control'] ?? null;

                return ['value' => 'done'];
            })
            ->addEdge(Constants::START, 'only')
            ->addEdge('only', Constants::END)
            ->compile();

        $graph->invoke([]);

        self::assertInstanceOf(RunControl::class, $seen);
        self::assertFalse($seen->drainRequested());
    }

    public function testAPreDrainedControlStopsBeforeExecutingTheFirstTask(): void
    {
        $ran = false;
        $control = new RunControl();
        $control->requestDrain('pre-drained');
        $graph = (new StateGraph(self::state()))
            ->addNode('only', static function () use (&$ran): array {
                $ran = true;

                return ['value' => 'done'];
            })
            ->addEdge(Constants::START, 'only')
            ->addEdge('only', Constants::END)
            ->compile();

        try {
            $graph->invoke([], self::withControl($control));
            self::fail('expected GraphDrained');
        } catch (GraphDrained $e) {
            self::assertSame('Graph drained: pre-drained', $e->getMessage());
        }
        self::assertFalse($ran);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function durabilities(): iterable
    {
        yield 'exit durability' => [['durability' => 'exit']];
        yield 'default durability' => [[]];
    }

    #[DataProvider('durabilities')]
    public function testADrainPersistsAResumableCheckpoint(array $options): void
    {
        $control = new RunControl();
        $graph = self::twoStep(
            static function () use ($control): array {
                $control->requestDrain('sigterm');

                return ['first' => 'done'];
            },
            new MemorySaver(),
            static fn (): array => ['second' => 'done'],
        );
        $thread = ['thread_id' => 'drain'];

        try {
            $graph->invoke([], self::withControl($control, $thread, $options));
            self::fail('expected GraphDrained');
        } catch (GraphDrained $e) {
            self::assertSame('Graph drained: sigterm', $e->getMessage());
        }

        // Resume without a control: the run finishes from the saved checkpoint.
        $resumed = $graph->invoke(null, new RunnableConfig(configurable: $thread, options: $options));

        self::assertSame('done', $resumed['first']);
        self::assertSame('done', $resumed['second']);
    }

    public function testADrainFromASubgraphBubblesUpAndTheParentCanResume(): void
    {
        $control = new RunControl();
        $child = (new StateGraph(self::state()))
            ->addNode('childFirst', static function () use ($control): array {
                $control->requestDrain('sigterm');

                return ['first' => 'done'];
            })
            ->addNode('childSecond', static fn (): array => ['second' => 'done'])
            ->addEdge(Constants::START, 'childFirst')
            ->addEdge('childFirst', 'childSecond')
            ->addEdge('childSecond', Constants::END)
            ->compile(['checkpointer' => true]);
        $parent = (new StateGraph(self::state()))
            ->addNode('child', $child)
            ->addNode('parentSecond', static fn (): array => ['value' => 'parent-done'])
            ->addEdge(Constants::START, 'child')
            ->addEdge('child', 'parentSecond')
            ->addEdge('parentSecond', Constants::END)
            ->compile(['checkpointer' => new MemorySaver()]);
        $thread = ['thread_id' => 'drain-subgraph'];

        try {
            $parent->invoke([], self::withControl($control, $thread));
            self::fail('expected GraphDrained');
        } catch (GraphDrained) {
            $this->addToAssertionCount(1);
        }

        $resumed = $parent->invoke(null, new RunnableConfig(configurable: $thread));

        self::assertSame(['first' => 'done', 'second' => 'done', 'value' => 'parent-done'], $resumed);
    }

    public function testControlIsAcceptedByStreamAndRaisesGraphDrained(): void
    {
        $control = new RunControl();
        $secondRan = false;
        $graph = self::twoStep(
            static function () use ($control): array {
                $control->requestDrain('sigterm');

                return ['value' => 'done'];
            },
            null,
            static function () use (&$secondRan): array {
                $secondRan = true;

                return ['second' => 'nope'];
            },
        );

        try {
            foreach ($graph->stream([], self::withControl($control)) as $ignored) {
                // drain the stream
            }
            self::fail('expected GraphDrained');
        } catch (GraphDrained $e) {
            self::assertSame('Graph drained: sigterm', $e->getMessage());
        }
        self::assertFalse($secondRan);
    }

    public function testADrainedStreamStillDeliveredTheStepsThatCompleted(): void
    {
        $control = new RunControl();
        $graph = self::twoStep(
            static function () use ($control): array {
                $control->requestDrain();

                return ['first' => 'done'];
            },
        );
        $chunks = [];

        try {
            foreach ($graph->stream([], self::withControl($control)) as $chunk) {
                $chunks[] = $chunk;
            }
        } catch (GraphDrained) {
            // expected
        }

        $updates = array_values(array_filter($chunks, static fn (array $c): bool => $c[0] === 'updates'));
        self::assertNotSame([], $updates);
        self::assertSame(['first' => 'done'], $updates[count($updates) - 1][1]['stepA']);
    }
}
