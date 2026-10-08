<?php

declare(strict_types=1);

namespace LangGraph\Agents\Nodes;

use LangGraph\Agents\Utils as AgentUtils;

/**
 * Node for executing a single middleware's `beforeAgent` hook (before the agent starts).
 *
 * Port of `BeforeAgentNode` from `langchain/src/agents/nodes/BeforeAgentNode.ts`.
 */
final class BeforeAgentNode extends MiddlewareNode
{
    /**
     * @param array<string, mixed>|object $middleware
     */
    public function __construct(array|object $middleware)
    {
        parent::__construct($middleware, 'BeforeAgentNode_' . AgentUtils::middlewareName($middleware));
    }

    protected function hookName(): string
    {
        return 'beforeAgent';
    }
}
