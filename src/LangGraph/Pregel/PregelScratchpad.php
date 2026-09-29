<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * Per-task state that survives an interrupt.
 *
 * Port of `PregelScratchpad` from `langgraph-core/src/pregel/types.ts`.
 *
 * A node that calls `interrupt()` suspends. On resume the node is re-executed
 * from the top, so the only way it can return a *different* answer the second
 * time is if something remembers that it already asked. That something is this
 * object, injected into the task's config:
 *
 *  - `resume` — the values queued for this task, in order;
 *  - `interruptCounter` — how many interrupts this task has already consumed, so
 *    the Nth `interrupt()` call in a node takes the Nth resume value;
 *  - `nullResume` — a resume value addressed to the graph rather than to one
 *    specific task.
 *
 * `consumeNullResume()` is idempotent by design: the underlying write is
 * deleted the first time it is taken, so a task that asks twice does not
 * silently get the same answer twice.
 */
final class PregelScratchpad
{
    public int $callCounter = 0;

    public int $interruptCounter = -1;

    public int $subgraphCounter = 0;

    /** The input this task was given, so a resume can re-derive its state. */
    public mixed $currentTaskInput = null;

    /**
     * @param list<mixed> $resume     Resume values for this task, in order.
     * @param mixed       $nullResume A resume value for the graph as a whole.
     */
    public function __construct(
        public array $resume = [],
        public mixed $nullResume = null,
        mixed $currentTaskInput = null,
    ) {
        $this->currentTaskInput = $currentTaskInput;
    }

    /**
     * Take the graph-level resume value, once.
     *
     * Returns the value the first time and `null` afterwards. The nulling is
     * the point: a node that loops and calls this again must see "nothing
     * waiting", not a replay of the same answer.
     */
    public function consumeNullResume(): mixed
    {
        if ($this->nullResume !== null) {
            $value = $this->nullResume;
            $this->nullResume = null;

            return $value;
        }

        return null;
    }

    /** The next resume value for this task, in queue order. */
    public function consumeResume(): mixed
    {
        return $this->resume[0] ?? null;
    }

    /**
     * The config of the task currently executing, if any.
     *
     * A node's body has no `$config` parameter — user nodes are called as
     * `fn (array $state) => ...` — but `interrupt()` and `Command` need to reach
     * the scratchpad that only the engine knows about. This is the thread of
     * execution the engine publishes them on.
     *
     * PHP has no `AsyncLocalStorage`, but the engine is synchronous and nested
     * tasks run inside the parent's call stack, so a plain static is both
     * sufficient and correct. The previous value is always restored, including
     * on throw, so a sibling task in the same superstep cannot see it.
     */
    private static ?\LangChain\Runnables\RunnableConfig $current = null;

    public static function setCurrentConfig(?\LangChain\Runnables\RunnableConfig $config): void
    {
        self::$current = $config;
    }

    public static function currentConfig(): ?\LangChain\Runnables\RunnableConfig
    {
        return self::$current;
    }

    /**
     * Run `$fn` with `$config` published as the current task config.
     *
     * @template T
     * @param  callable(): T $fn
     * @return T
     */
    public static function withConfig(?\LangChain\Runnables\RunnableConfig $config, callable $fn): mixed
    {
        $previous = self::$current;
        self::$current = $config;
        try {
            return $fn();
        } finally {
            self::$current = $previous;
        }
    }
}
