<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prompts;

use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\BasePromptTemplate;
use LangChain\Prompts\PromptInputError;
use LangChain\Prompts\PromptTemplate;
use LangChain\Prompts\StringPromptTemplate;
use LangChain\Prompts\Template;
use LangChain\Schema\StringPromptValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `prompts/tests/prompt.test.ts` and `prompts/tests/prompt.mustache.test.ts`.
 */
#[CoversClass(PromptTemplate::class)]
#[CoversClass(StringPromptTemplate::class)]
#[CoversClass(BasePromptTemplate::class)]
#[CoversClass(PromptInputError::class)]
final class PromptTemplateTest extends TestCase
{
    public function testUsingPartial(): void
    {
        $prompt = new PromptTemplate(
            template: '{foo}{bar}',
            inputVariables: ['foo'],
            partialVariables: ['bar' => 'baz'],
        );

        self::assertSame('foobaz', $prompt->format(['foo' => 'foo']));
    }

    public function testUsingPartialWithAnExtraVariable(): void
    {
        $prompt = new PromptTemplate(
            template: '{foo}{bar}',
            inputVariables: ['foo'],
            partialVariables: ['bar' => 'baz'],
        );

        self::assertSame('foobaz', $prompt->format(['foo' => 'foo', 'unused' => 'nada']));
    }

    public function testFromTemplate(): void
    {
        $prompt = PromptTemplate::fromTemplate('{foo}{bar}');

        $value = $prompt->invoke(['foo' => 'foo', 'bar' => 'baz', 'unused' => 'eee']);
        self::assertInstanceOf(StringPromptValue::class, $value);
        self::assertSame('foobaz', $value->toStringValue());
    }

    public function testFromTemplateWithANonStringValue(): void
    {
        $prompt = PromptTemplate::fromTemplate('{foo}{bar}');

        self::assertSame(
            '["barbar"][{"pageContent":"bar","metadata":[]}]',
            $prompt->format([
                'foo' => ['barbar'],
                'bar' => [['pageContent' => 'bar', 'metadata' => []]],
            ])
        );
    }

    public function testFromTemplateWithEscapedStrings(): void
    {
        $prompt = PromptTemplate::fromTemplate('{{foo}}{{bar}}');

        self::assertSame('{foo}{bar}', $prompt->format(['unused' => 'eee']));
    }

    public function testFromTemplateWithATypeParameterHasNoVariables(): void
    {
        $prompt = PromptTemplate::fromTemplate('test');

        self::assertSame([], $prompt->inputVariables);
        self::assertSame('test', $prompt->format(['unused' => 'eee']));
    }

    public function testFromTemplateWithAMissingVariableThrows(): void
    {
        $prompt = PromptTemplate::fromTemplate('{foo}');

        $this->expectException(PromptInputError::class);

        $prompt->format(['unused' => 'eee']);
    }

    public function testInvokeWithAMissingVariableThrows(): void
    {
        $prompt = PromptTemplate::fromTemplate('{foo}');

        $this->expectException(PromptInputError::class);

        $prompt->invoke(['unused' => 'eee']);
    }

    public function testFromTemplateWithAnExtraVariableWorks(): void
    {
        $prompt = PromptTemplate::fromTemplate('{foo}');

        self::assertSame('test', $prompt->format(['foo' => 'test', 'unused' => 'eee']));
        self::assertSame('test', $prompt->invoke(['foo' => 'test', 'unused' => 'eee'])->toStringValue());
    }

    public function testUsingFullPartial(): void
    {
        $prompt = new PromptTemplate(
            template: '{foo}{bar}',
            inputVariables: [],
            partialVariables: ['bar' => 'baz', 'foo' => 'boo'],
        );

        self::assertSame('boobaz', $prompt->format([]));
    }

    public function testPartial(): void
    {
        $prompt = new PromptTemplate(template: '{foo}{bar}', inputVariables: ['foo', 'bar']);

        self::assertSame(['foo', 'bar'], $prompt->inputVariables);

        $partialPrompt = $prompt->partial(['foo' => 'foo']);

        // The original prompt is untouched.
        self::assertSame(['foo', 'bar'], $prompt->inputVariables);
        // The partial prompt has only the remaining variables.
        self::assertSame(['bar'], $partialPrompt->inputVariables);
        self::assertSame('foobaz', $partialPrompt->format(['bar' => 'baz']));
    }

    public function testPartialWithFunction(): void
    {
        $prompt = new PromptTemplate(template: '{foo}{bar}', inputVariables: ['foo', 'bar']);

        $partialPrompt = $prompt->partial(['foo' => static fn (): string => 'boo']);

        self::assertSame('boobaz', $partialPrompt->format(['bar' => 'baz']));
    }

    public function testCallerValuesWinOverPartials(): void
    {
        $prompt = new PromptTemplate(
            template: '{foo}',
            inputVariables: ['foo'],
            partialVariables: ['foo' => 'default'],
        );

        self::assertSame('override', $prompt->format(['foo' => 'override']));
    }

    public function testRejectsTheReservedStopVariable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot have an input variable named 'stop'");

        new PromptTemplate(template: '{stop}', inputVariables: ['stop']);
    }

    public function testValidationCatchesAnUndeclaredVariable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid prompt schema: (f-string) Missing value for input baz');

        new PromptTemplate(template: '{foo}{baz}', inputVariables: ['foo']);
    }

    public function testValidationCanBeTurnedOff(): void
    {
        $prompt = new PromptTemplate(
            template: '{foo}{baz}',
            inputVariables: ['foo'],
            validateTemplate: false,
        );

        self::assertSame(['foo'], $prompt->inputVariables);
    }

    public function testFromExamples(): void
    {
        $prompt = PromptTemplate::fromExamples(
            ['a is A', 'b is B'],
            '{question}?',
            ['question'],
        );

        // The empty prefix still contributes a separator, exactly as the
        // TypeScript `join()` does.
        self::assertSame("\n\na is A\n\nb is B\n\nwhy?", $prompt->format(['question' => 'why']));
    }

    public function testSerializeAndDeserialize(): void
    {
        $prompt = PromptTemplate::fromTemplate('Say {foo}');

        $serialized = $prompt->serialize();
        self::assertSame('prompt', $serialized['_type']);
        self::assertSame(['foo'], $serialized['input_variables']);
        self::assertSame('Say {foo}', $serialized['template']);
        self::assertSame('f-string', $serialized['template_format']);

        $restored = PromptTemplate::deserialize($serialized);
        self::assertSame('Say hi', $restored->format(['foo' => 'hi']));
    }

    public function testSerializeRefusesAnOutputParser(): void
    {
        $prompt = new PromptTemplate(
            template: '{foo}',
            inputVariables: ['foo'],
            outputParser: new StringOutputParser(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot serialize a prompt template with an output parser');

        $prompt->serialize();
    }

    public function testDeserializeRefusesAMissingTemplate(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Prompt template must have a template');

        PromptTemplate::deserialize(['input_variables' => []]);
    }

    public function testSerializedKwargsMatchTheTypeScriptShape(): void
    {
        $prompt = PromptTemplate::fromTemplate('Say {foo}');

        self::assertSame([
            'lc' => 1,
            'type' => 'constructor',
            'id' => ['langchain_core', 'prompts', 'prompt', 'PromptTemplate'],
            'kwargs' => [
                'input_variables' => ['foo'],
                'template' => 'Say {foo}',
                'template_format' => 'f-string',
            ],
        ], $prompt->toJson());
    }

    // ---- mustache ---------------------------------------------------------

    public function testMustacheSingleInputVariable(): void
    {
        $prompt = PromptTemplate::fromTemplate('This is a {{foo}} test.', [
            'templateFormat' => Template::MUSTACHE,
        ]);

        self::assertSame('This is a bar test.', $prompt->format(['foo' => 'bar']));
        self::assertSame(['foo'], $prompt->inputVariables);
    }

    public function testMustacheMultipleInputVariables(): void
    {
        $prompt = PromptTemplate::fromTemplate('This {{bar}} is a {{foo}} test.', [
            'templateFormat' => Template::MUSTACHE,
        ]);

        self::assertSame('This baz is a bar test.', $prompt->format(['bar' => 'baz', 'foo' => 'bar']));
        self::assertSame(['bar', 'foo'], $prompt->inputVariables);
    }

    public function testMustacheMultipleInputVariablesWithRepeats(): void
    {
        $prompt = PromptTemplate::fromTemplate('This {{bar}} is a {{foo}} test {{foo}}.', [
            'templateFormat' => Template::MUSTACHE,
        ]);

        self::assertSame('This baz is a bar test bar.', $prompt->format(['bar' => 'baz', 'foo' => 'bar']));
        self::assertSame(['bar', 'foo'], $prompt->inputVariables);
    }

    public function testMustacheIgnoresFStringInputVariables(): void
    {
        $prompt = PromptTemplate::fromTemplate('This {bar} is a {foo} test {foo}.', [
            'templateFormat' => Template::MUSTACHE,
        ]);

        self::assertSame('This {bar} is a {foo} test {foo}.', $prompt->format(['bar' => 'baz', 'foo' => 'bar']));
        self::assertSame([], $prompt->inputVariables);
    }

    public function testMustacheNestedVariables(): void
    {
        $prompt = PromptTemplate::fromTemplate(
            'This {{obj.bar}} is a {{obj.foo}} test {{foo.bar.baz}}. Single: {{single}}',
            ['templateFormat' => Template::MUSTACHE]
        );

        self::assertSame(
            'This foo is a bar test baz. Single: one',
            $prompt->format([
                'obj' => ['bar' => 'foo', 'foo' => 'bar'],
                'foo' => ['bar' => ['baz' => 'baz']],
                'single' => 'one',
            ])
        );
        self::assertSame(['obj', 'foo', 'single'], $prompt->inputVariables);
    }

    public function testMustacheSectionContextVariables(): void
    {
        $template = "This{{#foo}}\n{{bar}}\n{{/foo}}is a test.";
        $prompt = PromptTemplate::fromTemplate($template, ['templateFormat' => Template::MUSTACHE]);

        self::assertSame("This\nyo\nis a test.", $prompt->format(['foo' => ['bar' => 'yo']]));
        self::assertSame(['foo', 'bar'], $prompt->inputVariables);
    }

    public function testMustacheSectionContextVariablesWithRepeats(): void
    {
        $template = "This{{#foo}}\n{{bar}}\n{{/foo}}is a test.";
        $prompt = PromptTemplate::fromTemplate($template, ['templateFormat' => Template::MUSTACHE]);

        self::assertSame(
            "This\nyo\n\nhello\nis a test.",
            $prompt->format(['foo' => [['bar' => 'yo'], ['bar' => 'hello']]])
        );
        self::assertSame(['foo', 'bar'], $prompt->inputVariables);
    }

    public function testMustacheEscapedVariables(): void
    {
        $template = 'test: {{{text}}}';
        $parsed = Template::parseTemplate($template, Template::MUSTACHE);

        self::assertSame(['type' => 'literal', 'text' => 'test: '], $parsed[0]);
        self::assertSame(['type' => 'variable', 'name' => 'text'], $parsed[1]);

        $promptTemplate = PromptTemplate::fromTemplate($template, ['templateFormat' => Template::MUSTACHE]);
        $result = $promptTemplate->invoke(['text' => 'hello i have a "quote']);

        self::assertSame('test: hello i have a "quote', $result->toStringValue());
    }

    public function testMustacheTemplatesSkipValidationByDefault(): void
    {
        $prompt = PromptTemplate::fromTemplate('{{foo}}', ['templateFormat' => Template::MUSTACHE]);

        self::assertFalse($prompt->validateTemplate);
    }

    /**
     * Each template, the variables the f-string parser sees, and the variables
     * the mustache parser sees. The two formats deliberately disagree: single
     * braces are variables to one and literals to the other.
     *
     * @return list<array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function variableExtractionCases(): array
    {
        return [
            ['{foo}', ['foo'], []],
            ['{{foo}}', [], ['foo']],
            ['no variables here', [], []],
            ['{{a}}{{b}}{{a}}', [], ['a', 'b']],
            ['{a} and {{b}}', ['a'], ['b']],
        ];
    }

    #[DataProvider('variableExtractionCases')]
    public function testFStringAndMustacheVariableExtraction(
        string $template,
        array $fStringVariables,
        array $mustacheVariables,
    ): void {
        self::assertSame($fStringVariables, Template::templateVariables($template, Template::F_STRING));
        self::assertSame($mustacheVariables, Template::templateVariables($template, Template::MUSTACHE));

        self::assertSame(
            $mustacheVariables,
            PromptTemplate::fromTemplate($template, ['templateFormat' => Template::MUSTACHE])->inputVariables
        );
    }
}
