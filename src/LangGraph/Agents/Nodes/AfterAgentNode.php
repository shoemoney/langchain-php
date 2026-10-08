<?php

declare(strict_types=1);

namespace LangGraph\Agents\Nodes;

/**
 * Node for executing a single middleware's `afterAgent` hook (after the agent finishes).
 *
 * Port of `AfterAgentNode` from `langchain/src/agents/nodes/AfterAgentNode.ts`.
 */
final class AfterAgentNode extends MiddlewareNode
{
    /**
     * @param array<string, mixed>|object $middleware
     */
    public function __construct(array|object $middleware)
    {
        parent::__construct($middleware, 'AfterAgentNode_' . \LangGraph\Agents\Utils::middlewareName($middleware));
    }

    protected function hookName(): string
    {
        return 'afterAgent';
    }
}
