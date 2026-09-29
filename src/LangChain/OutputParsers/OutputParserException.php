<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

/**
 * The exception output parsers raise to signal a *parsing* failure.
 *
 * Port of `OutputParserException` from `@langchain_core/output_parsers/base`.
 *
 * The class exists so a chain can tell "the model's text did not match the
 * shape I asked for" apart from "a bug in my parser". An agent loop catches the
 * former, feeds the failure back to the model as an observation, and lets the
 * model retry; the latter propagates.
 */
class OutputParserException extends \RuntimeException
{
    /** The `lc_error_code` stamped on this failure. */
    public string $lcErrorCode = 'OUTPUT_PARSING_FAILURE';

    /**
     * @param string      $message     Human-readable description of the failure.
     * @param string|null $llmOutput   The model output that failed to parse.
     * @param string|null $observation A remediation hint that can be sent back to the model.
     * @param bool        $sendToLLM   Whether an agent should forward the observation.
     */
    public function __construct(
        string $message,
        public ?string $llmOutput = null,
        public ?string $observation = null,
        public bool $sendToLLM = false,
    ) {
        parent::__construct($message);

        if ($sendToLLM && ($observation === null || $llmOutput === null)) {
            throw new \InvalidArgumentException(
                "Arguments 'observation' & 'llmOutput' are required if 'sendToLlm' is true"
            );
        }
    }
}
