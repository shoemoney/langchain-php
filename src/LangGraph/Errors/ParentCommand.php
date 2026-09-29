<?php

declare(strict_types=1);

namespace LangGraph\Errors;

use LangGraph\Pregel\Command;

/**
 * A `Command` a subgraph wants the parent graph to handle.
 *
 * Port of `ParentCommand`. A bubble-up carrying the command: the subgraph
 * cannot resolve it, so the retry layer re-addresses the command to the parent
 * namespace and rethrows, and the parent picks it up.
 */
class ParentCommand extends GraphBubbleUp
{
    public const UNMINIFIABLE_NAME = 'ParentCommand';

    public function __construct(public readonly Command $command)
    {
        parent::__construct('Parent command');
    }
}
