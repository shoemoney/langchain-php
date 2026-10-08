<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\XAI\Tools;

/**
 * xAI's built-in collections search tool (agentic tool calling API).
 *
 * Port of `tools/collections_search.ts`. Searches uploaded knowledge bases
 * (collections). The Responses API names the type `file_search`.
 */
final class CollectionsSearch
{
    public const TOOL_TYPE = 'file_search';

    private function __construct()
    {
    }

    /**
     * Build the tool definition.
     *
     * `vectorStoreIds` (the ids of collections created through the xAI
     * Collections API) becomes `vector_store_ids`.
     *
     * @param array{vectorStoreIds?: list<string>} $options
     *
     * @return array<string, mixed>
     */
    public static function create(array $options = []): array
    {
        $tool = ['type' => self::TOOL_TYPE];

        if (array_key_exists('vectorStoreIds', $options)) {
            $tool['vector_store_ids'] = $options['vectorStoreIds'];
        }

        return $tool;
    }
}
