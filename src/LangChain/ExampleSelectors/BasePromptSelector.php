<?php

declare(strict_types=1);

namespace LangChain\ExampleSelectors;

use LangChain\LanguageModels\BaseLanguageModel;
use LangChain\Prompts\BasePromptTemplate;

/**
 * Selects a prompt template for a given language model.
 *
 * Port of `BasePromptSelector` from `@langchain/core/example_selectors/conditional`.
 */
abstract class BasePromptSelector
{
    abstract public function getPrompt(BaseLanguageModel $llm): BasePromptTemplate;

    /**
     * `getPrompt()` with partial variables applied.
     *
     * @param array{partialVariables?: array<string, mixed>} $options
     */
    public function getPromptAsync(BaseLanguageModel $llm, array $options = []): BasePromptTemplate
    {
        return $this->getPrompt($llm)->partial($options['partialVariables'] ?? []);
    }
}
