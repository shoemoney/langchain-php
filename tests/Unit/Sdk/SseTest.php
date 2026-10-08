<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangGraph\Sdk\Utils\BytesLineDecoder;
use LangGraph\Sdk\Utils\SseDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/sse.test.ts`. The JS tests pipe a Node `Readable` through a `TransformStream`;
 * PHP feeds the same chunks to the decoder directly.
 */
#[CoversClass(BytesLineDecoder::class)]
#[CoversClass(SseDecoder::class)]
final class SseTest extends TestCase
{
    /**
     * @param list<string> $chunks
     *
     * @return list<string>
     */
    private function lines(array $chunks): array
    {
        return iterator_to_array(BytesLineDecoder::decode($chunks), false);
    }

    /**
     * @param list<string> $chunks
     *
     * @return list<array<string, mixed>>
     */
    private function events(array $chunks): array
    {
        return iterator_to_array(SseDecoder::decode($chunks), false);
    }

    public function testBytesLineDecoderHandlesASingleLineWithNewline(): void
    {
        $this->assertSame(['hello'], $this->lines(["hello\n"]));
    }

    public function testBytesLineDecoderHandlesMultipleLines(): void
    {
        $this->assertSame(['line1', 'line2', 'line3'], $this->lines(["line1\nline2\nline3\n"]));
    }

    public function testBytesLineDecoderHandlesSplitChunks(): void
    {
        $this->assertSame(['line1', 'line2'], $this->lines(['li', "ne1\nli", "ne2\n"]));
    }

    public function testBytesLineDecoderHandlesCrLfLineEndings(): void
    {
        $this->assertSame(['line1', 'line2'], $this->lines(["line1\r\nline2\r\n"]));
    }

    public function testBytesLineDecoderHandlesASplitCrLf(): void
    {
        $this->assertSame(['line1', 'line2'], $this->lines(["line1\r", "\nline2\r\n"]));
    }

    public function testBytesLineDecoderFlushesAStaleUnterminatedLine(): void
    {
        $this->assertSame(['hello'], $this->lines(['hello']));
    }

    public function testBytesLineDecoderTreatsBareCrAndBlankLinesAsLineBreaks(): void
    {
        $this->assertSame(['a', 'b', '', 'c'], $this->lines(["a\rb\r\n\r\nc\n"]));
    }

    public function testSseDecoderDecodesASimpleEvent(): void
    {
        $this->assertSame(
            [['id' => null, 'event' => 'test', 'data' => ['message' => 'hello']]],
            $this->events(["event: test\n", "data: {\"message\": \"hello\"}\n", "\n"]),
        );
    }

    public function testSseDecoderIgnoresComments(): void
    {
        $this->assertSame(
            [['id' => null, 'event' => 'test', 'data' => ['message' => 'hello']]],
            $this->events([": this is a comment\n", "event: test\n", "data: {\"message\": \"hello\"}\n"]),
        );
    }

    public function testSseDecoderHandlesMultipleEvents(): void
    {
        $events = $this->events([
            "event: test1\n", "data: {\"message\": \"hello\"}\n", "\n",
            "event: test2\n", "data: {\"message\": \"world\"}\n", "\n",
        ]);

        $this->assertCount(2, $events);
        $this->assertSame(['id' => null, 'event' => 'test1', 'data' => ['message' => 'hello']], $events[0]);
        $this->assertSame(['id' => null, 'event' => 'test2', 'data' => ['message' => 'world']], $events[1]);
    }

    public function testSseDecoderEmitsAnEndEventWithoutData(): void
    {
        $this->assertSame([['id' => null, 'event' => 'test', 'data' => null]], $this->events(["event: test\n"]));
    }

    public function testSseDecoderEmitsAnEndEventWithoutATrailingNewline(): void
    {
        $this->assertSame([['id' => null, 'event' => 'end', 'data' => null]], $this->events(['event: end']));
    }

    public function testSseDecoderKeepsTheLastEventIdAcrossEventsAsTheSpecDemands(): void
    {
        $events = $this->events([
            "id: 7\nevent: a\ndata: 1\n\n",
            "event: b\ndata: 2\n\n",
        ]);

        $this->assertSame('7', $events[0]['id']);
        $this->assertSame('7', $events[1]['id'], 'the last event id persists');
    }

    public function testSseDecoderIgnoresAnIdContainingNullAndAMalformedRetry(): void
    {
        $events = $this->events(["id: a\0b\nretry: soon\nevent: x\ndata: null\n\n"]);

        $this->assertSame([['id' => null, 'event' => 'x', 'data' => null]], $events);
    }

    public function testSseDecoderRemovesOnlyOneLeadingSpaceFromAValue(): void
    {
        $events = $this->events(["event:  padded\ndata:\"v\"\n\n"]);

        $this->assertSame(' padded', $events[0]['event']);
        $this->assertSame('v', $events[0]['data']);
    }

    public function testSseDecoderSkipsABlankLineWithNothingPending(): void
    {
        $this->assertSame([], $this->events(["\n\n"]));
    }

    public function testSseDecoderConcatenatesDataLinesWithoutAJoiner(): void
    {
        // Unlike LangChain\Utils\Http\SseParser, which joins with "\n" per the spec: the protocol
        // wraps one JSON document across data lines and the pieces must be glued back as sent.
        $events = $this->events(["event: v\ndata: {\"a\":\ndata: 1}\n\n"]);

        $this->assertSame(['a' => 1], $events[0]['data']);
    }

    public function testSseDecoderRejectsInvalidJsonData(): void
    {
        $this->expectException(\JsonException::class);
        $this->events(["event: bad\ndata: {nope\n\n"]);
    }

    public function testEndToEndFromRawNetworkChunksSplitMidEvent(): void
    {
        $wire = "event: metadata\r\ndata: {\"run_id\":\"r1\"}\r\n\r\nid: 3\r\nevent: values\r\ndata: {\"n\":1}\r\n\r\n";
        $events = $this->events(str_split($wire, 5));

        $this->assertSame(['metadata', 'values'], array_column($events, 'event'));
        $this->assertSame(['run_id' => 'r1'], $events[0]['data']);
        $this->assertSame('3', $events[1]['id']);
    }
}
