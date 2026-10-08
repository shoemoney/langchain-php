<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic\Tools;

use LangChain\LanguageModels\Chat\Anthropic\Tools\TextEditor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextEditor::class)]
final class TextEditorTest extends TestCase
{
    public function testCreatesAValidTextEditorToolWithNoOptions(): void
    {
        $editor = TextEditor::textEditor_20250728();

        self::assertSame('str_replace_based_edit_tool', $editor->name);
        self::assertSame(
            ['type' => 'text_editor_20250728', 'name' => 'str_replace_based_edit_tool'],
            $editor->extras['providerToolDefinition'],
        );
    }

    public function testCreatesAValidTextEditorToolWithMaxCharacters(): void
    {
        $editor = TextEditor::textEditor_20250728(['maxCharacters' => 10000]);

        self::assertSame(
            ['type' => 'text_editor_20250728', 'name' => 'str_replace_based_edit_tool', 'max_characters' => 10000],
            $editor->extras['providerToolDefinition'],
        );
    }

    public function testCreatesAValidTextEditorToolWithExecuteFunction(): void
    {
        $editor = TextEditor::textEditor_20250728([
            'execute' => static fn (array $args): string => "Executed {$args['command']} on {$args['path']}",
        ]);

        self::assertSame('str_replace_based_edit_tool', $editor->name);
        self::assertSame('Executed view on /a.txt', $editor->invoke(['command' => 'view', 'path' => '/a.txt']));
    }
}
