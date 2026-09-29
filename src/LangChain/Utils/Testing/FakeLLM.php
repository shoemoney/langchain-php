<?php

declare(strict_types=1);

namespace LangChain\Utils\Testing;

use LangChain\LanguageModels\LLM;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * A completion model that echoes its prompt.
 *
 * Port of `FakeLLM` from `@langchain/core/utils/testing`.
 *
 * Echoing the prompt rather than returning a canned string is deliberate: it
 * makes the output *depend on the input*, so a test that pipes two different
 * prompts through a chain can tell from the output which one produced it. A
 * fixed response would let a mis-wired chain pass.
 *
 * `thrownErrorString` makes the model fail on demand, so error paths can be
 * exercised without a provider that actually errors.
 */
final class FakeLLM extends LLM
{
    public ?string $response = null;

    public ?string $thrownErrorString = null;

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);
        $this->response = isset($fields['response']) ? (string) $fields['response'] : null;
        $this->thrownErrorString = isset($fields['thrownErrorString']) ? (string) $fields['thrownErrorString'] : null;
    }

    public function llmType(): string
    {
        return 'fake';
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function call(string $prompt, array $options = [], ?CallbackManagerForLLMRun $runManager = null): string
    {
        if ($this->thrownErrorString !== null) {
            throw new \RuntimeException($this->thrownErrorString);
        }

        $response = $this->response ?? $prompt;

        // The single token callback matters: it is what lets a test assert that
        // callbacks reach the model body, which is otherwise unobservable
        // because the non-streaming path emits nothing per token.
        $runManager?->handleLLMNewToken($response);

        return $response;
    }
}
