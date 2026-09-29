<?php

declare(strict_types=1);

namespace LangGraph\Pregel\Retry;

/**
 * How often, and how patiently, a failing node is retried.
 *
 * Port of `RetryPolicy` from `langgraph-core/src/pregel/utils/index.ts`.
 *
 * The backoff schedule is the standard one: `initialInterval`, multiplied by
 * `backoffFactor` per attempt, clamped at `maxInterval`, plus up to one second
 * of jitter unless `jitter` is false. Jitter is on by default because without
 * it, N nodes failing at once retry in lockstep and retry in lockstep forever.
 *
 * Two fields are subtler than they look:
 *
 *  - `retryOn` receives the error and decides. The default handler declines a
 *    handful of error classes outright — a 400 will still be a 400, and a
 *    cancelled request will still be cancelled.
 *  - `logWarning` defaults to *true*, so a node silently retrying three times
 *    over two minutes is visible by default. Silent retry is the failure mode
 *    people actually hit.
 */
final class RetryPolicy
{
    public const DEFAULT_INITIAL_INTERVAL = 500;
    public const DEFAULT_BACKOFF_FACTOR = 2.0;
    public const DEFAULT_MAX_INTERVAL = 128000;
    public const DEFAULT_MAX_RETRIES = 3;
    public const DEFAULT_JITTER = true;

    /**
     * HTTP statuses the default `retryOn` refuses to retry.
     *
     * These are all "the request is wrong", not "the server is unwell".
     *
     * @var list<int>
     */
    public const DEFAULT_STATUS_NO_RETRY = [400, 401, 402, 403, 404, 405, 406, 407, 409];

    /**
     * @param float|null        $initialInterval  Milliseconds before the first retry.
     * @param float|null        $backoffFactor    Multiplier applied per attempt.
     * @param float|null        $maxInterval      Ceiling on the wait between retries.
     * @param int|null          $maxAttempts      Attempts *after* the first.
     * @param bool|null         $jitter           Add up to one second of randomness.
     * @param callable|null     $retryOn          `fn(\Throwable): bool` — retry this error?
     * @param bool|null         $logWarning       Log each retry. Defaults to true.
     */
    public function __construct(
        public readonly ?float $initialInterval = null,
        public readonly ?float $backoffFactor = null,
        public readonly ?float $maxInterval = null,
        public readonly ?int $maxAttempts = null,
        public readonly ?bool $jitter = null,
        public readonly mixed $retryOn = null,
        public readonly ?bool $logWarning = null,
    ) {
    }

    /**
     * The default decision: retry unless retrying provably cannot help.
     *
     * Port of `DEFAULT_RETRY_ON_HANDLER`. Three categories are refused:
     * cancellation, a value error, and the specific HTTP/provider statuses that
     * mean the request itself is bad.
     */
    public static function defaultRetryOn(\Throwable $error): bool
    {
        $message = $error->getMessage();

        if (str_starts_with($message, 'Cancel') || str_starts_with($message, 'AbortError')) {
            return false;
        }

        // `interrupt()` without a checkpointer throws this; retrying is futile.
        if ($error instanceof \LangGraph\Errors\GraphValueError) {
            return false;
        }

        if ($error instanceof \LangGraph\Errors\GraphBubbleUp) {
            return false;
        }

        $status = null;
        $code = $error->getCode();
        if (is_int($code) && $code !== 0) {
            $status = $code;
        }
        if ($status !== null && in_array($status, self::DEFAULT_STATUS_NO_RETRY, true)) {
            return false;
        }

        if ($error instanceof \RuntimeException && str_contains($message, 'insufficient_quota')) {
            return false;
        }

        return true;
    }

    /**
     * The wait before the retry that follows attempt number `$attempt`.
     *
     * @param int   $attempt 1-based count of attempts already made.
     * @param float $random  A value in [0, 1) for the jitter term; injected so
     *                       the schedule is testable without sleeping.
     */
    public function intervalFor(int $attempt, float $random): int
    {
        $initial = $this->initialInterval ?? self::DEFAULT_INITIAL_INTERVAL;
        $factor = $this->backoffFactor ?? self::DEFAULT_BACKOFF_FACTOR;
        $max = $this->maxInterval ?? self::DEFAULT_MAX_INTERVAL;

        $interval = min($max, $initial * ($factor ** max(0, $attempt - 1)));

        if ($this->jitter ?? self::DEFAULT_JITTER) {
            $interval += $random * 1000.0;
        }

        return (int) round($interval);
    }

    /** Attempts allowed after the first, i.e. the total cap. */
    public function effectiveMaxAttempts(): int
    {
        return $this->maxAttempts ?? self::DEFAULT_MAX_RETRIES;
    }

    public function shouldLogWarning(): bool
    {
        return $this->logWarning ?? true;
    }

    /** `fn(\Throwable): bool`, falling back to the default handler. */
    public function shouldRetry(\Throwable $error): bool
    {
        $handler = $this->retryOn;

        if ($handler === null) {
            return self::defaultRetryOn($error);
        }

        return (bool) $handler($error);
    }
}
