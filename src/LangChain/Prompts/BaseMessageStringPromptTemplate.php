<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\Messages\BaseMessage;

/**
 * Base class for a message step backed by a single string prompt.
 *
 * Port of `BaseMessageStringPromptTemplate` from `@langchain_core/prompts/chat`.
 *
 * The template lives in a `StringPromptTemplate`; the subclass supplies the
 * message class to wrap the rendered text in. That split is what lets the same
 * `PromptTemplate` become a system message in one chain and a human message in
 * another.
 */
abstract class BaseMessageStringPromptTemplate extends BaseMessagePromptTemplate
{
    public StringPromptTemplate $prompt;

    /**
     * @param StringPromptTemplate $prompt
     */
    public function __construct(StringPromptTemplate $prompt)
    {
        $this->prompt = $prompt;
        $this->inputVariables = $prompt->inputVariables;
        $this->kwargs = ['prompt' => $prompt];
    }

    /**
     * Render the message.
     *
     * @param array<string, mixed> $values
     */
    abstract public function format(array $values): BaseMessage;

    /**
     * @param array<string, mixed> $values
     * @return list<BaseMessage>
     */
    public function formatMessages(array $values): array
    {
        return [$this->format($values)];
    }
}
