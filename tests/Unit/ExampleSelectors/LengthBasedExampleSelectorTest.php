<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\ExampleSelectors;

use LangChain\ExampleSelectors\BaseExampleSelector;
use LangChain\ExampleSelectors\LengthBasedExampleSelector;
use LangChain\ExampleSelectors\SemanticSimilarityExampleSelector;
use LangChain\Load\ImportMap;
use LangChain\Load\Load;
use LangChain\Prompts\FewShotPromptTemplate;
use LangChain\Prompts\PromptTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use LangChain\VectorStores\MemoryVectorStore;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langchain/src/prompts/tests/selectors.test.ts` and the example-selector
 * case of core `prompts/tests/few_shot.test.ts` ("partial with function and example
 * selector"), including the real FewShotPromptTemplate formatting path.
 */
#[CoversClass(LengthBasedExampleSelector::class)]
#[CoversClass(BaseExampleSelector::class)]
final class LengthBasedExampleSelectorTest extends TestCase
{
    public function testUsingLengthBasedExampleSelector(): void
    {
        $prompt = new PromptTemplate(
            template: '{foo} {bar}',
            inputVariables: ['foo'],
            partialVariables: ['bar' => 'baz'],
        );
        $selector = LengthBasedExampleSelector::fromExamples(
            [['foo' => 'one one one']],
            ['examplePrompt' => $prompt, 'maxLength' => 10],
        );
        $selector->addExample(['foo' => 'one two three']);
        $selector->addExample(['foo' => 'four five six']);
        $selector->addExample(['foo' => 'seven eight nine']);
        $selector->addExample(['foo' => 'ten eleven twelve']);

        $chosen = $selector->selectExamples(['foo' => 'hello', 'bar' => 'world']);

        $this->assertSame([['foo' => 'one one one'], ['foo' => 'one two three']], $chosen);
    }

    public function testFewShotPartialWithFunctionAndExampleSelector(): void
    {
        $examplePrompt = PromptTemplate::fromTemplate('An example about {x}');
        $selector = LengthBasedExampleSelector::fromExamples(
            [['x' => 'foo'], ['x' => 'bar']],
            ['examplePrompt' => $examplePrompt, 'maxLength' => 200],
        );

        $prompt = new FewShotPromptTemplate(
            examplePrompt: $examplePrompt,
            exampleSelector: $selector,
            inputVariables: ['foo', 'bar'],
            prefix: '{foo}{bar}',
            exampleSeparator: "\n",
        );

        $this->assertSame("boobaz\nAn example about foo\nAn example about bar\n", $prompt->partial(['foo' => static fn (): string => 'boo'])->format(['bar' => 'baz']));
    }

    public function testLengthIsWordsAndLinesByDefault(): void
    {
        $prompt = PromptTemplate::fromTemplate("{x}");
        $selector = LengthBasedExampleSelector::fromExamples([['x' => "a b\nc"]], ['examplePrompt' => $prompt]);

        $this->assertSame([3], $selector->exampleTextLengths);
    }

    public function testACustomLengthFunctionDecidesWhatFits(): void
    {
        $prompt = PromptTemplate::fromTemplate('{x}');
        $selector = LengthBasedExampleSelector::fromExamples(
            [['x' => 'aaaa'], ['x' => 'bb'], ['x' => 'cc']],
            ['examplePrompt' => $prompt, 'maxLength' => 7, 'getTextLength' => strlen(...)],
        );

        // the input costs 1 ("z"), leaving 6: aaaa (4) fits, bb (2) fits exactly, cc does not
        $this->assertSame([['x' => 'aaaa'], ['x' => 'bb']], $selector->selectExamples(['k' => 'z']));
    }

    public function testNothingIsSelectedWhenTheInputAloneFillsTheBudget(): void
    {
        $prompt = PromptTemplate::fromTemplate('{x}');
        $selector = LengthBasedExampleSelector::fromExamples([['x' => 'a']], ['examplePrompt' => $prompt, 'maxLength' => 3]);

        $this->assertSame([], $selector->selectExamples(['q' => 'one two three four']));
    }

    public function testSelectionStopsAtTheFirstExampleThatDoesNotFitEvenIfLaterOnesWould(): void
    {
        $prompt = PromptTemplate::fromTemplate('{x}');
        $selector = LengthBasedExampleSelector::fromExamples(
            [['x' => 'one'], ['x' => 'a long example here'], ['x' => 'two']],
            ['examplePrompt' => $prompt, 'maxLength' => 5],
        );

        // budget 5 - 1 (input) = 4; "one" costs 1; the next costs 4 > 3 remaining; "two" is never reached
        $this->assertSame([['x' => 'one']], $selector->selectExamples(['q' => 'z']));
    }

    public function testCalculateExampleTextLengthsReturnsGivenLengthsOrRecomputes(): void
    {
        $prompt = PromptTemplate::fromTemplate('{x}');
        $selector = LengthBasedExampleSelector::fromExamples([['x' => 'a b'], ['x' => 'c']], ['examplePrompt' => $prompt]);

        $this->assertSame([9], $selector->calculateExampleTextLengths([9], $selector));
        $this->assertSame([2, 1], $selector->calculateExampleTextLengths([], $selector));
    }

    public function testSerializedIdIsNamespacedByClass(): void
    {
        $this->assertSame(
            ['langchain_core', 'example_selectors', 'base', 'LengthBasedExampleSelector'],
            LengthBasedExampleSelector::lcId(),
        );
    }

    /** Upstream `BaseExampleSelector` keeps the default `lc_serializable = false`, so `toJSON()` is `not_implemented`. */
    public function testSelectorsSerializeAsNotImplementedWithoutKwargs(): void
    {
        $selectors = [
            'LengthBasedExampleSelector' => LengthBasedExampleSelector::fromExamples([['x' => 'a']], ['examplePrompt' => PromptTemplate::fromTemplate('{x}')]),
            'SemanticSimilarityExampleSelector' => SemanticSimilarityExampleSelector::fromExamples([['input' => 'happy']], new KeywordEmbeddings(), MemoryVectorStore::class),
            'NamedCustomSelector' => new NamedCustomSelector(),
        ];

        foreach ($selectors as $name => $selector) {
            $expected = ['lc' => 1, 'type' => 'not_implemented', 'id' => ['langchain_core', 'example_selectors', 'base', $name]];
            self::assertFalse($selector::lcSerializable(), $name);
            self::assertSame($expected, $selector->toJson(), $name);
            self::assertArrayNotHasKey('kwargs', $selector->toJson(), $name);
            self::assertSame(json_encode($expected), json_encode($selector), $name);
        }
    }

    public function testLoadingAnEmittedSelectorFailsOnTheNotImplementedPath(): void
    {
        $selector = LengthBasedExampleSelector::fromExamples([['x' => 'a']], ['examplePrompt' => PromptTemplate::fromTemplate('{x}')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Trying to load an object that does not implement serialization at $: langchain_core/example_selectors/base/LengthBasedExampleSelector');
        Load::load((string) json_encode($selector));
    }

    /** PHP adaptation: the fixed registry has no selector factory, unlike upstream's generic import map re-export. */
    public function testForgedSelectorConstructorPayloadsStillFail(): void
    {
        foreach ([
            [['langchain_core', 'example_selectors', 'base', 'LengthBasedExampleSelector'], 'Unsupported serialized constructor langchain_core/example_selectors/base/LengthBasedExampleSelector'],
            [['langchain_core', 'example_selectors', 'LengthBasedExampleSelector'], 'Invalid namespace for serialized constructor.'],
        ] as [$id, $message]) {
            try {
                Load::load((string) json_encode(['lc' => 1, 'type' => 'constructor', 'id' => $id, 'kwargs' => new \stdClass()]));
                self::fail('Forged selector payload must not revive: ' . implode('/', $id));
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported serialized constructor');
        ImportMap::resolve(SemanticSimilarityExampleSelector::lcId());
    }
}

final class NamedCustomSelector extends BaseExampleSelector
{
    public function addExample(array $example): string|null
    {
        return null;
    }

    public function selectExamples(array $inputVariables): array
    {
        return [];
    }
}
