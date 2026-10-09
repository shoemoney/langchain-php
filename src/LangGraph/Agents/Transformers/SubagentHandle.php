<?php

declare(strict_types=1);

namespace LangGraph\Agents\Transformers;

use LangGraph\Stream\Deferred;
use LangGraph\Stream\Transformers\MessagesTransformer;

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
     * @param MessagesTransformer                         $messages the per-subagent messages transformer
     */
    public function __construct(
        public readonly string $key,
        public readonly array $path,
        public readonly string $name,
        public readonly MessagesTransformer $messages,
        public readonly ToolCallTransformer $toolCall,
        public readonly SubagentTransformer $nested,
        public readonly Deferred $output,
    ) {
    }
}
