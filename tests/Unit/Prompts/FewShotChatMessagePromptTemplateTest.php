<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prompts;

use LangChain\ExampleSelectors\LengthBasedExampleSelector;
use LangChain\Load\Load;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Prompts\FewShotChatMessagePromptTemplate;
use LangChain\Prompts\HumanMessagePromptTemplate;
use LangChain\Prompts\PromptTemplate;
use LangChain\Schema\ChatPromptValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/** Port of the FewShotChatMessagePromptTemplate describe block in few_shot.test.ts. */
#[CoversClass(FewShotChatMessagePromptTemplate::class)]
#[CoversClass(ChatPromptTemplate::class)]
final class FewShotChatMessagePromptTemplateTest extends TestCase
{
    #[DataProviderExternal(FewShotPromptTemplateTest::class, 'upstreamPartials')]
    public function testUpstreamPartialCases(array $inputs, array $partials, array $bind, array $values, string $expected): void
    {
        $prompt = new FewShotChatMessagePromptTemplate(
            examplePrompt: ChatPromptTemplate::fromMessages([['ai', '{foo}{bar}']]),
            examples: [],
            inputVariables: $inputs,
            partialVariables: $partials,
            prefix: '{foo}{bar}',
            exampleSeparator: "\n",
        );
        $partialPrompt = $bind === [] ? $prompt : $prompt->partial($bind);

        self::assertSame($expected, $partialPrompt->format($values));
        self::assertSame($inputs, $prompt->inputVariables);
        self::assertSame([], $partialPrompt->formatMessages($values), 'prefix and suffix do not become chat messages');
        self::assertInstanceOf(ChatPromptValue::class, $partialPrompt->invoke($values));
    }

    public function testFormatMessages(): void
    {
        $prompt = new FewShotChatMessagePromptTemplate(
            examplePrompt: ChatPromptTemplate::fromMessages([['ai', '{ai_input_var}'], ['human', '{human_input_var}']]),
            examples: [
                ['ai_input_var' => 'ai-foo', 'human_input_var' => 'human-bar'],
                ['ai_input_var' => 'ai-foo2', 'human_input_var' => 'human-bar2'],
            ],
            inputVariables: ['ai_input_var', 'human_input_var'],
        );

        self::assertEquals([
            new AIMessage('ai-foo'), new HumanMessage('human-bar'),
            new AIMessage('ai-foo2'), new HumanMessage('human-bar2'),
        ], $prompt->formatMessages([]));
    }

    public function testPartialWithFunctionAndExamples(): void
    {
        $prompt = new FewShotChatMessagePromptTemplate(
            examplePrompt: ChatPromptTemplate::fromMessages([['ai', 'An example about {x}']]),
            examples: [['x' => 'foo'], ['x' => 'bar']],
            inputVariables: ['foo', 'bar'],
            prefix: '{foo}{bar}',
            exampleSeparator: "\n",
        );

        self::assertSame("boobaz\nAn example about foo\nAn example about bar\n", $prompt->partial(['foo' => static fn (): string => 'boo'])->format(['bar' => 'baz']));
    }

    public function testFewShotMessagesComposeInsideAChatPrompt(): void
    {
        $fewShot = new FewShotChatMessagePromptTemplate(
            examplePrompt: ChatPromptTemplate::fromMessages([['human', '{question}'], ['ai', '{answer}']]),
            examples: [['question' => '2+2?', 'answer' => '4']],
        );
        $prompt = ChatPromptTemplate::fromMessages([['system', 'Calculate'], $fewShot, ['human', '{input}']]);

        self::assertSame(['input'], $prompt->inputVariables);
        self::assertEquals([
            new SystemMessage('Calculate'), new HumanMessage('2+2?'), new AIMessage('4'), new HumanMessage('3+3?'),
        ], $prompt->formatMessages(['input' => '3+3?']));
    }

    public function testAMessagePromptCanFormatEachExample(): void
    {
        $prompt = new FewShotChatMessagePromptTemplate(
            examplePrompt: HumanMessagePromptTemplate::fromTemplate('{x}'),
            examples: [['x' => 'first'], ['x' => 'second']],
        );

        self::assertEquals([new HumanMessage('first'), new HumanMessage('second')], $prompt->formatMessages([]));
        self::assertSame("\n\nfirst\n\nsecond\n\n", $prompt->format([]));
    }

    public function testASelectorRemainsDynamicWhenFewShotIsNestedInAChatPrompt(): void
    {
        $selector = LengthBasedExampleSelector::fromExamples(
            [['x' => 'first'], ['x' => 'second']],
            ['examplePrompt' => PromptTemplate::fromTemplate('{x}'), 'maxLength' => 3],
        );
        $fewShot = new FewShotChatMessagePromptTemplate(
            examplePrompt: HumanMessagePromptTemplate::fromTemplate('{x}'),
            exampleSelector: $selector,
            inputVariables: ['query'],
        );
        $prompt = ChatPromptTemplate::fromMessages([$fewShot, ['human', '{query}']]);

        self::assertEquals([new HumanMessage('first'), new HumanMessage('second'), new HumanMessage('short')], $prompt->formatMessages(['query' => 'short']));
        self::assertEquals([new HumanMessage('a longer query now')], $prompt->formatMessages(['query' => 'a longer query now']));
    }

    /** Upstream holds the selector as a constructor field, so it nests as its own `not_implemented` and loading stops there. */
    public function testANestedSelectorSerializesAsNotImplementedAndBlocksLoading(): void
    {
        $fewShot = new FewShotChatMessagePromptTemplate(
            examplePrompt: HumanMessagePromptTemplate::fromTemplate('{x}'),
            exampleSelector: LengthBasedExampleSelector::fromExamples([['x' => 'first']], ['examplePrompt' => PromptTemplate::fromTemplate('{x}')]),
            inputVariables: ['query'],
        );
        $payload = (string) json_encode($fewShot);
        $decoded = json_decode($payload, true);

        self::assertSame('constructor', $decoded['type']);
        self::assertSame(
            ['lc' => 1, 'type' => 'not_implemented', 'id' => ['langchain_core', 'example_selectors', 'base', 'LengthBasedExampleSelector']],
            $decoded['kwargs']['example_selector'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Trying to load an object that does not implement serialization at $.kwargs.example_selector: langchain_core/example_selectors/base/LengthBasedExampleSelector');
        Load::load($payload);
    }
}
