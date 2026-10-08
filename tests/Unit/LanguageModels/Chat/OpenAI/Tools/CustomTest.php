<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\Tools\Custom;
use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesTools;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** `tools/custom.ts` (upstream only covers it in an integration test). */
#[CoversClass(Custom::class)]
final class CustomTest extends TestCase
{
    public function testWrapsTheDefinitionInMetadataAndRunsTheCallable(): void
    {
        $fields = ['name' => 'execute_code', 'description' => 'Runs code', 'format' => ['type' => 'text']];
        $tool = Custom::create(static fn (string $input): string => strtoupper($input), $fields);

        self::assertSame($fields, $tool->metadata['customTool']);
        self::assertTrue(ResponsesTools::isCustomTool($tool));
        self::assertSame('', $tool->description);
        self::assertSame('PRINT(1)', $tool->invoke('print(1)'));
    }
}
