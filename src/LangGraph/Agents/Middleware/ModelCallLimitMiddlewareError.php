<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

/**
 * Error thrown when the model call limit is exceeded.
 *
 * Port of the (unexported) `ModelCallLimitMiddlewareError` of `langchain/src/agents/middleware/modelCallLimit.ts`.
 */
final class ModelCallLimitMiddlewareError extends \Exception
{
    /**
     * @param array{threadLimit?: int|float|null, runLimit?: int|float|null, threadCount?: int|float|null, runCount?: int|float|null} $fields
     */
    public function __construct(array $fields)
    {
        $threadLimit = $fields['threadLimit'] ?? null;
        $runLimit = $fields['runLimit'] ?? null;
        $threadCount = $fields['threadCount'] ?? null;
        $runCount = $fields['runCount'] ?? null;

        $exceededHint = [];
        if ($threadLimit !== null && $threadCount !== null) {
            $exceededHint[] = "thread level call limit reached with {$threadCount} model calls";
        }
        if ($runLimit !== null && $runCount !== null) {
            $exceededHint[] = "run level call limit reached with {$runCount} model calls";
        }

        parent::__construct('Model call limits exceeded' . ($exceededHint !== [] ? ': ' . implode(', ', $exceededHint) : ''));
    }

    /** Upstream's `name`. */
    public function errorName(): string
    {
        return 'ModelCallLimitMiddlewareError';
    }
}
