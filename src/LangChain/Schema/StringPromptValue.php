<?php

declare(strict_types=1);

namespace LangChain\Schema;

use LangChain\Messages\MessageUtils;

/**
 * A prompt rendered to a plain string.
 *
 * Port of `StringPromptValue` from `@langchain_core/prompt_values`.
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

    /**
     * A string prompt read as a conversation is a single human message.
     *
     * @return list<\LangChain\Messages\BaseMessage>
     */
    public function toMessages(): array
    {
        return [MessageUtils::coerceMessageLikeToMessage($this->text)];
    }
}
