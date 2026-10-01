<?php

declare(strict_types=1);

namespace LangChain\Tools;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\CallbackManagerForToolRun;

/**
 * Injects a {@see ToolRuntime} into a tool function that declares one.
 *
 * Upstream `tools/types.ts:472-486`:
 *
 *     When a tool function has a parameter typed `ToolRuntime`, the tool execution system will
 *     automatically inject an instance containing state, toolCallId, config, context, store, writer.
 *     No `Annotated` wrapper is needed - just use `runtime: ToolRuntime` as a parameter.
 *
 * Upstream detects the runtime by TYPE, so the type hint is the entire signal — there is no wrapper object
 * to recognise and no config flag to set.
 *
 * **This lives in a trait because two classes need it and the logic must not drift.** `DynamicTool` and
 * `DynamicStructuredTool` have byte-identical invocation sites, and 458's first attempt pasted the helper
 * into both — which is precisely the hand-rolled duplication 412 removed from the five `batch()`
 * implementations and 433 spent an iteration hunting. One implementation, two consumers.
 *
 * **The injection is conditional, and that is the load-bearing decision.** `(input, runManager, config)` is
 * the OLDER upstream tool signature and this port implements it correctly, so swapping the second argument
 * unconditionally would break every tool already written against that shape.
 */
trait InvokesToolCallable
{
    /**
     * Stamp this tool's name onto the config when the caller did not set one, then run the shared path.
     *
     * Identical in `DynamicTool` and `DynamicStructuredTool`; 460 measured the pair and 461's scripted
     * attempt to extract it half-applied, so it moves here one method at a time with the control re-run
     * after each step.
     */
    public function call(mixed $arg, ?RunnableConfig $config = null, ?array $tags = null): mixed
    {
        $config ??= new RunnableConfig();
        if ($config->runName === null) {
            $config = clone $config;
            $config->runName = $this->name;
        }

        return parent::call($arg, $config, $tags);
    }

    protected function callTool(mixed $arg, ?CallbackManagerForToolRun $runManager = null, ?RunnableConfig $parentConfig = null): mixed
    {
        // Upstream `tools/types.ts:472-486`: a tool function with a parameter typed `ToolRuntime` has one
        // AUTOMATICALLY INJECTED, carrying state / toolCallId / config / context / store / writer, with
        // "no `Annotated` wrapper needed". The class shipped but nothing ever constructed one, so a tool
        // written correctly against that documentation died with a TypeError naming two unrelated classes.
        //
        // Conditional on the type hint, deliberately: `(input, runManager, config)` is the OLDER upstream
        // tool signature and this port implements it correctly, so swapping the second argument
        // unconditionally would break every tool already written against that shape.
        return ($this->func)($arg, self::secondToolArgument($this->func, $runManager, $parentConfig), $parentConfig);
    }

    /**
     * The run manager, unless the callable declares a `ToolRuntime` in its second parameter.
     *
     * `ToolRuntime::fromConfig()` returns null when the config carries no `toolCall`, which is the case for
     * a tool invoked outside a tool-call context — upstream injects a runtime there too, so the fallback is
     * an empty runtime rather than the run manager.
     */
    private static function secondToolArgument(
        callable $func,
        mixed $runManager,
        ?RunnableConfig $config,
    ): mixed {
        try {
            $ref = \is_array($func)
                ? new \ReflectionMethod($func[0], $func[1])
                : new \ReflectionFunction($func);
        } catch (\ReflectionException) {
            return $runManager;
        }

        $params = $ref->getParameters();
        if (!isset($params[1])) {
            return $runManager;
        }

        $type = $params[1]->getType();
        if (!$type instanceof \ReflectionNamedType || $type->getName() !== ToolRuntime::class) {
            return $runManager;
        }

        return ToolRuntime::fromConfig($config) ?? new ToolRuntime();
    }
}
