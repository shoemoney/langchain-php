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
    use InvokesToolCallable;

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

}
