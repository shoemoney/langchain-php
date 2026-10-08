<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic\Tools;

use LangChain\LanguageModels\Chat\Anthropic\Tools\ToolSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolSearch::class)]
final class ToolSearchTest extends TestCase
{
    public function testRegexDefinitionWithNoOptions(): void
    {
        self::assertSame(
            ['type' => 'tool_search_tool_regex_20251119', 'name' => 'tool_search_tool_regex'],
            ToolSearch::toolSearchRegex_20251119(),
        );
    }

    public function testRegexDefinitionWithCacheControl(): void
    {
        self::assertSame(
            ['type' => 'tool_search_tool_regex_20251119', 'name' => 'tool_search_tool_regex', 'cache_control' => ['type' => 'ephemeral']],
            ToolSearch::toolSearchRegex_20251119(['cacheControl' => ['type' => 'ephemeral']]),
        );
    }

    public function testBm25DefinitionWithNoOptions(): void
    {
        self::assertSame(
            ['type' => 'tool_search_tool_bm25_20251119', 'name' => 'tool_search_tool_bm25'],
            ToolSearch::toolSearchBM25_20251119(),
        );
    }

    public function testBm25DefinitionWithCacheControl(): void
    {
        self::assertSame(
            ['type' => 'tool_search_tool_bm25_20251119', 'name' => 'tool_search_tool_bm25', 'cache_control' => ['type' => 'ephemeral']],
            ToolSearch::toolSearchBM25_20251119(['cacheControl' => ['type' => 'ephemeral']]),
        );
    }
}
