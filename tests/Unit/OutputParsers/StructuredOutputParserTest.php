<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\Messages\AIMessage;
use LangChain\OutputParsers\AsymmetricStructuredOutputParser;
use LangChain\OutputParsers\JsonMarkdownStructuredOutputParser;
use LangChain\OutputParsers\OutputParserException;
use LangChain\OutputParsers\StructuredOutputParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `output_parsers/tests/structured.test.ts`.
 *
 * The TypeScript original drives this parser with Zod schemas and asserts the
 * exact `toJsonSchema()` output in every instruction snapshot — two different
 * snapshots per test, because Zod v3 and Zod v4 emit different dialects. PHP has
 * neither, so the schema is supplied directly as JSON Schema and there is one
 * instruction text. Everything else about the parser's behaviour — the fence
 * handling, the newline re-escaping, the failure mode — is asserted unchanged.
 */
#[CoversClass(StructuredOutputParser::class)]
#[CoversClass(JsonMarkdownStructuredOutputParser::class)]
#[CoversClass(AsymmetricStructuredOutputParser::class)]
#[CoversClass(OutputParserException::class)]
final class StructuredOutputParserTest extends TestCase
{
    /** The schema `fromNamesAndDescriptions()` builds for `['url' => …]`. */
    private const URL_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'url' => ['type' => 'string', 'description' => 'A link to the resource'],
        ],
        'required' => ['url'],
        'additionalProperties' => false,
        '$schema' => 'http://json-schema.org/draft-07/schema#',
    ];

    public function testFromNamesAndDescriptions(): void
    {
        $parser = StructuredOutputParser::fromNamesAndDescriptions([
            'url' => 'A link to the resource',
        ]);

        self::assertSame(['url' => 'value'], $parser->parse("```\n{\"url\": \"value\"}```"));
        self::assertSame(self::URL_SCHEMA, $parser->schema);

        $instructions = $parser->getFormatInstructions();
        self::assertStringStartsWith(
            'You must format your output as a JSON value that adheres to a given "JSON Schema" instance.',
            $instructions
        );
        self::assertStringContainsString('"JSON Schema" is a declarative language', $instructions);
        self::assertStringContainsString(
            'Here is the JSON Schema instance your output must adhere to. Include the enclosing markdown codeblock:',
            $instructions
        );
        self::assertStringContainsString(
            '{"type":"object","properties":{"url":{"type":"string","description":"A link to the resource"}},"required":["url"],"additionalProperties":false,"$schema":"http://json-schema.org/draft-07/schema#"}',
            $instructions
        );
    }

    public function testInvokeParsesBaseMessageWithReasoningBlocks(): void
    {
        $parser = StructuredOutputParser::fromJsonSchema([
            'type' => 'object',
            'properties' => ['answer' => ['type' => 'string']],
            'required' => ['answer'],
        ]);

        $result = $parser->invoke(new AIMessage([
            'content' => [
                ['type' => 'reasoning', 'reasoning' => 'Let me think...'],
                ['type' => 'text', 'text' => '{"answer":"value"}'],
            ],
        ]));

        self::assertSame(['answer' => 'value'], $result);
    }

    public function testFromSchema(): void
    {
        $parser = StructuredOutputParser::fromJsonSchema(self::URL_SCHEMA);

        self::assertSame(['url' => 'value'], $parser->parse("```\n{\"url\": \"value\"}```"));
    }

    public function testFromSchemaThrowsWhenTheOutputDoesNotMatch(): void
    {
        $parser = StructuredOutputParser::fromJsonSchema([
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string', 'enum' => ['yes', 'no'], 'description' => 'yes or no'],
            ],
            'required' => ['answer'],
        ]);

        $this->expectException(OutputParserException::class);

        $parser->parse("```\n{\"url\": \"value\"}```");
    }

    /**
     * @return list<array{0: string}>
     */
    public static function wellFormedOutputs(): array
    {
        return [
            ["```\n{\"answer\": \"value\", \"sources\": [\"this-source\"]}```"],
            ["```json\n{\"answer\": \"value\", \"sources\": [\"this-source\"]}```"],
            ["some other stuff```json\n{\"answer\": \"value\", \"sources\": [\"this-source\"]}```some other stuff at the end"],
        ];
    }

    #[DataProvider('wellFormedOutputs')]
    public function testParsesWellFormedOutputs(string $output): void
    {
        $parser = StructuredOutputParser::fromJsonSchema([
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string', 'description' => "answer to the user's question"],
                'sources' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'sources used to answer the question, should be websites.',
                ],
            ],
            'required' => ['answer', 'sources'],
        ]);

        self::assertSame(
            ['answer' => 'value', 'sources' => ['this-source']],
            $parser->parse($output)
        );
    }

    public function testParsesNewlinesInsideStringValues(): void
    {
        $parser = StructuredOutputParser::fromJsonSchema([
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'A link to the resource'],
                'summary' => ['type' => 'string', 'description' => 'A summary'],
            ],
            'required' => ['url', 'summary'],
        ]);

        self::assertSame(
            ['url' => 'value', 'summary' => "line1,\nline2,\nline3"],
            $parser->parse("```\n{\"url\": \"value\", \"summary\": \"line1,\nline2,\nline3\"}```")
        );
    }

    /**
     * Regression for langchain-ai/langchainjs#8339: a markdown fence *inside* a
     * string value must not be mistaken for the end of the document.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function backtickCases(): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Name'],
                'biograph' => ['type' => 'string', 'description' => 'Biograph in markdown'],
            ],
            'required' => ['name', 'biograph'],
        ];

        return [
            [
                'without backticks',
                '{"name": "John Doe", "biograph": "john doe is a cool dude"}',
            ],
            [
                'with outer backticks',
                "```json\n{\"name\": \"John Doe\", \"biograph\": \"john doe is a cool dude\"}```",
            ],
            [
                'with inner backticks',
                '{"name": "John Doe", "biograph": "john doe is a ```cool dude```"}',
            ],
        ];
    }

    #[DataProvider('backtickCases')]
    public function testParsesJsonWithBackticks(string $name, string $output): void
    {
        $parser = StructuredOutputParser::fromJsonSchema([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Name'],
                'biograph' => ['type' => 'string', 'description' => 'Biograph in markdown'],
            ],
            'required' => ['name', 'biograph'],
        ]);

        $result = $parser->parse($output);

        self::assertSame('John Doe', $result['name'], $name);
        self::assertStringStartsWith('john doe is a', (string) $result['biograph'], $name);
    }

    public function testFailureCarriesTheOffendingOutput(): void
    {
        $parser = StructuredOutputParser::fromJsonSchema(self::URL_SCHEMA);

        try {
            $parser->parse('not json at all');
            self::fail('expected an OutputParserException');
        } catch (OutputParserException $e) {
            self::assertSame('not json at all', $e->llmOutput);
            self::assertSame('OUTPUT_PARSING_FAILURE', $e->lcErrorCode);
            self::assertStringContainsString('Failed to parse. Text: "not json at all".', $e->getMessage());
        }
    }

    public function testSendToLlmRequiresObservationAndOutput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OutputParserException('boom', null, null, true);
    }

    public function testJsonMarkdownInstructionsAreASketchNotASchema(): void
    {
        $parser = new JsonMarkdownStructuredOutputParser([
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string', 'description' => 'the answer'],
                'sources' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['answer', 'sources'],
        ]);

        self::assertSame(
            "Return a markdown code snippet with a JSON object formatted to look like:\n"
            . "```json\n{\n  \"answer\": string // the answer\n  \"sources\": array[\n    string\n  ] \n}\n```",
            $parser->getFormatInstructions()
        );
    }

    public function testJsonMarkdownInstructionsCanDoubleBracesForFStringInterpolation(): void
    {
        $parser = new JsonMarkdownStructuredOutputParser([
            'type' => 'object',
            'properties' => ['answer' => ['type' => 'string']],
            'required' => ['answer'],
        ]);

        $instructions = $parser->getFormatInstructions(['interpolationDepth' => 2]);

        self::assertStringContainsString('{{', $instructions);
        self::assertStringNotContainsString('```json' . "\n" . '{ "answer"', $instructions);
    }

    public function testJsonMarkdownInstructionsRejectADepthBelowOne(): void
    {
        $parser = new JsonMarkdownStructuredOutputParser(['type' => 'object']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('f string interpolation depth must be at least 1');

        $parser->getFormatInstructions(['interpolationDepth' => 0]);
    }

    public function testAsymmetricParserRunsItsOutputProcessor(): void
    {
        $parser = new class([
            'type' => 'object',
            'properties' => ['answer' => ['type' => 'string']],
            'required' => ['answer'],
        ]) extends AsymmetricStructuredOutputParser {
            /** @return list<string> */
            public function outputProcessor(mixed $input): mixed
            {
                return [strtoupper((string) $input['answer'])];
            }
        };

        self::assertSame(['VALUE'], $parser->parse('{"answer":"value"}'));
    }
}
