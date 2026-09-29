<?php

declare(strict_types=1);

namespace LangGraph\Errors;

/**
 * Raised when a step writes an invalid sequence of updates to a channel.
 *
 * Port of `InvalidUpdateError` from `langgraph-core/src/errors.ts`. Typically
 * means two nodes wrote conflicting values to a single-value channel in the
 * same superstep.
 */
class InvalidUpdateError extends BaseLangGraphError
{
    public function __construct(string $message = 'Invalid update', array $fields = [])
    {
        parent::__construct($message, $fields + ['lc_error_code' => 'INVALID_UPDATE']);
    }
}
