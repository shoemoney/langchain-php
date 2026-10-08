<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * Raised when a hand-assembled graph is structurally invalid.
 *
 * Port of `GraphValidationError` from `langgraph-core/src/pregel/validate.ts`.
 *
 * Upstream gives it the name `GraphValidationError` and no error code, so unlike
 * the `BaseLangGraphError` family it is not minified or code-tagged.
 */
class GraphValidationError extends \Exception
{
    public function __construct(string $message = '')
    {
        parent::__construct($message);
    }
}
