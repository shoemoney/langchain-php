<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Tools;

use LangChain\Tools\StructuredTool;

/**
 * Anthropic bash tool (`tools/bash.ts`).
 */
final class Bash
{
    /**
     * @param array{execute?: callable} $options `execute` receives `['command' => ...]` or `['restart' => true]`
     */
    public static function bash_20250124(array $options = []): StructuredTool
    {
        return ClientTool::make(
            'bash',
            'A tool for executing bash commands',
            Types::bash20250124Command(),
            ['type' => 'bash_20250124', 'name' => 'bash'],
            $options['execute'] ?? null,
        );
    }
}
