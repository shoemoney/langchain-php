<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

/**
 * Error thrown when a tool call limit is exceeded (`exitBehavior` `error`).
 *
 * Port of `ToolCallLimitExceededError` from `langchain/src/agents/middleware/toolCallLimit.ts`.
 */
final class ToolCallLimitExceededError extends \Exception
{
    public function __construct(
        public readonly int|float $threadCount,
        public readonly int|float $runCount,
        public readonly int|float|null $threadLimit,
        public readonly int|float|null $runLimit,
        public readonly ?string $toolName = null,
    ) {
        parent::__construct(self::buildFinalAIMessageContent($threadCount, $runCount, $threadLimit, $runLimit, $toolName));
    }

    /** Upstream's `name`. */
    public function errorName(): string
    {
        return 'ToolCallLimitExceededError';
    }

    /**
     * The text shown to the user once the limit is hit.
     *
     * Port of `buildFinalAIMessageContent`.
     */
    public static function buildFinalAIMessageContent(
        int|float $threadCount,
        int|float $runCount,
        int|float|null $threadLimit,
        int|float|null $runLimit,
        ?string $toolName,
    ): string {
        $toolDesc = $toolName !== null && $toolName !== '' ? "'{$toolName}' tool" : 'Tool';
        $exceededLimits = [];

        if ($threadLimit !== null && $threadCount > $threadLimit) {
            $exceededLimits[] = "thread limit exceeded ({$threadCount}/{$threadLimit} calls)";
        }
        if ($runLimit !== null && $runCount > $runLimit) {
            $exceededLimits[] = "run limit exceeded ({$runCount}/{$runLimit} calls)";
        }

        return "{$toolDesc} call limit reached: " . implode(' and ', $exceededLimits) . '.';
    }
}
