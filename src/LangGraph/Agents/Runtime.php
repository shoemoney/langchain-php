<?php

declare(strict_types=1);

namespace LangGraph\Agents;

use LangChain\Runnables\RunnableConfig;

/**
 * Runtime information available to middleware (readonly).
 *
 * Port of the `Runtime` type from `langchain/src/agents/runtime.ts`.
 *
 * Upstream's type is `Partial<Omit<LangGraphRuntime, "context" | "configurable">> & {context, configurable}`
 * and is enforced as readonly by the compiler. Here every property is `readonly`, so assigning to one
 * raises PHP's own "Cannot modify readonly property" `\Error`. `context`, `store`, `writer`, `interrupt`
 * and `signal` stay untyped on purpose: they are whatever the host graph put on the run config, and the
 * layering rule keeps this package from naming the store or writer types.
 */
final class Runtime
{
    /**
     * @param array<string, mixed> $configurable `thread_id` and whatever else the run config carries.
     */
    public function __construct(
        public readonly mixed $context = null,
        public readonly mixed $store = null,
        public readonly mixed $writer = null,
        public readonly mixed $interrupt = null,
        public readonly mixed $signal = null,
        public readonly mixed $toolCallId = null,
        public readonly array $configurable = [],
    ) {
    }

    /**
     * Build a runtime from the LangGraph run config, the way `ToolNode.runTool` does upstream.
     *
     * The store reaches the config through `configurable['__pregel_store']`, the key the engine uses
     * (spelled out here because upstream reads `config.store`, which this port keeps under that key).
     */
    public static function fromConfig(?RunnableConfig $config): self
    {
        if ($config === null) {
            return new self();
        }

        $store = $config->configurable['__pregel_store'] ?? null;
        $toolCallId = \is_array($config->toolCall) ? ($config->toolCall['id'] ?? null) : null;

        return new self(
            context: $config->context,
            store: \is_object($store) ? $store : null,
            writer: $config->configurable['writer'] ?? null,
            interrupt: $config->configurable['interrupt'] ?? null,
            signal: $config->signal,
            toolCallId: $toolCallId,
            configurable: $config->configurable,
        );
    }

    /**
     * A copy with some properties replaced: upstream's `{ ...runtime, context }`.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        return new self(
            context: \array_key_exists('context', $changes) ? $changes['context'] : $this->context,
            store: \array_key_exists('store', $changes) ? $changes['store'] : $this->store,
            writer: \array_key_exists('writer', $changes) ? $changes['writer'] : $this->writer,
            interrupt: \array_key_exists('interrupt', $changes) ? $changes['interrupt'] : $this->interrupt,
            signal: \array_key_exists('signal', $changes) ? $changes['signal'] : $this->signal,
            toolCallId: \array_key_exists('toolCallId', $changes) ? $changes['toolCallId'] : $this->toolCallId,
            configurable: \array_key_exists('configurable', $changes) ? (array) $changes['configurable'] : $this->configurable,
        );
    }
}
