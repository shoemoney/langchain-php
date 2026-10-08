<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Tools;

use LangChain\Tools\StructuredTool;

/**
 * Anthropic text editor tool (`tools/textEditor.ts`).
 */
final class TextEditor
{
    /**
     * @param array{execute?: callable, maxCharacters?: int} $options
     */
    public static function textEditor_20250728(array $options = []): StructuredTool
    {
        $name = 'str_replace_based_edit_tool';

        return ClientTool::make(
            $name,
            'A tool for editing text files',
            Types::textEditor20250728Command(),
            ServerToolDefinition::withoutNulls([
                'type' => 'text_editor_20250728',
                'name' => $name,
                'max_characters' => $options['maxCharacters'] ?? null,
            ]),
            $options['execute'] ?? null,
        );
    }
}
