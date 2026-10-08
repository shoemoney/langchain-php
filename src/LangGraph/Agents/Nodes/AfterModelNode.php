<?php

declare(strict_types=1);

namespace LangGraph\Agents\Nodes;

/**
 * Node for executing a single middleware's `afterModel` hook (after each model call).
 *
 * Port of `AfterModelNode` from `langchain/src/agents/nodes/AfterModelNode.ts`.
 */
final class AfterModelNode extends MiddlewareNode
{
    /**
     * @param array<string, mixed>|object $middleware
     */
    public function __construct(array|object $middleware)
    {
        parent::__construct($middleware, 'AfterModelNode_' . \LangGraph\Agents\Utils::middlewareName($middleware));
    }

    protected function hookName(): string
    {
        return 'afterModel';
    }
}
