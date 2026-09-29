<?php

declare(strict_types=1);

namespace LangChain\Schema;

/**
 * A prompt rendered to a plain string.
 */
class StringPromptValue extends PromptValue
{
    public function __construct(public readonly string $text)
    {
        $this->kwargs = ['text' => $text];
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'prompt_values', 'StringPromptValue'];
    }

    public function toStringValue(): string
    {
        return $this->text;
    }

    /** @return list<BaseMessage> */
    public function toMessages(): array
    {
        return [MessageUtils::coerceMessageLikeToMessage($this->text)];
    }
}

/**
 * A prompt rendered to a list of messages.
 */
class ChatPromptValue extends PromptValue
{
    /**
     * @param list<BaseMessage> $messages
     */
    public function __construct(public readonly array $messages)
    {
        $this->kwargs = ['messages' => array_map(static fn (BaseMessage $m): array => $m->toDict(), $messages)];
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
