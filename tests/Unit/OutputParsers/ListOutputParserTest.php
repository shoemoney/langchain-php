<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\BytesOutputParser;
use LangChain\OutputParsers\CommaSeparatedListOutputParser;
use LangChain\OutputParsers\CustomListOutputParser;
use LangChain\OutputParsers\ListOutputParser;
use LangChain\OutputParsers\MarkdownListOutputParser;
use LangChain\OutputParsers\NumberedListOutputParser;
use LangChain\OutputParsers\OutputParserException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `output_parsers/tests/output_parser.test.ts`.
 *
 * The upstream suite drives these parsers through `FakeStreamingLLM`. That
 * fixture is a model, not a parser, and the model has no port here — so the
 * tests feed the parser's `transform()` the same character-by-character stream
 * the model would have, which is the property under test anyway.
 */
#[CoversClass(ListOutputParser::class)]
#[CoversClass(CommaSeparatedListOutputParser::class)]
#[CoversClass(NumberedListOutputParser::class)]
#[CoversClass(MarkdownListOutputParser::class)]
#[CoversClass(CustomListOutputParser::class)]
#[CoversClass(BytesOutputParser::class)]
final class ListOutputParserTest extends TestCase
{
    /**
     * The input stream the upstream test feeds its parsers: one character per chunk.
     *
     * @return \Generator<int, string>
     */
    private static function characters(string $input): \Generator
    {
        foreach (str_split($input) as $character) {
            yield $character;
        }
    }

    /**
     * @param class-string<ListOutputParser> $parserClass
     * @param list<string>                    $expected
     */
    private static function assertStreamsAndParses(string $parserClass, string $input, array $expected): void
    {
        $parser = new $parserClass();

        $chunks = [];
        foreach ($parser->transform(self::characters($input)) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertSame(
            array_map(static fn (string $item): array => [$item], $expected),
            $chunks,
            "streaming {$parserClass} on " . json_encode($input)
        );
        self::assertSame($expected, $parser->parse($input));
    }

    /**
     * @return list<array{0: class-string<ListOutputParser>, 1: string, 2: list<string>}>
     */
    public static function listCases(): array
    {
        return [
            [CommaSeparatedListOutputParser::class, 'a,b,c', ['a', 'b', 'c']],
            [CommaSeparatedListOutputParser::class, 'a,b,c,', ['a', 'b', 'c', '']],
            [CommaSeparatedListOutputParser::class, 'a', ['a']],
            [NumberedListOutputParser::class, "1. a\n2. b\n3. c", ['a', 'b', 'c']],
            [
                NumberedListOutputParser::class,
                "Items:\n\n1. apple\n\n2. banana\n\n3. cherry",
                ['apple', 'banana', 'cherry'],
            ],
            [
                NumberedListOutputParser::class,
                "Your response should be a numbered list with each item on a new line. For example: \n\n1. foo\n\n2. bar\n\n3. baz",
                ['foo', 'bar', 'baz'],
            ],
            [NumberedListOutputParser::class, 'No items in the list.', []],
            [MarkdownListOutputParser::class, "- a\n    - b\n- c", ['a', 'b', 'c']],
            [
                MarkdownListOutputParser::class,
                "Items:\n\n- apple\n\n- banana\n\n- cherry",
                ['apple', 'banana', 'cherry'],
            ],
            [
                MarkdownListOutputParser::class,
                "Your response should be a numbered - not an item - list with each item on a new line. For example: \n\n- foo\n\n- bar\n\n- baz",
                ['foo', 'bar', 'baz'],
            ],
            [MarkdownListOutputParser::class, 'No items in the list.', []],
            [MarkdownListOutputParser::class, "* a\n    * b\n* c", ['a', 'b', 'c']],
            [
                MarkdownListOutputParser::class,
                "Items:\n\n* apple\n\n* banana\n\n* cherry",
                ['apple', 'banana', 'cherry'],
            ],
            [
                MarkdownListOutputParser::class,
                "Your response should be a numbered list with each item on a new line. For example: \n\n* foo\n\n* bar\n\n* baz",
                ['foo', 'bar', 'baz'],
            ],
            [MarkdownListOutputParser::class, 'No items in the list.', []],
        ];
    }

    #[DataProvider('listCases')]
    public function testParsesAndStreams(
        string $parserClass,
        string $input,
        array $expected,
    ): void {
        self::assertStreamsAndParses($parserClass, $input, $expected);
    }

    public function testBytesOutputParserYieldsOneChunkPerCharacter(): void
    {
        $parser = new BytesOutputParser();

        $chunks = [];
        foreach ($parser->transform(self::characters('Hi there!')) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertCount(strlen('Hi there!'), $chunks);
        self::assertSame('Hi there!', implode('', $chunks));
    }

    public function testCommaSeparatedFormatInstructions(): void
    {
        self::assertSame(
            'Your response should be a list of comma separated values, eg: `foo, bar, baz`',
            (new CommaSeparatedListOutputParser())->getFormatInstructions()
        );
    }

    public function testCustomListSplitsOnTheGivenSeparator(): void
    {
        $parser = new CustomListOutputParser(null, ';');

        self::assertSame(['a', 'b', 'c'], $parser->parse('a; b ;c'));
        self::assertSame(
            'Your response should be a list of items separated by ";" (eg: `foo; bar; baz`)',
            $parser->getFormatInstructions()
        );
    }

    public function testCustomListRejectsTheWrongItemCount(): void
    {
        $parser = new CustomListOutputParser(3);

        $this->expectException(OutputParserException::class);
        $this->expectExceptionMessage('Incorrect number of items. Expected 3, got 2.');

        $parser->parse('a,b');
    }

    public function testCustomListFormatInstructionsMentionTheExpectedLength(): void
    {
        self::assertSame(
            'Your response should be a list of 2 items separated by "," (eg: `foo, bar, baz`)',
            (new CustomListOutputParser(2))->getFormatInstructions()
        );
    }
}
