<?php

declare(strict_types=1);

namespace LangGraph\Agents\Errors;

/**
 * Raised when the model returns multiple structured output tool calls when only one is expected.
 *
 * Port of `MultipleStructuredOutputsError` from `langchain/src/agents/errors.ts`.
 */
class MultipleStructuredOutputsError extends \Exception
{
    /** @param list<string> $toolNames */
    public function __construct(public readonly array $toolNames)
    {
        parent::__construct(
            'The model has called multiple tools: ' . implode(', ', $toolNames)
            . ' to return a structured output. '
            . 'This is not supported. Please provide a single structured output.'
        );
    }
}
