<?php

declare(strict_types=1);

namespace LangChain\Tools;

use LangChain\Runnables\RunnableConfig;

/**
 * Runtime values handed to a tool that declares it wants them.
 *
 * Port of the `ToolRuntime` type from `@langchain/core/tools/types`.
 *
 * A tool that needs the current graph state, the tool-call id, or the runtime
 * context would otherwise have to reach for all three through config plumbing.
 * This bundles them so one parameter carries the lot.
 *
 * @see self::fromConfig() for how a tool body obtains one.
 */
final class ToolRuntime
{
    /**
     * @param array<string, mixed> $state     The current graph state.
     * @param string               $toolCallId The call this invocation belongs to.
     * @param array<string, mixed>|null $toolCall The full tool call.
     * @param array<string, mixed> $configurable
     * @param mixed                $context   Runtime context from the agent.
     * @param object|null          $store     Persistent key-value storage.
     * @param callable|null        $writer    Stream writer for progressive output.
     */
    public function __construct(
        public array $state = [],
        public string $toolCallId = '',
        public ?array $toolCall = null,
        public array $configurable = [],
        public mixed $context = null,
        public ?object $store = null,
        public mixed $writer = null,
    ) {
    }

    /**
     * Build a runtime from the config a tool was invoked with.
     *
     * Returns null rather than an empty runtime when there is no tool call,
     * because "not called by a model" and "called with no state" are different
     * situations and a tool body should be able to tell them apart.
     */
    public static function fromConfig(?RunnableConfig $config): ?self
    {
        if ($config === null || $config->toolCall === null || !is_array($config->toolCall)) {
            return null;
        }

        return new self(
            state: (array) ($configurableState = $config->configurable['__state'] ?? []),
            toolCallId: is_string($config->toolCall['id'] ?? null) ? $config->toolCall['id'] : '',
            toolCall: $config->toolCall,
            configurable: $config->configurable,
            context: $config->context,
        );
    }
}
