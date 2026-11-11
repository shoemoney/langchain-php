<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\JsonOutputParser;
use LangChain\OutputParsers\JsonUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Public markdown boundaries derived from pinned json.ts's two String.trim() calls. */
#[CoversClass(JsonUtils::class)]
#[CoversClass(JsonOutputParser::class)]
final class JsonMarkdownBoundaryTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function sourceWhitespace(): iterable
    {
        foreach ([...range(0x09, 0x0D), 0x20, 0xA0, 0x1680, ...range(0x2000, 0x200A), 0x2028, 0x2029, 0x202F, 0x205F, 0x3000, 0xFEFF] as $codepoint) {
            yield \sprintf('U+%04X', $codepoint) => [mb_chr($codepoint, 'UTF-8')];
        }
    }

    #[DataProvider('sourceWhitespace')]
    public function testBothPublicTrimBoundariesUseTheSourceWhitespaceSet(string $space): void
    {
        $json = '{"value":1}';
        foreach ([$space . $json . $space, $space . "```json\n" . $space . $json . $space . "\n```" . $space, 'preamble ```' . $space . $json . $space] as $input) {
            self::assertSame($json, JsonUtils::parseJsonMarkdown($input, static fn (string $content): string => $content));
            self::assertSame(['value' => 1], (new JsonOutputParser())->parse($input));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function nulInsideJsonBoundary(): iterable
    {
        foreach (["\0" . '{"value":1}', '{"value":1}' . "\0", "\0" . '{"value":1}' . "\0"] as $index => $json) {
            yield 'unfenced ' . $index => [$json, $json];
            yield 'fenced ' . $index => ["```json\n" . $json . "\n```", $json];
        }
    }

    #[DataProvider('nulInsideJsonBoundary')]
    public function testNulIsPreservedAndCannotTurnInvalidJsonIntoValidOutput(string $input, string $content): void
    {
        self::assertSame($content, JsonUtils::parseJsonMarkdown($input, static fn (string $json): string => $json));
        self::assertNull(JsonUtils::parseJsonMarkdown($input));
        $this->expectException(\JsonException::class);
        (new JsonOutputParser())->parse($input);
    }

    public function testIgnoredProseOutsideTheFenceCanStillContainNul(): void
    {
        self::assertSame(['value' => 1], (new JsonOutputParser())->parse("\0preamble\0 ```json\n{\"value\":1}\n``` trailing\0"));
    }

    public function testWhitespaceWithinAQuotedValueIsPreserved(): void
    {
        self::assertSame("\u{FEFF} value \u{3000}", (new JsonOutputParser())->parse("\u{FEFF}```json\n\"\u{FEFF} value \u{3000}\"\n```\u{3000}"));
    }
}
