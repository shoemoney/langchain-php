<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stream;

use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Stream\Support\CallbackTransformer;
use LangChain\Tests\Unit\Stream\Support\StreamHelpers;
use LangChain\Utils\Testing\FakeStreamingChatModel;
use LangGraph\Channels\AnyValue;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Constants;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use LangGraph\Stream\AbortSignal;
use LangGraph\Stream\ChatModelStream;
use LangGraph\Stream\RunStream;
use LangGraph\Stream\StreamChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/**
 * {@see RunStream::create()} over real compiled graphs: the engine's chunks go through the pull-driven mux,
 * the built-in transformers and the projections, with no hand-fed events.
 */
#[CoversClass(RunStream::class)]
final class RunStreamEndToEndTest extends TestCase
{
    use StreamHelpers;

    private static function counterGraph(?\Closure $onNode = null): \LangGraph\Pregel\Pregel
    {
        $ann = Annotation::root(['v' => Annotation::withReducer(static fn ($a, $b) => $b, static fn () => 0)]);

        return (new StateGraph($ann))
            ->addNode('a', static function (array $s) use ($onNode): array {
                if ($onNode !== null) {
                    $onNode('a');
                }

                return ['v' => $s['v'] + 1];
            })
            ->addNode('b', static function (array $s) use ($onNode): array {
                if ($onNode !== null) {
                    $onNode('b');
                }

                return ['v' => $s['v'] * 10];
            })
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->addEdge('b', Constants::END)
            ->compile();
    }

    private static function chatGraph(): \LangGraph\Pregel\Pregel
    {
        $model = new FakeStreamingChatModel();

        return (new StateGraph(['messages' => new AnyValue()]))
            ->addNode('agent', static fn (array $s, RunnableConfig $config): array => ['messages' => [$model->invoke([new HumanMessage('hi')], $config)]])
            ->addEdge(Constants::START, 'agent')
            ->addEdge('agent', Constants::END)
            ->compile();
    }

    public function testALinearGraphStreamsLifecycleValuesAndUpdatesInEngineOrder(): void
    {
        $run = RunStream::create(self::counterGraph(), ['v' => 1]);

        $events = self::collect($run);
        $shape = array_map(
            static fn (array $e): string => $e['method'] . ($e['method'] === 'lifecycle' ? ':' . $e['params']['data']['event'] : '') . (isset($e['params']['node']) ? '@' . $e['params']['node'] : ''),
            $events,
        );

        $this->assertSame(
            ['lifecycle:running', 'values', 'updates@a', 'values', 'updates@b', 'values', 'lifecycle:completed'],
            $shape,
        );
        $this->assertSame(range(0, \count($events) - 1), array_column($events, 'seq'), 'the mux numbers every event');
        $this->assertSame(['node' => 'a', 'values' => ['v' => 2]], $events[2]['params']['data']);
        $this->assertSame([], $events[0]['params']['namespace']);
    }

    public function testOutputIsTheFinalStateAndValuesYieldsEverySnapshot(): void
    {
        $run = RunStream::create(self::counterGraph(), ['v' => 1]);

        $this->assertSame([['v' => 1], ['v' => 2], ['v' => 20]], self::collect($run->values()));
        $this->assertSame(['v' => 20], $run->output());
    }

    public function testOutputAloneDrivesTheWholeRun(): void
    {
        $run = RunStream::create(self::counterGraph(), ['v' => 2]);

        $this->assertSame(['v' => 30], $run->output());
        $this->assertSame(['v' => 30], $run->output(), 'a second read is the same settled value');
    }

    public function testLifecycleProjectionTracksTheRootRun(): void
    {
        $run = RunStream::create(self::counterGraph(), ['v' => 1]);

        $entries = self::collect($run->lifecycle());

        $this->assertSame(['running', 'completed'], array_column($entries, 'event'));
        $this->assertSame('root', $entries[0]['graph_name']);
        $this->assertSame([[], []], array_column($entries, 'namespace'));
    }

    public function testNothingRunsUntilAConsumerPulls(): void
    {
        $ran = [];
        $run = RunStream::create(self::counterGraph(static function (string $node) use (&$ran): void {
            $ran[] = $node;
        }), ['v' => 1]);

        $this->assertSame([], $ran, 'creating the stream does not start the engine');

        foreach ($run as $event) {
            if ($event['method'] === 'updates') {
                break;
            }
        }
        $this->assertSame(['a'], $ran, 'reading advances the engine one superstep at a time');

        $run->output();
        $this->assertSame(['a', 'b'], $ran);
    }

    public function testMessagesProjectionStreamsChatModelText(): void
    {
        $run = RunStream::create(self::chatGraph(), ['messages' => []]);

        $messages = self::collect($run->messages());

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(ChatModelStream::class, $messages[0]);
        $this->assertSame('agent', $messages[0]->node);
        $this->assertSame('agent', explode(':', $messages[0]->namespace[0])[0]);
        $this->assertSame('hi', $messages[0]->text(), 'the fake model echoes its last input');
    }

    public function testMessagesFromFiltersByNode(): void
    {
        $run = RunStream::create(self::chatGraph(), ['messages' => []]);
        $other = self::collect($run->messagesFrom('other'));

        $this->assertSame([], $other);

        $run = RunStream::create(self::chatGraph(), ['messages' => []]);
        $agent = self::collect($run->messagesFrom('agent'));

        $this->assertCount(1, $agent);
        $this->assertSame('hi', $agent[0]->text());
    }

    public function testTheNodeNamespaceIsDiscoveredAsAChildAndGoesStartedThenCompleted(): void
    {
        $run = RunStream::create(self::chatGraph(), ['messages' => []]);

        $events = self::collect($run);
        $child = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['method'] === 'lifecycle' && $e['params']['namespace'] !== [],
        ));

        $this->assertSame(['started', 'completed'], array_map(static fn (array $e): string => $e['params']['data']['event'], $child));
        $this->assertSame('agent', $child[0]['params']['data']['graph_name']);
    }

    public function testANodeFailureFailsTheOutputTheEventsAndTheLifecycle(): void
    {
        $graph = (new StateGraph(['x' => 'int']))
            ->addNode('boom', static function (): array {
                throw new \RuntimeException('node failed');
            })
            ->addEdge(Constants::START, 'boom')
            ->addEdge('boom', Constants::END)
            ->compile();

        $run = RunStream::create($graph, ['x' => 1]);
        try {
            $run->output();
            $this->fail('output() should throw the run failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('node failed', $e->getMessage());
        }

        $seen = [];
        try {
            foreach ($run as $event) {
                $seen[] = $event['method'];
            }
            $this->fail('iterating a failed run should throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('node failed', $e->getMessage());
        }
        $this->assertContains('lifecycle', $seen, 'events up to the failure are still delivered');

        $failed = [];
        try {
            foreach ($run->lifecycle() as $entry) {
                $failed[] = $entry['event'];
            }
        } catch (\RuntimeException) {
            // The lifecycle log fails after its entries, like every channel.
        }
        $this->assertSame(['running', 'failed'], $failed);
    }

    public function testAnInterruptMarksTheRunInterruptedAndKeepsItsPayload(): void
    {
        $graph = (new StateGraph(Annotation::root(['answer' => Annotation::last()])))
            ->addNode('node', static fn (): array => ['answer' => interrupt(['question' => 'approve?'])])
            ->addEdge(Constants::START, 'node')
            ->compile(['checkpointer' => new MemorySaver()]);

        $run = RunStream::create($graph, ['answer' => null], new RunnableConfig(configurable: ['thread_id' => 't-1']));
        $run->output();

        $this->assertTrue($run->interrupted());
        $this->assertCount(1, $run->interrupts());
        $this->assertSame(['question' => 'approve?'], $run->interrupts()[0]['payload']);
        $this->assertNotSame('', $run->interrupts()[0]['interruptId']);
    }

    public function testAbortStopsTheRunAtTheNextChunkWithTheReason(): void
    {
        $ran = [];
        $run = RunStream::create(self::counterGraph(static function (string $node) use (&$ran): void {
            $ran[] = $node;
        }), ['v' => 1]);

        $iter = $run->getIterator();
        $iter->current();
        $run->abort(new \RuntimeException('user cancelled'));

        try {
            $run->output();
            $this->fail('an aborted run has no output');
        } catch (\RuntimeException $e) {
            $this->assertSame('user cancelled', $e->getMessage());
        }
        $this->assertTrue($run->signal()->aborted());
    }

    public function testAnAbortWithoutAThrowableReasonFailsWithTheStandardMessage(): void
    {
        $signal = new AbortSignal();
        $run = RunStream::create(self::counterGraph(), ['v' => 1], null, [], $signal);
        $signal->abort();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This operation was aborted');
        $run->output();
    }

    public function testUserTransformersSeeRealEventsAndRemoteChannelsReachTheProtocolStream(): void
    {
        $channel = StreamChannel::remote('node-done');
        $factory = static fn (): CallbackTransformer => new CallbackTransformer(
            init: static fn (): array => ['done' => $channel],
            process: static function (array $e) use ($channel): bool {
                if ($e['method'] === 'updates') {
                    $channel->push($e['params']['node']);
                }

                return true;
            },
        );

        $run = RunStream::create(self::counterGraph(), ['v' => 1], null, [$factory]);
        $events = self::collect($run);

        $forwarded = array_values(array_filter($events, static fn (array $e): bool => $e['method'] === 'custom:node-done'));
        $this->assertSame(['a', 'b'], array_column(array_column($forwarded, 'params'), 'data'));
        $this->assertSame(['a', 'b'], self::collect($run->extensions()['done']));
    }

    public function testCreateDoesNotChangeTheCallersGraphOrConfig(): void
    {
        $graph = self::counterGraph();
        $modesBefore = $graph->streamMode;
        $config = new RunnableConfig();

        RunStream::create($graph, ['v' => 1], $config)->output();

        $this->assertSame($modesBefore, $graph->streamMode);
        $this->assertNull($config->signal);
        $this->assertArrayNotHasKey('version', $config->options);
    }

    public function testTheGraphRunsTwiceFromTwoStreams(): void
    {
        $graph = self::counterGraph();

        $this->assertSame(['v' => 20], RunStream::create($graph, ['v' => 1])->output());
        $this->assertSame(['v' => 50], RunStream::create($graph, ['v' => 4])->output());
    }
}
