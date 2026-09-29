<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * The per-run view for a chain (and, in this port, an agent's outer loop).
 *
 * Port of `CallbackManagerForChainRun` from `@langchain/core/callbacks/manager`.
 */
final class CallbackManagerForChainRun extends BaseRunManager
{
    public function handleChainError(\Throwable $error, array $kwargs = []): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreChain) {
                continue;
            }
            $this->dispatch($handler, 'handleChainError', [$error, $this->runId, $this->parentRunId, $this->tags, $kwargs]);
        }
    }

    /**
     * @param array<string, mixed> $outputs
     * @param array{inputs?: array<string, mixed>} $kwargs
     */
    public function handleChainEnd(array $outputs, array $kwargs = []): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreChain) {
                continue;
            }
            $this->dispatch($handler, 'handleChainEnd', [$outputs, $this->runId, $this->parentRunId, $this->tags, $kwargs]);
        }
    }

    public function handleAgentAction(array $action): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreAgent) {
                continue;
            }
            $this->dispatch($handler, 'handleAgentAction', [$action, $this->runId, $this->parentRunId, $this->tags]);
        }
    }

    public function handleAgentEnd(array $action): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreAgent) {
                continue;
            }
            $this->dispatch($handler, 'handleAgentEnd', [$action, $this->runId, $this->parentRunId, $this->tags]);
        }
    }
}
