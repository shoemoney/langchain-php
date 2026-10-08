<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Tools;

/**
 * OpenAI's tool search tool (server- or client-executed), for the Responses API.
 *
 * Port of `tools.toolSearch` from `@langchain/openai`. Like upstream's truthiness
 * checks, an empty `execution` or `description` is left out; `parameters` is
 * kept whenever it is not null.
 */
final class ToolSearch
{
    private function __construct()
    {
    }

    /**
     * @param array{execution?: 'server'|'client', description?: string, parameters?: mixed} $options
     *
     * @return array<string, mixed>
     */
    public static function tool(array $options = []): array
    {
        $tool = ['type' => 'tool_search'];

        if (!empty($options['execution'])) {
            $tool['execution'] = $options['execution'];
        }
        if (!empty($options['description'])) {
            $tool['description'] = $options['description'];
        }
        if (($options['parameters'] ?? null) !== null) {
            $tool['parameters'] = $options['parameters'];
        }

        return $tool;
    }
}
