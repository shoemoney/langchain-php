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
        yield 'two digits' => ['"a\u12', 'au12'];
        yield 'one digit' => ['"a\u1', 'au1'];
        yield 'no digits' => ['"a\u', 'au'];
        yield 'at the very start' => ['"\uD', 'uD'];
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
