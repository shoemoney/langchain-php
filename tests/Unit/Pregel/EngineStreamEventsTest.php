<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Utils\Config;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\StreamProtocol;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/** Source-derived task/checkpoint/custom payload regressions; PHP retains scalar mode envelopes. */
#[CoversClass(Pregel::class)]
#[CoversClass(StreamProtocol::class)]
final class EngineStreamEventsTest extends TestCase
{
    use TimeTravelFixtures;

    private static function graph(array $options = []): Pregel
    {
        return (new StateGraph(self::stateSchema()))
            ->addNode('one', static function (array $state, RunnableConfig $config): array {
                ($config->options['writer'])(['progress' => 'one']);

                return ['value' => ['one']];
            })
            ->addNode('two', static fn (): array => ['value' => ['two']])
            ->addEdge(Constants::START, 'one')
            ->addEdge('one', 'two')
            ->compile($options);
    }

    private static function payloads(array $chunks, string $mode): array
    {
        return array_values(array_map(static fn (array $c): mixed => $c[1], array_filter($chunks, static fn (array $c): bool => $c[0] === $mode)));
    }

    public function testTasksExposeStartsAndStreamChannelResultsWithSharedIds(): void
    {
        $chunks = iterator_to_array(self::graph()->stream(['value' => []], new RunnableConfig(metadata: ['caller' => 'source'], options: ['streamMode' => 'tasks'])));
        $events = self::payloads($chunks, 'tasks');
        self::assertSame(['one', 'one', 'two', 'two'], array_column($events, 'name'));
        self::assertSame(['value' => []], $events[0]['input']);
        self::assertSame(['value' => ['one']], $events[1]['result']);
        self::assertSame($events[0]['id'], $events[1]['id']);
        self::assertSame(['ls_integration' => 'langgraph', 'caller' => 'source'], $events[0]['metadata']);
        self::assertSame(['value' => ['two']], $events[3]['result']);
        self::assertSame(range(0, 3), array_keys($chunks));
    }

    public function testParallelTaskStartsPrecedeResultsAndHiddenTasksAreFiltered(): void
    {
        $graph = (new StateGraph(self::stateSchema()))
            ->addNode('a', static fn (): array => ['value' => ['a']])
            ->addNode('b', static fn (): array => ['value' => ['b']])
            ->addNode('hidden', static fn (): array => ['value' => ['hidden']], ['tags' => [Constants::TAG_HIDDEN]])
            ->addEdge(Constants::START, 'a')->addEdge(Constants::START, 'b')->addEdge(Constants::START, 'hidden')
            ->compile(['streamMode' => ['tasks']]);
        $events = self::payloads(iterator_to_array($graph->stream(['value' => []])), 'tasks');
        self::assertSame(['a', 'b', 'a', 'b'], array_column($events, 'name'));
        self::assertArrayHasKey('input', $events[0]);
        self::assertArrayHasKey('input', $events[1]);
        self::assertArrayHasKey('result', $events[2]);
    }

    public function testCustomModeUsesWriterAndSupportsArbitraryPayloads(): void
    {
        $graph = (new StateGraph(self::stateSchema()))->addNode('writer', static function (): array {
            $writer = Config::getWriter();
            $writer(null);
            $writer('chunk');
            $writer(['custom_key' => 'custom_value']);

            return ['value' => ['done']];
        })->addEdge(Constants::START, 'writer')->compile();
        $run = $graph->stream(['value' => []], new RunnableConfig(options: ['streamMode' => ['custom', 'updates']]));
        $chunks = iterator_to_array($run);
        self::assertSame([null, 'chunk', ['custom_key' => 'custom_value']], self::payloads($chunks, 'custom'));
        self::assertSame([['writer' => ['value' => ['done']]]], self::payloads($chunks, 'updates'));
        self::assertSame(['value' => ['done']], $run->getReturn());
    }

    public function testUserWriterOverridesDefaultAndUnrequestedCustomIsSilent(): void
    {
        $seen = [];
        $config = new RunnableConfig(options: ['streamMode' => 'custom', 'writer' => static function ($chunk) use (&$seen): void { $seen[] = $chunk; }]);
        self::assertSame([], iterator_to_array(self::graph()->stream(['value' => []], $config)));
        self::assertSame([['progress' => 'one']], $seen);
        self::assertSame([], self::payloads(iterator_to_array(self::graph()->stream(['value' => []])), 'custom'));
    }

    public function testCheckpointModeNamesSavedStateAndNextTasksIncludingTerminalState(): void
    {
        $saver = new MemorySaver();
        $graph = self::graph(['checkpointer' => $saver]);
        $config = new RunnableConfig(configurable: ['thread_id' => 'events'], options: ['streamMode' => ['checkpoints', 'tasks']]);
        $events = self::payloads(iterator_to_array($graph->stream(['value' => []], $config)), 'checkpoints');
        self::assertSame([-1, 0, 1, 2], array_column(array_column($events, 'metadata'), 'step'));
        self::assertSame([[Constants::START], ['one'], ['two'], []], array_column($events, 'next'));
        self::assertSame(['value' => ['one', 'two']], $events[3]['values']);
        foreach ($events as $i => $event) {
            $saved = $saver->getTuple($event['config']['configurable']);
            self::assertNotNull($saved);
            self::assertSame($event['metadata']['step'], $saved->metadata['step']);
            self::assertSame($event['metadata']['source'], $saved->metadata['source']);
            if ($i > 0) {
                self::assertSame($events[$i - 1]['config']['configurable']['checkpoint_id'], $event['parentConfig']['configurable']['checkpoint_id']);
            }
        }
    }

    public function testCheckpointModeWithoutSaverProducesNoSnapshot(): void
    {
        self::assertSame([], iterator_to_array(self::graph(['streamMode' => ['checkpoints']])->stream(['value' => []])));
    }

    public function testTaskResultsExposeInterruptsAndResumeReusesTaskId(): void
    {
        $graph = (new StateGraph(self::stateSchema()))->addNode('ask', static fn (): array => ['value' => [interrupt('question')]])
            ->addEdge(Constants::START, 'ask')->compile(['checkpointer' => new MemorySaver()]);
        $config = new RunnableConfig(configurable: ['thread_id' => 'pause'], options: ['streamMode' => ['tasks', 'updates']]);
        $paused = self::payloads(iterator_to_array($graph->stream(['value' => []], $config)), 'tasks');
        self::assertCount(2, $paused);
        self::assertSame([], $paused[1]['result']);
        self::assertSame('question', $paused[1]['interrupts'][0]['value']);
        $resumed = self::payloads(iterator_to_array($graph->stream(new Command(resume: 'answer'), $config)), 'tasks');
        self::assertSame($paused[0]['id'], $resumed[0]['id']);
        self::assertSame(['value' => ['answer']], $resumed[1]['result']);
    }

    public function testTaskErrorResultArrivesBeforeOriginalErrorPropagates(): void
    {
        $graph = (new StateGraph(self::stateSchema()))->addNode('fail', static fn () => throw new \RuntimeException('engine boom'))
            ->addEdge(Constants::START, 'fail')->compile(['streamMode' => ['tasks']]);
        $events = [];
        try {
            foreach ($graph->stream(['value' => []]) as $chunk) {
                $events[] = $chunk[1];
            }
            self::fail('Expected node error');
        } catch (\RuntimeException $e) {
            self::assertSame('engine boom', $e->getMessage());
        }
        self::assertCount(2, $events);
        self::assertSame($events[0]['id'], $events[1]['id']);
        self::assertSame([], $events[1]['result']);
        self::assertSame([], $events[1]['interrupts']);
    }

    public function testWriterIsClosedAfterCompletedAndFailedRuns(): void
    {
        foreach ([false, true] as $fail) {
            $writer = null;
            $graph = (new StateGraph(self::stateSchema()))->addNode('node', static function (array $state, RunnableConfig $config) use (&$writer, $fail): array {
                $writer = $config->options['writer'];
                $writer('during');
                if ($fail) { throw new \RuntimeException('failure'); }

                return ['value' => []];
            })->addEdge(Constants::START, 'node')->compile(['streamMode' => ['custom']]);
            $seen = [];
            try { foreach ($graph->stream(['value' => []]) as $chunk) { $seen[] = $chunk[1]; } } catch (\RuntimeException) { }
            self::assertSame(['during'], $seen);
            self::assertNull($writer('late'));
        }
    }

    public function testResumeCheckpointDescribesPriorTaskErrorBeforeRerunningIt(): void
    {
        $attempts = 0;
        $graph = (new StateGraph(self::stateSchema()))->addNode('retry', static function () use (&$attempts): array {
            if (++$attempts === 1) { throw new \RuntimeException('recorded error'); }

            return ['value' => ['recovered']];
        })->addEdge(Constants::START, 'retry')->compile(['checkpointer' => new MemorySaver()]);
        $config = new RunnableConfig(configurable: ['thread_id' => 'error-resume'], options: ['streamMode' => ['checkpoints', 'tasks']]);
        try { iterator_to_array($graph->stream(['value' => []], $config)); } catch (\RuntimeException) { }
        $chunks = iterator_to_array($graph->stream(null, $config));
        $checkpoints = self::payloads($chunks, 'checkpoints');
        self::assertSame('recorded error', $checkpoints[0]['tasks'][0]['error']['message']);
        self::assertSame(['retry'], $checkpoints[0]['next']);
        self::assertSame(['value' => ['recovered']], end($checkpoints)['values']);
        self::assertSame(2, $attempts);
    }

    public function testStreamSinkClosesIdempotentlyAndDoesNotInvokeLatePassthrough(): void
    {
        $seen = [];
        $sink = new StreamProtocol(['custom'], static function ($chunk) use (&$seen): void { $seen[] = $chunk; });
        self::assertFalse($sink->isClosed());
        $sink->push([[], 'custom', 'first']);
        $sink->close();
        $sink->close();
        $sink->push([[], 'custom', 'late']);
        self::assertTrue($sink->isClosed());
        self::assertSame([[[], 'custom', 'first']], $seen);
    }

    public function testDuplexSinkFiltersEachSubscriberAndPreservesNamespace(): void
    {
        $a = [];
        $b = [];
        $left = new StreamProtocol(['custom'], static function ($c) use (&$a): void { $a[] = $c; });
        $right = new StreamProtocol(['tasks'], static function ($c) use (&$b): void { $b[] = $c; });
        $stream = StreamProtocol::duplex($left, $right);
        $stream->push([['child:task'], 'custom', 'value']);
        $stream->push([['child:task'], 'tasks', ['name' => 'leaf']]);
        $stream->push([[], 'unrequested', 'ignored']);
        self::assertSame([[['child:task'], 'custom', 'value']], $a);
        self::assertSame([[['child:task'], 'tasks', ['name' => 'leaf']]], $b);
        $stream->close();
        self::assertFalse($left->isClosed());
    }

    public function testGetWriterAcceptsExplicitConfigAndRejectsMissingContext(): void
    {
        $writer = static fn ($value) => $value;
        self::assertSame($writer, Config::getWriter(new RunnableConfig(options: ['writer' => $writer])));
        self::assertSame($writer, Config::getWriter(new RunnableConfig(configurable: ['writer' => $writer])));
        self::assertNull(Config::getWriter(new RunnableConfig()));
        $this->expectException(\RuntimeException::class);
        Config::getWriter();
    }
}
