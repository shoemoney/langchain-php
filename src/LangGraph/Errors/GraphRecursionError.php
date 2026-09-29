<?php

declare(strict_types=1);

namespace LangGraph\Errors;

/**
 * Raised when a graph runs more supersteps than the recursion limit allows.
 *
 * Port of `GraphRecursionError`.
 *
 * A well-behaved graph terminates because no node is triggered. A graph that
 * hits the limit instead has a cycle no stop condition reaches, so this is the
 * engine's guard against an unbounded run — not a bug report about the graph's
 * logic being wrong, but a statement that it did not converge.
 */
class GraphRecursionError extends BaseLangGraphError
{
    public const UNMINIFIABLE_NAME = 'GraphRecursionError';

    public function __construct(string $message = 'Recursion limit of 25 reached', array $fields = [])
    {
        parent::__construct($message, $fields + ['lc_error_code' => 'GRAPH_RECURSION_LIMIT']);
    }
}
