<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\PartialJsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * JSON writes every astral character as a UTF-16 surrogate PAIR.
 *
 * `parseUnicodeEscape()` called `mb_chr((int) hexdec($hex), 'UTF-8')` for the
 * four-digit case. mbstring is stricter than JavaScript here and correct: a
 * surrogate is not a Unicode scalar value, so `mb_chr(0xD83D, 'UTF-8')` returns
 * `false`, and the method is typed `string` — so any emoji in a streamed JSON
 * response died with
 *
 *     TypeError: parseUnicodeEscape(): Return value must be of type string,
 *     false returned
 *
 * Upstream never hits this because `String.fromCharCode` (json.ts:86) returns
 * the lone surrogate and JS strings hold surrogate code units natively; the two
 * halves together render as the character. The port reassembles the pair into
 * the scalar it stands for, which mbstring can encode.
 *
 * The assert on BYTES rather than on a rendered glyph: a test that compares
 * printed output cannot tell U+1F600 from U+FFFD on a terminal that swallows
 * it, and that distinction is the entire fix.
 */
#[CoversClass(PartialJsonParser::class)]
final class SurrogatePairEscapesTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function astralCharacters(): array
    {
        return [
            // GRINNING FACE, the common case and the one that used to fatal.
            'emoji' => ['"\uD83D\uDE00"', '😀'],
            // A CJK Extension B ideograph: also astral, far less obvious.
            'CJK extension B' => ['"\uD842\uDFB7"', '𠮷'],
            // Two astral characters back to back.
            'two pairs' => ['"\uD83D\uDE00\uD83D\uDE01"', '😀😁'],
            // An astral character among plain ASCII.
            'inline' => ['"a😀b"', 'a😀b'],
        ];
    }

    #[DataProvider('astralCharacters')]
    public function testASurrogatePairBecomesTheCharacterItStandsFor(string $json, string $expected): void
    {
        $parsed = (new PartialJsonParser($json))->parse();

        self::assertSame($expected, $parsed);
        self::assertSame(
            bin2hex($expected),
            bin2hex((string) $parsed),
            'the bytes must be the real character, not U+FFFD',
        );
    }

    public function testASingleBmpEscapeIsUnaffected(): void
    {
        // The pre-existing behaviour must not move: one escape, one BMP char.
        self::assertSame('é', (new PartialJsonParser('"\u00e9"'))->parse());
    }

    /**
     * A stream cut between the halves is the NORMAL case, not an error, so an
     * unpaired surrogate must degrade rather than fatal.
     */
    public function testAnUnpairedSurrogateDegradesInsteadOfFataling(): void
    {
        // A high half with no partner, at a CLOSING QUOTE. This used to assert
        // '' — "nothing can be emitted yet" — and that rationale is only sound
        // for a TRUNCATED stream, where the partner may still arrive in the next
        // chunk. `'"\uD83D"'` is a CLOSED string: the quote has been seen, so no
        // partner can ever arrive, and emitting nothing made the parser lossy and
        // quiet (`"x\uD83Dy"` became `"xy"`, three characters as two). U+FFFD is
        // the faithful stand-in — mbstring has no value for a surrogate, since a
        // surrogate is not a Unicode scalar — and it is this method's own
        // documented policy for an unpaired surrogate. The genuinely-truncated
        // case is asserted separately below and still yields ''.
        self::assertSame("\u{FFFD}", (new PartialJsonParser('"\uD83D"'))->parse());

        // Truncated with no closing quote: U+FFFD as well, and this surprised me
        // enough to check before asserting it. A parser constructed with a whole
        // buffer has NO later chunk that could supply the partner — the class
        // takes the complete string up front — so the old '' protected nothing
        // here either. It was lossy in both branches, and only one of them was
        // reachable in practice.
        self::assertSame("\u{FFFD}", (new PartialJsonParser('"\uD83D'))->parse());

        // Low half with no high: U+FFFD, not a TypeError.
        self::assertSame("\u{FFFD}", (new PartialJsonParser('"\uDE00"'))->parse());

        // A high half followed by an ordinary BMP escape: the orphaned half
        // becomes U+FFFD and the real character still comes through.
        self::assertSame("\u{FFFD}A", (new PartialJsonParser('"\uD83D\u0041"'))->parse());
    }

    /**
     * A stream truncated MID-escape keeps working, which the existing
     * partial-truncation tests cover; this pins that the surrogate work did not
     * disturb the `pos` advance.
     */
    public function testAnEscapeTruncatedMidSequenceStillEmitsTheRawText(): void
    {
        // No backslash, so nothing here is an escape at all and the whole run is
        // literal. (Asserted against the measured value after guessing 'u12'.)
        self::assertSame('au12', (new PartialJsonParser('"au12'))->parse());
    }
}
