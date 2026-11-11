<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\JsonUtils;
use LangChain\OutputParsers\PartialJsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ECMAScript String.trim and /\s/ boundaries used in the pinned json.ts parser. */
#[CoversClass(PartialJsonParser::class)]
#[CoversClass(JsonUtils::class)]
final class PartialJsonUnicodeWhitespaceTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function sourceWhitespace(): iterable
    {
        foreach ([...range(0x09, 0x0D), 0x20, 0xA0, 0x1680, ...range(0x2000, 0x200A), 0x2028, 0x2029, 0x202F, 0x205F, 0x3000, 0xFEFF] as $codepoint) {
            yield \sprintf('U+%04X', $codepoint) => [mb_chr($codepoint, 'UTF-8')];
        }
    }

    #[DataProvider('sourceWhitespace')]
    public function testSourceWhitespaceIsTrimmedAndSkippedInUnclosedContainers(string $space): void
    {
        self::assertSame([1, 2], JsonUtils::strictParsePartialJson($space . '[' . $space . '1' . $space . ',' . $space . '2' . $space));
        self::assertSame(['value' => 1], JsonUtils::strictParsePartialJson($space . '{' . $space . '"value"' . $space . ':' . $space . '1' . $space));
        self::assertSame('value', JsonUtils::strictParsePartialJson('"value' . $space));
        self::assertSame('value' . $space, JsonUtils::strictParsePartialJson('"value' . $space . '"'));
    }

    #[DataProvider('sourceWhitespace')]
    public function testWhitespaceWithoutAValueStillFails(string $space): void
    {
        self::assertNull(JsonUtils::parsePartialJson($space));
        $this->expectException(\RuntimeException::class);
        JsonUtils::strictParsePartialJson($space);
    }

    /** @return iterable<string, array{string}> */
    public static function nonWhitespace(): iterable
    {
        // PCRE Unicode \s includes NEL; ECMAScript does not. PHP trim strips NUL; JS trim does not.
        foreach ([0x00, 0x1C, 0x85, 0x180E, 0x200B, 0x2060] as $codepoint) {
            $character = mb_chr($codepoint, 'UTF-8');
            foreach ([$character . '1', '1' . $character, '[1' . $character] as $index => $json) {
                yield \sprintf('U+%04X position %d', $codepoint, $index) => [$json];
            }
        }
    }

    #[DataProvider('nonWhitespace')]
    public function testCharactersOutsideTheSourceWhitespaceSetRemainMalformed(string $json): void
    {
        self::assertNull(JsonUtils::parsePartialJson($json));
        $this->expectException(\RuntimeException::class);
        JsonUtils::strictParsePartialJson($json);
    }
}
