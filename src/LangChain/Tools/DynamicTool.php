<?php

declare(strict_types=1);

namespace LangChain\Tools;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\CallbackManagerForToolRun;

/**
 * A string-argument tool built from a closure.
 *
 * Port of `DynamicTool` from `@langchain/core/tools`.
 *
 * This is what {@see tool()} returns for a string schema, and it is the shape
 * most simple tools take: a name, a description, and a function. The closure
 * receives the already-unwrapped string, plus the run manager and config, so a
 * tool can emit trace events or read `configurable` without declaring a class.
 */
final class DynamicTool extends Tool
{
    use InjectsToolRuntime;

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
        return 'DynamicTool';
    }

    /**
     * Name the trace run after the tool.
     *
     * Without this a dynamic tool's run is reported under its PHP class name,
     * which for a closure-defined tool is `DynamicTool` for every instance —
     * useless in a trace with more than one tool in it.
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

}
