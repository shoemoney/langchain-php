<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

/**
 * Port of `MaxReconnectAttemptsError`: thrown when maximum reconnection attempts are exceeded.
 */
final class MaxReconnectAttemptsError extends \RuntimeException
{
    public function __construct(public readonly int $maxAttempts, ?\Throwable $cause = null)
    {
        parent::__construct("Exceeded maximum SSE reconnection attempts ({$maxAttempts})", 0, $cause);
    }
}
