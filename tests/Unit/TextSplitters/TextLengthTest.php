<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\TextSplitters;

use LangChain\TextSplitters\TextLength;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * UTF-16 code-unit counting, including damaged input.
 *
 * An astral character is 2 UTF-16 code units but 1 in `mb_strlen`, so each is an
 * extra unit — which is why a chunk measured in UTF-8 units under-counts an
 * emoji by one, and why the splitter measures this way at all.
 *
 * The damaged case matters because the `/u` pattern makes `preg_match_all()`
 * return `false`, not `0`, on invalid UTF-8 — and `int + false` coerces to
 * `int + 0`. The count therefore degraded to the plain `mb_strlen` figure,
 * which is the right answer reached by accident, through loose coercion, with
 * nothing recording it.
 */
#[CoversClass(TextLength::class)]
final class TextLengthTest extends TestCase
{
    public function testAsciiCountsOneUnitPerByte(): void
    {
        self::assertSame(5, TextLength::utf16CodeUnits('hello'));
    }

    public function testAnAstralCharacterCostsTwoUnits(): void
    {
        // An earlier version of this file also asserted 3 here, expecting the
        // raw mb_strlen figure — but that is precisely what this method exists
        // NOT to return, so the assertion contradicted the next line.
        self::assertSame(
            4,
            TextLength::utf16CodeUnits('a😀b'),
            'but the emoji is a surrogate PAIR, so UTF-16 needs 4 units',
        );
    }

    public function testInvalidUtf8FallsBackToTheCodePointCount(): void
    {
        // Not a throw and not a wrong number: astral characters cannot be
        // counted in text whose encoding is broken, so the code-point figure is
        // the honest answer.
        self::assertSame(4, TextLength::utf16CodeUnits("a\xFF\xFEb"));
    }

    public function testAnEmptyStringIsZero(): void
    {
        self::assertSame(0, TextLength::utf16CodeUnits(''));
    }

    /**
     * Mixed valid text with one damaged byte must not go negative or coerce the
     * false into something absurd — the fallback is arithmetic, not a value.
     */
    public function testTheFallbackStaysAnInt(): void
    {
        $result = TextLength::utf16CodeUnits("\xFF");

        self::assertIsInt($result);
        self::assertGreaterThanOrEqual(0, $result);
    }
}
