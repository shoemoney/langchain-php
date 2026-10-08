<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt\Supervisor;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangGraph\Errors\ParentCommand;
use LangGraph\Pregel\Command;

/**
 * Delivers a `Command(graph: PARENT)` raised inside an agent subgraph to the graph that holds the agent.
 *
 * Upstream's `_runWithRetry` catches the `ParentCommand` a subgraph raises, re-addresses it to the
 * enclosing namespace and applies it with the enclosing node's writers. This port's runner does not do that
 * yet: the exception escapes every graph and the run dies. A handoff tool is exactly such a command, so the
 * multi-agent graphs run each agent through this bridge: it invokes the agent, and if the agent ends with a
 * parent command it returns the same command addressed to the enclosing graph (`graph` cleared), which the
 * enclosing node's writer applies like any node returning a `Command`. Used by {@see Supervisor} and
 * {@see \LangGraph\Prebuilt\Swarm\Swarm}.
 */
final class ParentCommandBridge
{
    private function __construct()
    {
    }

    /**
     * @return \Closure(mixed, RunnableConfig): mixed
     */
    public static function wrap(RunnableInterface $agent): \Closure
    {
        return static function (mixed $state, RunnableConfig $config) use ($agent): mixed {
            try {
                return $agent->invoke($state, $config);
            } catch (ParentCommand $e) {
                if ($e->command->graph !== Command::PARENT) {
                    throw $e;
                }

                return new Command(
                    update: $e->command->update,
                    resume: $e->command->resume,
                    goto: $e->command->goto,
                );
            }
        };
    }
}
