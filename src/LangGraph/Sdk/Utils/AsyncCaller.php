<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;

/**
 * Port of `utils/async_caller.ts`: retry with exponential backoff around a call, and a `fetch` that
 * turns a non-2xx response into an {@see HttpError}.
 *
 * Known non-exact behaviour: JS queues calls through `p-queue` with `maxConcurrency`. PHP runs one
 * call at a time, so `maxConcurrency` is stored and exposed but never contended.
 *
 * Params (all optional):
 *  - `maxConcurrency` (default INF), `maxRetries` (default 4)
 *  - `onFailedResponseHook` `callable(HttpResponse): mixed`, called on a retryable HTTP failure
 *  - `fetch` `callable(string $url, array $init): HttpResponse`, the transport
 *  - `minTimeoutMs` (default 1000), `factor` (default 2), `randomize` (default true) — p-retry's
 *    backoff knobs
 *  - `sleep` `callable(int $ms): void`, so tests need not wait the backoff out
 */
class AsyncCaller
{
    /** Statuses that are the caller's fault, so retrying cannot help. */
    private const STATUS_NO_RETRY = [400, 401, 402, 403, 404, 405, 406, 407, 408, 409, 422];

    protected float|int $maxConcurrency;

    protected int $maxRetries;

    /** @var (callable(HttpResponse): mixed)|null */
    private $onFailedResponseHook;

    /** @var (callable(string, array<string, mixed>): HttpResponse)|null */
    private $customFetch;

    /** @var callable(int): void */
    private $sleep;

    private int $minTimeoutMs;

    private float $factor;

    private bool $randomize;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params = [])
    {
        $this->maxConcurrency = $params['maxConcurrency'] ?? INF;
        $this->maxRetries = (int) ($params['maxRetries'] ?? 4);
        $this->onFailedResponseHook = $params['onFailedResponseHook'] ?? null;
        $this->customFetch = $params['fetch'] ?? null;
        $this->minTimeoutMs = (int) ($params['minTimeoutMs'] ?? 1000);
        $this->factor = (float) ($params['factor'] ?? 2);
        $this->randomize = (bool) ($params['randomize'] ?? true);
        $this->sleep = $params['sleep'] ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function maxConcurrency(): float|int
    {
        return $this->maxConcurrency;
    }

    public function maxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Run `$callable` with retries.
     *
     * Attempt count is `maxRetries + 1`, matching p-retry. Any failure is retried unless it is on the
     * no-retry list (cancel/timeout/abort, 4xx caller errors) or is a connection failure with no
     * retries left, which becomes a {@see ConnectionError}.
     */
    public function call(callable $callable, mixed ...$args): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $callable(...$args);
            } catch (\Throwable $error) {
                $retriesLeft = $this->maxRetries - $attempt + 1;
                $this->onFailedAttempt($error, $retriesLeft);

                if ($retriesLeft <= 0) {
                    throw $error;
                }

                ($this->sleep)($this->backoffMs($attempt));
            }
        }
    }

    /**
     * Port of `callWithOptions`. JS races the call against an `AbortSignal`; a synchronous PHP call
     * cannot be raced, so `signal` is a `callable(): bool` that is consulted BEFORE the call and
     * before every retry. It cannot interrupt a request already in flight.
     *
     * @param array{signal?: (callable(): bool)|null} $options
     */
    public function callWithOptions(array $options, callable $callable, mixed ...$args): mixed
    {
        $signal = $options['signal'] ?? null;
        if ($signal === null) {
            return $this->call($callable, ...$args);
        }

        $guarded = static function () use ($signal, $callable, $args): mixed {
            if ($signal()) {
                throw new \RuntimeException('AbortError');
            }

            return $callable(...$args);
        };

        return $this->call($guarded);
    }

    /**
     * @param array<string, mixed> $init Prepared request: method, headers, body, timeoutMs...
     */
    public function fetch(string $url, array $init = []): HttpResponse
    {
        $fetchFn = $this->customFetch
            ?? throw new \LogicException('AsyncCaller was constructed without a fetch implementation.');

        return $this->call(static function () use ($fetchFn, $url, $init): HttpResponse {
            $response = $fetchFn($url, $init);
            if (!$response->isOk()) {
                throw HttpError::fromResponse($response, true);
            }

            return $response;
        });
    }

    /**
     * Port of p-retry's `onFailedAttempt`: throwing here stops the retrying.
     */
    private function onFailedAttempt(\Throwable $error, int $retriesLeft): void
    {
        $message = $error->getMessage();

        if (
            str_starts_with($message, 'Cancel')
            || str_starts_with($message, 'TimeoutError')
            || str_starts_with($message, 'AbortError')
        ) {
            throw $error;
        }

        if (
            str_contains($message, 'ECONNREFUSED')
            || str_contains($message, 'fetch failed')
            || str_contains($message, 'Failed to fetch')
            || str_contains($message, 'NetworkError')
            || ($error instanceof HttpException && $error->status === 0)
        ) {
            if ($retriesLeft > 0) {
                return;
            }

            throw new ConnectionError(
                'Unable to connect to LangGraph server. Please ensure the server is running and accessible. '
                . 'Original error: ' . $message,
                0,
                $error,
            );
        }

        if ($error instanceof HttpError) {
            if (in_array($error->status, self::STATUS_NO_RETRY, true)) {
                throw $error;
            }
            if ($this->onFailedResponseHook !== null && $error->response !== null) {
                ($this->onFailedResponseHook)($error->response);
            }
        }
    }

    private function backoffMs(int $attempt): int
    {
        $random = $this->randomize ? 1 + (random_int(0, 1000) / 1000) : 1.0;

        return (int) round($random * $this->minTimeoutMs * ($this->factor ** ($attempt - 1)));
    }
}
