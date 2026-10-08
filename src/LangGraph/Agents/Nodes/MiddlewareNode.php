<?php

declare(strict_types=1);

namespace LangGraph\Agents\Nodes;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Agents\Middleware\Utils as MiddlewareUtils;
use LangGraph\Agents\RunnableCallable;
use LangGraph\Agents\Runtime;
use LangGraph\Agents\Utils as AgentUtils;
use LangGraph\Pregel\Command;

/**
 * Base of the graph nodes that run one middleware's `beforeAgent`, `beforeModel`, `afterModel` or `afterAgent` hook.
 *
 * Port of `MiddlewareNode` from `langchain/src/agents/nodes/middleware.ts`.
 *
 * The node builds the hook's inputs (the node state with `messages` kept as received, and a read-only
 * {@see Runtime} whose context is the run context filtered through the middleware's `contextSchema`), runs the
 * hook, checks any `jumpTo` against the hook's `canJumpTo`, and returns the state merged with the update plus
 * `jumpTo`, which the agent's routers read.
 */
abstract class MiddlewareNode extends RunnableCallable
{
    /** @var array<string, mixed>|object */
    public array|object $middleware;

    /**
     * @param array<string, mixed>|object $middleware
     */
    public function __construct(array|object $middleware, string $name)
    {
        $this->middleware = $middleware;

        parent::__construct(
            func: fn (mixed $state, RunnableConfig $config): mixed => $this->invokeMiddleware($state, $config),
            name: $name,
        );
    }

    /** The middleware property holding the hook this node runs, e.g. `beforeModel`. */
    abstract protected function hookName(): string;

    /**
     * Run the hook with the node's state and runtime.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>|Command|null
     */
    protected function runHook(array $state, Runtime $runtime): mixed
    {
        $hook = AgentUtils::middlewareValue($this->middleware, $this->hookName());

        return MiddlewareUtils::getHookFunction($hook)($state, $runtime);
    }

    /**
     * @return array<string, mixed>|Command
     */
    public function invokeMiddleware(mixed $invokeState, ?RunnableConfig $config = null): mixed
    {
        $config ??= new RunnableConfig();
        $middlewareName = AgentUtils::middlewareName($this->middleware);

        // Filter the context based on the middleware's contextSchema: only the fields relevant to the
        // schema, parsed so defaults apply and a missing required field raises.
        $contextSchema = AgentUtils::middlewareValue($this->middleware, 'contextSchema');
        $filteredContext = \is_array($contextSchema)
            ? MiddlewareUtils::parseContext($contextSchema, $config->context, $middlewareName)
            : [];

        // Don't overwrite possibly outdated messages from other middleware nodes.
        $state = \is_array($invokeState) ? $invokeState : [];

        $runtime = Runtime::fromConfig($config)->with(['context' => $filteredContext]);

        $result = $this->runHook($state, $runtime);

        // The hook made no state changes: return only the jumpTo sentinel so we don't re-emit every input
        // key as a state update.
        if ($result === null || $result === false) {
            return ['jumpTo' => null];
        }

        if ($result instanceof Command) {
            return $result;
        }

        $result = (array) $result;

        // Verify that the jump target is allowed for the middleware.
        $hookName = $this->hookName();
        $jumpToConstraint = MiddlewareUtils::getHookConstraint(AgentUtils::middlewareValue($this->middleware, $hookName));
        $constraint = $hookName . '.canJumpTo';

        $jumpTo = $result['jumpTo'] ?? null;
        if (\is_string($jumpTo) && !\in_array($jumpTo, $jumpToConstraint ?? [], true)) {
            $suggestion = $jumpToConstraint !== null && $jumpToConstraint !== []
                ? 'must be one of: ' . implode(', ', $jumpToConstraint) . '.'
                : 'no ' . $constraint . ' defined in middleware ' . $middlewareName;

            throw new \Exception('Invalid jump target: ' . $jumpTo . ', ' . $suggestion . '.');
        }

        // A control action.
        if (\array_key_exists('type', $result)) {
            if ($result['type'] === 'terminate') {
                if (($result['error'] ?? null) instanceof \Throwable) {
                    throw $result['error'];
                }

                return [...$state, ...(array) ($result['result'] ?? []), 'jumpTo' => $jumpTo];
            }

            throw new \Exception('Invalid control action: ' . json_encode($result));
        }

        // A state update: merge it with the current state.
        return [...$state, ...$result, 'jumpTo' => $jumpTo];
    }

    /**
     * The options the node is added to the graph with: its input is the middleware's (private) state.
     *
     * @return array{input: array<string, mixed>}
     */
    public function nodeOptions(): array
    {
        return [
            'input' => Utils::derivePrivateState(AgentUtils::middlewareValue($this->middleware, 'stateSchema')),
        ];
    }
}
