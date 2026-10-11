<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\JsonUtils;
use LangChain\OutputParsers\PartialJsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Substantial truncated buffers exercise recovery without load-sensitive time limits. */
#[CoversClass(PartialJsonParser::class)]
#[CoversClass(JsonUtils::class)]
final class PartialJsonLargeBufferTest extends TestCase
{
    public function testLargeTruncatedArrayRetainsEveryElement(): void
    {
        $input = '[' . str_repeat('12345,', 36666);
        self::assertSame(219997, \strlen($input));
        self::assertSame(array_fill(0, 36666, 12345), JsonUtils::strictParsePartialJson($input));
    }

    public function testLargeUnicodeStringPreservesSurrogatePairsAndPartialEscapeSuffix(): void
    {
        $input = '"' . str_repeat('é😀\uD83D\uDE00', 12000) . '\u12';
        self::assertSame(str_repeat('é😀😀', 12000) . 'u12', JsonUtils::strictParsePartialJson($input));
    }

    public function testLargeMalformedByteStringRetainsFollowingCharacters(): void
    {
        $previous = mb_substitute_character();
        try {
            mb_substitute_character(63);
            self::assertSame(str_repeat('é?b😀', 12000), JsonUtils::strictParsePartialJson('"' . str_repeat("é\xE2\x80b😀", 12000)));
        } finally {
            mb_substitute_character($previous);
        }
    }
}
