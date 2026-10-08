<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Tools;

/**
 * OpenAI's hosted web search tool, for the Responses API.
 *
 * Port of `tools.webSearch` from `@langchain/openai`. The result is a plain
 * built-in tool array that `ResponsesTools::isBuiltInTool()` passes through
 * untouched. Unset options are omitted, which is what the SDK's JSON
 * serialisation does with a TypeScript `undefined`.
 */
final class WebSearch
{
    private function __construct()
    {
    }

    /**
     * @param array{
     *     filters?: array{allowedDomains?: list<string>},
     *     userLocation?: array{type: string, country?: string, city?: string, region?: string, timezone?: string},
     *     search_context_size?: 'low'|'medium'|'high'
     * } $options
     *
     * @return array<string, mixed>
     */
    public static function tool(array $options = []): array
    {
        $allowed = $options['filters']['allowedDomains'] ?? null;

        return array_filter([
            'type' => 'web_search',
            'filters' => $allowed !== null ? ['allowed_domains' => $allowed] : null,
            'user_location' => $options['userLocation'] ?? null,
            'search_context_size' => $options['search_context_size'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
