<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\PartialJsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fragment cut mid-escape is the normal case for a streaming parser.
 *
 * Upstream accepts ZERO to four hex digits (`json.ts:86`) and advances the
 * position in BOTH branches (`pos += hex.length`, outside the length check).
 * This port advanced only in the four-digit branch, so the partial case
 * re-read the hex digits as ordinary characters: `"a\u12` produced `au1212`
 * where upstream produces `au122`.
 */
#[CoversClass(PartialJsonParser::class)]
final class PartialJsonTruncationTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function truncatedEscapes(): iterable
    {
        // These expectations USED to drop the backslash — 'au12' for `"a\u12`.
        // The parser has always re-emitted it for a cut escape
        // (`if ($escaped) { $result .= '\\'; }`), so the short-hex branch was
        // the inconsistency, and this data provider enshrined it. The
        // consequence was a spurious JSON-Patch `replace` per chunk per emoji
        // for `diff: true` consumers. See TruncatedEscapeRecoveryTest, which
        // pins the bytes.
        yield 'two digits' => ['"a\u12', 'a\\u12'];
        yield 'one digit' => ['"a\u1', 'a\\u1'];
        yield 'no digits' => ['"a\u', 'a\\u'];
        yield 'at the very start' => ['"\uD', '\\uD'];
    }

    #[DataProvider('truncatedEscapes')]
    public function testATruncatedEscapeIsEmittedOnceAndNotReRead(string $fragment, string $expected): void
    {
        self::assertSame($expected, (new PartialJsonParser($fragment))->parse());
    }

    public function testACompleteEscapeStillDecodes(): void
    {
        self::assertSame('aAb', (new PartialJsonParser('"a\u0041b"'))->parse());
    }

    /**
     * Truncation at every offset of one document.
     *
     * A streaming parser is fed a growing prefix; no prefix should crash, and
     * every prefix that closes a string should round-trip its content.
     */
    public function testNoPrefixOfARealDocumentThrows(): void
    {
        $document = '{"name":"a\\u0041b","tags":["x","y"],"n":12.5}';

        for ($i = 1; $i <= strlen($document); $i++) {
            $prefix = substr($document, 0, $i);

            try {
                (new PartialJsonParser($prefix))->parse();
            } catch (\Throwable $e) {
                self::fail('prefix of length ' . $i . ' threw ' . $e::class . ': ' . $e->getMessage());
            }
        }

        self::assertSame(
            ['name' => 'aAb', 'tags' => ['x', 'y'], 'n' => 12.5],
            (new PartialJsonParser($document))->parse(),
        );
    }

    /**
     * A complete JSON document containing a literal must parse.
     *
     * `str_starts_with` is `($haystack, $needle)`, and the three literal branches
     * passed the arguments the other way round, so the parser asked "does the
     * literal `null` start with the rest of the buffer?" — never true. `true`,
     * `false` and `null` therefore all fell through to the error path and
     * COMPLETE, VALID JSON threw `Unexpected character`. A partial parser whose
     * literals do not work is not a partial parser, and the suite missed it
     * because every existing case here is about truncation and surrogate pairs,
     * never about a literal token.
     *
     */
    #[DataProvider('literalDocuments')]
    public function testCompleteJsonContainingALiteralParses(string $json, mixed $expected): void
    {
        $this->assertSame($expected, (new PartialJsonParser($json))->parse());
    }

    public static function literalDocuments(): array
    {
        return [
            'true' => ['{"a": true}', ['a' => true]],
            'false' => ['{"a": false}', ['a' => false]],
            'null' => ['{"a": null}', ['a' => null]],
            'nested literals' => ['{"a": [true, false, null]}', ['a' => [true, false, null]]],
            'literal before a string' => ['{"a": true, "b": "x"}', ['a' => true, 'b' => 'x']],
        ];
    }

    /**
     * An escape for a FALSY character must decode to that character.
     *
     * `mb_chr($code) ?: "\u{FFFD}"` looks like a safe fallback and is not: `"0"`
     * is FALSY in PHP, so `mb_chr(0x30)` returned the one-character string `"0"`,
     * the `?:` read that as failure, and U+FFFD came out instead. Measured:
     *
     *     {"a": "x\u0030y"}  ->  {"a":"x\ufffdy"}
     *
     * The digit zero is the character this defect is named after, and `\u0000`
     * is the same trap one layer down. mb_chr returns `string|false`, so the only
     * correct test is `=== false`.
     *
     * @param string $json
     */
    #[DataProvider('falsyEscapes')]
    public function testAFalsyCharacterEscapesDecodesToThatCharacter(string $json, string $expected): void
    {
        $this->assertSame($expected, (new PartialJsonParser($json))->parse()['a']);
    }

    public static function falsyEscapes(): array
    {
        return [
            'zero' => ['{"a": "x\u0030y"}', 'x0y'],
            'nul' => ['{"a": "nul=\u0000end"}', "nul=\x00end"],
            'ordinary letter unaffected' => ['{"a": "A\u0041B"}', 'AAB'],
        ];
    }

    /**
     * An unpaired high surrogate must become U+FFFD, not vanish.
     *
     * The closing-quote branch returned immediately, discarding any pending high
     * surrogate, so the parser was LOSSY AND QUIET:
     *
     *     {"a":"x\ud800y","b":"z"}  ->  {"a":"xy","b":"z"}
     *
     * three characters became two and nothing reported it. Upstream never hits
     * this because `String.fromCharCode` yields the lone surrogate and a JS string
     * holds code units natively; PHP has no such value, and U+FFFD was already this
     * method's documented policy for an unpaired surrogate. Only this path skipped it.
     *
     * The valid-pair case is in the same provider deliberately: a fix that simply
     * appended U+FFFD everywhere would pass the first case and break every emoji.
     */
    #[DataProvider('surrogateCases')]
    public function testAnUnpairedHighSurrogateBecomesTheReplacementCharacter(string $json, string $expected): void
    {
        $this->assertSame($expected, (new PartialJsonParser($json))->parse()['a']);
    }

    public static function surrogateCases(): array
    {
        return [
            'lone high surrogate mid-string' => ['{"a":"x\ud800y"}', "xy\u{FFFD}"],
            'lone high surrogate alone' => ['{"a":"\ud800"}', "\u{FFFD}"],
            'a valid pair is untouched' => ['{"a":"\ud834\udd1e"}', "\u{1D11E}"],
        ];
    }
}
