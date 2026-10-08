<?php

declare(strict_types=1);

namespace LangGraph\State;

use LangGraph\Errors\BaseLangGraphError;

/**
 * Thrown when a `StateGraph` is constructed from something that is not a state schema.
 *
 * Port of `StateGraphInputError` from `langgraph-core/src/errors.ts`. Like upstream, the message is
 * fixed: whatever the caller passes, the error says what a valid schema looks like.
 */
class StateGraphInputError extends BaseLangGraphError
{
    public const UNMINIFIABLE_NAME = 'StateGraphInputError';

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(string $message = '', array $fields = [])
    {
        parent::__construct(
            'Invalid StateGraph input. Make sure to pass a valid StateDefinition, Annotation.Root, or JSON Schema.',
            $fields,
        );
    }
}
