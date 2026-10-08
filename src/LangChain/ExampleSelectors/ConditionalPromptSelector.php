<?php

declare(strict_types=1);

namespace LangChain\ExampleSelectors;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\BaseLanguageModel;
use LangChain\LanguageModels\BaseLLM;
use LangChain\Prompts\BasePromptTemplate;

/**
 * Picks a prompt by the first matching condition, else the default prompt.
 *
 * Port of `ConditionalPromptSelector` from `@langchain/core/example_selectors/conditional`.
 *
 * Upstream's `isLLM` / `isChatModel` type guards compare `_modelType()` to
 * `"base_llm"` / `"base_chat_model"`; here `modelType()` is not overridden per
 * family, so they are `instanceof` checks, exposed as static methods because
 * the port adds no function files.
 */
class ConditionalPromptSelector extends BasePromptSelector
{
    public BasePromptTemplate $defaultPrompt;

    /** @var list<array{0: callable(BaseLanguageModel): bool, 1: BasePromptTemplate}> */
    public array $conditionals;

    /**
     * @param list<array{0: callable(BaseLanguageModel): bool, 1: BasePromptTemplate}> $conditionals
     */
    public function __construct(BasePromptTemplate $defaultPrompt, array $conditionals = [])
    {
        $this->defaultPrompt = $defaultPrompt;
        $this->conditionals = $conditionals;
    }

    public function getPrompt(BaseLanguageModel $llm): BasePromptTemplate
    {
        foreach ($this->conditionals as [$condition, $prompt]) {
            if ($condition($llm)) {
                return $prompt;
            }
        }

        return $this->defaultPrompt;
    }

    public static function isLLM(BaseLanguageModel $llm): bool
    {
        return $llm instanceof BaseLLM;
    }

    public static function isChatModel(BaseLanguageModel $llm): bool
    {
        return $llm instanceof BaseChatModel;
    }
}
