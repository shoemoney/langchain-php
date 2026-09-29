<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\Messages\AIMessage;
use LangChain\OutputParsers\JsonOutputParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `output_parsers/tests/json.test.ts`.
 */
#[CoversClass(JsonOutputParser::class)]
final class JsonOutputParserTest extends TestCase
{
    /**
     * The upstream token stream: a joke object delivered one line at a time,
     * with the lines deliberately padded so the parser has to cope with the
     * whitespace a model actually emits.
     *
     * @return list<string>
     */
    private static function streamedTokens(): array
    {
        return explode("\n", <<<'STREAMED_TOKENS'
            {

             "
            setup
            ":
             "
            Why
             did
             the
             bears
             start
             a
             band
             called
             Bears
             Bears
             Bears
             ?
            "
            ,
             "
            punchline
            ":
             "
            Because
             they
             wanted
             to
             play
             bear
             -y
             good
             music
             !
            "
            ,
             "
            audience
            ":
              [
            "
            Haha
            "
            ,
             "
            So
             funny
            "
            ]

            }
            STREAMED_TOKENS);
    }

    /**
     * @param list<string> $tokens
     * @return \Generator<int, string>
     */
    private static function stream(array $tokens): \Generator
    {
        foreach ($tokens as $token) {
            yield $token;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function expectedStreamedJson(): array
    {
        $setup = 'Why did the bears start a band called Bears Bears Bears ?';
        $punchline = 'Because they wanted to play bear -y good music !';

        return [
            [],
            ['setup' => ''],
            ['setup' => 'Why'],
            ['setup' => 'Why did'],
            ['setup' => 'Why did the'],
            ['setup' => 'Why did the bears'],
            ['setup' => 'Why did the bears start'],
            ['setup' => 'Why did the bears start a'],
            ['setup' => 'Why did the bears start a band'],
            ['setup' => 'Why did the bears start a band called'],
            ['setup' => 'Why did the bears start a band called Bears'],
            ['setup' => 'Why did the bears start a band called Bears Bears'],
            ['setup' => 'Why did the bears start a band called Bears Bears Bears'],
            ['setup' => $setup],
            ['setup' => $setup, 'punchline' => ''],
            ['setup' => $setup, 'punchline' => 'Because'],
            ['setup' => $setup, 'punchline' => 'Because they'],
            ['setup' => $setup, 'punchline' => 'Because they wanted'],
            ['setup' => $setup, 'punchline' => 'Because they wanted to'],
            ['setup' => $setup, 'punchline' => 'Because they wanted to play'],
            ['setup' => $setup, 'punchline' => 'Because they wanted to play bear'],
            ['setup' => $setup, 'punchline' => 'Because they wanted to play bear -y'],
            ['setup' => $setup, 'punchline' => 'Because they wanted to play bear -y good'],
            ['setup' => $setup, 'punchline' => 'Because they wanted to play bear -y good music'],
            ['setup' => $setup, 'punchline' => 'Because they wanted to play bear -y good music !'],
            // The upstream fixture lists these key-ordered punchline-first, which
            // `toEqual` does not care about; this port keeps the insertion order
            // a JSON decode actually produces so the assertion can be exact.
            ['setup' => $setup, 'punchline' => $punchline, 'audience' => []],
            ['setup' => $setup, 'punchline' => $punchline, 'audience' => ['']],
            ['setup' => $setup, 'punchline' => $punchline, 'audience' => ['Haha']],
            ['setup' => $setup, 'punchline' => $punchline, 'audience' => ['Haha', '']],
            ['setup' => $setup, 'punchline' => $punchline, 'audience' => ['Haha', 'So']],
            ['setup' => $setup, 'punchline' => $punchline, 'audience' => ['Haha', 'So funny']],
        ];
    }

    /**
     * @return list<list<array<string, mixed>>>
     */
    private static function expectedStreamedJsonDiff(): array
    {
        $setup = 'Why did the bears start a band called Bears Bears Bears ?';
        $punchline = 'Because they wanted to play bear -y good music !';

        $patches = [
            [['op' => 'replace', 'path' => '', 'value' => []]],
            [['op' => 'add', 'path' => '/setup', 'value' => '']],
        ];

        foreach (['Why', 'Why did', 'Why did the', 'Why did the bears', 'Why did the bears start',
            'Why did the bears start a', 'Why did the bears start a band', 'Why did the bears start a band called',
            'Why did the bears start a band called Bears', 'Why did the bears start a band called Bears Bears',
            'Why did the bears start a band called Bears Bears Bears', $setup] as $value) {
            $patches[] = [['op' => 'replace', 'path' => '/setup', 'value' => $value]];
        }

        $patches[] = [['op' => 'add', 'path' => '/punchline', 'value' => '']];
        foreach (['Because', 'Because they', 'Because they wanted', 'Because they wanted to',
            'Because they wanted to play', 'Because they wanted to play bear',
            'Because they wanted to play bear -y', 'Because they wanted to play bear -y good',
            'Because they wanted to play bear -y good music', $punchline] as $value) {
            $patches[] = [['op' => 'replace', 'path' => '/punchline', 'value' => $value]];
        }

        $patches[] = [['op' => 'add', 'path' => '/audience', 'value' => []]];
        $patches[] = [['op' => 'add', 'path' => '/audience/0', 'value' => '']];
        $patches[] = [['op' => 'replace', 'path' => '/audience/0', 'value' => 'Haha']];
        $patches[] = [['op' => 'add', 'path' => '/audience/1', 'value' => '']];
        $patches[] = [['op' => 'replace', 'path' => '/audience/1', 'value' => 'So']];
        $patches[] = [['op' => 'replace', 'path' => '/audience/1', 'value' => 'So funny']];

        return $patches;
    }

    /**
     * The upstream markdown-streaming table: input chunks, and the documents the
     * parser is expected to have emitted by the end of the stream.
     *
     * @return list<array{0: string, 1: list<string>, 2: list<array<string, mixed>>}>
     */
    public static function markdownStreamCases(): array
    {
        return [
            [
                'Markdown with split code block',
                ["```json\n" . '{"', 'countries": [{"n', 'ame": "China"}]}', "\n```"],
                [[], ['countries' => [[]]], ['countries' => [['name' => 'China']]]],
            ],
            [
                'Markdown without json identifier, split',
                ["```\n" . '{"', 'key": "val', '"}\n```'],
                [[], ['key' => 'val']],
            ],
            [
                'Ignores text after closing markdown backticks',
                ["```json\n", '{ "data": 123 }', "\n```", ' Some extra text'],
                [['data' => 123]],
            ],
            [
                'Handles markdown with text around code block',
                ['Explanation:', "```json\n{", '"answer": 42', "}\n```\nConclusion"],
                [[], ['answer' => 42]],
            ],
        ];
    }

    /**
     * @param list<string>                    $chunks
     * @param list<array<string, mixed>>      $expected
     */
    #[DataProvider('markdownStreamCases')]
    public function testMarkdownStreamingScenarios(string $name, array $chunks, array $expected): void
    {
        $parser = new JsonOutputParser();

        $result = [];
        foreach ($parser->transform(self::stream($chunks)) as $chunk) {
            $result[] = $chunk;
        }

        self::assertSame($expected, $result, $name);
    }

    public function testHandlesMultipleCodeBlocksInASingleChunk(): void
    {
        $parser = new JsonOutputParser();

        $result = [];
        foreach ($parser->transform(['```json' . "\n" . '{"a":1}' . "\n```" . "\n" . '```json' . "\n" . '{"b":2}' . "\n```"]) as $chunk) {
            $result[] = $chunk;
        }

        self::assertSame([['a' => 1]], $result);
    }

    public function testParsesStreamedJson(): void
    {
        $parser = new JsonOutputParser();

        $result = [];
        foreach ($parser->transform(self::stream(self::streamedTokens())) as $chunk) {
            $result[] = $chunk;
        }

        $expected = self::expectedStreamedJson();
        self::assertSame($expected, $result);
        self::assertSame($expected[count($expected) - 1], $parser->parse(implode('', self::streamedTokens())));
    }

    public function testParsesStreamedJsonDiff(): void
    {
        $parser = new JsonOutputParser(['diff' => true]);

        $result = [];
        foreach ($parser->transform(self::stream(self::streamedTokens())) as $chunk) {
            $result[] = $chunk;
        }

        self::assertSame(self::expectedStreamedJsonDiff(), $result);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function fencedDocuments(): array
    {
        return [
            ["```json\n{\n    \"foo\": \"bar\"\n}\n```"],
            ["\n\n```json\n{\n    \"foo\": \"bar\"\n}\n```\n\n"],
            ["```json\n{\n\n    \"foo\": \"bar\"\n\n}\n```"],
            ["\n\n```json\n\n{\n\n    \"foo\": \"bar\"\n\n}\n\n```\n\n"],
            ["```\n\n{\n\n    \"foo\": \"bar\"\n\n}\n\n```\n\n"],
            ["{\n    \"foo\": \"bar\"\n}"],
            ["\n{\n    \"foo\": \"bar\"\n}\n"],
            ["Thought: I need to use the search tool\n\nAction:\n```\n{\n  \"foo\": \"bar\"\n}\n```"],
            ["```\n{\n  \"foo\": \"bar\"\n}\n```\nThis should do the trick"],
            ["Action: Testing\n\n```\n{\n  \"foo\": \"bar\"\n}\n```\nThis should do the trick"],
        ];
    }

    #[DataProvider('fencedDocuments')]
    public function testParsesDocumentsWithAndWithoutFences(string $document): void
    {
        $parser = new JsonOutputParser();

        $result = [];
        foreach ($parser->transform(self::stream(str_split($document))) as $chunk) {
            $result[] = $chunk;
        }

        self::assertSame(['foo' => 'bar'], $result[count($result) - 1]);
        self::assertSame(['foo' => 'bar'], $parser->parse($document));
    }

    public function testParsesEscapedDoubleQuotesInNestedJson(): void
    {
        $document = "```json\n{\n    \"action\": \"Final Answer\",\n    \"action_input\": \"{\\\"foo\\\": \\\"bar\\\", \\\"bar\\\": \\\"foo\\\"}\"\n}\n```";
        $expected = [
            'action' => 'Final Answer',
            'action_input' => '{"foo": "bar", "bar": "foo"}',
        ];

        $parser = new JsonOutputParser();

        $result = [];
        foreach ($parser->transform(self::stream(str_split($document))) as $chunk) {
            $result[] = $chunk;
        }

        self::assertSame($expected, $result[count($result) - 1]);
        self::assertSame($expected, $parser->parse($document));
    }

    public function testParsesAIMessageWithContentBlockContent(): void
    {
        $parser = new JsonOutputParser();

        $message = new AIMessage([
            'content' => [
                ['type' => 'text', 'text' => '{"conclusion": true, "reason": "test passed"}'],
            ],
        ]);

        self::assertSame(
            ['conclusion' => true, 'reason' => 'test passed'],
            $parser->invoke($message)
        );
    }

    public function testHandlesMultipleTextContentBlocks(): void
    {
        $parser = new JsonOutputParser();

        $message = new AIMessage([
            'content' => [
                ['type' => 'text', 'text' => '{"foo": "bar"'],
                ['type' => 'text', 'text' => ', "baz": 123}'],
            ],
        ]);

        self::assertSame(['foo' => 'bar', 'baz' => 123], $parser->invoke($message));
    }

    public function testHandlesContentBlocksWithNonTextTypesGracefully(): void
    {
        $parser = new JsonOutputParser();

        $message = new AIMessage([
            'content' => [
                ['type' => 'image_url', 'image_url' => 'https://example.com/image.png'],
                ['type' => 'text', 'text' => '{"answer": 42}'],
            ],
        ]);

        self::assertSame(['answer' => 42], $parser->invoke($message));
    }

    public function testStillWorksWithStringContent(): void
    {
        $parser = new JsonOutputParser();

        self::assertSame(
            ['simple' => 'string'],
            $parser->invoke(new AIMessage(['content' => '{"simple": "string"}']))
        );
    }

    public function testFormatInstructionsAreEmpty(): void
    {
        self::assertSame('', (new JsonOutputParser())->getFormatInstructions());
    }
}
