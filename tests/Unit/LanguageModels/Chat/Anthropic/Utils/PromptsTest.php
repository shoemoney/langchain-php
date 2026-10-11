<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic\Utils;

use LangChain\LanguageModels\Chat\Anthropic\Utils\Prompts;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Schema\ChatPromptValue;
use LangChain\Schema\StringPromptValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The local formatting/conversion portion of upstream prompts.int.test.ts, plus pure wrapper cases.
 * The upstream live `anthropicClient.messages.create()` call requires credentials and is not performed.
 */
#[CoversClass(Prompts::class)]
final class PromptsTest extends TestCase
{
    public function testConvertsTheUpstreamFormattedComedianPromptToAnthropicFields(): void
    {
        $prompt = ChatPromptTemplate::fromMessages([
            ['system', 'You are a world class comedian'],
            ['human', 'Tell me a joke about {topic}'],
        ]);

        $payload = Prompts::convertPromptToAnthropic($prompt->invoke(['topic' => 'cats']));

        self::assertSame([
            'system' => 'You are a world class comedian',
            'messages' => [['role' => 'user', 'content' => 'Tell me a joke about cats']],
        ], $payload);
    }

    public function testConvertsAStringPromptToOneUserTurn(): void
    {
        self::assertSame(['messages' => [['role' => 'user', 'content' => 'Hello']]], Prompts::convertPromptToAnthropic(new StringPromptValue('Hello')));
    }

    public function testEmptyAndSystemOnlyPromptsAlwaysCarryAMessagesList(): void
    {
        self::assertSame(['messages' => []], Prompts::convertPromptToAnthropic(new ChatPromptValue([])));
        self::assertSame(['system' => 'Follow these rules', 'messages' => []], Prompts::convertPromptToAnthropic(new ChatPromptValue([new SystemMessage('Follow these rules')])));
    }

    public function testUsesThePromptValuesChatMessagesMethod(): void
    {
        $prompt = new class ([]) extends ChatPromptValue {
            public function toMessages(): array
            {
                throw new \LogicException('Use toChatMessages(), as the upstream helper does.');
            }

            public function toChatMessages(): array
            {
                return [new HumanMessage('chat representation')];
            }
        };

        self::assertSame(['messages' => [['role' => 'user', 'content' => 'chat representation']]], Prompts::convertPromptToAnthropic($prompt));
    }

    public function testReusesTheConvertersSystemBlocksAndToolResultFoldingWithoutMutatingThePrompt(): void
    {
        $system = [['type' => 'text', 'text' => 'Cached rules', 'cache_control' => ['type' => 'ephemeral']]];
        $prompt = new ChatPromptValue([
            new SystemMessage(['content' => $system]),
            new AIMessage(['content' => 'Checking', 'tool_calls' => [
                ['id' => 'a', 'name' => 'lookup', 'args' => ['query' => 'first']],
                ['id' => 'b', 'name' => 'lookup', 'args' => ['query' => 'second']],
            ]]),
            new ToolMessage(['content' => 'one', 'tool_call_id' => 'a']),
            new ToolMessage(['content' => 'two', 'tool_call_id' => 'b']),
        ]);
        $before = array_map(static fn ($message): array => $message->toDict(), $prompt->messages);

        $payload = Prompts::convertPromptToAnthropic($prompt);

        self::assertSame($system, $payload['system']);
        self::assertCount(2, $payload['messages']);
        self::assertSame('assistant', $payload['messages'][0]['role']);
        self::assertSame(['type' => 'text', 'text' => 'Checking'], $payload['messages'][0]['content'][0]);
        self::assertSame([
            'role' => 'user',
            'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'a', 'content' => 'one'],
                ['type' => 'tool_result', 'tool_use_id' => 'b', 'content' => 'two'],
            ],
        ], $payload['messages'][1]);
        self::assertSame($before, array_map(static fn ($message): array => $message->toDict(), $prompt->messages));
    }
}
