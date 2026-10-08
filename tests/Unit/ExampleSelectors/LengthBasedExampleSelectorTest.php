<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\ExampleSelectors;

use LangChain\ExampleSelectors\BaseExampleSelector;
use LangChain\ExampleSelectors\LengthBasedExampleSelector;
use LangChain\Prompts\PromptTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langchain/src/prompts/tests/selectors.test.ts` and the example-selector
 * case of core `prompts/tests/few_shot.test.ts` ("partial with function and example
 * selector"); the `FewShotPromptTemplate` half of that case cannot run, because
 * that class is not ported.
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

    public function testExampleSelectorFeedingAPromptTheWayFewShotDoes(): void
    {
        $examplePrompt = PromptTemplate::fromTemplate('An example about {x}');
        $selector = LengthBasedExampleSelector::fromExamples(
            [['x' => 'foo'], ['x' => 'bar']],
            ['examplePrompt' => $examplePrompt, 'maxLength' => 200],
        );

        $examples = $selector->selectExamples(['bar' => 'baz']);
        $rendered = implode("\n", array_map($examplePrompt->format(...), $examples));

        $this->assertSame("An example about foo\nAn example about bar", $rendered);
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
}
