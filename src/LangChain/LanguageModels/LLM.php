<?php

declare(strict_types=1);

namespace LangChain\LanguageModels;

use LangChain\LanguageModels\Outputs\Generation;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * A completion model with the `_generate()` boilerplate already done.
 *
 * Port of `LLM` from `@langchain/core/language_models/llms`.
 *
 * A subclass implements `_call()` — one prompt in, one string out — and this
 * base fans it out across a batch. Every text-in/text-out provider can be
 * expressed this way; only a provider that needs to see the whole batch at once
 * (for real batching discounts, say) needs {@see BaseLLM} directly.
 */
abstract class LLM extends BaseLLM
{
    /**
     * Answer one prompt.
     *
     * @param array<string, mixed> $options
     */
    abstract protected function call(string $prompt, array $options = [], ?CallbackManagerForLLMRun $runManager = null): string;

    /**
     * @param list<string>         $prompts
     * @param array<string, mixed> $options
     */
    protected function generatePrompts(array $prompts, array $options = [], ?CallbackManagerForLLMRun $runManager = null): LLMResult
    {
        $generations = [];
        foreach ($prompts as $index => $prompt) {
            $text = $this->call($prompt, array_merge($options, ['promptIndex' => $index]), $runManager);
            $generations[] = [new Generation($text)];
        }

        return new LLMResult($generations);
    }
}
