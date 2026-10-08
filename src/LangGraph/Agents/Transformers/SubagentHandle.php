<?php

declare(strict_types=1);

namespace LangGraph\Agents\Transformers;

/**
 * The bookkeeping the {@see SubagentTransformer} keeps per discovered subagent (upstream's `SubagentHandle`).
 *
 * @internal
 */
final class SubagentHandle
{
    /** @var array<string, mixed>|null the subagent's last `values` snapshot */
    public ?array $latestValues = null;

    public bool $done = false;

    /**
     * @param list<string>                                $path     the subagent's namespace
     * @param NativeStreamTransformerInterface|null       $messages the per-subagent messages transformer, when one was supplied
     * @param StreamChannel                               $messagesChannel the `messages` projection (the transformer's, or an empty one)
     */
    public function __construct(
        public readonly string $key,
        public readonly array $path,
        public readonly string $name,
        public readonly ?NativeStreamTransformerInterface $messages,
        public readonly StreamChannel $messagesChannel,
        public readonly ToolCallTransformer $toolCall,
        public readonly SubagentTransformer $nested,
        public readonly Deferred $output,
    ) {
    }
}
