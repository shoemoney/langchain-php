<?php

declare(strict_types=1);

namespace LangGraph\State;

use LangGraph\Channels\BaseChannel;

/**
 * What a `StateGraph` remembers about a node beyond the {@see \LangGraph\Pregel\PregelNode} it stores.
 *
 * Port of `StateGraphNodeSpec` from `langgraph-core/src/graph/state.ts`. Upstream keeps the runnable,
 * the policies and these extras on one object. This port stores the runnable and retry policy on the
 * `PregelNode` (which `Graph` requires) and keeps here only the fields a `PregelNode` cannot hold:
 * the node's own input channels, a `cachePolicy` of `false` (the explicit opt-out of a graph
 * default, which a `?array` cannot express), `defer`, and the error handler.
 */
final class StateGraphNodeSpec
{
    /**
     * @param array<string, BaseChannel>  $input         Channels the node reads (its own `input`, or the graph state).
     * @param array<string, mixed>|false|null $cachePolicy `false` opts out of a graph default.
     * @param callable|null               $errorHandler  `fn(mixed $state, NodeError $error, ?RunnableConfig $config)`.
     */
    public function __construct(
        public array $input,
        public array|false|null $cachePolicy = null,
        public mixed $timeout = null,
        public bool $defer = false,
        public mixed $errorHandler = null,
        public bool $isErrorHandler = false,
    ) {
    }
}
