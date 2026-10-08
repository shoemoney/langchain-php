<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * Retry wrapper for calls to an expensive or rate-limited external resource.
 *
 * Port of `AsyncCaller` from `@langchain/core/utils/async_caller`, together with
 * its failed-attempt classification (`classifyRateLimitError`, `parseRetryAfterMs`)
 * and the retry loop it borrows from `p-retry` (exponential backoff, factor 2,
 * 1s minimum, randomised, `retryAfterMs` as a floor on the delay).
 *
 * Known non-exact behaviour, all of it forced by PHP being synchronous:
 *
 * - **Concurrency is sequential.** Upstream queues calls through `p-queue` with
 *   `maxConcurrency`. Here every call runs to completion before the next starts, so
 *   `$maxConcurrency` is stored but cannot throttle anything. It is accepted so call
 *   sites keep upstream's shape.
 * - **Abort is cooperative.** There is no `AbortSignal`; a `signal` option is a
 *   callable returning `null`/`false` while live and `true` (or a `\Throwable`, used
 *   as the reason) once aborted. It is checked before each attempt and after each
 *   failure; an in-flight call is never interrupted, which upstream does not do
 *   either ("this doesn't cancel the underlying request").
 * - **`fetch()` is not ported.** It wraps the JS `fetch` global; use an `HttpClient`.
 * - **Error metadata lives in a side table.** JavaScript stamps `name`,
 *   `rateLimitType`, `rateLimitReason` and `retryAfterMs` onto the error object and
 *   a symbol-keyed retryable mark. PHP exceptions are not open objects, so the same
 *   facts are kept in a {@see \WeakMap} and read with {@see self::errorName()},
 *   {@see self::rateLimitMetadata()} and {@see self::getRetryable()}. The
 *   `langchain-core/errors` module (`stampRetryable` / `getRetryable`) is not ported
 *   elsewhere, so those two live here.
 * - **Duck-typed error fields.** Status, headers and codes are read from public
 *   properties, array keys or (for `code`) a string `getCode()`: `status`,
 *   `statusCode`, `response` (array or object with `status` / `headers`), `headers`
 *   (array, or object with `get()`), `error.code`, `name`.
 * - The callable may return a {@see Promise}; it is awaited with {@see Await::sync()}.
 */
final class AsyncCaller
{
    private const STATUS_NO_RETRY = [400, 401, 402, 403, 404, 405, 406, 407, 409, 413];

    private const RETRY_AFTER_AUTO_RETRY_THRESHOLD_MS = 60_000;

    private const QUOTA_EXHAUSTED_MESSAGE_PATTERNS = [
        '/insufficient[_ -]?quota/i',
        '/exceeded (?:your|the current|the available).+quota/i',
        '/usage quota/i',
        '/quota (?:has been )?exhausted/i',
        '/billing/i',
        '/credit balance/i',
        '/out of credits/i',
        '/will reset at/i',
    ];

    private const RETRY_AFTER_MESSAGE_PATTERN =
        '/(?:try again in|retry after)\s+(\d+(?:\.\d+)?)\s*(milliseconds?|ms|seconds?|secs?|s|minutes?|mins?|m|hours?|hrs?|h)\b/i';

    /** @var null|\WeakMap<object, array<string, mixed>> */
    private static ?\WeakMap $metadata = null;

    public readonly int|float $maxConcurrency;

    /** @var callable(mixed): mixed */
    private $failedAttemptHandler;

    /** @var callable(int|float): void */
    private $sleeper;

    /**
     * @param null|int|float                $maxConcurrency  accepted for parity; calls are sequential (see class note)
     * @param int                           $maxRetries      retries per call, with exponential backoff between attempts
     * @param null|callable(mixed): mixed   $onFailedAttempt gets the thrown error; throw from it to stop retrying
     * @param null|callable(int|float): void $sleeper        receives each backoff in milliseconds; defaults to `usleep`.
     *                                                       Not in upstream: PHP has no timer to fake, so tests inject one.
     */
    public function __construct(
        int|float|null $maxConcurrency = null,
        private readonly int $maxRetries = 6,
        ?callable $onFailedAttempt = null,
        ?callable $sleeper = null,
    ) {
        $this->maxConcurrency = $maxConcurrency ?? \INF;
        $this->failedAttemptHandler = $onFailedAttempt ?? self::defaultFailedAttemptHandler(...);
        $this->sleeper = $sleeper ?? static function (int|float $ms): void {
            usleep((int) ($ms * 1000));
        };
    }

    /**
     * Call `$callable` with `$args`, retrying failures.
     */
    public function call(callable $callable, mixed ...$args): mixed
    {
        return $this->callWithRetries($this->maxRetries, $callable, $args, null);
    }

    /**
     * As {@see self::call()} with per-call options.
     *
     * @param array{maxRetries?: int, signal?: callable} $options `maxRetries` overrides the constructor value (0 means a
     *                                                            single attempt, with no Retry-After wait); `signal` is the
     *                                                            cooperative abort check described on the class
     */
    public function callWithOptions(array $options, callable $callable, mixed ...$args): mixed
    {
        $signal = $options['signal'] ?? null;

        return $this->callWithRetries($options['maxRetries'] ?? $this->maxRetries, $callable, $args, $signal);
    }

    /**
     * Run the configured failed-attempt handler on `$error`.
     *
     * Upstream reaches this through a protected property; it is public here so the
     * classification can be tested without a network.
     */
    public function onFailedAttempt(mixed $error): mixed
    {
        return ($this->failedAttemptHandler)($error);
    }

    /**
     * The default failed-attempt handler: throws for errors that should not be
     * retried (after marking them), returns for the ones that should.
     *
     * @throws \Throwable
     */
    public static function defaultFailedAttemptHandler(mixed $error): void
    {
        if (!is_object($error)) {
            return;
        }

        // Honor a verdict already reached inside the callable, e.g. by a provider.
        if (self::getRetryable($error) === false) {
            throw $error;
        }

        $message = self::message($error);
        $name = self::meta($error)['name'] ?? self::field($error, 'name');
        if (
            ($message !== null && (str_starts_with($message, 'Cancel') || str_starts_with($message, 'AbortError')))
            || $name === 'AbortError'
        ) {
            // Deliberate cancellation, not a failure worth another attempt.
            throw self::stampRetryable($error, false);
        }
        if (self::field($error, 'code') === 'ECONNABORTED') {
            throw $error;
        }
        $status = self::responseStatus($error) ?? self::directStatus($error);
        if ($status !== null && $status !== 0 && in_array($status, self::STATUS_NO_RETRY, true)) {
            // Deterministic client error; retrying it unchanged fails identically.
            throw self::stampRetryable($error, false);
        }

        $code = self::errorCode($error);
        if ($code === 'insufficient_quota') {
            $err = self::coerceError($error, $message ?? 'Insufficient quota');
            self::setMeta($err, ['name' => 'InsufficientQuotaError']);
            self::setRateLimitMetadata($err, ['action' => 'stop', 'reason' => 'insufficient_quota']);
            // Exhausted quota needs an account action, not another attempt.
            throw self::stampRetryable($err, false);
        }

        $classification = self::classifyRateLimitError($error);
        if ($classification !== null) {
            if ($classification['action'] === 'wait') {
                self::setRateLimitMetadata($error, $classification);
                self::stampRetryable($error, true);

                return;
            }

            $err = self::coerceError($error, $message ?? 'Rate limit exceeded');
            $explicit = self::field($err, 'name');
            if (!is_string($explicit) || $explicit === '' || $explicit === 'Error') {
                self::setMeta($err, [
                    'name' => $classification['action'] === 'stop' ? 'RateLimitQuotaExhaustedError' : 'RateLimitCapacityError',
                ]);
            }
            self::setRateLimitMetadata($err, $classification);
            // Only "stop" is exhausted quota; "capacity" can still succeed later.
            throw self::stampRetryable($err, $classification['action'] !== 'stop');
        }
    }

    /**
     * Classify a 429. Returns null for anything that is not a rate limit.
     *
     * @return null|array{action: 'wait'|'capacity'|'stop', retryAfterMs?: int|float, reason: string}
     */
    public static function classifyRateLimitError(mixed $error): ?array
    {
        $status = self::responseStatus($error) ?? self::directStatus($error);
        if ($status !== 429) {
            return null;
        }

        if (self::errorCode($error) === 'insufficient_quota') {
            return ['action' => 'stop', 'reason' => 'insufficient_quota'];
        }

        $message = self::message($error);
        if ($message !== null) {
            foreach (self::QUOTA_EXHAUSTED_MESSAGE_PATTERNS as $pattern) {
                if (preg_match($pattern, $message) === 1) {
                    return ['action' => 'stop', 'reason' => 'quota_message'];
                }
            }
        }

        $retryAfterMs = self::parseRetryAfterMs(self::retryAfterHeader($error))
            ?? self::parseRetryAfterFromMessageMs($message);

        if ($retryAfterMs !== null) {
            if ($retryAfterMs <= self::RETRY_AFTER_AUTO_RETRY_THRESHOLD_MS) {
                return ['action' => 'wait', 'retryAfterMs' => $retryAfterMs, 'reason' => 'retry_after_hint'];
            }

            return ['action' => 'capacity', 'retryAfterMs' => $retryAfterMs, 'reason' => 'retry_after_too_large'];
        }

        return ['action' => 'capacity', 'reason' => 'headerless_429'];
    }

    /**
     * Parse a `Retry-After` header (delta-seconds or HTTP-date) to milliseconds.
     */
    public static function parseRetryAfterMs(?string $headerValue): int|float|null
    {
        if ($headerValue === null) {
            return null;
        }
        $trimmed = trim($headerValue);
        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed) && (float) $trimmed >= 0) {
            return $trimmed + 0 === (int) $trimmed ? ((int) $trimmed) * 1000 : (float) $trimmed * 1000;
        }

        $date = strtotime($trimmed);
        if ($date !== false) {
            $delayMs = $date * 1000 - (int) (microtime(true) * 1000);

            return $delayMs > 0 ? $delayMs : 0;
        }

        return null;
    }

    /**
     * Mark an error as safe or unsafe to retry. Non-objects are returned untouched.
     *
     * @template T
     * @param T $error
     * @return T
     */
    public static function stampRetryable(mixed $error, bool $retryable): mixed
    {
        if (is_object($error)) {
            self::setMeta($error, ['retryable' => $retryable]);
        }

        return $error;
    }

    /**
     * An error's retryability mark: true/false when marked, null when unclassified.
     */
    public static function getRetryable(mixed $error): ?bool
    {
        if (!is_object($error)) {
            return null;
        }

        $value = self::meta($error)['retryable'] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * What upstream stamps onto the error: `name` (when changed), `rateLimitType`,
     * `rateLimitReason`, `retryAfterMs`. Empty when nothing was recorded.
     *
     * @return array<string, mixed>
     */
    public static function rateLimitMetadata(object $error): array
    {
        return array_intersect_key(self::meta($error), array_flip(['rateLimitType', 'rateLimitReason', 'retryAfterMs']));
    }

    /**
     * The JavaScript-style `name` of an error: a name recorded by the caller, else a
     * public `name` field, else the short class name.
     */
    public static function errorName(object $error): string
    {
        $recorded = self::meta($error)['name'] ?? null;
        if (is_string($recorded)) {
            return $recorded;
        }
        $field = self::field($error, 'name');
        if (is_string($field) && $field !== '') {
            return $field;
        }

        return (new \ReflectionClass($error))->getShortName();
    }

    // -------------------------------------------------------------- retry loop

    /**
     * `p-retry` with `randomize: true`: attempts, then exponential backoff.
     *
     * @param array<array-key, mixed> $args
     * @param null|callable $signal
     */
    private function callWithRetries(int $retries, callable $callable, array $args, ?callable $signal): mixed
    {
        $retries = max(0, $retries);
        $consumed = 0;
        self::throwIfAborted($signal);

        while ($consumed <= $retries) {
            self::throwIfAborted($signal);

            try {
                $result = Await::sync($callable(...$args));
            } catch (\Throwable $error) {
                // Upstream wraps non-Error rejections; PHP can only throw Throwables.
                $this->onFailedAttempt($error);

                $retriesLeft = max(0, $retries - $consumed);
                if ($retriesLeft <= 0) {
                    throw $error;
                }
                // p-retry never retries a non-network TypeError: it is a bug, not a flake.
                if ($error instanceof \TypeError) {
                    throw $error;
                }

                $delay = round((mt_rand() / mt_getrandmax() + 1) * 1000 * (2 ** $consumed));
                $retryAfterMs = self::meta($error)['retryAfterMs'] ?? null;
                if ((is_int($retryAfterMs) || is_float($retryAfterMs)) && $retryAfterMs >= 0) {
                    $delay = max($delay, $retryAfterMs);
                }
                if ($delay > 0) {
                    ($this->sleeper)($delay);
                }
                self::throwIfAborted($signal);
                $consumed++;

                continue;
            }
            self::throwIfAborted($signal);

            return $result;
        }

        throw new \LogicException('unreachable: the retry loop always returns or throws');
    }

    private static function throwIfAborted(?callable $signal): void
    {
        if ($signal === null) {
            return;
        }
        $reason = $signal();
        if ($reason instanceof \Throwable) {
            throw $reason;
        }
        if ($reason === true) {
            $error = new \RuntimeException('AbortError: The operation was aborted');
            self::setMeta($error, ['name' => 'AbortError', 'retryable' => false]);

            throw $error;
        }
    }

    // ----------------------------------------------------------- error access

    /**
     * @return array<string, mixed>
     */
    private static function meta(object $error): array
    {
        return self::$metadata[$error] ?? [];
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function setMeta(object $error, array $values): void
    {
        self::$metadata ??= new \WeakMap();
        self::$metadata[$error] = $values + self::meta($error);
    }

    /**
     * @param array{action: string, retryAfterMs?: int|float, reason: string} $classification
     */
    private static function setRateLimitMetadata(object $error, array $classification): void
    {
        $values = ['rateLimitType' => $classification['action'], 'rateLimitReason' => $classification['reason']];
        if (array_key_exists('retryAfterMs', $classification)) {
            $values['retryAfterMs'] = $classification['retryAfterMs'];
        }
        self::setMeta($error, $values);
    }

    private static function coerceError(object $error, string $fallbackMessage): object
    {
        return $error instanceof \Throwable ? $error : new \RuntimeException($fallbackMessage);
    }

    private static function field(mixed $holder, string $key): mixed
    {
        if (is_array($holder)) {
            return $holder[$key] ?? null;
        }
        if (!is_object($holder)) {
            return null;
        }
        if ($holder instanceof \ArrayAccess && isset($holder[$key])) {
            return $holder[$key];
        }
        $vars = get_object_vars($holder);
        if (array_key_exists($key, $vars)) {
            return $vars[$key];
        }
        if ($key === 'code' && $holder instanceof \Throwable) {
            $code = $holder->getCode();

            return is_string($code) ? $code : null;
        }

        return null;
    }

    private static function message(mixed $error): ?string
    {
        if ($error instanceof \Throwable) {
            return $error->getMessage();
        }
        $message = self::field($error, 'message');

        return is_string($message) ? $message : null;
    }

    private static function responseStatus(mixed $error): ?int
    {
        if (!is_object($error) && !is_array($error)) {
            return null;
        }
        $status = self::field(self::field($error, 'response'), 'status');

        return is_int($status) ? $status : null;
    }

    private static function directStatus(mixed $error): ?int
    {
        if (!is_object($error) && !is_array($error)) {
            return null;
        }
        foreach (['status', 'statusCode'] as $key) {
            $value = self::field($error, $key);
            if (is_int($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function errorCode(mixed $error): ?string
    {
        if (!is_object($error) && !is_array($error)) {
            return null;
        }
        $code = self::field($error, 'code');
        if (is_string($code)) {
            return $code;
        }
        $nested = self::field(self::field($error, 'error'), 'code');

        return is_string($nested) ? $nested : null;
    }

    private static function retryAfterHeader(mixed $error): ?string
    {
        foreach ([self::field($error, 'headers'), self::field(self::field($error, 'response'), 'headers')] as $headers) {
            if ($headers === null || $headers === false) {
                continue;
            }
            if (is_object($headers) && method_exists($headers, 'get')) {
                $value = $headers->get('retry-after');
            } elseif (is_array($headers)) {
                $value = $headers['retry-after'] ?? $headers['Retry-After'] ?? null;
            } else {
                $value = null;
            }

            return is_scalar($value) ? (string) $value : null;
        }

        return null;
    }

    private static function parseRetryAfterFromMessageMs(?string $message): int|float|null
    {
        if ($message === null || preg_match(self::RETRY_AFTER_MESSAGE_PATTERN, $message, $m) !== 1) {
            return null;
        }
        $raw = (float) $m[1];
        $unit = strtolower($m[2]);

        return match (true) {
            $unit === 'ms', str_starts_with($unit, 'millisecond') => $raw,
            $unit === 'm', str_starts_with($unit, 'min') => $raw * 60_000,
            $unit === 'h', str_starts_with($unit, 'hr'), str_starts_with($unit, 'hour') => $raw * 3_600_000,
            default => $raw * 1000,
        };
    }
}
