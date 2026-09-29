<?php

declare(strict_types=1);

namespace LangChain\Utils\Testing;

use LangChain\LanguageModels\BaseLLM;
use LangChain\LanguageModels\Outputs\Generation;
use LangChain\LanguageModels\Outputs\GenerationChunk;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * A completion model that streams its response one character at a time.
 *
 * Port of `FakeStreamingLLM` from `@langchain/core/utils/testing`.
 *
 * `responses` is consumed from the front, one entry per call, so a test can
 * script an exact sequence of outputs and then assert the queue drained. The
 * final entry repeats — the list is a loop, not a script to run out of — so a
 * test that makes one call more than expected still gets an answer instead of
 * `null`.
 *
 * Character-by-character is the point: a chunk-per-word stream would hide
 * exactly the bugs streaming exists to expose, where a token boundary splits a
 * UTF-8 sequence or a tool-call argument.
 */
final class FakeStreamingLLM extends BaseLLM
{
    /** Milliseconds between characters. Zero in tests; a real delay makes demos readable. */
    public int $sleep = 0;

    /** @var list<string> */
    public array $responses = [];

    public ?string $thrownErrorString = null;

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);
        $this->sleep = isset($fields['sleep']) ? (int) $fields['sleep'] : 0;
        $this->responses = array_values((array) ($fields['responses'] ?? []));
        $this->thrownErrorString = isset($fields['thrownErrorString']) ? (string) $fields['thrownErrorString'] : null;
    }

    public function llmType(): string
    {
        return 'fake';
    }

    /**
     * @param list<string>         $prompts
     * @param array<string, mixed> $options
     */
    protected function generatePrompts(array $prompts, array $options = [], ?CallbackManagerForLLMRun $runManager = null): LLMResult
    {
        if ($this->thrownErrorString !== null) {
            throw new \RuntimeException($this->thrownErrorString);
        }

        $generations = [];
        foreach ($prompts as $prompt) {
            $generations[] = [new Generation($this->nextResponse($prompt))];
        }

        return new LLMResult($generations);
    }

    /**
     * @param array<string, mixed> $options
     * @return \Generator<int, GenerationChunk>
     */
    protected function streamResponseChunks(
        string $prompt,
        array $options = [],
        ?CallbackManagerForLLMRun $runManager = null,
    ): \Generator {
        if ($this->thrownErrorString !== null) {
            throw new \RuntimeException($this->thrownErrorString);
        }

        $response = $this->nextResponse($prompt);

        // Split by code point, not byte: an emoji or an accented character is
        // one token to a model and several bytes to PHP, and splitting bytes
        // would emit invalid UTF-8 mid-stream. Nor by UTF-16 code unit — that
        // would split an astral character into a lone surrogate, which has no
        // valid UTF-8 encoding at all.
        foreach ($response === '' ? [] : mb_str_split($response) as $char) {
            if ($this->sleep > 0) {
                usleep($this->sleep * 1000);
            }

            yield new GenerationChunk($char, []);
            $runManager?->handleLLMNewToken($char);
        }
    }

    /**
     * Take the next scripted response off the front of the queue.
     *
     * Unlike {@see FakeListChatModel}, this queue *drains* rather than looping.
     * Once it is empty the model falls back to echoing the prompt, so a test
     * that makes more calls than it scripted still gets a deterministic,
     * input-dependent answer instead of `null`. A `null` would fail in a way
     * that reads as a bug in the model under test rather than in the test.
     */
    private function nextResponse(string $prompt): string
    {
        if ($this->responses === []) {
            return $prompt;
        }

        $response = $this->responses[0];
        $this->responses = array_slice($this->responses, 1);

        return $response;
    }
}
