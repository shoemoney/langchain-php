<?php

declare(strict_types=1);

namespace LangChain\Schema;

use LangChain\Messages\BaseMessage;
use LangChain\Messages\MessageUtils;

/**
 * The normalised form of "a prompt", whatever it arrived as.
 *
 * Port of `PromptValue` from `@langchain_core/prompt_values`.
 *
 * This is the pivot type that lets one chain serve both a raw string and a
 * message list: a prompt renders *down* to a `PromptValue`, and a model
 * consumes one and knows whether to send `string` or `messages` on the wire.
 */
abstract class PromptValue extends \LangChain\Load\Serializable
{
    abstract public function toStringValue(): string;

    /** @return list<BaseMessage> */
    abstract public function toMessages(): array;

    /**
     * Try to view this prompt value as a list of messages.
     */
    public function toChatMessages(): array
    {
        return $this->toMessages();
    }

    abstract public static function lcId(): array;
}
