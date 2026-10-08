<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Tools;

use LangChain\Tools\StructuredTool;

/**
 * Anthropic memory tool (`tools/memory.ts`). Upstream gives it no description.
 */
final class Memory
{
    /**
     * @param array{execute?: callable} $options
     */
    public static function memory_20250818(array $options = []): StructuredTool
    {
        return ClientTool::make(
            'memory',
            null,
            Types::memory20250818Command(),
            ['type' => 'memory_20250818', 'name' => 'memory'],
            $options['execute'] ?? null,
        );
    }
}
