<?php

declare(strict_types=1);

namespace LangChain\ChatHistory;

use LangChain\Load\Serializable;
use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;

/**
 * Base class for all list chat message histories.
 *
 * Port of `BaseListChatMessageHistory` from `@langchain/core/chat_history`.
 * Upstream keeps this a sibling of {@see BaseChatMessageHistory} rather than a
 * subclass ("TODO: Combine into one class"), and so does this port.
 */
abstract class BaseListChatMessageHistory extends Serializable
{
    /** @return list<BaseMessage> */
    abstract public function getMessages(): array;

    abstract public function addMessage(BaseMessage $message): void;

    /** Convenience; prefer {@see self::addMessages()} to save round trips. */
    public function addUserMessage(string $message): void
    {
        $this->addMessage(new HumanMessage($message));
    }

    /** Convenience; prefer {@see self::addMessages()} to save round trips. */
    public function addAIMessage(string $message): void
    {
        $this->addMessage(new AIMessage($message));
    }

    /**
     * @param list<BaseMessage> $messages
     */
    public function addMessages(array $messages): void
    {
        foreach ($messages as $message) {
            $this->addMessage($message);
        }
    }

    /** Remove all messages from the store. */
    public function clear(): void
    {
        throw new \RuntimeException('Not implemented.');
    }
}
