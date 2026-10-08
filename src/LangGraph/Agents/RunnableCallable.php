<?php

declare(strict_types=1);

namespace LangGraph\Agents;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangGraph\Utils\RunnableCallable as BaseRunnableCallable;

/**
 * The agent flavour of `RunnableCallable`: a callable runnable that remembers what it last returned.
 *
 * Port of `RunnableCallable` from `langchain/src/agents/RunnableCallable.ts`.
 *
 * The invocation contract (the function is always called as `func($input, $config)`, the merged config
 * is published as the current config, a returned runnable is invoked when `recurse` is set) is the
 * LangGraph one, so this extends {@see BaseRunnableCallable} rather than restating it. What the agent
 * variant adds is the node state: `createAgent` shares each middleware's node outputs through
 * {@see AgentState}, which reads {@see self::getState()}. As upstream, only a plain return value is
 * recorded; a returned runnable that gets recursed into is not.
 */
class RunnableCallable extends BaseRunnableCallable
{
    private mixed $state = null;

    /**
     * @param list<string>|null $tags
     */
    public function __construct(
        callable $func,
        ?string $name = null,
        ?array $tags = null,
        bool $trace = true,
        bool $recurse = true,
    ) {
        parent::__construct(
            func: function (mixed $input, RunnableConfig $config) use ($func): mixed {
                $returnValue = $func($input, $config);
                if (!($returnValue instanceof RunnableInterface && $this->recurse)) {
                    $this->state = $returnValue;
                }

                return $returnValue;
            },
            name: $name,
            tags: $tags,
            trace: $trace,
            recurse: $recurse,
        );
    }

    /** The last value this node returned. */
    public function getState(): mixed
    {
        return $this->state;
    }

    /**
     * Merge a state patch into the recorded state, e.g. for model and middleware nodes.
     *
     * @internal
     *
     * @param array<string, mixed> $state
     */
    public function setState(array $state): void
    {
        $this->state = [...(\is_array($this->state) ? $this->state : []), ...$state];
    }
}
