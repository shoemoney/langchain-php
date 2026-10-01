<?php

declare(strict_types=1);

namespace LangChain\Tools;

use LangChain\Runnables\RunnableConfig;

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
trait InjectsToolRuntime
{
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
