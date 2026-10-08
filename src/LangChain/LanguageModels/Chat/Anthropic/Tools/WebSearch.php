<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Tools;

/**
 * Anthropic web search server tool (`tools/webSearch.ts`).
 */
final class WebSearch
{
    /**
     * @param array{
     *     maxUses?: int,
     *     allowedDomains?: list<string>,
     *     blockedDomains?: list<string>,
     *     cacheControl?: array<string, mixed>,
     *     userLocation?: array<string, mixed>,
     *     deferLoading?: bool,
     *     strict?: bool
     * } $options
     *
     * @return array<string, mixed>
     */
    public static function webSearch_20250305(array $options = []): array
    {
        return ServerToolDefinition::withoutNulls([
            'type' => 'web_search_20250305',
            'name' => 'web_search',
            'max_uses' => $options['maxUses'] ?? null,
            'allowed_domains' => $options['allowedDomains'] ?? null,
            'blocked_domains' => $options['blockedDomains'] ?? null,
            'cache_control' => $options['cacheControl'] ?? null,
            'defer_loading' => $options['deferLoading'] ?? null,
            'strict' => $options['strict'] ?? null,
            'user_location' => $options['userLocation'] ?? null,
        ]);
    }
}
