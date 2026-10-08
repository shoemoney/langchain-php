<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\ChatHistory;

use LangChain\ChatHistory\BaseChatMessageHistory;
use LangChain\ChatHistory\BaseListChatMessageHistory;
use LangChain\ChatHistory\InMemoryChatMessageHistory;
use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Own tests for `chat_history.ts` (upstream has none).
 */
#[CoversClass(InMemoryChatMessageHistory::class)]
#[CoversClass(BaseListChatMessageHistory::class)]
#[CoversClass(BaseChatMessageHistory::class)]
final class InMemoryChatMessageHistoryTest extends TestCase
{
    public function testStartsEmptyOrFromSeedMessages(): void
    {
        self::assertSame([], (new InMemoryChatMessageHistory())->getMessages());

        $seed = new HumanMessage('hi');
        self::assertSame([$seed], (new InMemoryChatMessageHistory([$seed]))->getMessages());
    }

    public function testAddMessageAppendsInOrder(): void
    {
        $history = new InMemoryChatMessageHistory();
        $a = new HumanMessage('a');
        $b = new AIMessage('b');

        $history->addMessage($a);
        $history->addMessage($b);

        self::assertSame([$a, $b], $history->getMessages());
    }

    public function testConvenienceMethodsWrapStringsInTheRightMessageType(): void
    {
        $history = new InMemoryChatMessageHistory();
        $history->addUserMessage('question');
        $history->addAIMessage('answer');

        [$user, $ai] = $history->getMessages();
        self::assertInstanceOf(HumanMessage::class, $user);
        self::assertSame('question', $user->content);
        self::assertInstanceOf(AIMessage::class, $ai);
        self::assertSame('answer', $ai->content);
    }

    public function testAddMessagesDelegatesToAddMessageInOrder(): void
    {
        $history = new class () extends InMemoryChatMessageHistory {
            /** @var list<string> */
            public array $calls = [];

            public function addMessage(BaseMessage $message): void
            {
                $this->calls[] = (string) $message->content;
                parent::addMessage($message);
            }
        };

        $history->addMessages([new HumanMessage('1'), new AIMessage('2'), new HumanMessage('3')]);

        self::assertSame(['1', '2', '3'], $history->calls);
        self::assertCount(3, $history->getMessages());
    }

    public function testClearEmptiesTheHistory(): void
    {
        $history = new InMemoryChatMessageHistory([new HumanMessage('x')]);
        $history->clear();

        self::assertSame([], $history->getMessages());
    }

    public function testListHistoryBaseClearIsNotImplemented(): void
    {
        $history = new class () extends BaseListChatMessageHistory {
            public static function lcId(): array
            {
                return ['test'];
            }

            public function getMessages(): array
            {
                return [];
            }

            public function addMessage(BaseMessage $message): void
            {
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not implemented.');
        $history->clear();
    }

    public function testBaseChatMessageHistoryAddMessagesLoopsOverAddMessage(): void
    {
        $history = new class () extends BaseChatMessageHistory {
            /** @var list<BaseMessage> */
            public array $stored = [];

            public static function lcId(): array
            {
                return ['test'];
            }

            public function getMessages(): array
            {
                return $this->stored;
            }

            public function addMessage(BaseMessage $message): void
            {
                $this->stored[] = $message;
            }

            public function addUserMessage(string $message): void
            {
                $this->addMessage(new HumanMessage($message));
            }

            public function addAIMessage(string $message): void
            {
                $this->addMessage(new AIMessage($message));
            }

            public function clear(): void
            {
                $this->stored = [];
            }
        };

        $history->addMessages([new HumanMessage('a'), new AIMessage('b')]);

        self::assertCount(2, $history->getMessages());
    }

    public function testSerializedId(): void
    {
        self::assertSame(
            ['langchain', 'stores', 'message', 'in_memory', 'InMemoryChatMessageHistory'],
            InMemoryChatMessageHistory::lcId(),
        );
    }
}
