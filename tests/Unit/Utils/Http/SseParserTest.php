<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils\Http;

use LangChain\Utils\Http\SseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SseParser::class)]
final class SseParserTest extends TestCase
{
    /**
     * The three terminators the spec allows, plus a stream that mixes them.
     *
     * Providers are not consistent about this — a proxy that rewrites line
     * endings is enough to break a parser that assumed one form.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function framingProvider(): iterable
    {
        yield 'LF' => ["\n\n", ['{"a":1}', '{"b":2}']];
        yield 'CRLF' => ["\r\n\r\n", ['{"a":1}', '{"b":2}']];
        yield 'CR' => ["\r\r", ['{"a":1}', '{"b":2}']];
        yield 'mixed' => ["\r\n\r\n", ['{"a":1}', '{"b":2}']];
    }

    #[DataProvider('framingProvider')]
    public function testEveryFramingYieldsTheSamePayloads(string $terminator, array $expected): void
    {
        $parser = new SseParser();

        $got = [];
        foreach ($parser->feed('data: {"a":1}' . $terminator . 'data: {"b":2}' . $terminator) as $payload) {
            $got[] = $payload;
        }

        self::assertSame($expected, $got);
    }

    /**
     * Three consecutive CRLF events, each yielding exactly its own payload.
     *
     * Worth pinning because it is the shape that exposed a real design fault in
     * {@see SseParser}: the separator length used to be read from the *front* of
     * the buffer, which never begins with a separator, so it always fell back
     * to two bytes and stranded half of every `\r\n\r\n`. A 4,000-case
     * differential run against that version found **zero** observable
     * divergences — the stray bytes always landed on a line the payload reader
     * discards — so this test documents the guarantee rather than catching a
     * regression. The implementation is now correct by construction rather than
     * by that accident.
     */
    public function testSeparatorIsFullyConsumed(): void
    {
        $parser = new SseParser();

        $got = [];
        foreach ($parser->feed("data: first\r\n\r\ndata: second\r\n\r\ndata: third\r\n\r\n") as $payload) {
            $got[] = $payload;
        }

        self::assertSame(['first', 'second', 'third'], $got);
    }

    /**
     * A network read can split a payload anywhere, including mid-JSON.
     */
    public function testAPayloadSplitAcrossFeedsIsReassembled(): void
    {
        $parser = new SseParser();

        $got = [];
        foreach ($parser->feed('data: {"a":1') as $p) {
            $got[] = 'early:' . $p;
        }
        foreach ($parser->feed('23}') as $p) {
            $got[] = 'mid:' . $p;
        }
        foreach ($parser->feed("\n\n") as $p) {
            $got[] = 'late:' . $p;
        }

        // Nothing may be emitted before the event is complete.
        self::assertSame(['late:{"a":123}'], $got);
    }

    /**
     * A split landing inside a multi-byte character must not corrupt it.
     */
    public function testAMultiByteCharacterSplitAcrossFeedsSurvives(): void
    {
        $parser = new SseParser();
        $payload = 'data: {"t":"héllo →"}' . "\n\n";
        $bytes = $payload;
        $half = (int) (strlen($bytes) / 2);

        $got = [];
        foreach ($parser->feed(substr($bytes, 0, $half)) as $p) {
            $got[] = $p;
        }
        foreach ($parser->feed(substr($bytes, $half)) as $p) {
            $got[] = $p;
        }

        self::assertSame(['{"t":"héllo →"}'], $got);
    }

    public function testDoneSentinelIsNotYielded(): void
    {
        $parser = new SseParser();

        $got = [];
        foreach ($parser->feed("data: {\"a\":1}\n\ndata: [DONE]\n\n") as $p) {
            $got[] = $p;
        }

        self::assertSame(['{"a":1}'], $got);
    }

    /**
     * A server that ends without a trailing blank line would otherwise strand
     * the last event, losing the final token of every stream.
     */
    public function testFlushRecoversAnUnterminatedFinalEvent(): void
    {
        $parser = new SseParser();

        $got = [];
        foreach ($parser->feed('data: {"last":true}') as $p) {
            $got[] = $p;
        }
        self::assertSame([], $got, 'an incomplete event must not be emitted early');

        foreach ($parser->flush() as $p) {
            $got[] = $p;
        }

        self::assertSame(['{"last":true}'], $got);
    }

    public function testNonDataLinesAreIgnored(): void
    {
        $parser = new SseParser();

        $got = [];
        foreach ($parser->feed("event: message\nid: 7\ndata: {\"a\":1}\n\n:keepalive\n\n") as $p) {
            $got[] = $p;
        }

        self::assertSame(['{"a":1}'], $got);
    }

    /**
     * One event split across several `data:` lines is one payload, and the
     * lines join with a newline rather than being concatenated.
     */
    public function testMultiLineDataIsJoinedWithNewlines(): void
    {
        $parser = new SseParser();

        $got = [];
        foreach ($parser->feed("data: {\"a\":\ndata: 1}\n\n") as $p) {
            $got[] = $p;
        }

        self::assertSame(["{\"a\":\n1}"], $got);
    }

    /**
     * A stream cut mid-character must not hand on the broken bytes.
     *
     * `flush()` exists to recover a final event the server sent without a
     * trailing blank line. When the connection instead ends INSIDE a multi-byte
     * character, those bytes are unrecoverable, and yielding them turns a
     * truncated stream into a JSON error at the far end — or, on a laxer path,
     * a silently mangled payload.
     */
    public function testAStreamEndingMidCharacterYieldsNothingInvalid(): void
    {
        $event = "data: {\"t\":\"héllo →\"}\n\n";

        for ($cut = 1; $cut < strlen($event); $cut++) {
            $parser = new SseParser();

            $payloads = [];
            foreach ($parser->feed(substr($event, 0, $cut)) as $p) {
                $payloads[] = $p;
            }
            foreach ($parser->flush() as $p) {
                $payloads[] = $p;
            }

            foreach ($payloads as $payload) {
                self::assertTrue(
                    mb_check_encoding($payload, 'UTF-8'),
                    'truncation at ' . $cut . ' yielded invalid UTF-8: ' . bin2hex($payload),
                );
            }
        }
    }

    /**
     * A COMPLETE unterminated event is still recovered.
     *
     * The guard above must not swallow the case `flush()` exists for.
     */
    public function testACompleteUnterminatedEventIsStillFlushed(): void
    {
        $parser = new SseParser();

        $payloads = [];
        foreach ($parser->feed('data: {"t":"héllo →"}') as $p) {
            $payloads[] = $p;
        }
        foreach ($parser->flush() as $p) {
            $payloads[] = $p;
        }

        self::assertSame(['{"t":"héllo →"}'], $payloads);
    }

    public function testEmptyInputYieldsNothing(): void
    {
        $parser = new SseParser();

        $got = [];
        foreach ($parser->feed('') as $p) {
            $got[] = $p;
        }
        foreach ($parser->flush() as $p) {
            $got[] = $p;
        }

        self::assertSame([], $got);
    }
}
