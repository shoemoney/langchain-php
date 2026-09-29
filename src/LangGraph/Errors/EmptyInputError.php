<?php

declare(strict_types=1);

namespace LangGraph\Errors;

/**
 * Raised when a graph is invoked with input that produces no writes.
 *
 * Port of `EmptyInputError`. A graph that receives `null` and is not resuming
 * has nothing to do, and silently returning the current state would hide a
 * caller bug.
 */
class EmptyInputError extends BaseLangGraphError
{
    public const UNMINIFIABLE_NAME = 'EmptyInputError';

    public function __construct(string $message = 'Received empty input', array $fields = [])
    {
        parent::__construct($message, $fields);
    }
}
