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
}
