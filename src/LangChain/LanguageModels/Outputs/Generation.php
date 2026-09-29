<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Outputs;

/**
 * One completion's text, plus whatever the provider said about producing it.
 *
 * Port of the `Generation` interface from `@langchain/core/outputs`.
 *
 * A *generation* is the result for one prompt. When a model is asked `n > 1`
 * completions there are several, and {@see LLMResult::$generations} is a list
 * per input — so the nesting is prompt-major, completion-minor.
 */
class Generation
{
    /**
     * @param string               $text          The generated text.
     * @param array<string, mixed> $generationInfo Raw provider metadata for this
     *        completion — finish reason, logprobs, model id. Providers disagree on
     *        the keys, so this is deliberately untyped.
     */
    public function __construct(
        public string $text = '',
        public array $generationInfo = [],
    ) {
    }
}
