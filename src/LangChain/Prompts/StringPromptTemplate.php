<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\Schema\PromptValue;
use LangChain\Schema\StringPromptValue;

/**
 * Base class for prompt templates that render to a single string.
 *
 * Port of `BaseStringPromptTemplate` from `@langchain_core/prompts/string`.
 *
 * The only thing it adds is where `format()` goes: a `StringPromptValue` rather
 * than a `ChatPromptValue`. That is the whole difference between a prompt for a
 * completion model and one for a chat model, and keeping it in the base class
 * means every string-shaped template gets it for free.
 *
 * @template TPartialVariableName of string
 * @extends BasePromptTemplate<TPartialVariableName>
 */
abstract class StringPromptTemplate extends BasePromptTemplate
{
    /**
     * @param array<string, mixed> $values
     */
    public function formatPromptValue(array $values): PromptValue
    {
        return new StringPromptValue((string) $this->format($values));
    }
}
