<?php

declare(strict_types=1);

namespace LangChain\ChatHistory;

use LangChain\Messages\BaseMessage;

/**
 * Chat message history held in memory.
 *
 * Port of `InMemoryChatMessageHistory` from `@langchain/core/chat_history`.
 */
class InMemoryChatMessageHistory extends BaseListChatMessageHistory
{
    /** @var list<BaseMessage> */
    private array $messages;

    /** @param list<BaseMessage>|null $messages */
    public function __construct(?array $messages = null)
    {
        $this->messages = array_values($messages ?? []);
    }

    /** @return list<string> */
    public static function lcNamespace(): array
    {
        return ['langchain', 'stores', 'message', 'in_memory'];
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return array_merge(static::lcNamespace(), ['InMemoryChatMessageHistory']);
    }

    /** @return list<BaseMessage> */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function addMessage(BaseMessage $message): void
    {
        $this->messages[] = $message;
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
