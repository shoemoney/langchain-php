<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stream;

use LangChain\Tests\Unit\Stream\Support\StreamHelpers;
use LangGraph\Stream\StreamChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langgraph-core/src/stream/stream-channel.test.ts`.
 *
 * Cursors are generators and a run has no event loop, so "delivers items pushed after iteration starts" is
 * recast with a driver (the mux's pull) that pushes between reads.
 */
#[CoversClass(StreamChannel::class)]
final class StreamChannelTest extends TestCase
{
    use StreamHelpers;

    public function testCreatesLocalOnlyChannelsWithoutAProtocolName(): void
    {
        $this->assertNull(StreamChannel::local()->channelName);
    }

    public function testCreatesRemoteChannelsWithAProtocolName(): void
    {
        $this->assertSame('timeline', StreamChannel::remote('timeline')->channelName);
    }

    public function testPreservesConstructorCompatibilityForLocalAndRemoteChannels(): void
    {
        $this->assertNull((new StreamChannel())->channelName);
        $this->assertSame('timeline', (new StreamChannel('timeline'))->channelName);
    }

    public function testIteratesPushedValuesIndependentlyForEachConsumer(): void
    {
        $channel = StreamChannel::local();
        $channel->push(1);
        $channel->push(2);
        $channel->_close();

        $this->assertSame([1, 2], self::collect($channel));
        $this->assertSame([1, 2], self::collect($channel));
    }

    public function testPropagatesFailureToIteratorsAfterBufferedValues(): void
    {
        $channel = StreamChannel::local();
        $channel->push(1);
        $channel->_fail(new \RuntimeException('boom'));

        $iter = $channel->getIterator();
        $this->assertSame(1, $iter->current());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $iter->next();
    }

    public function testSupportsCursorsThatStartAtASpecificPosition(): void
    {
        $channel = StreamChannel::local();
        $channel->push(10);
        $channel->push(20);
        $channel->push(30);
        $channel->close();

        $this->assertSame([30], self::collect($channel->iterate(2)));
        $this->assertSame([], self::collect($channel->iterate(3)));
    }

    public function testToAsyncIterableReturnsIndependentIterables(): void
    {
        $channel = StreamChannel::local();
        $channel->push('a');
        $channel->push('b');
        $channel->close();

        $iterable = $channel->toAsyncIterable();

        $this->assertSame(['a', 'b'], self::collect($iterable));
        $this->assertSame(['a', 'b'], self::collect($iterable));
    }

    public function testToEventStreamEmitsPushedValuesAsServerSentEvents(): void
    {
        $channel = StreamChannel::remote('a2a');
        $channel->push(['msg' => 'hello']);
        $channel->push(['msg' => 'world']);
        $channel->close();

        $this->assertSame(
            'event: a2a' . "\n" . 'data: {"msg":"hello"}' . "\n\n" . 'event: a2a' . "\n" . 'data: {"msg":"world"}' . "\n\n",
            implode('', self::collect($channel->toEventStream())),
        );
    }

    public function testToEventStreamSupportsLocalChannelsWithAnEventOverride(): void
    {
        $channel = StreamChannel::local();
        $channel->push('hello');
        $channel->close();

        $this->assertSame(
            'event: custom' . "\n" . 'data: "hello"' . "\n\n",
            implode('', self::collect($channel->toEventStream(['event' => 'custom']))),
        );
    }

    public function testToEventStreamSupportsStartingFromACustomCursor(): void
    {
        $channel = StreamChannel::remote('numbers');
        $channel->push(1);
        $channel->push(2);
        $channel->push(3);
        $channel->close();

        $this->assertSame(
            "event: numbers\ndata: 2\n\nevent: numbers\ndata: 3\n\n",
            implode('', self::collect($channel->toEventStream(['startAt' => 1]))),
        );
    }

    public function testToEventStreamSupportsCustomSerialization(): void
    {
        $channel = StreamChannel::remote('messages');
        $channel->push(['text' => 'hello']);
        $channel->close();

        $this->assertSame(
            "event: messages\ndata: HELLO\n\n",
            implode('', self::collect($channel->toEventStream(['serialize' => static fn (array $item): string => strtoupper($item['text'])]))),
        );
    }

    public function testDeliversItemsPushedAfterIterationStarts(): void
    {
        $channel = StreamChannel::local();
        $pushes = [[42], [43]];
        $channel->setDriver(static function () use ($channel, &$pushes): bool {
            if ($pushes === []) {
                $channel->close();

                return true;
            }
            foreach (array_shift($pushes) as $item) {
                $channel->push($item);
            }

            return true;
        });

        $iter = $channel->iterate();
        $this->assertSame(42, $iter->current());
        $iter->next();
        $this->assertSame(43, $iter->current());
        $iter->next();
        $this->assertFalse($iter->valid());
    }

    public function testExposesBufferedSizeDoneStateAndIndexedAccess(): void
    {
        $channel = StreamChannel::local();
        $this->assertSame(0, $channel->size());
        $this->assertFalse($channel->done());

        $channel->push('a');
        $channel->push('b');

        $this->assertSame(2, $channel->size());
        $this->assertSame('a', $channel->get(0));
        $this->assertSame('b', $channel->get(1));

        $channel->close();
        $this->assertTrue($channel->done());
    }

    public function testThrowsForOutOfBoundsIndexedAccess(): void
    {
        $channel = StreamChannel::local();
        $channel->push(1);

        foreach ([-1, 1] as $index) {
            try {
                $channel->get($index);
                $this->fail('expected an out-of-range failure for index ' . $index);
            } catch (\OutOfRangeException $e) {
                $this->assertStringContainsString('out of bounds', $e->getMessage());
            }
        }

        $this->expectException(\OutOfRangeException::class);
        StreamChannel::local()->get(0);
    }

    // ---- StreamChannel.isInstance ------------------------------------------------------------

    public function testIsInstanceRecognisesRealInstances(): void
    {
        $channel = new StreamChannel('timeline');

        $this->assertTrue(StreamChannel::isInstance($channel));
    }

    public function testIsInstanceRejectsPlainObjectsAndPrimitives(): void
    {
        $this->assertFalse(StreamChannel::isInstance(null));
        $this->assertFalse(StreamChannel::isInstance('channel'));
        $this->assertFalse(StreamChannel::isInstance([]));
        $this->assertFalse(StreamChannel::isInstance(new \stdClass()));
        $this->assertFalse(StreamChannel::isInstance(['channelName' => 'x']));
    }

    public function testIsInstanceAcceptsBrandedLookAlikesFromADifferentPackageCopy(): void
    {
        // A channel built against an independent copy of this package: the class identity differs, so
        // instanceof fails, but the shared brand still identifies it.
        $foreign = new class('timeline') {
            public function __construct(public readonly ?string $channelName = null)
            {
            }

            public function streamChannelBrand(): string
            {
                return StreamChannel::BRAND;
            }
        };

        $this->assertNotInstanceOf(StreamChannel::class, $foreign);
        $this->assertTrue(StreamChannel::isInstance($foreign));
    }

    public function testIsInstanceRejectsObjectsMissingTheBrand(): void
    {
        $impostor = new class {
            public string $channelName = 'timeline';

            public function push(): void
            {
            }

            public function streamChannelBrand(): string
            {
                return 'something-else';
            }
        };

        $this->assertFalse(StreamChannel::isInstance($impostor));
    }

    // ---- beyond the upstream cases -----------------------------------------------------------

    public function testACursorOnAnUndrivenOpenChannelEndsWhereTheBufferEnds(): void
    {
        $channel = StreamChannel::local();
        $channel->push(1);

        $this->assertSame([1], self::collect($channel));
    }

    public function testASseItemWithNewlinesBecomesOneDataLinePerLine(): void
    {
        $channel = StreamChannel::local();
        $channel->push("a\r\nb\nc");
        $channel->close();

        $this->assertSame("data: a\ndata: b\ndata: c\n\n", implode('', self::collect($channel->toEventStream(['serialize' => static fn (string $s): string => $s]))));
    }
}
