<?php

declare(strict_types=1);

namespace LangGraph\Errors;

/**
 * A value error raised by the graph engine itself.
 *
 * Port of `GraphValueError`. Distinct from {@see \InvalidArgumentException} so
 * the retry layer's default `retryOn` handler can recognise it by type and
 * decline to retry — a bad value never becomes good by being tried again.
 */
class GraphValueError extends BaseLangGraphError
{
    public const UNMINIFIABLE_NAME = 'GraphValueError';

    public function __construct(string $message = '', array $fields = [])
    {
        parent::__construct($message, $fields);
    }
}
