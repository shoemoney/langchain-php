<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prompts;

use LangChain\Prompts\FewShotPromptTemplate;
use LangChain\Prompts\PromptTemplate;
use LangChain\Schema\StringPromptValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Port of the FewShotPromptTemplate describe block in core few_shot.test.ts. */
#[CoversClass(FewShotPromptTemplate::class)]
final class FewShotPromptTemplateTest extends TestCase
{
    /** @return iterable<string, array{array, array, array, array, string}> */
    public static function upstreamPartials(): iterable
    {
        yield 'Test using partial' => [['foo'], ['bar' => 'baz'], [], ['foo' => 'foo'], "foobaz\n"];
        yield 'Test using full partial' => [[], ['bar' => 'baz', 'foo' => 'boo'], [], [], "boobaz\n"];
        yield 'Test partial with string' => [['foo', 'bar'], [], ['foo' => 'foo'], ['bar' => 'baz'], "foobaz\n"];
        yield 'Test partial with function' => [['foo', 'bar'], [], ['foo' => static fn (): string => 'boo'], ['bar' => 'baz'], "boobaz\n"];
    }

    #[DataProvider('upstreamPartials')]
    public function testUpstreamPartialCases(array $inputs, array $partials, array $bind, array $values, string $expected): void
    {
        $prompt = new FewShotPromptTemplate(
            examplePrompt: PromptTemplate::fromTemplate('{foo}{bar}'),
            examples: [],
            inputVariables: $inputs,
            partialVariables: $partials,
            prefix: '{foo}{bar}',
            exampleSeparator: "\n",
        );
        $partialPrompt = $bind === [] ? $prompt : $prompt->partial($bind);

        self::assertSame($expected, $partialPrompt->format($values));
        self::assertSame($inputs, $prompt->inputVariables);
        self::assertInstanceOf(StringPromptValue::class, $partialPrompt->invoke($values));
        self::assertSame($expected, $partialPrompt->invoke($values)->toStringValue());
    }

    public function testPartialWithFunctionAndExamples(): void
    {
        $prompt = new FewShotPromptTemplate(
            examplePrompt: PromptTemplate::fromTemplate('An example about {x}'),
            examples: [['x' => 'foo'], ['x' => 'bar']],
            inputVariables: ['foo', 'bar'],
            prefix: '{foo}{bar}',
            exampleSeparator: "\n",
        );

        self::assertSame("boobaz\nAn example about foo\nAn example about bar\n", $prompt->partial(['foo' => static fn (): string => 'boo'])->format(['bar' => 'baz']));
    }

    public function testExampleStringsAreInterpolatedAgainAfterJoining(): void
    {
        $prompt = new FewShotPromptTemplate(
            examplePrompt: PromptTemplate::fromTemplate('{x}'),
            examples: [['x' => '{question}']],
            inputVariables: ['question'],
            prefix: 'Start',
            suffix: 'End {question}',
            exampleSeparator: '|',
        );

        self::assertSame('Start|hello|End hello', $prompt->format(['question' => 'hello']));
    }

    public function testCallablePartialsAreResolvedPerCallAndUserValuesWin(): void
    {
        $calls = 0;
        $prompt = new FewShotPromptTemplate(
            examplePrompt: PromptTemplate::fromTemplate('{x}'),
            examples: [],
            partialVariables: ['foo' => static function () use (&$calls): string { return (string) ++$calls; }],
            prefix: '{foo}',
            exampleSeparator: '|',
        );

        self::assertSame('1|', $prompt->format([]));
        self::assertSame('override|', $prompt->format(['foo' => 'override']));
        self::assertSame('3|', $prompt->format([]));
    }
}
