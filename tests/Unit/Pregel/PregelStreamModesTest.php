<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\DynamicStructuredTool;
use LangChain\Tools\Schema;
use LangGraph\Pregel\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/**
 * Port of the PHP-expressible part of `tests/pregel.stream.test.ts`.
 *
 * That file tests `streamEvents(..., { version: "v3" })`, a protocol-event layer
 * (`ProtocolEvent`, `StreamTransformer`, `StreamChannel`, `SubgraphRunStream`,
 * `AbortSignal`) that sits ABOVE `stream()` and is not part of this port. What
 * the file proves about the stream modes themselves is converted here against
 * `stream()`/`invoke()`, whose chunks are `[mode, payload]` tuples.
 *
 * Converted (15 of 31):
 *   values mode       - snapshots, final state, final-state as last chunk, intermediate snapshots (4 of 5)
 *   updates mode      - per-node deltas, node delta values (2 of 2)
 *   tasks mode        - re-expressed over `debug` `task`/`task_result` events (1 of 1)
 *   all modes         - several modes in one run (1 of 4)
 *   interrupts        - interrupt surfaces, resume with a Command (2 of 2)
 *   protocol shape    - values carry state, updates carry node values (2 of 3)
 *   tool errors       - tool start + error events, handled and unhandled (2 of 2)
 *   chunk shape       - every chunk is a `[mode, payload]` pair (1 of 2)
 *
 * Skipped (16), each needing something outside the port: text/event-stream
 * encoding, `custom` mode and `config.writer`, `checkpoints` mode envelopes (5
 * tests), seq/type/timestamp on protocol events (3), subgraph run streams (2),
 * `AbortSignal` (1), user transformers/`StreamChannel` extensions (3), and the
 * v3 checkpoint-envelope assertion of the chunk-shape pair.
 */
#[CoversClass(Pregel::class)]
final class PregelStreamModesTest extends TestCase
{
    private static function counterSchema(): \LangGraph\State\AnnotationRoot
    {
        return Annotation::root([
            'count' => Annotation::withReducer(
                static fn ($a, $b) => ($a ?? 0) + ($b ?? 0),
                static fn (): int => 0,
            ),
        ]);
    }

    private static function counterGraph(array $compile = []): Pregel
    {
        return (new StateGraph(self::counterSchema()))
            ->addNode('add_one', static fn (array $s): array => ['count' => 1])
            ->addEdge(Constants::START, 'add_one')
            ->addEdge('add_one', Constants::END)
            ->compile($compile);
    }

    private static function twoStepGraph(array $compile = []): Pregel
    {
        return (new StateGraph(self::counterSchema()))
            ->addNode('step1', static fn (array $s): array => ['count' => 1])
            ->addNode('step2', static fn (array $s): array => ['count' => 10])
            ->addEdge(Constants::START, 'step1')
            ->addEdge('step1', 'step2')
            ->addEdge('step2', Constants::END)
            ->compile($compile);
    }

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    private static function chunks(Pregel $graph, mixed $input, ?RunnableConfig $config = null): array
    {
        return iterator_to_array($graph->stream($input, $config), false);
    }

    /**
     * @param list<array{0: string, 1: mixed}> $chunks
     * @return list<mixed>
     */
    private static function payloads(array $chunks, string $mode): array
    {
        return array_values(array_map(
            static fn (array $c): mixed => $c[1],
            array_filter($chunks, static fn (array $c): bool => $c[0] === $mode),
        ));
    }

    // ---- values mode ------------------------------------------------------

    public function testValuesChunksMatchTheStateSnapshots(): void
    {
        $values = self::payloads(self::chunks(self::twoStepGraph(['streamMode' => ['values']]), ['count' => 0]), 'values');

        self::assertGreaterThanOrEqual(2, count($values));
        self::assertSame(11, end($values)['count']);
    }

    public function testInvokeReturnsTheFinalState(): void
    {
        self::assertSame(['count' => 6], self::counterGraph()->invoke(['count' => 5]));
    }

    public function testTheLastValuesChunkIsTheFinalState(): void
    {
        $graph = self::counterGraph(['streamMode' => ['values']]);

        $values = self::payloads(self::chunks($graph, ['count' => 0]), 'values');

        self::assertSame(['count' => 1], end($values));
        self::assertSame(end($values), $graph->invoke(['count' => 0]));
    }

    public function testValuesCanBeIteratedForIntermediateSnapshots(): void
    {
        $values = self::payloads(self::chunks(self::twoStepGraph(['streamMode' => ['values']]), ['count' => 0]), 'values');

        self::assertSame([['count' => 0], ['count' => 1], ['count' => 11]], $values);
    }

    // ---- updates mode -----------------------------------------------------

    public function testUpdatesAreEmittedForEachNodeExecution(): void
    {
        $updates = self::payloads(self::chunks(self::twoStepGraph(['streamMode' => ['updates']]), ['count' => 0]), 'updates');

        self::assertSame([['step1' => ['count' => 1]], ['step2' => ['count' => 10]]], $updates);
    }

    public function testUpdatesCarryTheNodeDeltaValues(): void
    {
        $updates = self::payloads(self::chunks(self::counterGraph(['streamMode' => ['updates']]), ['count' => 0]), 'updates');

        self::assertContains(['add_one' => ['count' => 1]], $updates);
    }

    // ---- tasks (via debug) ------------------------------------------------

    public function testTaskEventsAreEmittedAroundANodeRun(): void
    {
        $events = self::payloads(self::chunks(self::counterGraph(['streamMode' => ['debug']]), ['count' => 0]), 'debug');
        $tasks = array_values(array_filter(
            $events,
            static fn (array $e): bool => in_array($e['type'], ['task', 'task_result'], true) && $e['payload']['name'] === 'add_one',
        ));

        self::assertCount(2, $tasks);
        [$start, $result] = $tasks;

        self::assertSame('task', $start['type']);
        self::assertSame('add_one', $start['payload']['name']);
        self::assertSame(['count' => 0], $start['payload']['input']);
        self::assertSame(['branch:to:add_one'], $start['payload']['triggers']);
        self::assertSame([], $start['payload']['interrupts']);
        self::assertIsString($start['payload']['id']);

        self::assertSame('task_result', $result['type']);
        self::assertSame('add_one', $result['payload']['name']);
        self::assertSame(['count' => 1], $result['payload']['result']);
        self::assertSame([], $result['payload']['interrupts']);
        self::assertSame($start['payload']['id'], $result['payload']['id']);
    }

    // ---- all modes together -----------------------------------------------

    public function testSeveralModesAreEmittedInASingleRun(): void
    {
        $chunks = self::chunks(self::twoStepGraph(['streamMode' => ['values', 'updates', 'debug']]), ['count' => 0]);

        $modes = array_unique(array_map(static fn (array $c): string => $c[0], $chunks));

        self::assertContains('values', $modes);
        self::assertContains('updates', $modes);
        self::assertContains('debug', $modes);
    }

    // ---- interrupts -------------------------------------------------------

    private static function askGraph(): Pregel
    {
        return (new StateGraph(Annotation::root(['value' => Annotation::last()])))
            ->addNode('ask', static function (array $s): array {
                return ['value' => (string) interrupt('question?')];
            })
            ->addEdge(Constants::START, 'ask')
            ->addEdge('ask', Constants::END)
            ->compile(['checkpointer' => new MemorySaver(), 'streamMode' => ['updates']]);
    }

    public function testAnInterruptSurfacesInTheStream(): void
    {
        $config = new RunnableConfig(configurable: ['thread_id' => 'int-1']);

        $updates = self::payloads(self::chunks(self::askGraph(), ['value' => 'start'], $config), 'updates');

        $interrupts = array_values(array_filter($updates, static fn (array $u): bool => isset($u[Constants::INTERRUPT])));
        self::assertNotSame([], $interrupts);
        self::assertSame('question?', $interrupts[0][Constants::INTERRUPT][0]['value']);
    }

    public function testAStreamCanBeResumedWithACommand(): void
    {
        $graph = self::askGraph();
        $config = new RunnableConfig(configurable: ['thread_id' => 'int-resume-1']);

        self::chunks($graph, ['value' => 'start'], $config);
        $resumed = self::chunks($graph, new Command(resume: 'yes'), $config);

        $updates = self::payloads($resumed, 'updates');
        self::assertContains(['ask' => ['value' => 'yes']], $updates);
    }

    // ---- protocol shape ---------------------------------------------------

    public function testValuesChunksCarryTheStateAsThePayload(): void
    {
        $chunks = self::chunks(self::counterGraph(['streamMode' => ['values']]), ['count' => 5]);

        self::assertNotSame([], $chunks);
        foreach ($chunks as $chunk) {
            self::assertSame('values', $chunk[0]);
            self::assertArrayHasKey('count', $chunk[1]);
        }
    }

    public function testUpdatesChunksCarryTheNodeNameAndItsValues(): void
    {
        $updates = self::payloads(self::chunks(self::counterGraph(['streamMode' => ['updates']]), ['count' => 0]), 'updates');

        self::assertNotSame([], $updates);
        self::assertSame(['add_one'], array_keys($updates[0]));
        self::assertArrayHasKey('count', $updates[0]['add_one']);
    }

    // ---- tool errors ------------------------------------------------------

    private static function errorTool(): DynamicStructuredTool
    {
        return new DynamicStructuredTool(
            ['name' => 'error_tool', 'description' => 'Always throws', 'schema' => Schema::object([], [])],
            static function (): never {
                throw new \RuntimeException('test error');
            },
        );
    }

    /**
     * @return list<array<string, mixed>> The `tools` events of a run.
     */
    private static function toolEvents(bool $handleToolErrors): array
    {
        $tool = self::errorTool();
        $graph = (new StateGraph(Annotation::root(['out' => Annotation::last()])))
            ->addNode('tools', static function (array $s, RunnableConfig $config) use ($tool, $handleToolErrors): array {
                try {
                    return ['out' => $tool->invoke([], $config->with(['run_name' => 'error_tool']))];
                } catch (\RuntimeException $e) {
                    if (!$handleToolErrors) {
                        throw $e;
                    }

                    return ['out' => 'handled: ' . $e->getMessage()];
                }
            })
            ->addEdge(Constants::START, 'tools')
            ->addEdge('tools', Constants::END)
            ->compile(['streamMode' => ['tools']]);

        $events = [];
        try {
            foreach ($graph->stream(['out' => '']) as [$mode, $payload]) {
                if ($mode === 'tools') {
                    $events[] = $payload;
                }
            }
        } catch (\RuntimeException) {
            // Expected when the error is not handled: it propagates out of the stream.
        }

        return $events;
    }

    public function testToolStartAndErrorAreEmittedWhenTheErrorIsNotHandled(): void
    {
        $events = self::toolEvents(false);

        self::assertContains('on_tool_start', array_column($events, 'event'));
        self::assertContains('on_tool_error', array_column($events, 'event'));
        $error = $events[array_search('on_tool_error', array_column($events, 'event'), true)];
        self::assertSame('error_tool', $error['name']);
        self::assertSame('test error', $error['error']->getMessage());
    }

    public function testToolStartAndErrorAreEmittedWhenTheErrorIsHandled(): void
    {
        $events = self::toolEvents(true);

        self::assertContains('on_tool_start', array_column($events, 'event'));
        self::assertContains('on_tool_error', array_column($events, 'event'));
    }

    // ---- chunk shape ------------------------------------------------------

    public function testEveryChunkIsAModePayloadPairForAMultiModeRunWithACheckpointer(): void
    {
        $graph = self::counterGraph(['checkpointer' => new MemorySaver(), 'streamMode' => ['values', 'updates']]);

        $chunks = self::chunks($graph, ['count' => 0], new RunnableConfig(configurable: ['thread_id' => 'stream-chunk-shape']));

        self::assertNotSame([], $chunks);
        self::assertContains('values', array_column($chunks, 0));
        // `checkpoints` was not requested, so no envelope chunk appears.
        self::assertNotContains('checkpoints', array_column($chunks, 0));
        foreach ($chunks as $chunk) {
            self::assertIsArray($chunk);
            self::assertCount(2, $chunk, 'this port streams [mode, payload]; upstream adds a namespace for 3');
        }
    }
}
