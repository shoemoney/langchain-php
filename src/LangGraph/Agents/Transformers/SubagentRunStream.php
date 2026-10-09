<?php

declare(strict_types=1);

namespace LangGraph\Agents\Transformers;

use LangGraph\Stream\Deferred;
use LangGraph\Stream\StreamChannel;

/**
 * A nested named-agent execution surfaced on `run.subagents`.
 *
 * Port of `SubagentRunStream` from `langchain/src/agents/transformers/types.ts`. A subagent is a nested agent run
 * whose `lc_agent_name` differs from its parent's, e.g. an agent created with a `name` and invoked inside a tool
 * body. The handle exposes scoped projections plus the resolved name, the cause that triggered it, and the
 * subagent's final output state.
 */
final class SubagentRunStream
{
    /**
     * @param string                                         $name      the subagent's `lc_agent_name`
     * @param array{type: 'toolCall', tool_call_id: string}|null $cause the tool call that dispatched it, when it could be recovered
     * @param Deferred                                       $output    resolves with the subagent's final state once it completes
     * @param \IteratorAggregate                              $messages  one {@see \LangGraph\Stream\ChatModelStream} per message, scoped to this subagent
     * @param StreamChannel                                  $toolCalls the subagent's own {@see ToolCallStream}s
     * @param StreamChannel                                  $subagents nested {@see SubagentRunStream}s this subagent dispatches from its own tools
     */
    public function __construct(
        public readonly string $name,
        public readonly ?array $cause,
        public readonly Deferred $output,
        public readonly \IteratorAggregate $messages,
        public readonly StreamChannel $toolCalls,
        public readonly StreamChannel $subagents,
    ) {
    }
}
