<?php

declare(strict_types=1);

namespace LangChain\Prompts;

/**
 * A prompt input error that a chain can recover from.
 *
 * Stands in for `addLangChainErrorFields(err, "INVALID_PROMPT_INPUT")` in the
 * TypeScript original. The code is on the exception because callers — an agent
 * loop deciding whether to retry, a validator deciding whether to fail the run
 * — branch on it, and reading it back off a message string would be a trap.
 */
class PromptInputError extends \RuntimeException
{
    /** The `lc_error_code` stamped on this failure. */
    public string $lcErrorCode = 'INVALID_PROMPT_INPUT';
}
