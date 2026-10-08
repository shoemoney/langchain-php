<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\XAI\Tools;

/**
 * xAI's built-in web search tool (agentic tool calling API).
 *
 * Port of `tools/web_search.ts`. Enables the model to search the web and browse
 * pages. Executed server-side by the xAI API.
 */
final class WebSearch
{
    public const TOOL_TYPE = 'web_search';

    private function __construct()
    {
    }

    /**
     * Build the tool definition.
     *
     * Options (camelCase) are mapped to the snake_case API fields:
     * `allowedDomains` (max 5, exclusive with `excludedDomains`),
     * `excludedDomains` (max 5) and `enableImageUnderstanding`.
     *
     * @param array{allowedDomains?: list<string>, excludedDomains?: list<string>, enableImageUnderstanding?: bool} $options
     *
     * @return array<string, mixed>
     */
    public static function create(array $options = []): array
    {
        $tool = ['type' => self::TOOL_TYPE];

        if (array_key_exists('allowedDomains', $options)) {
            $tool['allowed_domains'] = $options['allowedDomains'];
        }

        if (array_key_exists('excludedDomains', $options)) {
            $tool['excluded_domains'] = $options['excludedDomains'];
        }

        if (array_key_exists('enableImageUnderstanding', $options)) {
            $tool['enable_image_understanding'] = $options['enableImageUnderstanding'];
        }

        return $tool;
    }
}
