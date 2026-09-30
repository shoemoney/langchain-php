<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\StructuredOutputParser;
use LangChain\Tools\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The format instruction must contain a real markdown fence.
 *
 * The string was written as `\`\`\`json` in a double-quoted PHP literal. PHP has
 * no `\` escape, so each backslash survived into the output and the model was
 * sent:
 *
 *     \`\`\`json
 *
 * instead of
 *
 *     ```json
 *
 * Measured before the fix: `str_contains($instructions, "\\`")` was TRUE, and the
 * affected line rendered as `"\\`\\`\\`json"`.
 *
 * This is the model-facing half of the contract: the instructions tell the model
 * which fence to emit, so a corrupted fence here asks for output the parser
 * will not then recognise. Nothing downstream would complain — the parse
 * failure surfaces as an unrelated "Failed to parse" much later.
 */
#[CoversClass(StructuredOutputParser::class)]
final class FormatInstructionsFenceTest extends TestCase
{
    public function testTheFenceCarriesNoStrayBackslashes(): void
    {
        $parser = new StructuredOutputParser(['schema' => Schema::object([], [])]);
        $instructions = $parser->getFormatInstructions();

        self::assertStringNotContainsString(
            '\\`',
            $instructions,
            'a PHP double-quoted string keeps `\\` literally — the model was sent a corrupted fence',
        );
    }

    public function testTheFenceIsTheOneTheParserLooksFor(): void
    {
        $parser = new StructuredOutputParser(['schema' => Schema::object([], [])]);
        $instructions = $parser->getFormatInstructions();

        // Opening and closing fences, exactly as the extractor's own patterns
        // expect them: an anchored ``` or ```json opener, and a ``` closer.
        self::assertMatchesRegularExpression('/^```(?:json)?$/m', $instructions);
        self::assertSame(
            2,
            preg_match_all('/^```(?:json)?$/m', $instructions),
            'one opening fence and one closing fence',
        );
    }

    public function testTheSchemaIsStillIncluded(): void
    {
        $parser = new StructuredOutputParser([
            'schema' => Schema::object(['a' => Schema::string()], ['a']),
        ]);

        self::assertStringContainsString('"a"', $parser->getFormatInstructions());
    }
}
