<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * The per-run view for a tool invocation.
 *
 * Port of `CallbackManagerForToolRun` from `@langchain/core/callbacks/manager`.
 *
 * Note the `ignoreAgent` gate rather than an `ignoreTool` one: tools are agent
 * events, so a handler that ignores agents ignores tools. This is why a plain
 * "log agent decisions" handler does not also log every tool call.
 */
final class CallbackManagerForToolRun extends BaseRunManager
{
    public function handleToolError(\Throwable $error): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreAgent) {
                continue;
            }
            $this->dispatch($handler, 'handleToolError', [$error, $this->runId, $this->parentRunId, $this->tags]);
        }
    }

    /**
     * A streaming tool yielded an intermediate value.
     *
     * The TypeScript original rethrows on `raiseError` here WITHOUT the warn
     * branch every sibling method has. The asymmetry is preserved: this hook
     * exists to surface partial progress, and a handler that fails to accept a
     * chunk is saying something about the stream itself.
     */
    public function handleToolEvent(mixed $chunk): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreAgent) {
                continue;
            }
            try {
                $handler->handleToolEvent($chunk, $this->runId, $this->parentRunId, $this->tags);
            } catch (\Throwable $e) {
                if ($handler->raiseError) {
                    throw $e;
                }
            }
        }
    }

    public function handleToolEnd(mixed $output): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreAgent) {
                continue;
            }
            $this->dispatch($handler, 'handleToolEnd', [$output, $this->runId, $this->parentRunId, $this->tags]);
        }
    }
}
