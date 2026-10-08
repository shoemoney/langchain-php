<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Tools;

use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;

use function LangChain\Tools\tool;

/**
 * OpenAI's apply_patch tool, for the Responses API.
 *
 * Port of `tools.applyPatch` from `@langchain/openai`. The `execute` callable
 * receives one operation (`create_file` / `update_file` with `path` and `diff`,
 * or `delete_file` with `path`) and returns a result string. The chat model
 * binds the tool as `{type: "apply_patch"}`.
 */
final class ApplyPatch
{
    public const TOOL_NAME = 'apply_patch';

    private function __construct()
    {
    }

    /**
     * The union of the three operation schemas.
     */
    public static function schema(): Schema
    {
        $withDiff = static fn (string $type): array => [
            'type' => 'object',
            'properties' => [
                'type' => ['const' => $type],
                'path' => ['type' => 'string'],
                'diff' => ['type' => 'string'],
            ],
            'required' => ['type', 'path', 'diff'],
        ];

        return new Schema([
            'anyOf' => [
                $withDiff('create_file'),
                $withDiff('update_file'),
                [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['const' => 'delete_file'],
                        'path' => ['type' => 'string'],
                    ],
                    'required' => ['type', 'path'],
                ],
            ],
        ]);
    }

    /**
     * @param array{execute: callable(array<string, mixed>): string} $options
     */
    public static function create(array $options): StructuredTool
    {
        $execute = $options['execute'];

        return tool(
            static fn (array $operation): mixed => $execute($operation),
            [
                'name' => self::TOOL_NAME,
                'description' => 'Apply structured diffs to create, update, or delete files in the codebase.',
                'schema' => self::schema(),
                'extras' => ['providerToolDefinition' => ['type' => 'apply_patch']],
            ],
        );
    }
}
