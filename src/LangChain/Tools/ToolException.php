<?php

declare(strict_types=1);

namespace LangChain\Tools;

/**
 * Raised when a tool's arguments do not match its schema.
 *
 * Port of `ToolInputParsingException` from `@langchain/core/tools/utils`.
 *
 * This is a distinct type from a generic error on purpose: a tool call whose
 * arguments the model got wrong is an expected, recoverable condition — an agent
 * loop catches it, feeds the message back, and lets the model retry. Burying it
 * in a generic exception makes that distinction invisible at the catch site.
 *
 * `$output` carries the raw input that failed, which is the thing worth putting
 * in the retry message: the model needs to see what it actually sent, not a
 * re-serialised version of it.
 */
class ToolException extends \RuntimeException
{
    public ?string $output;

    public function __construct(string $message, ?string $output = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->output = $output;
    }
}
