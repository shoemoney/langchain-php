<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Tools;

/**
 * Anthropic tool search server tools (`tools/toolSearch.ts`).
 */
final class ToolSearch
{
    /**
     * @param array{cacheControl?: array<string, mixed>} $options
     *
     * @return array<string, mixed>
     */
    public static function toolSearchRegex_20251119(array $options = []): array
    {
        return ServerToolDefinition::withoutNulls([
            'type' => 'tool_search_tool_regex_20251119',
            'name' => 'tool_search_tool_regex',
            'cache_control' => $options['cacheControl'] ?? null,
        ]);
    }

    /**
     * @param array{cacheControl?: array<string, mixed>} $options
     *
     * @return array<string, mixed>
     */
    public static function toolSearchBM25_20251119(array $options = []): array
    {
        return ServerToolDefinition::withoutNulls([
            'type' => 'tool_search_tool_bm25_20251119',
            'name' => 'tool_search_tool_bm25',
            'cache_control' => $options['cacheControl'] ?? null,
        ]);
    }
}
