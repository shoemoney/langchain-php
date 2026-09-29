<?php

declare(strict_types=1);

namespace LangGraph\Errors;

/**
 * The interrupt raised by a node calling `interrupt()`.
 *
 * Port of `NodeInterrupt`. Extends {@see GraphInterrupt} so the loop treats it
 * as a pause, and exists as its own class so a caller can distinguish an
 * engine-level interrupt (a superstep boundary or subgraph) from one a node
 * asked for.
 */
class NodeInterrupt extends GraphInterrupt
{
    public const UNMINIFIABLE_NAME = 'NodeInterrupt';

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(mixed $message = null, array $fields = [])
    {
        parent::__construct([['id' => null, 'value' => $message]], $fields);
    }
}
