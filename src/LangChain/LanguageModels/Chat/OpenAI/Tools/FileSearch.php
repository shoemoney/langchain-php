<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Tools;

/**
 * OpenAI's hosted file search tool, for the Responses API.
 *
 * Port of `tools.fileSearch` from `@langchain/openai`. Filters are comparison
 * (`eq ne gt gte lt lte`) or compound (`and or`) arrays and pass through as given.
 */
final class FileSearch
{
    private function __construct()
    {
    }

    /**
     * @param array{
     *     vectorStoreIds: list<string>,
     *     maxNumResults?: int,
     *     filters?: array<string, mixed>,
     *     rankingOptions?: array{
     *         ranker?: 'auto'|'default-2024-11-15',
     *         scoreThreshold?: float|int,
     *         hybridSearch?: array{embeddingWeight: float|int, textWeight: float|int}
     *     }
     * } $options
     *
     * @return array<string, mixed>
     */
    public static function tool(array $options): array
    {
        return array_filter([
            'type' => 'file_search',
            'vector_store_ids' => $options['vectorStoreIds'],
            'max_num_results' => $options['maxNumResults'] ?? null,
            'filters' => $options['filters'] ?? null,
            'ranking_options' => self::rankingOptions($options['rankingOptions'] ?? null),
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed>|null $options
     *
     * @return array<string, mixed>|null
     */
    private static function rankingOptions(?array $options): ?array
    {
        if ($options === null) {
            return null;
        }

        $hybrid = $options['hybridSearch'] ?? null;

        return array_filter([
            'ranker' => $options['ranker'] ?? null,
            'score_threshold' => $options['scoreThreshold'] ?? null,
            'hybrid_search' => $hybrid !== null
                ? ['embedding_weight' => $hybrid['embeddingWeight'], 'text_weight' => $hybrid['textWeight']]
                : null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
