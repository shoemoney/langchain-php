<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Tools;

use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;

use function LangChain\Tools\tool;

/**
 * OpenAI's managed shell tool, for the Responses API.
 *
 * Port of `tools.shell` from `@langchain/openai`. Unlike the hosted-tool
 * builders this returns a real tool: the model calls `shell`, the `execute`
 * callable runs the commands, and its {@see ShellResult} array is serialised to
 * JSON for the tool message. `extras.providerToolDefinition` makes the chat
 * model bind it as `{type: "shell"}`.
 */
final class Shell
{
    public const TOOL_NAME = 'shell';

    private function __construct()
    {
    }

    /**
     * The argument schema: `commands`, optional `timeout_ms` and `max_output_length`.
     */
    public static function schema(): Schema
    {
        return Schema::object([
            'commands' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Array of shell commands to execute',
            ],
            'timeout_ms' => [
                'type' => 'number',
                'description' => 'Optional timeout in milliseconds for the commands',
            ],
            'max_output_length' => [
                'type' => 'number',
                'description' => 'Optional maximum number of characters to return from each command',
            ],
        ], ['commands']);
    }

    /**
     * @param array{execute: callable(array<string, mixed>): array{output: list<array<string, mixed>>, maxOutputLength?: int|null}} $options
     *        `execute` receives the action (`commands`, `timeout_ms`, `max_output_length`) and returns
     *        `['output' => [...], 'maxOutputLength' => ?int]`; each output item has `stdout`, `stderr`
     *        and an `outcome` of `['type' => 'exit', 'exit_code' => n]` or `['type' => 'timeout']`.
     */
    public static function create(array $options): StructuredTool
    {
        $execute = $options['execute'];

        return tool(
            static function (array $action) use ($execute): string {
                $result = $execute($action);
                $payload = ['output' => $result['output']];
                if (array_key_exists('maxOutputLength', $result)) {
                    $payload['max_output_length'] = $result['maxOutputLength'];
                }

                return (string) json_encode($payload, \JSON_UNESCAPED_SLASHES);
            },
            [
                'name' => self::TOOL_NAME,
                'description' => 'Execute shell commands in a managed environment. Commands can be run concurrently.',
                'schema' => self::schema(),
                'extras' => ['providerToolDefinition' => ['type' => 'shell']],
            ],
        );
    }
}
