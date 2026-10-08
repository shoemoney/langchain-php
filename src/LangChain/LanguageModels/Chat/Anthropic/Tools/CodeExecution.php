<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Tools;

/**
 * Anthropic code execution server tool (`tools/codeExecution.ts`).
 */
final class CodeExecution
{
    /**
     * @param array{cacheControl?: array<string, mixed>} $options
     *
     * @return array<string, mixed>
     */
    public static function codeExecution_20250825(array $options = []): array
    {
        return ServerToolDefinition::withoutNulls([
            'type' => 'code_execution_20250825',
            'name' => 'code_execution',
            'cache_control' => $options['cacheControl'] ?? null,
        ]);
    }
}
