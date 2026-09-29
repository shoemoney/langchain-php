<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prompts;

use LangChain\Prompts\DictPromptTemplate;
use LangChain\Prompts\Template;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `prompts/tests/dict.test.ts`.
 *
 * The upstream suite finishes each case by round-tripping through `load()`, the
 * serialisation loader. That loader is not part of this port, so the round trip
 * here is through the class's own serialized form instead — the `id` and `kwargs`
 * the loader would have read back.
 */
#[CoversClass(DictPromptTemplate::class)]
final class DictPromptTemplateTest extends TestCase
{
    public function testFormatsDictsWithFStringTemplateValues(): void
    {
        $template = [
            'type' => 'text',
            'text' => '{text1}',
            'cache_control' => ['type' => 'ephemeral'],
            'hello' => 2,
            'booleano' => true,
        ];

        $prompt = new DictPromptTemplate($template, Template::F_STRING);

        self::assertSame([
            'type' => 'text',
            'text' => 'important message',
            'cache_control' => ['type' => 'ephemeral'],
            'hello' => 2,
            'booleano' => true,
        ], $prompt->format(['text1' => 'important message', 'name1' => 'foo']));

        self::assertSame([
            'lc' => 1,
            'type' => 'constructor',
            'id' => ['langchain_core', 'prompts', 'dict', 'DictPromptTemplate'],
            'kwargs' => [
                'input_variables' => ['text1'],
                'template' => $template,
                'template_format' => 'f-string',
            ],
        ], $prompt->toJson());

        // The serialized form is what a reload rebuilds from.
        $reloaded = new DictPromptTemplate(
            $prompt->toJson()['kwargs']['template'],
            $prompt->toJson()['kwargs']['template_format']
        );
        self::assertSame(
            $prompt->format(['text1' => 'important message', 'name1' => 'foo']),
            $reloaded->format(['text1' => 'important message', 'name1' => 'foo'])
        );
    }

    public function testFormatsDictsWithMustacheTemplateValues(): void
    {
        $template = [
            'type' => 'text',
            'text' => '{{text1}}',
            'cache_control' => ['type' => 'ephemeral'],
            'mimeType' => '{{mimeType}}',
            'hello' => 2,
            'booleano' => true,
        ];

        $prompt = new DictPromptTemplate($template, Template::MUSTACHE);

        self::assertSame([
            'type' => 'text',
            'text' => 'important message',
            'cache_control' => ['type' => 'ephemeral'],
            'mimeType' => 'text/plain',
            'hello' => 2,
            'booleano' => true,
        ], $prompt->format([
            'text1' => 'important message',
            'name1' => 'foo',
            'mimeType' => 'text/plain',
        ]));

        self::assertSame([
            'lc' => 1,
            'type' => 'constructor',
            'id' => ['langchain_core', 'prompts', 'dict', 'DictPromptTemplate'],
            'kwargs' => [
                'input_variables' => ['text1', 'mimeType'],
                'template' => $template,
                'template_format' => 'mustache',
            ],
        ], $prompt->toJson());
    }

    public function testTemplateFormatDefaultsToFString(): void
    {
        $prompt = new DictPromptTemplate(['text' => '{foo}']);

        self::assertSame(Template::F_STRING, $prompt->templateFormat);
        self::assertSame(['foo'], $prompt->inputVariables);
    }

    public function testVariablesAreCollectedFromNestedListsAndMaps(): void
    {
        $prompt = new DictPromptTemplate([
            'text' => '{a}',
            'audio' => ['path' => '{b}'],
            'items' => ['{c}', ['deep' => '{d}']],
            'untouched' => 5,
        ]);

        self::assertSame(['a', 'b', 'c', 'd'], $prompt->inputVariables);
    }
}
