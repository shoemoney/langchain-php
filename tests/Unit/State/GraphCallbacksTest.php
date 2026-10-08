<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\State;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\CompiledStateGraph;
use LangGraph\Pregel\Constants;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/**
 * The graph transitions behind `langgraph-core/src/tests/graph_callbacks.test.ts`.
 *
 * Upstream's file tests `GraphCallbackHandler`: a callback handler whose `handleInterrupt` and
 * `handleResume` receive `{runId, status, checkpointId, checkpointNs, interrupts}` when a run stops or
 * continues. That handler class lives in the Pregel loop (`PregelLoop.php`, out of this package's
 * reach), and the root graph run does not open a callback run here at all — a handler passed in the
 * config sees no `handleChainStart` for the graph. So the events themselves are NOT ported, and
 * neither are the cases that are about callback plumbing (the mixed callback manager, `raiseError`,
 * `awaitHandlers`, `streamEvents` ordering, `withConfig` callbacks).
 *
 * What is ported is each scenario's **observable transition**, read from the checkpoint the events are
 * derived from: whether the run is pending (dynamic interrupt), `interrupt_before`, `interrupt_after`,
 * the checkpoint it stopped at, the interrupt payloads, and that a resume continues from that
 * checkpoint while new input on a finished thread does not. Those are the facts a lifecycle observer
 * would be handed, so they are what a future `GraphCallbackHandler` port must report.
 */
#[CoversClass(StateGraph::class)]
#[CoversClass(CompiledStateGraph::class)]
final class GraphCallbacksTest extends TestCase
{
    private static function state(): \LangGraph\State\AnnotationRoot
    {
        return Annotation::root(['answer' => Annotation::last()]);
    }

    private static function buildGraph(array $options = []): CompiledStateGraph
    {
        return (new StateGraph(self::state()))
            ->addNode('ask', static fn (): array => ['answer' => interrupt('approve?')])
            ->addEdge(Constants::START, 'ask')
            ->compile(['checkpointer' => new MemorySaver(), ...$options]);
    }

    private static function config(string $thread): RunnableConfig
    {
        return new RunnableConfig(configurable: ['thread_id' => $thread]);
    }

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    private static function chunks(CompiledStateGraph $graph, mixed $input, RunnableConfig $config): array
    {
        return array_values(iterator_to_array($graph->stream($input, $config), false));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pendingInterrupts(CompiledStateGraph $graph, RunnableConfig $config): array
    {
        $out = [];
        foreach ($graph->getState($config)->tasks as $task) {
            foreach ($task->interrupts as $interrupt) {
                $out[] = $interrupt;
            }
        }

        return $out;
    }

    // ---- "reports a dynamic interrupt ... and resumes from its checkpoint" ----------

    public function testADynamicInterruptStopsAtACheckpointCarryingTheInterruptPayload(): void
    {
        $graph = self::buildGraph();
        $config = self::config('dynamic');

        $graph->invoke(['answer' => null], $config);
        $snapshot = $graph->getState($config);

        self::assertSame(['ask'], $snapshot->next);
        self::assertNotEmpty($snapshot->config['configurable']['checkpoint_id'] ?? null, 'the stop has a checkpoint id to report');
        self::assertSame('', $snapshot->config['configurable']['checkpoint_ns'] ?? '', 'a root run reports an empty namespace');

        $interrupts = self::pendingInterrupts($graph, $config);
        self::assertCount(1, $interrupts);
        self::assertSame('approve?', $interrupts[0]['value']);
        self::assertIsString($interrupts[0]['id']);
        self::assertNotSame('', $interrupts[0]['id']);
    }

    public function testTheInterruptPayloadOnTheStreamMatchesTheOneOnTheCheckpoint(): void
    {
        $graph = self::buildGraph();
        $config = self::config('payload');

        $streamed = [];
        foreach (self::chunks($graph, ['answer' => null], $config) as [$mode, $chunk]) {
            if ($mode === 'updates' && is_array($chunk) && isset($chunk[Constants::INTERRUPT])) {
                array_push($streamed, ...$chunk[Constants::INTERRUPT]);
            }
        }

        self::assertSame(self::pendingInterrupts($graph, $config), $streamed);
    }

    public function testAResumeContinuesFromTheInterruptedCheckpoint(): void
    {
        $graph = self::buildGraph();
        $config = self::config('resume');

        $graph->invoke(['answer' => null], $config);
        $interruptedAt = $graph->getState($config)->config['configurable']['checkpoint_id'];
        $interruptId = self::pendingInterrupts($graph, $config)[0]['id'];

        $result = $graph->invoke(new Command(resume: 'yes'), $config);

        self::assertSame(['answer' => 'yes'], $result);
        $after = $graph->getState($config);
        self::assertSame([], $after->next);
        self::assertSame([], self::pendingInterrupts($graph, $config), 'the interrupt was consumed');
        self::assertNotSame($interruptedAt, $after->config['configurable']['checkpoint_id'], 'the resume moved the thread forward');
        self::assertNotSame('', $interruptId);
    }

    // ---- interruptBefore / interruptAfter --------------------------------------------

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function staticInterrupts(): array
    {
        return [
            'interruptBefore' => ['interruptBefore', ['first']],
            'interruptAfter' => ['interruptAfter', ['second']],
        ];
    }

    /**
     * @param list<string> $expectedNext
     */
    #[DataProvider('staticInterrupts')]
    public function testAStaticInterruptCarriesNoInterruptPayloadsAndResumesWithNullInput(string $option, array $expectedNext): void
    {
        $graph = (new StateGraph(self::state()))
            ->addNode('first', static fn (): array => ['answer' => 'first'])
            ->addNode('second', static fn (): array => ['answer' => 'second'])
            ->addEdge(Constants::START, 'first')
            ->addEdge('first', 'second')
            ->compile(['checkpointer' => new MemorySaver(), $option => ['first']]);
        $config = self::config($option);

        $graph->invoke(['answer' => null], $config);
        $stopped = $graph->getState($config);

        self::assertSame($expectedNext, $stopped->next);
        self::assertNotEmpty($stopped->config['configurable']['checkpoint_id'] ?? null);
        self::assertSame([], self::pendingInterrupts($graph, $config), 'a static interrupt has no interrupt payloads');

        self::assertSame(['answer' => 'second'], $graph->invoke(null, $config), 'null input resumes');
        self::assertSame([], $graph->getState($config)->next);
    }

    public function testAStaticInterruptDoesNotNeedACheckpointer(): void
    {
        $graph = (new StateGraph(self::state()))
            ->addNode('ask', static fn (): array => ['answer' => 'done'])
            ->addEdge(Constants::START, 'ask')
            ->compile(['interruptBefore' => ['ask']]);

        self::assertSame(['answer' => null], $graph->invoke(['answer' => null]), 'the node never ran');
    }

    // ---- independence from the stream mode ---------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function streamModes(): array
    {
        // `custom` is the fourth mode upstream tests; this engine rejects it outright (Pregel::SUPPORTED_STREAM_MODES).
        return ['values' => ['values'], 'updates' => ['updates'], 'messages' => ['messages']];
    }

    #[DataProvider('streamModes')]
    public function testTheTransitionIsIndependentOfTheStreamMode(string $mode): void
    {
        $graph = self::buildGraph(['streamMode' => [$mode]]);
        $config = self::config($mode);

        $chunks = self::chunks($graph, ['answer' => null], $config);

        self::assertCount(1, self::pendingInterrupts($graph, $config));
        if ($mode === 'messages') {
            self::assertSame([], $chunks, 'this mode emits nothing for a graph with no model');
        }

        self::chunks($graph, new Command(resume: 'yes'), $config);

        self::assertSame([], self::pendingInterrupts($graph, $config));
        self::assertSame(['answer' => 'yes'], $graph->getState($config)->values);
    }

    // ---- nested graphs ------------------------------------------------------------------

    public function testANestedInterruptIsReportedOnceAtTheRootNamespace(): void
    {
        $child = (new StateGraph(self::state()))
            ->addNode('ask', static fn (): array => ['answer' => interrupt('nested?')])
            ->addEdge(Constants::START, 'ask')
            ->compile(['name' => 'child']);
        $graph = (new StateGraph(self::state()))
            ->addNode('child', $child)
            ->addEdge(Constants::START, 'child')
            ->compile(['checkpointer' => new MemorySaver(), 'name' => 'parent']);
        $config = self::config('nested');

        $graph->invoke(['answer' => null], $config);
        $snapshot = $graph->getState($config);

        self::assertSame(['child'], $snapshot->next);
        self::assertSame('', $snapshot->config['configurable']['checkpoint_ns'] ?? '', 'the root checkpoint is the one reported');
        $interrupts = self::pendingInterrupts($graph, $config);
        self::assertCount(1, $interrupts);
        self::assertSame('nested?', $interrupts[0]['value']);

        self::assertSame(['answer' => 'yes'], $graph->invoke(new Command(resume: 'yes'), $config));
        self::assertSame([], self::pendingInterrupts($graph, $config));
    }

    // ---- ordinary execution ---------------------------------------------------------------

    /**
     * @return array<string, array{0: bool}>
     */
    public static function failureModes(): array
    {
        return ['success' => [false], 'failure' => [true]];
    }

    #[DataProvider('failureModes')]
    public function testOrdinaryExecutionLeavesNoInterruptBehind(bool $failure): void
    {
        $graph = (new StateGraph(self::state()))
            ->addNode('node', static function () use ($failure): array {
                if ($failure) {
                    throw new \RuntimeException('node failure');
                }

                return ['answer' => 'done'];
            })
            ->addEdge(Constants::START, 'node')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::config('ordinary-' . (int) $failure);

        if ($failure) {
            try {
                $graph->invoke(['answer' => null], $config);
                self::fail('the node failure must propagate');
            } catch (\RuntimeException $e) {
                self::assertSame('node failure', $e->getMessage());
            }
        } else {
            self::assertSame(['answer' => 'done'], $graph->invoke(['answer' => null], $config));
        }

        self::assertSame([], self::pendingInterrupts($graph, $config));
    }

    public function testNewInputOnAnExistingThreadIsNotAResume(): void
    {
        $graph = (new StateGraph(self::state()))
            ->addNode('node', static fn (array $state): array => $state)
            ->addEdge(Constants::START, 'node')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::config('new-input');

        self::assertSame(['answer' => 'first'], $graph->invoke(['answer' => 'first'], $config));
        $firstCheckpoint = $graph->getState($config)->config['configurable']['checkpoint_id'];

        self::assertSame(['answer' => 'second'], $graph->invoke(['answer' => 'second'], $config));
        self::assertNotSame($firstCheckpoint, $graph->getState($config)->config['configurable']['checkpoint_id']);
        self::assertSame([], self::pendingInterrupts($graph, $config));
    }

    // ---- parallel interrupts --------------------------------------------------------------

    public function testAllParallelInterruptsAreCapturedTogetherAtTheRoot(): void
    {
        $graph = (new StateGraph(self::state()))
            ->addNode('left', static function (): array {
                interrupt('left');

                return [];
            })
            ->addNode('right', static function (): array {
                interrupt('right');

                return [];
            })
            ->addEdge(Constants::START, 'left')
            ->addEdge(Constants::START, 'right')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = self::config('parallel');

        $graph->invoke(['answer' => null], $config);
        $interrupts = self::pendingInterrupts($graph, $config);

        $values = array_map(static fn (array $i): mixed => $i['value'], $interrupts);
        sort($values);
        self::assertSame(['left', 'right'], $values);
        self::assertCount(2, array_unique(array_map(static fn (array $i): string => $i['id'], $interrupts)), 'ids are distinct');

        $resume = [];
        foreach ($interrupts as $interrupt) {
            $resume[$interrupt['id']] = 'yes';
        }
        $graph->invoke(new Command(resume: $resume), $config);

        self::assertSame([], self::pendingInterrupts($graph, $config));
        self::assertSame([], $graph->getState($config)->next);
    }
}
