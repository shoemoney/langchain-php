<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Tools;

use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;

use function LangChain\Tools\tool;

/**
 * OpenAI's local shell tool, for the Responses API.
 *
 * Port of `tools.localShell` from `@langchain/openai`. The `execute` callable
 * receives the `exec` action (`command` argv, optional `env`, `working_directory`,
 * `timeout_ms`, `user`) and returns the output string. The chat model binds the
 * tool as `{type: "local_shell"}`.
 */
final class LocalShell
{
    public const TOOL_NAME = 'local_shell';

    private function __construct()
    {
    }

    /**
     * The `exec` action schema (the only local shell action).
     */
    public static function schema(): Schema
    {
        return new Schema([
            'anyOf' => [[
                'type' => 'object',
                'properties' => [
                    'type' => ['const' => 'exec'],
                    'command' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'env' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    'working_directory' => ['type' => 'string'],
                    'timeout_ms' => ['type' => 'number'],
                    'user' => ['type' => 'string'],
                ],
                'required' => ['type', 'command'],
            ]],
        ]);
    }

    /**
     * @param array{execute: callable(array<string, mixed>): string} $options
     */
    public static function create(array $options): StructuredTool
    {
        $execute = $options['execute'];

        return tool(
            static fn (array $action): mixed => $execute($action),
            [
                'name' => self::TOOL_NAME,
                'description' => 'Execute shell commands locally on the machine. Commands are provided as argv tokens.',
                'schema' => self::schema(),
                'extras' => ['providerToolDefinition' => ['type' => 'local_shell']],
            ],
        );
    }
}
