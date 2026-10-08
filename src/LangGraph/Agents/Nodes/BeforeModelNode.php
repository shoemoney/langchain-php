<?php

declare(strict_types=1);

namespace LangGraph\Agents\Nodes;

/**
 * Node for executing a single middleware's `beforeModel` hook (before each model call).
 *
 * Port of `BeforeModelNode` from `langchain/src/agents/nodes/BeforeModelNode.ts`.
 */
final class BeforeModelNode extends MiddlewareNode
{
    /**
     * @param array<string, mixed>|object $middleware
     */
    public function __construct(array|object $middleware)
    {
        parent::__construct($middleware, 'BeforeModelNode_' . \LangGraph\Agents\Utils::middlewareName($middleware));
    }

    protected function hookName(): string
    {
        return 'beforeModel';
    }
}
