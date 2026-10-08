<?php

declare(strict_types=1);

namespace LangGraph\Utils;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangGraph\Pregel\PregelScratchpad;

/**
 * Wrap a callable as a runnable that always receives the run config.
 *
 * Port of `RunnableCallable` from `langgraph-core/src/utils.ts`.
 *
 * Unlike {@see \LangChain\Runnables\RunnableLambda}, which inspects the callable's
 * signature to decide whether to hand it the config, this class has one fixed
 * contract, exactly as upstream: the function is always called as
 * `func($input, $config)`.
 *
 * Two behaviours are what the class exists for:
 *  - the merged config is published as the *current* config for the duration of
 *    the call, so `interrupt()` and anything else reading the task context from
 *    inside the function finds it (upstream's `AsyncLocalStorage.runWithConfig`);
 *  - when `$recurse` is set and the function returns a runnable, that runnable is
 *    invoked with the same input, which is how a node can return a subgraph.
 *
 * `$trace` is stored but inert: this port's runnables do not emit callback-manager
 * runs, so there is no traced path to choose.
 */
class RunnableCallable extends Runnable
{
    /** @var callable */
    public $func;

    public ?RunnableConfig $config;

    public bool $trace;

    public bool $recurse;

    private string $name;

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
        $this->func = $func;
        $this->name = $name ?? self::nameOf($func);
        $this->config = $tags !== null && $tags !== [] ? new RunnableConfig(tags: $tags) : null;
        $this->trace = $trace;
        $this->recurse = $recurse;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $ensured = $config ?? PregelScratchpad::currentConfig() ?? new RunnableConfig();
        $merged = RunnableConfig::mergeConfigs($this->config, $ensured);

        $returnValue = PregelScratchpad::withConfig(
            $merged,
            fn (): mixed => ($this->func)($input, $merged),
        );

        if ($returnValue instanceof RunnableInterface && $this->recurse) {
            return PregelScratchpad::withConfig(
                $merged,
                static fn (): mixed => $returnValue->invoke($input, $merged),
            );
        }

        return $returnValue;
    }

    private static function nameOf(callable $func): string
    {
        return match (true) {
            \is_string($func) => $func,
            \is_array($func) => (string) $func[1],
            default => 'RunnableCallable',
        };
    }
}
