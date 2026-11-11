<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prompts;

use LangChain\ExampleSelectors\BaseExampleSelector;
use LangChain\ExampleSelectors\LengthBasedExampleSelector;
use LangChain\Messages\HumanMessage;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\BaseMessagePromptTemplate;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Prompts\FewShotChatMessagePromptTemplate;
use LangChain\Prompts\FewShotPromptTemplate;
use LangChain\Prompts\PromptTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Additional contracts converted directly from the constructors and serialize methods. */
#[CoversClass(FewShotPromptTemplate::class)]
#[CoversClass(FewShotChatMessagePromptTemplate::class)]
final class FewShotValidationAndSerializationTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function promptClasses(): iterable
    {
        yield 'string' => [FewShotPromptTemplate::class];
        yield 'chat' => [FewShotChatMessagePromptTemplate::class];
    }

    private static function examplePrompt(string $class): PromptTemplate|ChatPromptTemplate
    {
        return $class === FewShotPromptTemplate::class
            ? PromptTemplate::fromTemplate('{x}')
            : ChatPromptTemplate::fromMessages([['human', '{x}']]);
    }

    #[DataProvider('promptClasses')]
    public function testNeitherExamplesNorSelectorIsRejected(string $class): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("One of 'examples' and 'example_selector' should be provided");
        new $class(examplePrompt: self::examplePrompt($class));
    }

    #[DataProvider('promptClasses')]
    public function testBothExamplesAndSelectorAreRejectedEvenForEmptyExamples(string $class): void
    {
        $selector = new LengthBasedExampleSelector(['examplePrompt' => PromptTemplate::fromTemplate('{x}')]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Only one of 'examples' and 'example_selector' should be provided");
        new $class(examplePrompt: self::examplePrompt($class), examples: [], exampleSelector: $selector);
    }

    #[DataProvider('promptClasses')]
    public function testPrefixAndSuffixAreValidatedWithPartialVariables(string $class): void
    {
        $prompt = new $class(
            examplePrompt: self::examplePrompt($class),
            examples: [],
            inputVariables: ['query'],
            partialVariables: ['label' => 'Question'],
            prefix: '{label}',
            suffix: '{query}',
        );
        self::assertSame("Question\n\nhello", $prompt->format(['query' => 'hello']));

        $this->expectException(\RuntimeException::class);
        new $class(examplePrompt: self::examplePrompt($class), examples: [], suffix: '{missing}');
    }

    #[DataProvider('promptClasses')]
    public function testValidationCanBeDeferredAndAnEmptyExampleListIsValid(string $class): void
    {
        $prompt = new $class(examplePrompt: self::examplePrompt($class), examples: [], suffix: '{later}', validateTemplate: false);
        self::assertSame("\n\nyes", $prompt->format(['later' => 'yes']));
        self::assertSame("\n\n", (new $class(examplePrompt: self::examplePrompt($class), examples: []))->format([]));

        $this->expectException(\RuntimeException::class);
        $prompt->format([]);
    }

    #[DataProvider('promptClasses')]
    public function testSelectorsReceiveResolvedPartialsAndUserOverrides(string $class): void
    {
        $selector = new class extends BaseExampleSelector {
            public array $seen = [];
            public function addExample(array $example): ?string { return null; }
            public function selectExamples(array $inputVariables): array
            {
                $this->seen[] = $inputVariables;

                return [['x' => 'example']];
            }
        };
        $prompt = new $class(
            examplePrompt: self::examplePrompt($class),
            exampleSelector: $selector,
            inputVariables: ['query'],
            partialVariables: ['label' => static fn (): string => 'default'],
            prefix: '{label}',
            suffix: '{query}',
        );
        self::assertSame("override\n\nexample\n\nhello", $prompt->format(['query' => 'hello', 'label' => 'override']));
        self::assertSame([['label' => 'override', 'query' => 'hello']], $selector->seen);
    }

    public function testChatMessagesProjectOnlyExamplePromptVariables(): void
    {
        $examplePrompt = new class extends BaseMessagePromptTemplate {
            public array $seen = [];
            public function __construct() { $this->inputVariables = ['x']; }
            public function formatMessages(array $values): array
            {
                $this->seen[] = $values;

                return [new HumanMessage($values['x'])];
            }
        };
        $prompt = new FewShotChatMessagePromptTemplate(
            examplePrompt: $examplePrompt,
            examples: [['x' => 'example', 'ignored' => 'extra']],
        );

        self::assertEquals([new HumanMessage('example')], $prompt->formatMessages(['x' => 'user']));
        self::assertSame([['x' => 'example']], $examplePrompt->seen);
        self::assertSame("\n\nexample\n\n", $prompt->format([]));
        self::assertSame(['x' => 'example', 'ignored' => 'extra'], $examplePrompt->seen[1], 'string format supplies complete examples, as upstream');
    }

    public function testChatStringFormatUsesTheUpstreamContentArrayJoinCoercion(): void
    {
        $content = [['type' => 'text', 'text' => 'hello'], ['type' => 'image_url', 'image_url' => ['url' => 'image']]];
        $examplePrompt = ChatPromptTemplate::fromMessages([new HumanMessage(['content' => $content])]);
        $prompt = new FewShotChatMessagePromptTemplate(examplePrompt: $examplePrompt, examples: [[]]);

        self::assertSame($content, $prompt->formatMessages([])[0]->content);
        self::assertSame("\n\n[object Object],[object Object]\n\n", $prompt->format([]));
    }

    public function testExplicitExamplesSerializeAndDeserialize(): void
    {
        $prompt = new FewShotPromptTemplate(
            examplePrompt: PromptTemplate::fromTemplate('Example {x}'),
            examples: [['x' => 'one'], ['x' => 'two']],
            inputVariables: ['query'],
            prefix: 'Begin',
            suffix: '{query}',
            exampleSeparator: '|',
        );
        $serialized = $prompt->serialize();

        self::assertSame([
            '_type' => 'few_shot',
            'input_variables' => ['query'],
            'example_prompt' => ['_type' => 'prompt', 'input_variables' => ['x'], 'template' => 'Example {x}', 'template_format' => 'f-string'],
            'example_separator' => '|', 'suffix' => '{query}', 'prefix' => 'Begin',
            'template_format' => 'f-string', 'examples' => [['x' => 'one'], ['x' => 'two']],
        ], $serialized);
        self::assertSame('Begin|Example one|Example two|Question', FewShotPromptTemplate::deserialize($serialized)->format(['query' => 'Question']));
        self::assertSame('not_implemented', $prompt->toJson()['type']);
        self::assertSame(['langchain_core', 'prompts', 'few_shot', 'FewShotPromptTemplate'], $prompt->toJson()['id']);
    }

    public function testSerializationRejectsSelectors(): void
    {
        $examplePrompt = PromptTemplate::fromTemplate('{x}');
        $selector = LengthBasedExampleSelector::fromExamples([['x' => 'one']], ['examplePrompt' => $examplePrompt]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Serializing an example selector is not currently supported');
        (new FewShotPromptTemplate(examplePrompt: $examplePrompt, exampleSelector: $selector))->serialize();
    }

    public function testSerializationRejectsOutputParsers(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Serializing an output parser is not currently supported');
        (new FewShotPromptTemplate(examplePrompt: PromptTemplate::fromTemplate('{x}'), examples: [], outputParser: new StringOutputParser()))->serialize();
    }

    public function testDeserializeRequiresAnExamplePrompt(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing example prompt');
        FewShotPromptTemplate::deserialize(['examples' => []]);
    }

    public function testDeserializeRequiresAListOfExamples(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid examples format. Only list or string are supported.');
        FewShotPromptTemplate::deserialize(['example_prompt' => PromptTemplate::fromTemplate('{x}')->serialize(), 'examples' => 'examples.json']);
    }

    public function testChatConstructorSerializationCarriesExamplePromptAndExamples(): void
    {
        $prompt = new FewShotChatMessagePromptTemplate(
            examplePrompt: ChatPromptTemplate::fromMessages([['human', '{x}']]),
            examples: [['x' => 'one']],
        );
        $serialized = json_decode(json_encode($prompt, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('constructor', $serialized['type']);
        self::assertSame(['langchain_core', 'prompts', 'few_shot_chat', 'FewShotChatMessagePromptTemplate'], $serialized['id']);
        self::assertSame([['x' => 'one']], $serialized['kwargs']['examples']);
        self::assertSame('ChatPromptTemplate', $serialized['kwargs']['example_prompt']['id'][3]);
        self::assertArrayNotHasKey('partial_variables', $serialized['kwargs']);
    }
}
