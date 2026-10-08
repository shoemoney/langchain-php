<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\ExampleSelectors;

use LangChain\ExampleSelectors\BasePromptSelector;
use LangChain\ExampleSelectors\ConditionalPromptSelector;
use LangChain\Prompts\PromptTemplate;
use LangChain\Utils\Testing\FakeLLM;
use LangChain\Utils\Testing\FakeListChatModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Own tests: upstream has none for `ConditionalPromptSelector`.
 */
#[CoversClass(ConditionalPromptSelector::class)]
#[CoversClass(BasePromptSelector::class)]
final class ConditionalPromptSelectorTest extends TestCase
{
    public function testTheFirstMatchingConditionWins(): void
    {
        $default = PromptTemplate::fromTemplate('default {x}');
        $chat = PromptTemplate::fromTemplate('chat {x}');
        $llm = PromptTemplate::fromTemplate('llm {x}');
        $selector = new ConditionalPromptSelector($default, [
            [ConditionalPromptSelector::isChatModel(...), $chat],
            [ConditionalPromptSelector::isLLM(...), $llm],
        ]);

        $this->assertSame($chat, $selector->getPrompt(new FakeListChatModel(['responses' => ['a']])));
        $this->assertSame($llm, $selector->getPrompt(new FakeLLM()));
    }

    public function testNoMatchFallsBackToTheDefault(): void
    {
        $default = PromptTemplate::fromTemplate('default {x}');
        $selector = new ConditionalPromptSelector($default, [
            [static fn (): bool => false, PromptTemplate::fromTemplate('never {x}')],
        ]);

        $this->assertSame($default, $selector->getPrompt(new FakeLLM()));
        $this->assertSame($default, (new ConditionalPromptSelector($default))->getPrompt(new FakeLLM()));
    }

    public function testTypeGuardsDistinguishCompletionFromChatModels(): void
    {
        $this->assertTrue(ConditionalPromptSelector::isLLM(new FakeLLM()));
        $this->assertFalse(ConditionalPromptSelector::isLLM(new FakeListChatModel(['responses' => ['a']])));
        $this->assertTrue(ConditionalPromptSelector::isChatModel(new FakeListChatModel(['responses' => ['a']])));
        $this->assertFalse(ConditionalPromptSelector::isChatModel(new FakeLLM()));
    }

    public function testGetPromptAsyncAppliesPartialVariables(): void
    {
        $selector = new ConditionalPromptSelector(PromptTemplate::fromTemplate('{greeting} {name}'));

        $prompt = $selector->getPromptAsync(new FakeLLM(), ['partialVariables' => ['greeting' => 'hello']]);

        $this->assertSame('hello world', $prompt->format(['name' => 'world']));
    }
}
