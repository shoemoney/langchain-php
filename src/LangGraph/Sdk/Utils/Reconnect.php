<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

/**
 * Port of `utils/reconnect.ts`: shared reconnect defaults and backoff for `streamWithRetry`.
 *
 * `ReconnectInfo` and `ConnectedInfo` are TypeScript-only interfaces for protocol transports
 * (WebSocket / SSE adapters) that are not part of this port; they have no PHP counterpart.
 */
final class Reconnect
{
    /** Default max reconnect / retry attempts after an unexpected disconnect. */
    public const DEFAULT_MAX_RECONNECT_ATTEMPTS = 5;

    /** Base delay (ms) for exponential reconnect backoff (`base * 2^(attempt-1)`). */
    public const DEFAULT_RECONNECT_BASE_DELAY_MS = 1000;

    /** Cap (ms) for exponential reconnect backoff before jitter. */
    public const DEFAULT_RECONNECT_MAX_DELAY_MS = 5000;

    /** Max random jitter (ms) added on top of the capped base delay. */
    public const DEFAULT_RECONNECT_JITTER_MS = 1000;

    /**
     * Exponential backoff with jitter for stream reconnect:
     * `min(base * 2^(attempt-1), max) + random(0, jitter)`.
     *
     * @param (callable(): float)|null $random Returns [0, 1); a test seam, defaults to a real draw.
     */
    public static function reconnectDelayMs(int $attempt, ?callable $random = null): float
    {
        $baseDelay = min(
            self::DEFAULT_RECONNECT_BASE_DELAY_MS * (2 ** ($attempt - 1)),
            self::DEFAULT_RECONNECT_MAX_DELAY_MS,
        );
        $draw = $random !== null ? $random() : mt_rand() / (mt_getrandmax() + 1);

        return $baseDelay + $draw * self::DEFAULT_RECONNECT_JITTER_MS;
    }
}
