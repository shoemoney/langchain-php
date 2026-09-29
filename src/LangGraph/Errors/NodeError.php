<?php

declare(strict_types=1);

namespace LangGraph\Errors;

use LangGraph\Pregel\Command;

/**
 * Failure context handed to a node-level error handler.
 *
 * Port of `NodeError` from `langgraph-core/src/errors.ts`.
 *
 * A node-level error handler is registered with
 * `StateGraph::addNode($name, $fn, ['errorHandler' => $handler])`. The handler
 * runs only after the failing node's retry policy is exhausted, so retry and
 * handling stay decoupled. It receives the failed node's name and the thrown
 * error here, may return a state update, and may route to a recovery branch
 * with `new Command(['goto' => ...])` (saga / compensation flows).
 */
class NodeError
{
    public const UNMINIFIABLE_NAME = 'NodeError';

    public function __construct(
        public readonly string $node,
        public readonly \Throwable $error,
    ) {
    }
}
