<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Checkpoint\Checkpoint;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/**
 * The `durability` run option: `sync`, `async` (default) or `exit`.
 *
 * Upstream covers it inside `pregel.test.ts` ("checkpointDuring" / "durability" cases) and the
 * `run_control` drain cases; this file pins what the port promises:
 *  - `sync` and `async` collapse to one behaviour (every saver call is synchronous), and both
 *    write a checkpoint per superstep;
 *  - `exit` writes NOTHING until the run exits, then one final checkpoint plus the task writes that
 *    were held back, so an interrupt or a failure is still resumable;
 *  - the deprecated `checkpointDuring` maps onto it, and naming both is an error.
 */
#[CoversClass(Pregel::class)]
final class DurabilityTest extends TestCase
{
    use TimeTravelFixtures;

    private static function saver(): MemorySaver
    {
        return new class () extends MemorySaver {
            public int $puts = 0;

            public int $putWritesCalls = 0;

            public function put(array $config, Checkpoint $checkpoint, array $metadata = [], array $newVersions = []): array
            {
                $this->puts++;

                return parent::put($config, $checkpoint, $metadata, $newVersions);
            }

            public function putWrites(array $config, array $writes, string $taskId): array
            {
                $this->putWritesCalls++;

                return parent::putWrites($config, $writes, $taskId);
            }
        };
    }

    private static function pipeline(MemorySaver $saver, array $options = []): Pregel
    {
        return (new StateGraph(self::stateSchema()))
            ->addNode('node_a', static fn (): array => ['value' => ['a']])
            ->addNode('node_b', static fn (): array => ['value' => ['b']])
            ->addNode('node_c', static fn (): array => ['value' => ['c']])
            ->addEdge(Constants::START, 'node_a')
            ->addEdge('node_a', 'node_b')
            ->addEdge('node_b', 'node_c')
            ->compile(['checkpointer' => $saver] + $options);
    }

    private static function withOptions(string $threadId, array $options): RunnableConfig
    {
        return new RunnableConfig(configurable: ['thread_id' => $threadId], options: $options);
    }

    // ---- sync / async -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function perStepModes(): iterable
    {
        yield 'default' => [[]];
        yield 'async' => [['durability' => 'async']];
        yield 'sync' => [['durability' => 'sync']];
        yield 'checkpointDuring true' => [['checkpointDuring' => true]];
    }

    #[DataProvider('perStepModes')]
    public function testEveryPerStepModeWritesACheckpointPerSuperstep(array $options): void
    {
        $saver = self::saver();
        $graph = self::pipeline($saver);

        $result = $graph->invoke(['value' => []], self::withOptions('t', $options));

        self::assertSame(['a', 'b', 'c'], $result['value']);
        $history = $graph->getStateHistory(self::threadConfig('t'));
        self::assertSame([[], ['node_c'], ['node_b'], ['node_a'], ['__start__']], array_column($history, 'next'));
        self::assertSame(['loop', 'loop', 'loop', 'loop', 'input'], array_map(static fn (array $s): mixed => $s['metadata']['source'], $history));
        self::assertSame(5, $saver->puts);
    }

    // ---- exit ---------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function exitModes(): iterable
    {
        yield 'durability exit' => [['durability' => 'exit']];
        yield 'checkpointDuring false' => [['checkpointDuring' => false]];
    }

    #[DataProvider('exitModes')]
    public function testExitWritesOneFinalCheckpointAndNothingBefore(array $options): void
    {
        $saver = self::saver();
        $graph = self::pipeline($saver);

        $result = $graph->invoke(['value' => []], self::withOptions('t', $options));

        self::assertSame(['a', 'b', 'c'], $result['value']);
        self::assertSame(1, $saver->puts);

        $history = $graph->getStateHistory(self::threadConfig('t'));
        self::assertCount(1, $history);
        self::assertSame(['value' => ['a', 'b', 'c']], $history[0]['values']);
        self::assertSame([], $history[0]['next']);
        self::assertSame('loop', $history[0]['metadata']['source']);
        // The supersteps ran in memory (`__start__`, a, b, c = steps 0..3), so the one checkpoint is
        // stamped with the last of them.
        self::assertSame(3, $history[0]['metadata']['step']);
    }

    public function testExitHoldsBackTaskWritesOfACompletedRun(): void
    {
        $saver = self::saver();
        $graph = self::pipeline($saver);

        $graph->invoke(['value' => []], self::withOptions('t', ['durability' => 'exit']));
        $exitWrites = $saver->putWritesCalls;

        $other = self::saver();
        self::pipeline($other)->invoke(['value' => []], self::withOptions('t', ['durability' => 'async']));

        // Per-step mode saves every task's writes as it finishes; exit saves only what is still
        // pending when the run ends, and a finished run has none.
        self::assertLessThan($other->putWritesCalls, $exitWrites);
        self::assertSame(0, $exitWrites);
    }

    public function testASecondExitRunChainsOntoTheFirstCheckpoint(): void
    {
        $saver = self::saver();
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('only', static fn (): array => ['value' => ['o']])
            ->addEdge(Constants::START, 'only')
            ->compile(['checkpointer' => $saver]);
        $options = ['durability' => 'exit'];

        $graph->invoke(['value' => []], self::withOptions('t', $options));
        $graph->invoke(['value' => []], self::withOptions('t', $options));

        $history = $graph->getStateHistory(self::threadConfig('t'));
        self::assertCount(2, $history);
        self::assertSame(['value' => ['o', 'o']], $history[0]['values']);
        self::assertSame(
            $history[1]['config']['configurable']['checkpoint_id'],
            $history[0]['parentConfig']['configurable']['checkpoint_id'],
        );
    }

    public function testAnInterruptUnderExitIsStillResumable(): void
    {
        $saver = self::saver();
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('node_a', static fn (): array => ['value' => ['a']])
            ->addNode('ask', static fn (): array => ['value' => ['human:' . interrupt('Q?')]])
            ->addNode('node_b', static fn (): array => ['value' => ['b']])
            ->addEdge(Constants::START, 'node_a')
            ->addEdge('node_a', 'ask')
            ->addEdge('ask', 'node_b')
            ->compile(['checkpointer' => $saver]);
        $options = ['durability' => 'exit'];

        $graph->invoke(['value' => []], self::withOptions('t', $options));

        // Nothing was saved mid-run, yet the pause is on record: the exit save carries the pending
        // interrupt, so a different process can ask "what is it waiting on?" and answer it.
        self::assertSame(1, $saver->puts);
        self::assertSame(['ask'], $graph->getState(self::threadConfig('t'))->next);
        self::assertSame(['Q?'], self::interruptValues($graph, self::threadConfig('t')));

        $result = $graph->invoke(new Command(resume: 'yes'), self::withOptions('t', $options));
        self::assertSame(['a', 'human:yes', 'b'], $result['value']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function everyMode(): iterable
    {
        yield 'async' => ['async'];
        yield 'sync' => ['sync'];
        yield 'exit' => ['exit'];
    }

    #[DataProvider('everyMode')]
    public function testAFailureKeepsTheFinishedSiblingsWritesSoOnlyTheFailedNodeReruns(string $mode): void
    {
        $saver = self::saver();
        $ran = [];
        $failOnce = true;
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('good', static function () use (&$ran): array {
                $ran[] = 'good';

                return ['value' => ['good']];
            })
            ->addNode('flaky', static function () use (&$ran, &$failOnce): array {
                $ran[] = 'flaky';
                if ($failOnce) {
                    $failOnce = false;
                    throw new \RuntimeException('boom');
                }

                return ['value' => ['flaky']];
            })
            ->addNode('after', static function () use (&$ran): array {
                $ran[] = 'after';

                return ['value' => ['after']];
            })
            ->addEdge(Constants::START, 'good')
            ->addEdge(Constants::START, 'flaky')
            ->addEdge('good', 'after')
            ->addEdge('flaky', 'after')
            ->compile(['checkpointer' => $saver]);
        $options = ['durability' => $mode];

        try {
            $graph->invoke(['value' => []], self::withOptions('t', $options));
            self::fail('expected the flaky node to fail the run');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertEqualsCanonicalizing(['good', 'flaky'], $ran);

        // The run died mid-superstep. `good` had finished, so its write is on record (in `exit`
        // mode the exit flush put it there) and survives: on resume it is neither run again nor
        // lost, which is the half that matters, since a dropped output is silent.
        $ran = [];
        $result = $graph->invoke(null, self::withOptions('t', $options));

        self::assertSame(['flaky', 'after'], $ran, 'the finished sibling is not run again');
        self::assertEqualsCanonicalizing(['good', 'flaky', 'after'], $result['value']);
    }

    public function testExitAppliesToANestedSubgraphToo(): void
    {
        $saver = self::saver();
        $child = (new StateGraph(self::stateSchema()))
            ->addNode('c1', static fn (): array => ['value' => ['c1']])
            ->addNode('c2', static fn (): array => ['value' => ['c2']])
            ->addEdge(Constants::START, 'c1')
            ->addEdge('c1', 'c2')
            ->compile();
        $parent = (new StateGraph(self::stateSchema()))
            ->addNode('child', $child)
            ->addNode('tail', static fn (): array => ['value' => ['tail']])
            ->addEdge(Constants::START, 'child')
            ->addEdge('child', 'tail')
            ->compile(['checkpointer' => $saver]);

        $parent->invoke(['value' => []], self::withOptions('t', ['durability' => 'exit']));
        $exitPuts = $saver->puts;

        $perStep = self::saver();
        $parent2 = (new StateGraph(self::stateSchema()))
            ->addNode('child', $child)
            ->addNode('tail', static fn (): array => ['value' => ['tail']])
            ->addEdge(Constants::START, 'child')
            ->addEdge('child', 'tail')
            ->compile(['checkpointer' => $perStep]);
        $parent2->invoke(['value' => []], self::withOptions('t', ['durability' => 'async']));

        // The parent saves once; the child (a throwaway namespace with no error or interrupt)
        // saves nothing. Per-step durability saves every superstep of both.
        self::assertSame(1, $exitPuts);
        self::assertGreaterThan($exitPuts, $perStep->puts);
    }

    // ---- resolution -----------------------------------------------------------------------------

    public function testBothDurabilityAndCheckpointDuringIsAnError(): void
    {
        $graph = self::pipeline(self::saver());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot use both `durability` and `checkpointDuring` at the same time.');
        $graph->invoke(['value' => []], self::withOptions('t', ['durability' => 'exit', 'checkpointDuring' => true]));
    }

    public function testAnUnknownDurabilityIsRejectedRatherThanTreatedAsDefault(): void
    {
        $graph = self::pipeline(self::saver());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown durability "eventually"');
        $graph->invoke(['value' => []], self::withOptions('t', ['durability' => 'eventually']));
    }

    public function testTheConfigurableKeyANestingParentSetsIsHonoured(): void
    {
        $saver = self::saver();
        $graph = self::pipeline($saver);

        $graph->invoke(['value' => []], new RunnableConfig(configurable: [
            'thread_id' => 't',
            Constants::CONFIG_KEY_DURABILITY => 'exit',
        ]));

        self::assertSame(1, $saver->puts);
    }

    public function testExplicitOptionsBeatTheConfigurableKey(): void
    {
        $saver = self::saver();
        $graph = self::pipeline($saver);

        $graph->invoke(['value' => []], new RunnableConfig(
            configurable: ['thread_id' => 't', Constants::CONFIG_KEY_DURABILITY => 'exit'],
            options: ['durability' => 'sync'],
        ));

        self::assertSame(5, $saver->puts);
    }

    public function testTheModeListIsExposed(): void
    {
        self::assertSame(['sync', 'async', 'exit'], Pregel::DURABILITY_MODES);
    }

    public function testWithoutACheckpointerDurabilityChangesNothing(): void
    {
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('only', static fn (): array => ['value' => ['o']])
            ->addEdge(Constants::START, 'only')
            ->compile();

        foreach (['sync', 'async', 'exit'] as $mode) {
            self::assertSame(['o'], $graph->invoke(['value' => []], new RunnableConfig(options: ['durability' => $mode]))['value'], $mode);
        }
    }
}
