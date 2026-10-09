<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stream;

use LangChain\Tests\Unit\Stream\Support\CallbackTransformer;
use LangChain\Tests\Unit\Stream\Support\RecordingHandle;
use LangChain\Tests\Unit\Stream\Support\StreamHelpers;
use LangGraph\Pregel\Constants;
use LangGraph\Stream\Deferred;
use LangGraph\Stream\Mux;
use LangGraph\Stream\StreamChannel;
use LangGraph\Stream\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langgraph-core/src/stream/mux.test.ts`: `nsKey`, `hasPrefix`, `StreamMux` and `pump`.
 *
 * `vi.fn()` spies are counters in closures. Upstream's promise-valued projections are {@see Deferred}s.
 */
#[CoversClass(Mux::class)]
#[CoversClass(Types::class)]
final class MuxTest extends TestCase
{
    use StreamHelpers;

    // ---- nsKey -------------------------------------------------------------------------------

    public function testNsKeyJoinsSegmentsWithNullByte(): void
    {
        $this->assertSame("a\x00b\x00c", Types::nsKey(['a', 'b', 'c']));
    }

    public function testNsKeyReturnsEmptyStringForEmptyNamespace(): void
    {
        $this->assertSame('', Types::nsKey([]));
    }

    public function testNsKeyReturnsTheSegmentItselfForSingleElementNamespace(): void
    {
        $this->assertSame('root', Types::nsKey(['root']));
    }

    // ---- hasPrefix ---------------------------------------------------------------------------

    public function testHasPrefixReturnsTrueForEmptyPrefix(): void
    {
        $this->assertTrue(Types::hasPrefix(['a', 'b'], []));
    }

    public function testHasPrefixReturnsTrueForExactMatch(): void
    {
        $this->assertTrue(Types::hasPrefix(['a', 'b'], ['a', 'b']));
    }

    public function testHasPrefixReturnsTrueWhenNsStartsWithPrefix(): void
    {
        $this->assertTrue(Types::hasPrefix(['a', 'b', 'c'], ['a', 'b']));
    }

    public function testHasPrefixReturnsFalseWhenPrefixIsLongerThanNs(): void
    {
        $this->assertFalse(Types::hasPrefix(['a'], ['a', 'b']));
    }

    public function testHasPrefixReturnsFalseWhenSegmentsDiffer(): void
    {
        $this->assertFalse(Types::hasPrefix(['a', 'b'], ['a', 'x']));
    }

    public function testHasPrefixReturnsTrueForTwoEmptyArrays(): void
    {
        $this->assertTrue(Types::hasPrefix([], []));
    }

    // ---- StreamMux ---------------------------------------------------------------------------

    public function testPushRunsTransformerPipelineEventsSuppressedWhenTransformerReturnsFalse(): void
    {
        $mux = new Mux();
        $mux->addTransformer(new CallbackTransformer(process: static fn (array $e): bool => $e['method'] !== 'debug'));

        $mux->push([], self::makeEvent('messages', [], [], null, 0));
        $mux->push([], self::makeEvent('debug', [], [], null, 1));
        $mux->push([], self::makeEvent('updates', [], [], null, 2));
        $mux->events->close();

        $events = self::collect($mux->events->iterate());
        $this->assertCount(2, $events);
        $this->assertSame('messages', $events[0]['method']);
        $this->assertSame('updates', $events[1]['method']);
    }

    public function testStreamChannelAutoForwardsPushesIntoTheMainEventLog(): void
    {
        $mux = new Mux();
        $channel = StreamChannel::remote('tools');
        $transformer = new CallbackTransformer(
            init: static fn (): array => ['tools' => $channel],
            process: static function (array $e) use ($channel): bool {
                if ($e['method'] === 'messages') {
                    $channel->push(['event' => 'tool-started', 'tool_name' => 'search']);
                }

                return true;
            },
        );
        $mux->addTransformer($transformer);
        $mux->wireChannels($transformer->init());

        $mux->push([], self::makeEvent('messages', [], [], null, 0));
        $mux->events->close();

        $events = self::collect($mux->events->iterate());
        $this->assertCount(2, $events);
        // Channel-forwarded events appear during process(), before the original event is appended.
        $this->assertSame('custom:tools', $events[0]['method']);
        $this->assertSame(['event' => 'tool-started', 'tool_name' => 'search'], $events[0]['params']['data']);
        $this->assertSame('messages', $events[1]['method']);
    }

    public function testLocalStreamChannelsAreTrackedButNotAutoForwarded(): void
    {
        $mux = new Mux();
        $channel = StreamChannel::local();
        $transformer = new CallbackTransformer(
            init: static fn (): array => ['tools' => $channel],
            process: static function (array $e) use ($channel): bool {
                if ($e['method'] === 'messages') {
                    $channel->push(['event' => 'tool-started']);
                }

                return true;
            },
        );
        $mux->addTransformer($transformer);
        $mux->wireChannels($transformer->init());

        $mux->push([], self::makeEvent('messages', [], [], null, 0));
        $mux->close();

        $events = self::collect($mux->events->iterate());
        $this->assertCount(1, $events);
        $this->assertSame('messages', $events[0]['method']);
        $this->assertSame([['event' => 'tool-started']], self::collect($channel));
    }

    public function testStreamChannelAutoForwardedEventsInheritTheTriggeringNamespace(): void
    {
        $mux = new Mux();
        $channel = StreamChannel::remote('tools');
        $transformer = new CallbackTransformer(
            init: static fn (): array => ['tools' => $channel],
            process: static function () use ($channel): bool {
                $channel->push(['event' => 'tool-started']);

                return true;
            },
        );
        $mux->addTransformer($transformer);
        $mux->wireChannels($transformer->init());

        $mux->push(['agent'], self::makeEvent('messages', ['agent'], [], null, 0));
        $mux->events->close();

        $events = self::collect($mux->events->iterate());
        $this->assertSame(['agent'], $events[1]['params']['namespace']);
    }

    public function testStreamChannelAutoForwardedEventsGetSequentialSeqNumbers(): void
    {
        $mux = new Mux();
        $ch1 = StreamChannel::remote('tools');
        $ch2 = StreamChannel::remote('custom');
        $transformer = new CallbackTransformer(
            init: static fn (): array => ['ch1' => $ch1, 'ch2' => $ch2],
            process: static function () use ($ch1, $ch2): bool {
                $ch1->push(['a' => 1]);
                $ch2->push(['b' => 2]);

                return true;
            },
        );
        $mux->addTransformer($transformer);
        $mux->wireChannels($transformer->init());

        $mux->push([], self::makeEvent('messages', [], [], null, 5));
        $mux->events->close();

        $events = self::collect($mux->events->iterate());
        // Channel events appear before the original because pushes happen during process(); the mux
        // re-stamps every event with its monotonic counter so the log is strictly increasing.
        $this->assertSame(['custom:tools', 'custom:custom', 'messages'], array_column($events, 'method'));
        for ($i = 1; $i < \count($events); ++$i) {
            $this->assertGreaterThan($events[$i - 1]['seq'], $events[$i]['seq']);
        }
    }

    public function testMuxAutoClosesStreamChannelsOnClose(): void
    {
        $mux = new Mux();
        $channel = StreamChannel::remote('stats');
        $transformer = new CallbackTransformer(
            init: static fn (): array => ['stats' => $channel],
            process: static function () use ($channel): bool {
                $channel->push(42);

                return true;
            },
        );
        $mux->addTransformer($transformer);
        $mux->wireChannels($transformer->init());

        $mux->push([], self::makeEvent('values', [], [], null, 0));
        $mux->close();

        $this->assertSame([42], self::collect($channel));
        $this->assertTrue($channel->done());
    }

    public function testMuxAutoFailsStreamChannelsOnFail(): void
    {
        $mux = new Mux();
        $channel = StreamChannel::remote('stats');
        $transformer = new CallbackTransformer(init: static fn (): array => ['stats' => $channel]);
        $mux->addTransformer($transformer);
        $mux->wireChannels($transformer->init());

        $mux->fail(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        self::collect($channel);
    }

    public function testFinalValueProjectionsFlushAsCustomKeyEventsOnClose(): void
    {
        $mux = new Mux();
        $toolCallCount = new Deferred();
        $projection = ['toolCallCount' => $toolCallCount];
        $mux->addTransformer(new CallbackTransformer(
            init: static fn (): array => $projection,
            finalize: static fn () => $toolCallCount->resolve(7),
        ));
        $mux->wireChannels($projection);

        $mux->close();

        $events = self::collect($mux->events->iterate());
        $this->assertCount(1, $events);
        $this->assertSame('custom', $events[0]['method']);
        $this->assertSame(['name' => 'toolCallCount', 'payload' => 7], $events[0]['params']['data']);
        $this->assertTrue($mux->events->done());
        $this->assertTrue($mux->discoveries->done());
    }

    public function testUsesTheProjectionKeyAsTheCustomEventName(): void
    {
        $mux = new Mux();
        $projection = ['alpha' => new Deferred(), 'beta' => new Deferred()];
        $mux->addTransformer(new CallbackTransformer(
            init: static fn (): array => $projection,
            finalize: static function () use ($projection) {
                $projection['alpha']->resolve(1);
                $projection['beta']->resolve('two');
            },
        ));
        $mux->wireChannels($projection);
        $mux->close();

        $names = array_map(static fn (array $e): string => $e['params']['data']['name'], self::collect($mux->events->iterate()));
        sort($names);
        $this->assertSame(['alpha', 'beta'], $names);
    }

    public function testRejectedFinalValuePromisesAreDroppedAndDoNotBlockClose(): void
    {
        $mux = new Mux();
        $projection = ['failing' => new Deferred(), 'fine' => new Deferred()];
        $mux->addTransformer(new CallbackTransformer(
            init: static fn (): array => $projection,
            finalize: static function () use ($projection) {
                $projection['failing']->reject(new \RuntimeException('nope'));
                $projection['fine']->resolve(42);
            },
        ));
        $mux->wireChannels($projection);
        $mux->close();

        $events = self::collect($mux->events->iterate());
        $this->assertCount(1, $events);
        $this->assertSame(['name' => 'fine', 'payload' => 42], $events[0]['params']['data']);
        $this->assertTrue($mux->events->done());
    }

    public function testIgnoresNonStreamChannelNonDeferredProjectionValues(): void
    {
        $mux = new Mux();
        $projection = ['plain' => 42, 'nested' => ['foo' => 'bar'], 'fn' => static fn (): int => 1];
        $mux->addTransformer(new CallbackTransformer(init: static fn (): array => $projection));
        $mux->wireChannels($projection);
        $mux->close();

        $this->assertCount(0, self::collect($mux->events->iterate()));
        $this->assertTrue($mux->events->done());
    }

    public function testStreamChannelAndDeferredProjectionsCoexistInOneTransformer(): void
    {
        $mux = new Mux();
        $activity = StreamChannel::remote('activity');
        $count = new Deferred();
        $projection = ['activity' => $activity, 'count' => $count];
        $mux->addTransformer(new CallbackTransformer(
            init: static fn (): array => $projection,
            process: static function () use ($activity): bool {
                $activity->push(['event' => 'tool-started']);

                return true;
            },
            finalize: static fn () => $count->resolve(3),
        ));
        $mux->wireChannels($projection);

        $mux->push([], self::makeEvent('messages', [], [], null, 0));
        $mux->close();

        $events = self::collect($mux->events->iterate());
        $methods = array_column($events, 'method');
        $this->assertContains('custom:activity', $methods);
        $this->assertContains('custom', $methods);
        $final = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['method'] === 'custom' && ($e['params']['data']['name'] ?? null) === 'count',
        ));
        $this->assertCount(1, $final);
        $this->assertSame(3, $final[0]['params']['data']['payload']);
    }

    public function testAddTransformerProcessOrder(): void
    {
        $mux = new Mux();
        $order = [];
        foreach ([1, 2, 3] as $id) {
            $mux->addTransformer(new CallbackTransformer(process: static function () use (&$order, $id): bool {
                $order[] = $id;

                return true;
            }));
        }

        $mux->push([], self::makeEvent('messages'));

        $this->assertSame([1, 2, 3], $order);
    }

    public function testCloseFinalizesTransformersClosesEventAndDiscoveryLogs(): void
    {
        $mux = new Mux();
        $finalized = 0;
        $mux->addTransformer(new CallbackTransformer(finalize: static function () use (&$finalized) {
            ++$finalized;
        }));
        $mux->close();

        $this->assertSame(1, $finalized);
        $this->assertTrue($mux->events->done());
        $this->assertTrue($mux->discoveries->done());
    }

    public function testCloseResolvesValuesOnRegisteredStreams(): void
    {
        $mux = new Mux();
        $stream = new RecordingHandle();
        $mux->register([], $stream);

        $mux->push([], self::makeEvent('values', [], ['count' => 42], null, 0));
        $mux->close();

        $this->assertSame(['count' => 42], $stream->resolved[0]);
    }

    public function testFailCallsFailOnAllTransformersEventsDiscoveriesAndStreams(): void
    {
        $mux = new Mux();
        $failedWith = null;
        $mux->addTransformer(new CallbackTransformer(fail: static function (mixed $err) use (&$failedWith): void {
            $failedWith = $err;
        }));
        $stream = new RecordingHandle();
        $mux->register(['sub'], $stream);

        $error = new \RuntimeException('test failure');
        $mux->fail($error);

        $this->assertSame($error, $failedWith);
        $this->assertTrue($mux->events->done());
        $this->assertTrue($mux->discoveries->done());
        $this->assertSame([$error], $stream->rejected);
    }

    public function testSubscribeEventsFiltersByNamespacePrefix(): void
    {
        $mux = new Mux();

        $mux->push([], self::makeEvent('messages', [], [], null, 0));
        $mux->push(['agent'], self::makeEvent('messages', ['agent'], [], null, 1));
        $mux->push(['agent', 'sub'], self::makeEvent('updates', ['agent', 'sub'], [], null, 2));
        $mux->push(['other'], self::makeEvent('messages', ['other'], [], null, 3));
        $mux->events->close();

        $filtered = self::collect($mux->subscribeEvents(['agent']));
        $this->assertCount(2, $filtered);
        $this->assertSame(['agent'], $filtered[0]['params']['namespace']);
        $this->assertSame(['agent', 'sub'], $filtered[1]['params']['namespace']);
    }

    public function testAddTransformerReplaysBufferedEventsToTheNewTransformer(): void
    {
        $mux = new Mux();
        $mux->push([], self::makeEvent('messages', [], [], null, 0));
        $mux->push([], self::makeEvent('updates', [], [], null, 1));
        $mux->push([], self::makeEvent('values', [], [], null, 2));

        $replayed = [];
        $mux->addTransformer(new CallbackTransformer(process: static function (array $e) use (&$replayed): bool {
            $replayed[] = $e['method'];

            return true;
        }));

        $this->assertSame(['messages', 'updates', 'values'], $replayed);
    }

    public function testAddTransformerReplaysThenProcessesFutureEvents(): void
    {
        $mux = new Mux();
        $mux->push([], self::makeEvent('messages', [], [], null, 0));

        $processed = [];
        $mux->addTransformer(new CallbackTransformer(process: static function (array $e) use (&$processed): bool {
            $processed[] = $e['method'];

            return true;
        }));
        $this->assertSame(['messages'], $processed);

        $mux->push([], self::makeEvent('updates', [], [], null, 1));
        $this->assertSame(['messages', 'updates'], $processed);
    }

    public function testAddTransformerCallsFinalizeIfMuxAlreadyClosed(): void
    {
        $mux = new Mux();
        $mux->push([], self::makeEvent('messages', [], [], null, 0));
        $mux->close();

        $finalized = 0;
        $mux->addTransformer(new CallbackTransformer(finalize: static function () use (&$finalized) {
            ++$finalized;
        }));

        $this->assertSame(1, $finalized);
    }

    public function testAddTransformerCallsFailIfMuxAlreadyFailed(): void
    {
        $mux = new Mux();
        $mux->push([], self::makeEvent('messages', [], [], null, 0));
        $error = new \RuntimeException('run failed');
        $mux->fail($error);

        $failedWith = null;
        $mux->addTransformer(new CallbackTransformer(fail: static function (mixed $err) use (&$failedWith): void {
            $failedWith = $err;
        }));

        $this->assertSame($error, $failedWith);
    }

    public function testAddTransformerReplaysOnlyEventsBufferedBeforeRegistration(): void
    {
        $mux = new Mux();
        $mux->push([], self::makeEvent('messages', [], [], null, 0));
        $mux->push([], self::makeEvent('values', [], [], null, 1));

        $replayedSeqs = [];
        $lateTransformer = new CallbackTransformer(process: static function (array $e) use (&$replayedSeqs): bool {
            $replayedSeqs[] = $e['seq'];

            return true;
        });

        // Suppress one event so the replay must come from the log (kept events), not the raw push history.
        $mux->addTransformer(new CallbackTransformer(process: static fn (array $e): bool => $e['method'] !== 'debug'));
        $mux->push([], self::makeEvent('debug', [], [], null, 2));
        $mux->push([], self::makeEvent('updates', [], [], null, 3));

        $mux->addTransformer($lateTransformer);

        // The late transformer sees messages, values, updates (debug was suppressed), restamped by the mux.
        $this->assertSame([0, 1, 2], $replayedSeqs);
    }

    public function testMarkInterruptedSetsInterruptedFlagAndStoresPayloads(): void
    {
        $mux = new Mux();
        $this->assertFalse($mux->interrupted());
        $this->assertSame([], $mux->interrupts());

        $payloads = [
            ['interruptId' => 'int-1', 'payload' => ['question' => 'continue?']],
            ['interruptId' => 'int-2', 'payload' => null],
        ];
        $mux->markInterrupted($payloads);

        $this->assertTrue($mux->interrupted());
        $this->assertSame($payloads, $mux->interrupts());
    }

    public function testMarkInterruptedAccumulatesAcrossMultipleCalls(): void
    {
        $mux = new Mux();
        $mux->markInterrupted([['interruptId' => 'a', 'payload' => 1]]);
        $mux->markInterrupted([['interruptId' => 'b', 'payload' => 2]]);

        $this->assertCount(2, $mux->interrupts());
        $this->assertSame('a', $mux->interrupts()[0]['interruptId']);
        $this->assertSame('b', $mux->interrupts()[1]['interruptId']);
    }

    // ---- pump --------------------------------------------------------------------------------

    public function testPumpConvertsChunksAndPushesThemClosesOnEnd(): void
    {
        $mux = new Mux();

        Mux::pump(self::makeSource([
            [[], 'messages', ['text' => 'hello']],
            [[], 'updates', ['node' => 'a']],
        ]), $mux);

        $this->assertTrue($mux->events->done());
        $events = self::collect($mux->events->iterate());
        $this->assertCount(2, $events);
        $this->assertSame('messages', $events[0]['method']);
        $this->assertSame('updates', $events[1]['method']);
        $this->assertSame(0, $events[0]['seq']);
        $this->assertSame(1, $events[1]['seq']);
    }

    public function testPumpCallsFailOnError(): void
    {
        $mux = new Mux();
        $source = (static function (): \Generator {
            yield [[], 'messages', []];

            throw new \RuntimeException('stream broke');
        })();

        Mux::pump($source, $mux);

        $this->assertTrue($mux->events->done());
        $iter = $mux->events->iterate();
        $this->assertSame('messages', $iter->current()['method']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stream broke');
        $iter->next();
    }

    public function testPumpSkipsNullEventsFromUnrecognisedModes(): void
    {
        $mux = new Mux();

        Mux::pump(self::makeSource([
            [[], 'unknown_mode', []],
            [[], 'messages', ['text' => 'hi']],
        ]), $mux);

        $events = self::collect($mux->events->iterate());
        $this->assertCount(1, $events);
        $this->assertSame('messages', $events[0]['method']);
    }

    // ---- beyond the upstream cases -----------------------------------------------------------

    public function testPumpMarksInterruptsCarriedByAValuesChunk(): void
    {
        $mux = new Mux();

        Mux::pump(self::makeSource([
            [[], 'values', [Constants::INTERRUPT => [['id' => 'i-1', 'value' => ['q' => 'ok?']], ['value' => 7]]]],
        ]), $mux);

        $this->assertTrue($mux->interrupted());
        $this->assertSame([
            ['interruptId' => 'i-1', 'payload' => ['q' => 'ok?']],
            ['interruptId' => '', 'payload' => 7],
        ], $mux->interrupts());
    }

    public function testPumpTakesTheEnginesTwoElementChunkAsTheRootNamespace(): void
    {
        $mux = new Mux();

        Mux::pump([['values', ['n' => 1]]], $mux);

        $events = self::collect($mux->events->iterate());
        $this->assertSame([], $events[0]['params']['namespace']);
        $this->assertSame(['n' => 1], $events[0]['params']['data']);
    }

    public function testPumpStepsStopsWithAFailureOnceTheAbortCallbackReportsAReason(): void
    {
        $mux = new Mux();
        $abort = false;

        $steps = Mux::pumpSteps(
            self::makeSource([[[], 'values', ['n' => 1]], [[], 'values', ['n' => 2]]]),
            $mux,
            static function () use (&$abort): mixed {
                return $abort ? 'stop now' : null;
            },
        );
        $steps->current();
        $abort = true;
        $steps->next();

        $this->assertTrue($mux->isClosed());
        $iter = $mux->events->iterate();
        $this->assertSame(['n' => 1], $iter->current()['params']['data'], 'the aborted chunk is never pushed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stop now');
        $iter->next();
    }

    public function testThePullDriverAdvancesTheRunWhenAChannelRunsDry(): void
    {
        $mux = new Mux();
        $steps = Mux::pumpSteps(self::makeSource([[[], 'values', ['n' => 1]], [[], 'values', ['n' => 2]]]), $mux);
        $started = false;
        $mux->setPuller(static function () use ($steps, &$started): bool {
            if (!$started) {
                $started = true;
                $steps->current();

                return true;
            }
            if (!$steps->valid()) {
                return false;
            }
            $steps->next();

            return true;
        });

        $values = array_map(static fn (array $e): array => $e['params']['data'], self::collect($mux->events));

        $this->assertSame([['n' => 1], ['n' => 2]], $values);
        $this->assertTrue($mux->isClosed());
    }

    public function testPullIsRefusedWhileAPullIsAlreadyRunning(): void
    {
        $mux = new Mux();
        $inner = null;
        $mux->setPuller(function () use ($mux, &$inner): bool {
            $inner = $mux->pull();

            return false;
        });

        $mux->pull();

        $this->assertFalse($inner);
    }

    public function testAnEmitterHandedToOnRegisterInjectsEventsThatThePipelineRestamps(): void
    {
        $mux = new Mux();
        $mux->addTransformer(new CallbackTransformer(onRegister: static function ($emitter): void {
            $emitter->push(['sub'], self::makeEvent('lifecycle', ['sub'], ['event' => 'started'], null, 999));
        }));

        $events = self::collect($mux->events->iterate());
        $this->assertCount(1, $events);
        $this->assertSame(0, $events[0]['seq']);
        $this->assertSame(['sub'], $events[0]['params']['namespace']);
    }
}
