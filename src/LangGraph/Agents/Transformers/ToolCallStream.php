<?php

declare(strict_types=1);

namespace LangGraph\Agents\Transformers;

/**
 * One tool call as the {@see ToolCallTransformer} surfaces it: what was asked, and settle-later outcomes.
 *
 * Counterpart of langgraph's `ToolCallStream`. `output` resolves with the tool result (rejects on a tool
 * failure), `status` with `finished` or `error`, `error` with the failure message (null when it finished).
 */
final class ToolCallStream
{
    public function __construct(
        public readonly string $name,
        public readonly string $callId,
        public readonly mixed $input,
        public readonly Deferred $output,
        public readonly Deferred $status,
        public readonly Deferred $error,
    ) {
    }
}
