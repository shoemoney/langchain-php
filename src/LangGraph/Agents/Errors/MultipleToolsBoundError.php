<?php

declare(strict_types=1);

namespace LangGraph\Agents\Errors;

/**
 * Raised when `createAgent` is handed a model that already has tools bound.
 *
 * Port of `MultipleToolsBoundError` from `langchain/src/agents/errors.ts`.
 */
class MultipleToolsBoundError extends \Exception
{
    public function __construct()
    {
        parent::__construct(
            'The provided LLM already has bound tools. '
            . 'Please provide an LLM without bound tools to createAgent. '
            . "The agent will bind the tools provided in the 'tools' parameter."
        );
    }
}
