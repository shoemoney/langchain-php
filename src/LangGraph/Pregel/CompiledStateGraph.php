<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangGraph\State\StateGraph;

/**
 * A {@see Pregel} graph whose state is declared as a set of channels.
 *
 * Port of `CompiledStateGraph` from `langgraph-core/src/graph/state.ts`.
 *
 * Structurally this adds almost nothing over {@see Pregel} — that is the point
 * of the split. `StateGraph` is the builder that knows about reducers, edges,
 * and conditional routing; `CompiledStateGraph` is the result, which is just a
 * Pregel graph with a state-shaped input and output. Being able to hold a
 * compiled state graph as a plain `Pregel` is what lets one graph be used as a
 * node inside another.
 */
class CompiledStateGraph extends Pregel
{
    public function __construct(
        array $nodes = [],
        array $channels = [],
        string|array $inputChannels = [],
        string|array $outputChannels = [],
        array $streamChannels = [],
        ?Checkpoint\BaseCheckpointSaver $checkpointer = null,
        array $interruptBefore = [],
        array $interruptAfter = [],
        ?string $name = null,
        ?string $description = null,
        array $streamMode = ['updates', 'values'],
        ?Retry\RetryPolicy $retryPolicy = null,
        array $triggerToNodes = [],
        public readonly ?StateGraph $builder = null,
    ) {
        parent::__construct(
            nodes: $nodes,
            channels: $channels,
            inputChannels: $inputChannels,
            outputChannels: $outputChannels,
            streamChannels: $streamChannels,
            checkpointer: $checkpointer,
            streamMode: $streamMode,
            retryPolicy: $retryPolicy,
            triggerToNodes: $triggerToNodes,
            name: $name,
            interruptBefore: $interruptBefore,
            interruptAfter: $interruptAfter,
            description: $description,
        );
    }
}
