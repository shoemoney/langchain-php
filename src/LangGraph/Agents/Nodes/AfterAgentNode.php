<?php

declare(strict_types=1);

namespace LangGraph\Agents\Nodes;

use LangGraph\Agents\Utils as AgentUtils;

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
        parent::__construct($middleware, 'AfterAgentNode_' . AgentUtils::middlewareName($middleware));
    }

    protected function hookName(): string
    {
        return 'afterAgent';
    }
}
