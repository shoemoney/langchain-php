<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Tools;

/**
 * JSON Schemas for the arguments of Anthropic's client-executed tools, and the beta flag each
 * tool type needs (`tools/types.ts`, `utils/tools.ts`).
 *
 * Upstream expresses these as zod discriminated unions; the JSON Schema equivalent is `anyOf`
 * over one object schema per discriminator value, which the port's `Schema` validates.
 */
final class Types
{
    /**
     * Tool type => the `anthropic-beta` flag it requires.
     */
    public const TOOL_BETAS = [
        'tool_search_tool_regex_20251119' => 'advanced-tool-use-2025-11-20',
        'tool_search_tool_bm25_20251119' => 'advanced-tool-use-2025-11-20',
        'memory_20250818' => 'context-management-2025-06-27',
        'web_fetch_20250910' => 'web-fetch-2025-09-10',
        'code_execution_20250825' => 'code-execution-2025-08-25',
        'computer_20251124' => 'computer-use-2025-11-24',
        'computer_20250124' => 'computer-use-2025-01-24',
        'mcp_toolset' => 'mcp-client-2025-11-20',
    ];

    /**
     * The beta flags needed by a list of converted tool definitions, de-duplicated, in first-seen order.
     *
     * @param list<mixed> $tools
     *
     * @return list<string>
     */
    public static function betasFor(array $tools): array
    {
        $betas = [];
        foreach ($tools as $tool) {
            $type = is_array($tool) ? ($tool['type'] ?? null) : null;
            if (is_string($type) && isset(self::TOOL_BETAS[$type])) {
                $betas[self::TOOL_BETAS[$type]] = true;
            }
        }

        return array_keys($betas);
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     *
     * @return array<string, mixed>
     */
    private static function object(array $properties, array $required): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }

    /**
     * @return array<string, mixed>
     */
    private static function command(string $key, string $value, array $properties = [], array $required = []): array
    {
        return self::object(
            [$key => ['type' => 'string', 'const' => $value]] + $properties,
            [$key, ...$required],
        );
    }

    /** @return array<string, mixed> */
    private static function coordinate(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'number'], 'minItems' => 2, 'maxItems' => 2];
    }

    /** @return array<string, mixed> */
    public static function memory20250818Command(): array
    {
        $str = ['type' => 'string'];

        return ['anyOf' => [
            self::command('command', 'view', ['path' => $str], ['path']),
            self::command('command', 'create', ['path' => $str, 'file_text' => $str], ['path', 'file_text']),
            self::command('command', 'str_replace', ['path' => $str, 'old_str' => $str, 'new_str' => $str], ['path', 'old_str', 'new_str']),
            self::command('command', 'insert', ['path' => $str, 'insert_line' => ['type' => 'number'], 'insert_text' => $str], ['path', 'insert_line', 'insert_text']),
            self::command('command', 'delete', ['path' => $str], ['path']),
            self::command('command', 'rename', ['old_path' => $str, 'new_path' => $str], ['old_path', 'new_path']),
        ]];
    }

    /** @return array<string, mixed> */
    public static function textEditor20250728Command(): array
    {
        $str = ['type' => 'string'];

        return ['anyOf' => [
            self::command('command', 'view', [
                'path' => $str,
                'view_range' => ['type' => 'array', 'items' => ['type' => 'number'], 'minItems' => 2, 'maxItems' => 2],
            ], ['path']),
            self::command('command', 'str_replace', ['path' => $str, 'old_str' => $str, 'new_str' => $str], ['path', 'old_str', 'new_str']),
            self::command('command', 'create', ['path' => $str, 'file_text' => $str], ['path', 'file_text']),
            self::command('command', 'insert', ['path' => $str, 'insert_line' => ['type' => 'number'], 'new_str' => $str], ['path', 'insert_line', 'new_str']),
        ]];
    }

    /** @return array<string, mixed> */
    public static function computer20250124Action(): array
    {
        $at = ['coordinate' => self::coordinate()];
        $key = ['key' => ['type' => 'string']];

        return ['anyOf' => [
            self::command('action', 'screenshot'),
            self::command('action', 'left_click', $at, ['coordinate']),
            self::command('action', 'right_click', $at, ['coordinate']),
            self::command('action', 'middle_click', $at, ['coordinate']),
            self::command('action', 'double_click', $at, ['coordinate']),
            self::command('action', 'triple_click', $at, ['coordinate']),
            self::command('action', 'left_click_drag', [
                'start_coordinate' => self::coordinate(),
                'end_coordinate' => self::coordinate(),
            ], ['start_coordinate', 'end_coordinate']),
            self::command('action', 'left_mouse_down', $at, ['coordinate']),
            self::command('action', 'left_mouse_up', $at, ['coordinate']),
            self::command('action', 'scroll', $at + [
                'scroll_direction' => ['type' => 'string', 'enum' => ['up', 'down', 'left', 'right']],
                'scroll_amount' => ['type' => 'number'],
            ], ['coordinate', 'scroll_direction', 'scroll_amount']),
            self::command('action', 'type', ['text' => ['type' => 'string']], ['text']),
            self::command('action', 'key', $key, ['key']),
            self::command('action', 'mouse_move', $at, ['coordinate']),
            self::command('action', 'hold_key', $key, ['key']),
            self::command('action', 'wait', ['duration' => ['type' => 'number']]),
        ]];
    }

    /** @return array<string, mixed> */
    public static function computer20251124Action(): array
    {
        $schema = self::computer20250124Action();
        $schema['anyOf'][] = self::command('action', 'zoom', [
            'region' => ['type' => 'array', 'items' => ['type' => 'number'], 'minItems' => 4, 'maxItems' => 4],
        ], ['region']);

        return $schema;
    }

    /** @return array<string, mixed> */
    public static function bash20250124Command(): array
    {
        return ['anyOf' => [
            self::object(['command' => ['type' => 'string', 'description' => 'The bash command to run']], ['command']),
            self::object(['restart' => ['type' => 'boolean', 'const' => true, 'description' => 'Set to true to restart the bash session']], ['restart']),
        ]];
    }
}
