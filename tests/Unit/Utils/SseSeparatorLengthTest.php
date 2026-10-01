<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Utils\Http\SseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `separatorLengthAt()` returned a hardcoded `2` when no separator matched at the offset.
 *
 * `nextBoundary()` guarantees the offset it returns really does start a separator, so the fallback was
 * unreachable — which is exactly why it survived: no reachable input could distinguish it from a
 * correct value. A silent wrong length on an unreachable path is a latent trap, not a bug, and the
 * fix is to make the no-match case ANNOUNCED so a future separator or a future caller cannot inherit a
 * guess.
 *
 * The `2` was also a lie about the data: the real separators are 4, 2 and 2 bytes, so "2" is right only
 * by coincidence for two of three.
 */
#[CoversClass(SseParser::class)]
final class SseSeparatorLengthTest extends TestCase
{
    private function separatorLengthAt(SseParser $parser, string $buffer, int $offset): ?int
    {
        $property = new \ReflectionProperty(SseParser::class, 'buffer');
        $property->setValue($parser, $buffer);

        $method = new \ReflectionMethod(SseParser::class, 'separatorLengthAt');

        return $method->invoke($parser, $offset);
    }

    /** The reachable cases must be unchanged. */
    public function testItReportsTheRealLengthForEachSeparator(): void
    {
        $parser = new SseParser();

        self::assertSame(4, $this->separatorLengthAt($parser, "data: x\r\n\r\nrest", 7));
        self::assertSame(2, $this->separatorLengthAt($parser, "data: x\n\nrest", 7));
        self::assertSame(2, $this->separatorLengthAt($parser, "data: x\r\rrest", 7));
    }

    /**
     * RED before the fix: the old code returned 2 here, which is indistinguishable from a real
     * two-byte separator and would silently strand bytes if the offset were ever wrong.
     */
    public function testItReportsNoMatchRatherThanGuessingTwo(): void
    {
        self::assertNull(
            $this->separatorLengthAt(new SseParser(), 'data: x', 0),
            'an offset that starts no separator must report no match, not a guessed length'
        );
    }

    /** End-to-end: a 4-byte separator at the head of the buffer must not strand bytes. */
    public function testAStreamUsingFourByteSeparatorsRoundTrips(): void
    {
        $parser = new SseParser();

        $payloads = iterator_to_array($parser->feed("data: one\r\n\r\ndata: two\r\n\r\n"), false);

        self::assertSame(['one', 'two'], $payloads);
    }
}
