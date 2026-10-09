<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp\Client;

use LangGraph\Mcp\Client\Transport\SseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SseParser::class)]
final class SseParserTest extends TestCase
{
    public function testAnEventIsDispatchedOnABlankLine(): void
    {
        $events = (new SseParser())->feed("id: 5\nevent: message\ndata: {\"a\":1}\n\n");

        self::assertSame([['event' => 'message', 'data' => '{"a":1}', 'id' => '5']], $events);
    }

    public function testMultipleDataLinesJoinWithNewlines(): void
    {
        $events = (new SseParser())->feed("data: one\ndata: two\n\n");

        self::assertSame('one' . "\n" . 'two', $events[0]['data']);
    }

    public function testCrLfAndLoneCrLineEndings(): void
    {
        $parser = new SseParser();

        self::assertSame('a', $parser->feed("data: a\r\n\r\n")[0]['data']);
        self::assertSame('b', $parser->feed("data: b\r\rdata: c\n")[0]['data']);
    }

    public function testChunksMayCutAnywhere(): void
    {
        $parser = new SseParser();

        self::assertSame([], $parser->feed("da"));
        self::assertSame([], $parser->feed("ta: hel"));
        self::assertSame([], $parser->feed("lo\n"));
        self::assertSame('hello', $parser->feed("\n")[0]['data']);
    }

    public function testACrAtTheEndOfAChunkWaitsForTheLfOfACrLf(): void
    {
        $parser = new SseParser();

        self::assertSame([], $parser->feed("data: x\r"));
        self::assertSame('x', $parser->feed("\n\r\n")[0]['data']);
    }

    public function testCommentsAndUnknownFieldsAreIgnored(): void
    {
        $events = (new SseParser())->feed(": keep-alive\nbogus: 1\ndata: ok\n\n");

        self::assertCount(1, $events);
        self::assertSame('ok', $events[0]['data']);
    }

    public function testAnEventWithoutDataIsNotDispatchedButItsIdIsRemembered(): void
    {
        $parser = new SseParser();

        self::assertSame([], $parser->feed("id: prime-1\n\n"));
        self::assertSame('prime-1', $parser->lastEventId());
    }

    public function testRetryIsTrackedAndTheDefaultEventTypeIsMessage(): void
    {
        $parser = new SseParser();
        $events = $parser->feed("retry: 2500\ndata: x\n\n");

        self::assertSame(2500, $parser->retryMs());
        self::assertSame('message', $events[0]['event']);
        self::assertNull($events[0]['id']);
    }
}
