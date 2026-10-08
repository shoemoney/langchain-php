<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

/**
 * Port of `StreamIdleTimeoutError`: injected into the stream by {@see StreamRetry::idleReconnectStream()}
 * when no lines arrive within the active idle window, so the reconnect loop can recover a half-open
 * socket (one dropped without a FIN/RST, where neither an end nor a network error ever arrives).
 */
final class StreamIdleTimeoutError extends \RuntimeException
{
    public function __construct(public readonly int $idleTimeoutMs)
    {
        parent::__construct(
            "No SSE bytes received for {$idleTimeoutMs}ms; assuming the connection is half-open and reconnecting.",
        );
    }
}
