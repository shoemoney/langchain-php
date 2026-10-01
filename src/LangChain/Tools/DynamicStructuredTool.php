<?php

declare(strict_types=1);

namespace LangChain\Tools;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\CallbackManagerForToolRun;

/**
 * A structured-argument tool built from a closure.
 *
 * Port of `DynamicStructuredTool` from `@langchain/core/tools`.
 *
 * The closure receives the arguments as an associative array — the parsed,
 * schema-validated form — rather than a string. That is the whole point of the
 * class: the tool author gets typed fields instead of re-parsing JSON.
 */
final class DynamicStructuredTool extends StructuredTool
{
    /** @var callable(mixed, CallbackManagerForToolRun|null, RunnableConfig|null): mixed */
    private $func;

    /**
     * @param array<string, mixed> $fields
     * @param callable             $func
     */
    public function __construct(array $fields, callable $func)
    {
        parent::__construct($fields);
        $this->func = $func;
    }

    public static function lcName(): string
    {
        return 'DynamicStructuredTool';
    }

    /**
     * Name the trace run after the tool. See {@see DynamicTool::call()}.
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
     * The run manager, unless the callable declares a `ToolRuntime` second parameter.
     *
     * Upstream injects the runtime by TYPE, so the type hint is the whole signal — there is no wrapper
     * object to detect and no config flag to set.
     */
    private static function secondToolArgument(callable $func, mixed $runManager, ?RunnableConfig $config): mixed
    {
        try {
            $ref = \is_array($func) ? new \ReflectionMethod($func[0], $func[1]) : new \ReflectionFunction($func);
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
