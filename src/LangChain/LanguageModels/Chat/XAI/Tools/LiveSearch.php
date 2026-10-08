<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\XAI\Tools;

/**
 * xAI's built-in live search tool.
 *
 * Port of `tools/live_search.ts`. Executed server-side by the xAI API.
 *
 * @deprecated xAI deprecated the Live Search API on December 15, 2025. Use
 *             {@see WebSearch} and {@see XSearch} instead.
 */
final class LiveSearch
{
    /** xAI's deprecated live_search tool type. */
    public const TOOL_TYPE = 'live_search_deprecated_20251215';

    public const TOOL_NAME = 'live_search';

    private function __construct()
    {
    }

    /**
     * Build the tool definition.
     *
     * Options (camelCase) map onto the snake_case `search_parameters` fields:
     * `mode`, `maxSearchResults`, `fromDate`, `toDate`, `returnCitations` and
     * `sources`. A source is `web`, `news`, `x` or `rss`; its camelCase keys
     * (`allowedWebsites`, `excludedWebsites`, `safeSearch`, `includedXHandles`,
     * `excludedXHandles`, `postFavoriteCount`, `postViewCount`, `country`,
     * `links`) are converted the same way. Unset options are left out of the
     * definition.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function create(array $options = []): array
    {
        $sources = isset($options['sources']) && is_array($options['sources'])
            ? array_map(static fn (array $source): array => self::mapSource($source), array_values($options['sources']))
            : null;

        return array_filter([
            'type' => self::TOOL_TYPE,
            'name' => self::TOOL_NAME,
            'mode' => $options['mode'] ?? null,
            'max_search_results' => $options['maxSearchResults'] ?? null,
            'from_date' => $options['fromDate'] ?? null,
            'to_date' => $options['toDate'] ?? null,
            'return_citations' => $options['returnCitations'] ?? null,
            'sources' => $sources,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private static function mapSource(array $source): array
    {
        $map = match ($source['type'] ?? null) {
            'web' => [
                'country' => 'country',
                'allowedWebsites' => 'allowed_websites',
                'excludedWebsites' => 'excluded_websites',
                'safeSearch' => 'safe_search',
            ],
            'news' => [
                'country' => 'country',
                'excludedWebsites' => 'excluded_websites',
                'safeSearch' => 'safe_search',
            ],
            'x' => [
                'includedXHandles' => 'included_x_handles',
                'excludedXHandles' => 'excluded_x_handles',
                'postFavoriteCount' => 'post_favorite_count',
                'postViewCount' => 'post_view_count',
            ],
            'rss' => ['links' => 'links'],
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown xAI live search source type "%s"; expected web, news, x or rss.',
                is_scalar($source['type'] ?? null) ? (string) $source['type'] : get_debug_type($source['type'] ?? null),
            )),
        };

        $mapped = ['type' => $source['type']];
        foreach ($map as $option => $field) {
            if (array_key_exists($option, $source) && $source[$option] !== null) {
                $mapped[$field] = $source[$option];
            }
        }

        return $mapped;
    }
}
