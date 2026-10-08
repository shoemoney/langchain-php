<?php

declare(strict_types=1);

namespace LangGraph\Agents;

/**
 * Manages the state of the agent.
 *
 * Port of `StateManager` from `langchain/src/agents/state.ts`.
 *
 * `createAgent` maintains different nodes with their own state. A user only sees the combined state of
 * all nodes; this class shares the state between them, grouped by middleware name.
 *
 * A middleware is anything with a `name`: an array with a `name` key or an object with a `name`
 * property (`createAgent`'s middleware type belongs to a later work package).
 *
 * @internal
 */
final class AgentState
{
    /** @var array<string, list<RunnableCallable>> */
    private array $nodes = [];

    /**
     * Add a node to a middleware group.
     *
     * @param array<string, mixed>|object $middleware
     */
    public function addNode(array|object $middleware, RunnableCallable $node): void
    {
        $name = Utils::middlewareName($middleware);
        $this->nodes[$name] = [...($this->nodes[$name] ?? []), $node];
    }

    /**
     * The combined state of a middleware group.
     *
     * @return array<string, mixed>
     */
    public function getState(string $name): array
    {
        $state = [];
        foreach ($this->nodes[$name] ?? [] as $node) {
            $nodeState = $node->getState();
            if (\is_array($nodeState)) {
                $state = [...$state, ...$nodeState];
            }
        }

        // `jumpTo` is reset internally and must not leak into the middleware hooks.
        unset($state['jumpTo']);

        return $state;
    }
}
