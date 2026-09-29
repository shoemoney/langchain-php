<?php

declare(strict_types=1);

namespace LangGraph\Errors;

/**
 * Base class for the errors that control the Pregel loop's own flow.
 *
 * Port of `GraphBubbleUp` from `langgraph-core/src/errors.ts`.
 *
 * A bubble-up is an error thrown by the engine *about* the engine: an
 * interrupt, a cooperative drain, a `Command` addressed to a parent graph. It
 * is never a node failure. The distinction is load-bearing, and the retry layer
 * branches on it: `_runWithRetry` re-throws a bubble-up immediately without
 * consuming a retry attempt, because retrying an interrupt would just interrupt
 * again.
 *
 * Anything that does NOT extend this class is treated as a node error and is
 * subject to the retry policy and, potentially, an error handler.
 */
class GraphBubbleUp extends BaseLangGraphError
{
    /**
     * Marker read by the retry layer and the runner.
     *
     * Kept as a method rather than a property so a subclass cannot accidentally
     * shadow it with a differently-typed value.
     */
    public function isBubbleUp(): bool
    {
        return true;
    }
}
