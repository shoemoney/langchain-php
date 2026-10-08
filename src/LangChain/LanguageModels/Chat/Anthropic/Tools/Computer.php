<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Tools;

use LangChain\Tools\StructuredTool;

/**
 * Anthropic computer use tools (`tools/computer.ts`).
 */
final class Computer
{
    private const TOOL_NAME = 'computer';

    /**
     * Claude Opus 4.5 and later; adds the `zoom` action.
     *
     * @param array{displayWidthPx: int, displayHeightPx: int, displayNumber?: int, enableZoom?: bool, execute?: callable} $options
     */
    public static function computer_20251124(array $options): StructuredTool
    {
        return ClientTool::make(
            self::TOOL_NAME,
            null,
            Types::computer20251124Action(),
            ServerToolDefinition::withoutNulls([
                'type' => 'computer_20251124',
                'name' => self::TOOL_NAME,
                'display_width_px' => self::dimension($options, 'displayWidthPx'),
                'display_height_px' => self::dimension($options, 'displayHeightPx'),
                'display_number' => $options['displayNumber'] ?? null,
                'enable_zoom' => $options['enableZoom'] ?? null,
            ]),
            $options['execute'] ?? null,
        );
    }

    /**
     * Claude 4 and Claude 3.7.
     *
     * @param array{displayWidthPx: int, displayHeightPx: int, displayNumber?: int, execute?: callable} $options
     */
    public static function computer_20250124(array $options): StructuredTool
    {
        return ClientTool::make(
            self::TOOL_NAME,
            'A tool for interacting with the computer',
            Types::computer20250124Action(),
            ServerToolDefinition::withoutNulls([
                'type' => 'computer_20250124',
                'name' => self::TOOL_NAME,
                'display_width_px' => self::dimension($options, 'displayWidthPx'),
                'display_height_px' => self::dimension($options, 'displayHeightPx'),
                'display_number' => $options['displayNumber'] ?? null,
            ]),
            $options['execute'] ?? null,
        );
    }

    /** @param array<string, mixed> $options */
    private static function dimension(array $options, string $key): int
    {
        if (!isset($options[$key])) {
            throw new \InvalidArgumentException("The computer tool requires {$key}.");
        }

        return (int) $options[$key];
    }
}
