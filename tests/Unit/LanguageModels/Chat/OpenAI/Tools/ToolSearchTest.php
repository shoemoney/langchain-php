<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\Tools\ToolSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** `tools/tests/toolSearch.test.ts`. */
#[CoversClass(ToolSearch::class)]
final class ToolSearchTest extends TestCase
{
    public function testBasicServerExecutedToolDefinition(): void
    {
        self::assertSame(['type' => 'tool_search'], ToolSearch::tool());
    }

    public function testServerExecutedToolWithExplicitExecution(): void
    {
        self::assertSame(['type' => 'tool_search', 'execution' => 'server'], ToolSearch::tool(['execution' => 'server']));
    }

    public function testClientExecutedToolWithDescriptionAndParameters(): void
    {
        $parameters = [
            'type' => 'object',
            'properties' => ['goal' => ['type' => 'string', 'description' => 'The goal to search tools for']],
            'required' => ['goal'],
        ];

        self::assertSame(
            [
                'type' => 'tool_search',
                'execution' => 'client',
                'description' => 'Search for available tools by goal',
                'parameters' => $parameters,
            ],
            ToolSearch::tool([
                'execution' => 'client',
                'description' => 'Search for available tools by goal',
                'parameters' => $parameters,
            ]),
        );
    }
}
