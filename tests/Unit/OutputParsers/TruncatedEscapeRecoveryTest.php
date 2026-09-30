<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\PartialJsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A stream cut mid-escape must recover the text the caller wrote.
 *
 * `parseString` already had a rule for this — a cut escape re-emits its
 * backslash (`if ($escaped) { $result .= '\\'; }`) — and the short-hex branch of
 * `parseUnicodeEscape` broke it. `match` had already consumed the `\`, and the
 * branch returned `'u' . $hex`, so it vanished.
 *
 * Measured before the fix, by bytes: `"a\u1` recovered as `61 75 31` — 'a', 'u',
 * '1', no `5c`.
 *
 * Why it matters beyond tidiness: `JsonOutputParser`'s `diff: true` path
 * compares successive partial results and emits JSON-Patch operations from the
 * difference. Losing the backslash makes the string flip from `''` to `au1` to
 * `a\u1234` to the real character, so every chunk of every emoji in a stream
 * produces a spurious `replace`. Silent, and it scales with the payload.
 *
 * Assertions are on BYTES, not on rendered text: a terminal swallows the
 * distinction between `au1` and `a\u1`, and so would a naive string assertion.
 */
#[CoversClass(PartialJsonParser::class)]
final class TruncatedEscapeRecoveryTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> fragment => expected bytes */
    public static function truncatedEscapes(): array
    {
        return [
            'one hex digit' => ['"a\\u1', '61 5c 75 31'],
            'two hex digits' => ['"a\\u12', '61 5c 75 31 32'],
            'three hex digits' => ['"a\\u123', '61 5c 75 31 32 33'],
        ];
    }

    #[DataProvider('truncatedEscapes')]
    public function testACutEscapeKeepsItsBackslash(string $fragment, string $expectedHex): void
    {
        $recovered = (new PartialJsonParser($fragment))->parse();

        self::assertSame(
            str_replace(' ', '', $expectedHex),
            bin2hex((string) $recovered),
            'a truncated escape must keep the backslash the caller wrote',
        );
    }

    /**
     * The no-escape case must be untouched — the fix adds a backslash only when
     * one was present.
     */
    public function testTextWithNoEscapeIsUnaffected(): void
    {
        $recovered = (new PartialJsonParser('"au12'))->parse();

        self::assertSame('au12', $recovered);
        self::assertSame('61753132', bin2hex($recovered), 'no 5c here, and none should be added');
    }

    /** A complete escape still resolves to the character, unchanged. */
    public function testACompleteEscapeIsUnaffected(): void
    {
        self::assertSame(
            'aሴ',
            (new PartialJsonParser('"a\u1234"'))->parse(),
            'the four-digit path is untouched',
        );
    }
}
