<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stream;

use LangChain\Tests\Unit\Stream\Support\CallbackTransformer;
use LangChain\Tests\Unit\Stream\Support\NativeCallbackTransformer;
use LangChain\Tests\Unit\Stream\Support\StreamHelpers;
use LangGraph\Stream\AbortSignal;
use LangGraph\Stream\Mux;
use LangGraph\Stream\RunStream;
use LangGraph\Stream\StreamChannel;
use LangGraph\Stream\SubgraphRunStream;
use LangGraph\Stream\Transformers\SubgraphDiscoveryTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langgraph-core/src/stream/run-stream.test.ts`: `GraphRunStream`, `SubgraphRunStream` and
 * `createGraphRunStream` (here {@see RunStream}, {@see SubgraphRunStream} and {@see RunStream::fromSource()}).
 *
 * Recast for PHP: the promise half of `values` / `output` is `output()`, which drives the run; abort is
 * the {@see AbortSignal}. Each case that fed a source through `createGraphRunStream` does the same through
 * the pull-driven run.
 */
#[CoversClass(RunStream::class)]
#[CoversClass(SubgraphRunStream::class)]
#[CoversClass(AbortSignal::class)]
final class RunStreamTest extends TestCase
{
    use StreamHelpers;

    private static function installSubgraphDiscovery(Mux $mux): void
    {
        $mux->addTransformer(new SubgraphDiscoveryTransformer(
            $mux,
            static fn (array $path, int $discoveryStart, int $eventStart): SubgraphRunStream => new SubgraphRunStream($path, $mux, $discoveryStart, $eventStart),
        ));
    }

    // ---- GraphRunStream ----------------------------------------------------------------------

    public function testIteratesProtocolEventsViaGetIterator(): void
    {
        $mux = new Mux();
        $stream = new RunStream([], $mux);
        $mux->register([], $stream);

        $mux->push([], self::makeEvent('values', [], ['count' => 1], null, 0));
        $mux->push([], self::makeEvent('updates', [], ['count' => 2], null, 1));
        $mux->close();

        $events = self::collect($stream);
        $this->assertCount(2, $events);
        $this->assertSame('values', $events[0]['method']);
        $this->assertSame('updates', $events[1]['method']);
    }

    public function testSubgraphsGetterYieldsSubgraphRunStreamOnDiscovery(): void
    {
        $mux = new Mux();
        self::installSubgraphDiscovery($mux);
        $root = new RunStream([], $mux);
        $mux->register([], $root);

        $mux->push(['child:0'], self::makeEvent('values', ['child:0'], ['x' => 1], null, 0));
        $mux->close();

        $subs = self::collect($root->subgraphs());
        $this->assertCount(1, $subs);
        $this->assertInstanceOf(SubgraphRunStream::class, $subs[0]);
        $this->assertSame('child', $subs[0]->name);
        $this->assertSame(0, $subs[0]->index);
    }

    public function testValuesGetterIteratesSnapshotsAndOutputIsTheFinalState(): void
    {
        $mux = new Mux();
        $root = new RunStream([], $mux);
        $mux->register([], $root);

        $mux->push([], self::makeEvent('values', [], ['step' => 1], null, 0));
        $mux->push([], self::makeEvent('values', [], ['step' => 2], null, 1));
        $mux->close();

        $this->assertSame([['step' => 1], ['step' => 2]], self::collect($root->values()));
        $this->assertSame(['step' => 2], $root->output());
    }

    public function testOutputResolvesWhenResolveValuesIsCalled(): void
    {
        $root = new RunStream([], new Mux());

        $root->resolveValues(['answer' => 42]);

        $this->assertSame(['answer' => 42], $root->output());
    }

    public function testOutputRejectsWhenRejectValuesIsCalled(): void
    {
        $root = new RunStream([], new Mux());

        $root->rejectValues(new \RuntimeException('run failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('run failed');
        $root->output();
    }

    public function testMessagesGetterReturnsFallbackIterableWhenNotSet(): void
    {
        $root = new RunStream([], new Mux());

        $this->assertInstanceOf(\Traversable::class, $root->messages());
        $this->assertSame([], self::collect($root->messages()));
    }

    public function testMessagesFromReplaysBufferedMessagesEventsViaAddTransformer(): void
    {
        $mux = new Mux();
        $root = new RunStream([], $mux);
        $mux->register([], $root);

        // A complete message lifecycle at depth 1 with node attribution, before anyone asks for messagesFrom().
        $mux->push(['agent:0'], self::makeEvent('messages', ['agent:0'], ['event' => 'message-start'], 'agent', 0));
        $mux->push(['agent:0'], self::makeEvent('messages', ['agent:0'], ['event' => 'content-block-delta', 'content' => ['type' => 'text', 'text' => 'hello']], 'agent', 1));
        $mux->push(['agent:0'], self::makeEvent('messages', ['agent:0'], ['event' => 'message-finish', 'reason' => 'stop'], 'agent', 2));
        $mux->close();

        // Late call: the transformer is registered after all events, so addTransformer replays them.
        $messages = self::collect($root->messagesFrom('agent'));

        $this->assertCount(1, $messages);
        $this->assertSame('hello', $messages[0]->text());
    }

    public function testMessagesGetterReturnsProvidedIterableWhenSet(): void
    {
        $root = new RunStream([], new Mux());
        $root->setMessagesIterable(new \ArrayObject([['fake' => 'message']]));

        $this->assertSame([['fake' => 'message']], self::collect($root->messages()));
    }

    public function testInterruptedAndInterruptsDelegateToMux(): void
    {
        $mux = new Mux();
        $root = new RunStream([], $mux);

        $this->assertFalse($root->interrupted());
        $this->assertSame([], $root->interrupts());

        $mux->markInterrupted([['interruptId' => 'int-1', 'payload' => ['question' => 'continue?']]]);

        $this->assertTrue($root->interrupted());
        $this->assertCount(1, $root->interrupts());
        $this->assertSame('int-1', $root->interrupts()[0]['interruptId']);
    }

    public function testAbortTriggersTheSignal(): void
    {
        $root = new RunStream([], new Mux());

        $this->assertFalse($root->signal()->aborted());
        $root->abort('cancelled');
        $this->assertTrue($root->signal()->aborted());
        $this->assertSame('cancelled', $root->signal()->reason());
        $this->assertTrue($root->signal()(), 'the signal is also a polled callable');
    }

    public function testSignalGetterReturnsTheAbortSignal(): void
    {
        $controller = new AbortSignal();
        $root = new RunStream([], new Mux(), 0, 0, [], $controller);

        $this->assertSame($controller, $root->signal());
    }

    // ---- SubgraphRunStream -------------------------------------------------------------------

    public function testParsesNameFromLastPathSegment(): void
    {
        $this->assertSame('agent', (new SubgraphRunStream(['agent'], new Mux()))->name);
    }

    public function testParsesIndexFromNameNSuffix(): void
    {
        $sub = new SubgraphRunStream(['parent', 'child:3'], new Mux());

        $this->assertSame('child', $sub->name);
        $this->assertSame(3, $sub->index);
    }

    public function testDefaultsIndexToZeroWhenNoNumericSuffix(): void
    {
        $noColon = new SubgraphRunStream(['nodeName'], new Mux());
        $this->assertSame('nodeName', $noColon->name);
        $this->assertSame(0, $noColon->index);

        $nonNumeric = new SubgraphRunStream(['node:abc'], new Mux());
        $this->assertSame('node', $nonNumeric->name);
        $this->assertSame(0, $nonNumeric->index);
    }

    public function testInheritsRunStreamFunctionality(): void
    {
        $mux = new Mux();
        $sub = new SubgraphRunStream(['sub:0'], $mux);
        $mux->register(['sub:0'], $sub);

        $mux->push(['sub:0'], self::makeEvent('values', ['sub:0'], ['v' => 10], null, 0));
        $mux->close();

        $events = self::collect($sub);
        $this->assertCount(1, $events);
        $this->assertSame(['v' => 10], $events[0]['params']['data']);

        $this->assertFalse($sub->interrupted());
        $this->assertInstanceOf(AbortSignal::class, $sub->signal());
    }

    // ---- createGraphRunStream ----------------------------------------------------------------

    public function testCreatesAStreamThatIteratesEventsFromSource(): void
    {
        $stream = RunStream::fromSource(self::makeSource([
            [[], 'values', ['a' => 1]],
            [[], 'updates', ['node' => 'n', 'values' => []]],
        ]));

        $methods = array_column(self::collect($stream), 'method');

        $this->assertContains('values', $methods);
        $this->assertGreaterThanOrEqual(1, \count($methods));
    }

    public function testOutputResolvesWithFinalValuesFromSource(): void
    {
        $stream = RunStream::fromSource(self::makeSource([
            [[], 'values', ['step' => 1]],
            [[], 'values', ['step' => 2]],
        ]));

        $this->assertSame(['step' => 2], $stream->output());
    }

    public function testCustomTransformersReceiveEventsAndProduceExtensions(): void
    {
        $processed = 0;
        $factory = static function () use (&$processed): CallbackTransformer {
            return new CallbackTransformer(
                init: static fn (): array => ['counter' => 0],
                process: static function () use (&$processed): bool {
                    ++$processed;

                    return true;
                },
            );
        };

        $stream = RunStream::fromSource(self::makeSource([[[], 'values', ['x' => 1]], [[], 'values', ['x' => 2]]]), [$factory]);
        $stream->output();

        $this->assertGreaterThan(0, $processed);
        $this->assertArrayHasKey('counter', $stream->extensions());
    }

    public function testProcessesValuesModeEventsThroughTheValuesTransformer(): void
    {
        $stream = RunStream::fromSource(self::makeSource([
            [[], 'values', ['count' => 10]],
            [[], 'values', ['count' => 20]],
            [[], 'values', ['count' => 30]],
        ]));

        $this->assertSame([['count' => 10], ['count' => 20], ['count' => 30]], self::collect($stream->values()));
    }

    public function testWiresStreamChannelProjectionsFromExtensionTransformersToTheProtocolStream(): void
    {
        $channel = StreamChannel::remote('custom-ext');
        $factory = static fn (): CallbackTransformer => new CallbackTransformer(
            init: static fn (): array => ['myChannel' => $channel],
            process: static function (array $e) use ($channel): bool {
                if ($e['method'] === 'values') {
                    $channel->push(['msg' => 'forwarded']);
                }

                return true;
            },
        );

        $stream = RunStream::fromSource(self::makeSource([[[], 'values', ['x' => 1]]]), [$factory]);
        $events = self::collect($stream);

        $channelEvents = array_values(array_filter($events, static fn (array $e): bool => $e['method'] === 'custom:custom-ext'));
        $this->assertCount(1, $channelEvents);
        $this->assertSame(['msg' => 'forwarded'], $channelEvents[0]['params']['data']);
        $this->assertArrayHasKey('myChannel', $stream->extensions());
    }

    public function testKeepsLocalStreamChannelProjectionsInProcessOnly(): void
    {
        $channel = StreamChannel::local();
        $factory = static fn (): CallbackTransformer => new CallbackTransformer(
            init: static fn (): array => ['myChannel' => $channel],
            process: static function (array $e) use ($channel): bool {
                if ($e['method'] === 'values') {
                    $channel->push(['msg' => 'local']);
                }

                return true;
            },
        );

        $stream = RunStream::fromSource(self::makeSource([[[], 'values', ['x' => 1]]]), [$factory]);
        $events = self::collect($stream);

        $this->assertNotContains('custom:', array_map(static fn (array $e): string => substr($e['method'], 0, 7), $events));
        $this->assertCount(0, array_filter($events, static fn (array $e): bool => $e['params']['data'] === $channel));
        $this->assertSame([['msg' => 'local']], self::collect($channel));
    }

    public function testDoesNotWireStreamChannelProjectionsFromNativeTransformers(): void
    {
        $channel = StreamChannel::remote('native-ch');
        $factory = static fn (): NativeCallbackTransformer => new NativeCallbackTransformer(
            init: static fn (): array => ['nativeProp' => $channel],
            process: static function (array $e) use ($channel): bool {
                if ($e['method'] === 'values') {
                    $channel->push(['obj' => 42]);
                }

                return true;
            },
        );

        $stream = RunStream::fromSource(self::makeSource([[[], 'values', ['x' => 1]]]), [$factory]);
        $events = self::collect($stream);

        $this->assertCount(0, array_filter($events, static fn (array $e): bool => $e['method'] === 'native-ch'));
        $this->assertCount(0, array_filter($events, static fn (array $e): bool => $e['method'] === 'custom:native-ch'));
        $this->assertArrayNotHasKey('nativeProp', $stream->extensions());
    }

    public function testNativeProjectionsAreAssignedDirectlyToTheRootStreamNotExtensions(): void
    {
        $factory = static fn (): NativeCallbackTransformer => new NativeCallbackTransformer(
            init: static fn (): array => ['toolCalls' => ['call_1']],
        );

        $stream = RunStream::fromSource(self::makeSource([[[], 'values', ['x' => 1]]]), [$factory]);
        $stream->output();

        $this->assertArrayNotHasKey('toolCalls', $stream->extensions());
        $this->assertTrue(isset($stream->toolCalls));
        $this->assertSame(['call_1'], $stream->toolCalls);
    }

    public function testMixedNativeAndExtensionTransformersOnlyExtensionChannelsAreWired(): void
    {
        $extChannel = StreamChannel::remote('ext-data');
        $nativeChannel = StreamChannel::remote('native-data');
        $extensionFactory = static fn (): CallbackTransformer => new CallbackTransformer(
            init: static fn (): array => ['extData' => $extChannel],
            process: static function (array $e) use ($extChannel): bool {
                if ($e['method'] === 'values') {
                    $extChannel->push('ext-item');
                }

                return true;
            },
        );
        $nativeFactory = static fn (): NativeCallbackTransformer => new NativeCallbackTransformer(
            init: static fn (): array => ['nativeData' => $nativeChannel],
            process: static function (array $e) use ($nativeChannel): bool {
                if ($e['method'] === 'values') {
                    $nativeChannel->push('native-item');
                }

                return true;
            },
        );

        $stream = RunStream::fromSource(self::makeSource([[[], 'values', ['x' => 1]]]), [$extensionFactory, $nativeFactory]);
        $events = self::collect($stream);

        $extEvents = array_values(array_filter($events, static fn (array $e): bool => $e['method'] === 'custom:ext-data'));
        $this->assertCount(1, $extEvents);
        $this->assertSame('ext-item', $extEvents[0]['params']['data']);
        $this->assertCount(0, array_filter($events, static fn (array $e): bool => $e['method'] === 'native-data' || $e['method'] === 'custom:native-data'));
        $this->assertSame(['native-item'], self::collect($stream->nativeData), 'native channels still iterate (and drive the run)');
    }
}
