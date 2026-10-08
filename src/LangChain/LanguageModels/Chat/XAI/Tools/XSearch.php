<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\XAI\Tools;

/**
 * xAI's built-in X (formerly Twitter) search tool (agentic tool calling API).
 *
 * Port of `tools/x_search.ts`. Enables keyword search, semantic search, user
 * search and thread fetch on X. Executed server-side by the xAI API.
 */
final class XSearch
{
    public const TOOL_TYPE = 'x_search';

    /** camelCase option => snake_case API field. */
    private const FIELDS = [
        'allowedXHandles' => 'allowed_x_handles',
        'excludedXHandles' => 'excluded_x_handles',
        'fromDate' => 'from_date',
        'toDate' => 'to_date',
        'enableImageUnderstanding' => 'enable_image_understanding',
        'enableVideoUnderstanding' => 'enable_video_understanding',
    ];

    private function __construct()
    {
    }

    /**
     * Build the tool definition.
     *
     * Options: `allowedXHandles` (max 10, exclusive with `excludedXHandles`),
     * `excludedXHandles` (max 10), `fromDate` / `toDate` (`YYYY-MM-DD`),
     * `enableImageUnderstanding`, `enableVideoUnderstanding`.
     *
     * @param array{allowedXHandles?: list<string>, excludedXHandles?: list<string>, fromDate?: string, toDate?: string, enableImageUnderstanding?: bool, enableVideoUnderstanding?: bool} $options
     *
     * @return array<string, mixed>
     */
    public static function create(array $options = []): array
    {
        $tool = ['type' => self::TOOL_TYPE];

        foreach (self::FIELDS as $option => $field) {
            if (array_key_exists($option, $options)) {
                $tool[$field] = $options[$option];
            }
        }

        return $tool;
    }
}
