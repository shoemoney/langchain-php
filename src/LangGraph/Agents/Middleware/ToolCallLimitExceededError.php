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
        ?bool $hasThreadLimit = null,
        ?bool $hasRunLimit = null,
    ) {
        parent::__construct(self::buildFinalAIMessageContent($threadCount, $runCount, $threadLimit, $runLimit, $toolName, $hasThreadLimit, $hasRunLimit));
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
     * Optional presence flags distinguish a supplied null (zero threshold) from an omitted limit.
     * Without flags, existing callers retain null-as-absent behavior.
     */
    public static function buildFinalAIMessageContent(
        int|float $threadCount,
        int|float $runCount,
        int|float|null $threadLimit,
        int|float|null $runLimit,
        ?string $toolName,
        ?bool $hasThreadLimit = null,
        ?bool $hasRunLimit = null,
    ): string {
        $toolDesc = $toolName !== null && $toolName !== '' ? "'{$toolName}' tool" : 'Tool';
        $exceededLimits = [];

        if (($hasThreadLimit ?? ($threadLimit !== null)) && $threadCount > ($threadLimit ?? 0)) {
            $displayLimit = $threadLimit ?? 'null';
            $exceededLimits[] = "thread limit exceeded ({$threadCount}/{$displayLimit} calls)";
        }
        if (($hasRunLimit ?? ($runLimit !== null)) && $runCount > ($runLimit ?? 0)) {
            $displayLimit = $runLimit ?? 'null';
            $exceededLimits[] = "run limit exceeded ({$runCount}/{$displayLimit} calls)";
        }

        return "{$toolDesc} call limit reached: " . implode(' and ', $exceededLimits) . '.';
    }
}
