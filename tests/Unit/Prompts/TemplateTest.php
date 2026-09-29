<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prompts;

use LangChain\Prompts\Mustache;
use LangChain\Prompts\PromptInputError;
use LangChain\Prompts\Template;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `prompts/tests/template.test.ts` and `prompts/tests/template.mustache.test.ts`.
 */
#[CoversClass(Template::class)]
#[CoversClass(Mustache::class)]
#[CoversClass(PromptInputError::class)]
final class TemplateTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: array<string, string>, 2: string}>
     */
    public static function validFStrings(): array
    {
        return [
            ['{foo}', ['foo' => 'bar'], 'bar'],
            ['pre{foo}post', ['foo' => 'bar'], 'prebarpost'],
            ['{{pre{foo}post}}', ['foo' => 'bar'], '{prebarpost}'],
            ['text', [], 'text'],
            ['}}{{', [], '}{'],
            ['{first}_{second}', ['first' => 'foo', 'second' => 'bar'], 'foo_bar'],
        ];
    }

    #[DataProvider('validFStrings')]
    public function testValidFStringInterpolation(string $template, array $values, string $expected): void
    {
        self::assertSame($expected, Template::interpolateFString($template, $values));
    }

    /**
     * @return list<array{0: string, 1: array<string, string>}>
     */
    public static function invalidFStrings(): array
    {
        return [
            ['{', []],
            ['}', []],
            ['{foo', []],
            ['foo}', []],
        ];
    }

    #[DataProvider('invalidFStrings')]
    public function testInvalidFStringInterpolationThrows(string $template, array $values): void
    {
        $this->expectException(\RuntimeException::class);

        Template::interpolateFString($template, $values);
    }

    public function testMissingVariableIsReportedByName(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('(f-string) Missing value for input foo');

        Template::interpolateFString('{foo}', []);
    }

    public function testNonStringValuesAreJsonEncoded(): void
    {
        self::assertSame(
            '["barbar"]',
            Template::interpolateFString('{foo}', ['foo' => ['barbar']])
        );
    }

    public function testRenderTemplateTagsFailuresAsPromptInputErrors(): void
    {
        try {
            Template::renderTemplate('{foo}', Template::F_STRING, []);
            self::fail('expected a PromptInputError');
        } catch (PromptInputError $e) {
            self::assertSame('INVALID_PROMPT_INPUT', $e->lcErrorCode);
            self::assertSame('(f-string) Missing value for input foo', $e->getMessage());
        }
    }

    public function testUnknownTemplateFormatIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid template format. Got `handlebars`');

        Template::renderTemplate('{foo}', 'handlebars', ['foo' => 'bar']);
    }

    public function testCheckValidTemplateAcceptsAMatchingVariableSet(): void
    {
        Template::checkValidTemplate('{foo} and {bar}', Template::F_STRING, ['foo', 'bar']);

        $this->addToAssertionCount(1);
    }

    public function testCheckValidTemplateRejectsAnUndeclaredVariable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid prompt schema: (f-string) Missing value for input baz');

        Template::checkValidTemplate('{foo} and {baz}', Template::F_STRING, ['foo']);
    }

    public function testCheckValidTemplateRejectsAnUnknownFormat(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('should be one of f-string, mustache');

        Template::checkValidTemplate('{foo}', 'handlebars', ['foo']);
    }

    public function testCheckValidTemplateRejectsAnUnrenderableContentBlock(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid prompt schema: Invalid message template received.');

        Template::checkValidTemplate(
            [['type' => 'audio', 'audio' => ['path' => 'sound.mp3']]],
            Template::F_STRING,
            []
        );
    }

    public function testTemplateVariablesAreReturnedInFirstAppearanceOrder(): void
    {
        self::assertSame(
            ['bar', 'foo'],
            Template::templateVariables('This {bar} is a {foo} test {foo}.', Template::F_STRING)
        );
    }

    private const HTML_UNSAFE = '1 < 2 & 3 > 2';

    public function testMustacheTemplatesRenderPromptValuesWithoutHtmlEscaping(): void
    {
        self::assertSame(
            'Hello ' . self::HTML_UNSAFE . '!',
            Template::interpolateMustache('Hello {{name}}!', ['name' => self::HTML_UNSAFE])
        );

        self::assertSame(
            'Hello ' . self::HTML_UNSAFE . '!',
            \LangChain\Prompts\PromptTemplate::fromTemplate('Hello {{name}}!', [
                'templateFormat' => Template::MUSTACHE,
            ])->format(['name' => self::HTML_UNSAFE])
        );
    }

    public function testParsingAMustacheTemplateKeepsLiteralsAndVariables(): void
    {
        $parsed = Template::parseTemplate('test: {{{text}}}', Template::MUSTACHE);

        self::assertSame([
            ['type' => 'literal', 'text' => 'test: '],
            ['type' => 'variable', 'name' => 'text'],
        ], $parsed);
    }

    public function testMustacheInvertedSectionRendersOnlyWhenAbsent(): void
    {
        // Mustache's inverted section renders for every falsy-or-present value
        // EXCEPT a non-empty list, which is the case it exists to catch.
        self::assertSame('yes', Mustache::render('{{^missing}}yes{{/missing}}', []));
        self::assertSame('yes', Mustache::render('{{^present}}yes{{/present}}', ['present' => 'x']));
        self::assertSame('yes', Mustache::render('{{^present}}yes{{/present}}', ['present' => []]));
        self::assertSame('', Mustache::render('{{^present}}yes{{/present}}', ['present' => ['a']]));
    }

    public function testMustachePartialsAreRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mustache partials are not supported by the PHP port');

        Mustache::render('{{>header}}', []);
    }
}
