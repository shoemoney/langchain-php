<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\XAI\Tools;

/**
 * xAI's built-in code execution tool (agentic tool calling API).
 *
 * Port of `tools/code_execution.ts`. The model writes and runs Python in a
 * server-side sandbox. The Responses API names the type `code_interpreter`.
 */
final class CodeExecution
{
    public const TOOL_TYPE = 'code_interpreter';

    private function __construct()
    {
    }

    /**
     * @return array{type: string}
     */
    public static function create(): array
    {
        return ['type' => self::TOOL_TYPE];
    }
}
