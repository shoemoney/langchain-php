<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\JsonUtils;
use LangChain\OutputParsers\PartialJsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** PHP malformed-byte adaptation: retain mbstring's substitution and diagnostic positions. */
#[CoversClass(PartialJsonParser::class)]
#[CoversClass(JsonUtils::class)]
final class PartialJsonInvalidUtf8Test extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function malformedSubparts(): iterable
    {
        yield 'invalid lead' => ["\xFF", '?'];
        yield 'stray continuation' => ["\x80", '?'];
        yield 'overlong encoding' => ["\xC0\x80", '??'];
        yield 'truncated three-byte sequence' => ["\xE2\x80", '?'];
        yield 'encoded surrogate' => ["\xED\xA0\x80", '???'];
        yield 'truncated four-byte sequence' => ["\xF0\x9F", '?'];
        yield 'out of range scalar' => ["\xF4\x90\x80\x80", '????'];
    }

    #[DataProvider('malformedSubparts')]
    public function testMalformedSubpartsKeepTheirRecoveryWhileBoundaryWhitespaceIsTrimmed(string $bytes, string $replacement): void
    {
        $policy = mb_substitute_character();
        try {
            mb_substitute_character(63);
            self::assertSame('a' . $replacement . 'b', JsonUtils::strictParsePartialJson('"a' . $bytes . 'b'));
            self::assertSame('a' . $replacement, JsonUtils::strictParsePartialJson('"a' . $bytes . " \t\n"));
            self::assertSame('a' . $replacement, JsonUtils::strictParsePartialJson("\u{A0}\"a" . $bytes . "\u{3000}"));
            self::assertSame('a' . $replacement . ' ', JsonUtils::strictParsePartialJson('"a' . $bytes . ' "'));
        } finally {
            mb_substitute_character($policy);
        }
    }

    /** @return iterable<string, array{int|string, string}> */
    public static function substitutionPolicies(): iterable
    {
        yield 'question mark' => [63, '?'];
        yield 'replacement scalar' => [0xFFFD, "\u{FFFD}"];
        yield 'no replacement' => ['none', ''];
        yield 'long replacement' => ['long', '?'];
        yield 'numeric replacement' => [48, '0'];
    }

    #[DataProvider('substitutionPolicies')]
    public function testExistingMbstringSubstitutionPolicyIsPreserved(int|string $policy, string $replacement): void
    {
        $previous = mb_substitute_character();
        try {
            mb_substitute_character($policy);
            self::assertSame('a' . $replacement . 'b', JsonUtils::strictParsePartialJson("\"a\xE2\x80b"));
            self::assertSame('a' . $replacement . "b😀", JsonUtils::strictParsePartialJson("\"a\xFFb\\uD83D\\uDE00"));
        } finally {
            mb_substitute_character($previous);
        }
    }

    public function testMalformedBytesStillOccupyOnePositionPerMaximalSubpart(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unexpected character 'x' at position 7");
        JsonUtils::strictParsePartialJson("[\"a\xE2\x80b\",x");
    }

    public function testQuoteSubstitutionRetainsItsExistingEffectOnStringParsing(): void
    {
        $previous = mb_substitute_character();
        try {
            mb_substitute_character(34);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("Unexpected character 'b' at position 3");
            JsonUtils::strictParsePartialJson("\"a\xE2\x80b");
        } finally {
            mb_substitute_character($previous);
        }
    }

    public function testValidAstralCharactersKeepCodePointDiagnosticOffsets(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unexpected character 'x' at position 6");
        JsonUtils::strictParsePartialJson('["é😀",x');
    }
}
