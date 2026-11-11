<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\PartialJsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A stream cut inside an escape pair must not lose the half it saw.
 *
 * `parseUnicodeEscape()` stores a high surrogate and returns '' while it waits
 * for its low partner. If the stream ends there, the state was simply abandoned
 * and the character vanished. Measured before this fix: `"a\uD83D` recovered as
 * bytes `61` — the letter `a` and nothing else, the high surrogate dropped with
 * no error at all.
 *
 * That is worse than seeing a replacement character. A consumer diffing partial
 * results sees the string quietly LOSE a character, which it cannot distinguish
 * from a bug in its own reducer. U+FFFD says "a character began here and I
 * could not read it" — which is true, and is the same choice already made for an
 * unpaired low surrogate. Short-hex escapes instead recover as literal text.
 *
 * The earlier fix for truncated escapes covered the SHORT-HEX path and missed
 * this one. Same instruction both times: test the degenerate end-of-input case,
 * because that is where the state lives.
 *
 * Asserted on BYTES — a terminal and any naive string assertion cannot tell
 * `a` from `a\uFFFD`.
 */
#[CoversClass(PartialJsonParser::class)]
final class SurrogateCutAtEofTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function cutsInsideAPair(): array
    {
        return [
            // U+FFFD is efbfbd.
            'after a letter' => ['"a\uD83D', '61efbfbd'],
            'at the very start' => ['"\uD83D', 'efbfbd'],
            // The replacement occupies the original surrogate's position,
            // before any following short-hex recovery or plain characters.
            'then a partial hex digit' => ['"a\uD83D\uD', '61efbfbd7544'],
            'then a partial low escape' => ['"\uD83D\uDE0', 'efbfbd75444530'],
            'then plain text' => ['"x\ud800y"', '78efbfbd79'],
            'then an escaped newline' => ['"\uD83D\n', 'efbfbd0a'],
            'then a lone backslash' => ['"\uD83D\\', 'efbfbd5c'],
            'then another high surrogate' => ['"\uD800\uD83D', 'efbfbdefbfbd'],
            'then a complete pair' => ['"\uD800\uD83D\uDE00"', 'efbfbdf09f9880'],
            'intervening text prevents pairing' => ['"\uD83Dx\uDE00"', 'efbfbd78efbfbd'],
        ];
    }

    #[DataProvider('cutsInsideAPair')]
    public function testAnUnpairedHighSurrogateIsReplacedNotDropped(string $fragment, string $expectedHex): void
    {
        self::assertSame(
            $expectedHex,
            bin2hex((string) (new PartialJsonParser($fragment))->parse()),
            'a stream cut inside an escape pair must occupy the position with U+FFFD, not vanish',
        );
    }

    /** A COMPLETE pair must still resolve to the real character. */
    public function testACompletePairIsUnaffected(): void
    {
        self::assertSame(
            '😀',
            (new PartialJsonParser('"\uD83D\uDE00"'))->parse(),
            'f09f9880 — the fix must not break the case that already worked',
        );
    }

    public function testAnUnpairedLowSurrogateStillReplaces(): void
    {
        self::assertSame("\u{FFFD}", (new PartialJsonParser('"\uDE00"'))->parse());
    }

    /** Text with no escapes at all is untouched. */
    public function testPlainTextIsUntouched(): void
    {
        self::assertSame('ok', (new PartialJsonParser('"ok"'))->parse());
    }
}
