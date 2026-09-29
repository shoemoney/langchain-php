<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Outputs;

/**
 * What a chat model returns for one call.
 *
 * Port of the `ChatResult` interface from `@langchain/core/outputs`.
 *
 * Note this is FLAT — a list of generations — whereas {@see LLMResult} is a
 * list of those lists. A `ChatResult` is what one `_generate()` call produced
 * for one set of messages; the extra nesting on `LLMResult` exists because a
 * batch of prompts produces one `ChatResult` per prompt.
 */
final class ChatResult
{
    /**
     * @param list<ChatGeneration>  $generations
     * @param array<string, mixed>  $llmOutput Raw provider output, passed through
     *        untouched. This is where a provider's token accounting and model id
     *        ride, so it is untyped by necessity.
     */
    public function __construct(
        public array $generations = [],
        public array $llmOutput = [],
    ) {
    }
}
