<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\OutputParsers\JsonUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/json.test.ts`.
 *
 * `JsonUtils` already carried every export of upstream `utils/json.ts`
 * (`parseJsonMarkdown`, `strictParsePartialJson`, `parsePartialJson`), so this
 * file adds only the cases the existing parser tests do not cover: the
 * malformed-input matrix, the number/string/whitespace tables, the
 * every-prefix walks and the kitchen-sink document.
 *
 * Two upstream expectations are NOT converted because the PHP parser
 * (`PartialJsonParser`, outside this work package) diverges from upstream; see
 * {@see self::testKnownDivergencesFromUpstream()}.
 */
#[CoversClass(JsonUtils::class)]
final class JsonUtilsTest extends TestCase
{
    private const KITCHEN_SINK = '{"array": ["hello", null, false, true, 12345678910], "object": {"string": "string", '
        . '"null": null, "false": false, "true": true, "number": 12345678910}, "long": "very long string"}';

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function partialDocuments(): array
    {
        $cases = [
            ['{', []],
            ['{}', []],
            ['[]', []],
            ['[1', [1]],
            ['[', []],
            ['[null', [null]],
            ['[null,', [null]],
            ['[null,{', [null, []]],
            ['[null,{"', [null, []]],
            ['[null,{"a', [null, []]],
            ['[null,{"a"', [null, []]],
            ['[null,{"a":', [null, []]],
            ['[null,{"a":1', [null, ['a' => 1]]],
            ['"', ''],
            ['"hello', 'hello'],
            ['"hello"', 'hello'],
            ["\"15\\n\\t\\r", "15\n\t\r"],
            ["\"15\\u00f8", "15\u{f8}"],
            ["\"15\\u00f8C", "15\u{f8}C"],
            ["\"15\\u00f8C\"", "15\u{f8}C"],
            ['"hello\\\\', 'hello\\'],
            ['"hello\\\\"', 'hello\\'],
            ['"hello\\"', 'hello"'],
            ['"hello\\""', 'hello"'],
            ['"\\t\\n\\r\\b\\f\\/', "\t\n\r\x08\x0c/"],
            ['"\\t\\n\\r\\b\\f\\/"', "\t\n\r\x08\x0c/"],
            ['"foo\\bar', "foo\x08ar"],
            ['"foo\\bar"', "foo\x08ar"],
            ['"\\u00f8\\\\', "\u{f8}\\"],
            ['1', 1],
            ['12', 12],
            ['123', 123],
            ['-1', -1],
            ['-12', -12],
            ['-12.', -12],
            ['-12.1', -12.1],
            ['-1e', -1],
            ['-1e1', -10],
            ['-1e10', -1e10],
            ['-1e+', -1],
            ['-1e+1', -10],
            ['-1e+10', -1e10],
            ['-1e-', -1],
            ['-1e-1', -0.1],
            ['-1e-10', -1e-10],
            [" \n\t\r123", 123],
            ["123\n\t\r", 123],
        ];

        $named = [];
        foreach ($cases as [$input, $expected]) {
            $named[json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)] = [$input, $expected];
        }

        return $named;
    }

    #[DataProvider('partialDocuments')]
    public function testParsesPartialDocuments(string $input, mixed $expected): void
    {
        self::assertEquals($expected, JsonUtils::strictParsePartialJson($input), "[{$input}]");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function literals(): array
    {
        return ['null' => ['null'], 'true' => ['true'], 'false' => ['false']];
    }

    #[DataProvider('literals')]
    public function testCompleteLiteralsParse(string $literal): void
    {
        self::assertSame(json_decode($literal), JsonUtils::strictParsePartialJson($literal));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedDocuments(): array
    {
        $inputs = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/json.malformed.json'), true, 512, JSON_THROW_ON_ERROR);

        $named = [];
        foreach ($inputs as $input) {
            $named[json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)] = [$input];
        }

        return $named;
    }

    #[DataProvider('malformedDocuments')]
    public function testMalformedJsonThrows(string $input): void
    {
        $this->expectException(\RuntimeException::class);

        JsonUtils::strictParsePartialJson($input);
    }

    public function testMalformedJsonIsNullWhenLenient(): void
    {
        self::assertNull(JsonUtils::parsePartialJson('[1,]'));
        self::assertNull(JsonUtils::parsePartialJson(''));
    }

    public function testKnownDivergencesFromUpstream(): void
    {
        // Upstream accepts a truncated literal or a lone minus sign and answers
        // with the value it is on its way to (`[t` -> [true], `-` -> -0). The PHP
        // `PartialJsonParser` rejects them. That file is outside this work
        // package, so the gap is pinned rather than hidden: when the parser is
        // fixed this test fails and should be replaced by the upstream cases.
        foreach (['[t', '[n', '[-', '-', 'n', 'nul', 't', 'tru', 'f', 'fals', '[null,t'] as $truncated) {
            try {
                JsonUtils::strictParsePartialJson($truncated);
                self::fail("[{$truncated}] now parses; convert the upstream expectation");
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testKitchenSinkOfPartialJsonParsing(): void
    {
        $segments = array_values(array_map(
            static fn (string $line): mixed => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_filter(
                explode("\n", (string) file_get_contents(__DIR__ . '/Fixtures/json.kitchen-sink.jsonl')),
                static fn (string $line): bool => $line !== '',
            ),
        ));

        self::assertCount(strlen(self::KITCHEN_SINK), $segments);
        $skipped = 0;
        for ($i = 1, $n = strlen(self::KITCHEN_SINK); $i < $n; $i++) {
            $prefix = substr(self::KITCHEN_SINK, 0, $i);
            if (preg_match('/(?:^|[\[,:\s])(?:n|nu|nul|t|tr|tru|f|fa|fal|fals)$/', $prefix) === 1) {
                // The known divergence pinned in testKnownDivergencesFromUpstream().
                $skipped++;
                continue;
            }
            self::assertEquals($segments[$i], JsonUtils::strictParsePartialJson($prefix), "[{$prefix}]");
        }
        self::assertLessThan(30, $skipped, 'only prefixes ending inside a literal may be skipped');
    }

    public function testParseJsonMarkdownStillUsesTheSharedParser(): void
    {
        self::assertEquals(['a' => 1], JsonUtils::parseJsonMarkdown("```json\n{\"a\": 1\n```"));
    }
}
