<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Tools;

/**
 * Anthropic web fetch server tool (`tools/webFetch.ts`).

Enabling this where Claude processes untrusted input next to sensitive data risks exfiltration.
 */
final class WebFetch
{
    /**
     * @param array{
     *     maxUses?: int,
     *     allowedDomains?: list<string>,
     *     blockedDomains?: list<string>,
     *     cacheControl?: array<string, mixed>,
     *     citations?: array{enabled: bool},
     *     maxContentTokens?: int
     * } $options
     *
     * @return array<string, mixed>
     */
    public static function webFetch_20250910(array $options = []): array
    {
        return ServerToolDefinition::withoutNulls([
            'type' => 'web_fetch_20250910',
            'name' => 'web_fetch',
            'max_uses' => $options['maxUses'] ?? null,
            'allowed_domains' => $options['allowedDomains'] ?? null,
            'blocked_domains' => $options['blockedDomains'] ?? null,
            'cache_control' => $options['cacheControl'] ?? null,
            'citations' => $options['citations'] ?? null,
            'max_content_tokens' => $options['maxContentTokens'] ?? null,
        ]);
    }
}
