<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

use LangChain\Utils\Http\HttpResponse;

/**
 * Port of `utils/stream.ts`: SSE reconnect with resume, and the idle watchdog.
 *
 * ## Where this differs from JS
 *
 *  - `signal` is a `callable(): bool` (see {@see Signals}); it is polled before each read.
 *  - JS arms a `setTimeout` that errors the stream from outside. A synchronous PHP generator cannot
 *    be interrupted mid-read, so {@see self::idleReconnectStream()} judges the silence when control
 *    comes back to it: on the next line, or on a `null` tick from a transport that polls. A half-open
 *    socket on a transport that blocks forever and never ticks is therefore NOT caught, which is the
 *    one case the JS watchdog exists for. The clock and the backoff sleep are injectable so tests do
 *    not wait.
 *  - `IterableReadableStream` (a `ReadableStream` adapter) has no PHP counterpart: a PHP `Generator`
 *    is already iterable.
 */
final class StreamRetry
{
    /** `":"`: first byte of an SSE comment / keep-alive line. */
    private const SSE_COMMENT_BYTE = ':';

    /**
     * Default idle-reconnect policy. Heartbeat-adaptive `"auto"` stays dormant unless the server emits
     * keep-alive heartbeats. Pass `0` to disable.
     */
    public const DEFAULT_IDLE_RECONNECT = 'auto';

    /**
     * A pass-through over the LINE stream that throws {@see StreamIdleTimeoutError} when it goes idle.
     *
     * MUST sit after {@see BytesLineDecoder} and before {@see SseDecoder} (which discards `:` comment
     * lines), so any line (data or heartbeat) counts as liveness and heartbeats can drive `"auto"`.
     * In `"auto"` it stays dormant until it has seen two heartbeats, to measure the cadence.
     *
     * `$lines` yields lines, and `null` for "polled, nothing arrived" (see {@see StreamResponse}).
     *
     * Options:
     *  - `mode` int|'auto' (required): a fixed idle window in ms, armed from the start; or heartbeat-adaptive
     *  - `timeoutFactor` (default 3), `minTimeoutMs` (6000), `maxTimeoutMs` (30000): `"auto"` window sizing
     *  - `onIdle` `callable(array{timeoutMs: int, source: 'fixed'|'heartbeat'}): void`: fired just before throwing
     *  - `clock` `callable(): int|float` milliseconds, monotonic (default `hrtime`)
     *
     * @param iterable<string|null> $lines
     * @param array<string, mixed>  $options
     *
     * @return \Generator<int, string>
     */
    public static function idleReconnectStream(iterable $lines, array $options): \Generator
    {
        $mode = $options['mode'];
        $factor = $options['timeoutFactor'] ?? 3;
        $minTimeoutMs = $options['minTimeoutMs'] ?? 6000;
        $maxTimeoutMs = $options['maxTimeoutMs'] ?? 30000;
        $clock = $options['clock'] ?? static fn (): float => hrtime(true) / 1_000_000;
        $fixedTimeoutMs = is_int($mode) ? $mode : null;

        $lastActivityAt = $clock();
        $lastHeartbeatAt = null;
        // The active idle window: the fixed value, or (auto) the heartbeat-derived one once known.
        // null means "not armed yet".
        $timeoutMs = $fixedTimeoutMs;

        foreach ($lines as $line) {
            $now = $clock();

            if ($timeoutMs !== null && $timeoutMs > 0 && $now - $lastActivityAt >= $timeoutMs) {
                if (isset($options['onIdle'])) {
                    ($options['onIdle'])(['timeoutMs' => $timeoutMs, 'source' => $fixedTimeoutMs !== null ? 'fixed' : 'heartbeat']);
                }

                throw new StreamIdleTimeoutError($timeoutMs);
            }

            if ($line === null) {
                continue;
            }

            if ($line !== '' && $line[0] === self::SSE_COMMENT_BYTE && $fixedTimeoutMs === null) {
                if ($lastHeartbeatAt !== null) {
                    $interval = $now - $lastHeartbeatAt;
                    if ($interval > 0) {
                        $candidate = (int) min(max($interval * $factor, $minTimeoutMs), $maxTimeoutMs);
                        // Keep the most conservative (largest) window observed so far.
                        $timeoutMs = $timeoutMs === null ? $candidate : max($timeoutMs, $candidate);
                    }
                }
                $lastHeartbeatAt = $now;
            }

            // Any line is liveness: re-arm.
            $lastActivityAt = $now;

            yield $line;
        }
    }

    /**
     * Stream with automatic retry for SSE connections, resuming from the last event id seen.
     *
     * `$makeRequest(?array{lastEventId: string|null, reconnectPath: string} $params)` returns
     * `['response' => HttpResponse, 'stream' => iterable<array{id?: string|null}>]`. `$params` is null for
     * the initial request and carries the reconnect path (from the response's `Location` header) after.
     * A reconnect is only possible once the server has sent a `Location`.
     *
     * Options: `maxRetries` (default {@see Reconnect::DEFAULT_MAX_RECONNECT_ATTEMPTS}), `signal`
     * `callable(): bool`, `onReconnect` `callable(array{attempt: int, lastEventId: string|null, cause: \Throwable}): void`,
     * `sleep` `callable(int $ms): void` (default `usleep`).
     *
     * An error while READING the stream reconnects whatever it is, provided there is a reconnect path
     * and the signal has not fired; an error making the request reconnects only if it is a network
     * error ({@see ErrorUtils::isNetworkError()}). Anything else, and any error with no reconnect path,
     * propagates.
     *
     * @param callable(?array{lastEventId: string|null, reconnectPath: string}): array{response: HttpResponse, stream: iterable<array<string, mixed>>} $makeRequest
     * @param array<string, mixed>                                                                                                                        $options
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function streamWithRetry(callable $makeRequest, array $options = []): \Generator
    {
        $maxRetries = $options['maxRetries'] ?? Reconnect::DEFAULT_MAX_RECONNECT_ATTEMPTS;
        $signal = $options['signal'] ?? null;
        $sleep = $options['sleep'] ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        $aborted = static fn (): bool => $signal !== null && $signal();

        $attempt = 0;
        $lastEventId = null;
        $reconnectPath = null;

        while (true) {
            $shouldRetry = false;
            $lastError = null;

            try {
                // Check if aborted before making the request.
                if ($aborted()) {
                    return;
                }

                // Initial request when there is no reconnect path, a reconnect otherwise.
                ['response' => $response, 'stream' => $stream] = $makeRequest(
                    $reconnectPath !== null ? ['lastEventId' => $lastEventId, 'reconnectPath' => $reconnectPath] : null,
                );

                // A Location header is the server-provided reconnection path.
                $location = $response->header('location');
                if ($location !== null && $location !== '') {
                    $reconnectPath = $location;
                }

                $contentType = $response->header('content-type');
                $contentType = $contentType !== null ? explode(';', $contentType)[0] : null;
                if ($contentType !== null && $contentType !== '' && !str_contains($contentType, 'text/event-stream')) {
                    throw new \RuntimeException(
                        "Expected response header Content-Type to contain 'text/event-stream', got '{$contentType}'",
                    );
                }

                $iterator = $stream instanceof \Iterator ? $stream : new \IteratorIterator(
                    is_array($stream) ? new \ArrayIterator($stream) : $stream,
                );

                try {
                    $started = false;
                    while (true) {
                        // Check the signal before each read.
                        if ($aborted()) {
                            return;
                        }

                        if ($started) {
                            $iterator->next();
                        } else {
                            $iterator->rewind();
                            $started = true;
                        }

                        if (!$iterator->valid()) {
                            break;
                        }

                        $value = $iterator->current();

                        // Track the last event id for reconnection.
                        if (isset($value['id']) && $value['id'] !== '') {
                            $lastEventId = (string) $value['id'];
                        }

                        yield $value;
                    }

                    // Stream completed successfully: leave the retry loop.
                    break;
                } catch (\Throwable $error) {
                    // An error during streaming: reconnect if the server gave us somewhere to.
                    if ($reconnectPath !== null && !$aborted()) {
                        $shouldRetry = true;
                        $lastError = $error;
                    } else {
                        throw $error;
                    }
                }
            } catch (\Throwable $error) {
                $lastError = $error;

                // Only retry with reconnection capability, and only a network error.
                if (ErrorUtils::isNetworkError($error) && $reconnectPath !== null && !$aborted()) {
                    $shouldRetry = true;
                } else {
                    throw $error;
                }
            }

            if ($shouldRetry) {
                ++$attempt;
                if ($attempt > $maxRetries) {
                    throw new MaxReconnectAttemptsError($maxRetries, $lastError);
                }

                if (isset($options['onReconnect'])) {
                    ($options['onReconnect'])(['attempt' => $attempt, 'lastEventId' => $lastEventId, 'cause' => $lastError]);
                }

                // Exponential backoff with jitter, shared with the protocol transports.
                $sleep((int) round(Reconnect::reconnectDelayMs($attempt)));

                continue;
            }

            break;
        }
    }
}
