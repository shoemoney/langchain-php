<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\StructuredTool;
use LangChain\Tracers\CallbackManagerForToolRun;

/**
 * Runs another tool unchanged while remembering exactly what its body returned.
 *
 * {@see StructuredTool::invoke()} wraps every result of a tool-call-driven invocation in a `ToolMessage`,
 * and a `Command` or `Send` that a tool returned to steer the graph is not "direct tool output", so it
 * would be JSON-encoded into that message and its routing lost. Upstream's core tool layer lets a
 * `Command` through; this port's does not, and `ToolNode` may not change it, so `ToolNode` invokes each
 * tool through this wrapper instead. Validation, callbacks and tracing all still run (they live in
 * `invoke()`); only the raw return value is recorded alongside.
 *
 * @internal
 */
final class CommandPassthroughTool extends StructuredTool
{
    public mixed $captured = null;

    public bool $didCapture = false;

    public function __construct(private readonly StructuredTool $inner)
    {
        parent::__construct([
            'name' => $inner->name,
            'description' => $inner->description,
            'schema' => $inner->schema,
            'returnDirect' => $inner->returnDirect,
            'verboseParsingErrors' => $inner->verboseParsingErrors,
            'responseFormat' => $inner->responseFormat,
            'defaultConfig' => $inner->defaultConfig,
            'extras' => $inner->extras,
            'verbose' => $inner->verbose,
            'callbacks' => $inner->callbacks,
            'tags' => $inner->tags,
            'metadata' => $inner->metadata,
        ]);
    }

    public static function lcName(): string
    {
        return 'CommandPassthroughTool';
    }

    protected function callTool(mixed $arg, ?CallbackManagerForToolRun $runManager = null, ?RunnableConfig $parentConfig = null): mixed
    {
        $raw = $this->inner->callTool($arg, $runManager, $parentConfig);

        if ($raw instanceof \Generator) {
            return (function () use ($raw) {
                $result = yield from $raw;
                $this->captured = $result;
                $this->didCapture = true;

                return $result;
            })();
        }

        $this->captured = $raw;
        $this->didCapture = true;

        return $raw;
    }
}
