<?php

declare(strict_types=1);

namespace LangChain\ChatHistory;

use LangChain\Load\Serializable;
use LangChain\Messages\BaseMessage;

/**
 * Base class for all chat message histories.
 *
 * Port of `BaseChatMessageHistory` from `@langchain/core/chat_history`.
 */
abstract class BaseChatMessageHistory extends Serializable
{
    /** @return list<BaseMessage> */
    abstract public function getMessages(): array;

    abstract public function addMessage(BaseMessage $message): void;

    abstract public function addUserMessage(string $message): void;

    abstract public function addAIMessage(string $message): void;

    /**
     * Add a list of messages. Implementations should override this to batch the
     * write and avoid a round trip per message.
     *
     * @param list<BaseMessage> $messages
     */
    public function addMessages(array $messages): void
    {
        foreach ($messages as $message) {
            $this->addMessage($message);
        }
    }

    abstract public function clear(): void;
}
