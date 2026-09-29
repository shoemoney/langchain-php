<?php

declare(strict_types=1);

namespace LangChain\Schema;

use LangChain\Messages\BaseMessage;
use LangChain\Messages\MessageUtils;

/**
 * A prompt rendered to a list of messages.
 *
 * Port of `ChatPromptValue` from `@langchain_core/prompt_values`.
 */
class ChatPromptValue extends PromptValue
{
    /**
     * @param list<BaseMessage> $messages
     */
    public function __construct(public readonly array $messages)
    {
        $this->kwargs = [
            'messages' => array_map(static fn (BaseMessage $m): array => $m->toDict(), $messages),
        ];
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'prompt_values', 'ChatPromptValue'];
    }

    public function toStringValue(): string
    {
        return MessageUtils::getBufferString($this->messages);
    }

    /** @return list<BaseMessage> */
    public function toMessages(): array
    {
        return $this->messages;
    }
}
