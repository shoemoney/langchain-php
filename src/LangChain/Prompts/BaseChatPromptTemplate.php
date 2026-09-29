<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\Messages\BaseMessage;
use LangChain\Schema\ChatPromptValue;
use LangChain\Schema\PromptValue;

/**
 * Base class for a prompt that renders to a conversation.
 *
 * Port of `BaseChatPromptTemplate` from `@langchain_core/prompts/chat`.
 *
 * The prompt's natural output is a list of messages, but a completion model
 * cannot take one. `format()` therefore flattens through the same
 * `ChatPromptValue` a model would use — the `Human: …` / `AI: …` transcript — so
 * the same template serves both model families without a second definition.
 */
abstract class BaseChatPromptTemplate extends BasePromptTemplate
{
    public const PROMPT_TYPE = 'chat';

    /**
     * Render the conversation.
     *
     * @param array<string, mixed> $values
     * @return list<BaseMessage>
     */
    abstract public function formatMessages(array $values): array;

    /**
     * @param array<string, mixed> $values
     */
    public function format(array $values): string
    {
        return $this->formatPromptValue($values)->toStringValue();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function formatPromptValue(array $values): PromptValue
    {
        return new ChatPromptValue($this->formatMessages($values));
    }

    public function getPromptType(): string
    {
        return 'chat';
    }
}
