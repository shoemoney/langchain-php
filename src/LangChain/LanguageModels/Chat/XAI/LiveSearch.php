<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\XAI;

/**
 * Helpers for xAI's Live Search API.
 *
 * Port of `live_search.ts` from `@langchain/xai`. Search parameters are plain
 * snake_case arrays, exactly the shape the API takes:
 *
 *     ['mode' => 'auto'|'on'|'off', 'max_search_results' => 20, 'from_date' => '2024-01-01',
 *      'to_date' => '2024-12-31', 'return_citations' => true, 'sources' => [...]]
 *
 * A source is `['type' => 'web'|'news'|'x'|'rss', ...]`:
 *
 *  - `web`:  `country`, `excluded_websites` (max 5), `allowed_websites` (max 5), `safe_search`
 *  - `news`: `country`, `excluded_websites` (max 5), `safe_search`
 *  - `x`:    `included_x_handles` (max 10), `excluded_x_handles` (max 10),
 *            `post_favorite_count`, `post_view_count`
 *  - `rss`:  `links`
 *
 * The Live Search API is deprecated by xAI in favour of the agentic tools in
 * {@see Tools\WebSearch} and {@see Tools\XSearch}.
 */
final class LiveSearch
{
    private function __construct()
    {
    }

    /**
     * Merge search parameters from the instance defaults, a tool definition and
     * per-call overrides.
     *
     * Precedence (lowest to highest): tool, instance, call. Null means "not
     * given"; an empty array is given and yields an empty merge.
     *
     * @param array<string, mixed>|null $instanceParams
     * @param array<string, mixed>|null $callParams
     * @param array<string, mixed>|null $toolParams
     *
     * @return array<string, mixed>|null
     */
    public static function mergeSearchParams(?array $instanceParams = null, ?array $callParams = null, ?array $toolParams = null): ?array
    {
        if ($instanceParams === null && $callParams === null && $toolParams === null) {
            return null;
        }

        return array_merge($toolParams ?? [], $instanceParams ?? [], $callParams ?? []);
    }

    /**
     * Build the `search_parameters` payload sent to the xAI API.
     *
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>|null
     */
    public static function buildSearchParametersPayload(?array $params = null): ?array
    {
        if ($params === null) {
            return null;
        }

        $payload = ['mode' => $params['mode'] ?? 'auto'];

        foreach (['max_search_results', 'from_date', 'to_date', 'return_citations'] as $key) {
            if (isset($params[$key])) {
                $payload[$key] = $params[$key];
            }
        }

        if (isset($params['sources']) && is_array($params['sources']) && $params['sources'] !== []) {
            $payload['sources'] = $params['sources'];
        }

        return $payload;
    }

    /**
     * Filter xAI built-in tools (like `live_search`) out of a tools list.
     *
     * Used before the request is sent, since built-in tools are controlled via
     * `search_parameters` instead. Entries that are not arrays, or have no
     * `type`, are kept. Returns null when nothing is left.
     *
     * @param array{tools?: list<mixed>|null, excludedTypes?: list<string>|null}|null $payload
     *
     * @return list<mixed>|null
     */
    public static function filterXAIBuiltInTools(?array $payload = null): ?array
    {
        if ($payload === null || !isset($payload['tools']) || !is_array($payload['tools'])) {
            return null;
        }

        $excluded = $payload['excludedTypes'] ?? [];

        $filtered = array_values(array_filter(
            $payload['tools'],
            static function (mixed $tool) use ($excluded): bool {
                if (!is_array($tool) || !array_key_exists('type', $tool) || $excluded === []) {
                    return true;
                }

                return !in_array($tool['type'], $excluded, true);
            },
        ));

        return $filtered !== [] ? $filtered : null;
    }
}
