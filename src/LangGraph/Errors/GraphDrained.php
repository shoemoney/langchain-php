<?php

declare(strict_types=1);

namespace LangGraph\Errors;

/**
 * Raised when a graph stops cooperatively at a superstep boundary.
 *
 * Port of `GraphDrained`.
 *
 * A drain is not a failure: the run stops where it is, its checkpoint is
 * saved, and it can be resumed later. It is a bubble-up so it propagates
 * through a subgraph to the parent without being caught by a node's `catch`.
 */
class GraphDrained extends GraphBubbleUp
{
    public const UNMINIFIABLE_NAME = 'GraphDrained';

    public function __construct(public readonly string $reason = 'shutdown', array $fields = [])
    {
        parent::__construct('Graph drained: ' . $reason, $fields);
    }
}
