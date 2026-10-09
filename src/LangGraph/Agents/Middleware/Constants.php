<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Utils\AsyncCaller;

/**
 * Port of `langchain/src/agents/middleware/constants.ts`.
 *
 * `RETRY_DEFAULTS` and {@see self::parseRetrySchema()} are upstream's `RetrySchema` (a Zod object). The
 * parse returns Zod's `safeParse` shape: `['success' => true, 'data' => ...]` or `['success' => false,
 * 'issues' => ...]`, with Zod's issue codes and v3 messages.
 */
final class Constants
{
    /** LangGraph's messages handlers skip runs tagged with this, keeping middleware-internal model calls out of the messages stream. */
    public const INTERNAL_CALL_TAG = 'nostream';

    /** Maximum number of retry attempts after the initial call (3 attempts in total). */
    public const DEFAULT_MAX_RETRIES = 2;

    /** Multiplier for exponential backoff; 0.0 gives a constant delay. */
    public const DEFAULT_BACKOFF_FACTOR = 2.0;

    /** Delay before the first retry, in milliseconds. */
    public const DEFAULT_INITIAL_DELAY_MS = 1000;

    /** Cap on the delay between retries, in milliseconds. */
    public const DEFAULT_MAX_DELAY_MS = 60000;

    /** Whether to add +-25% random jitter to the delay. */
    public const DEFAULT_JITTER = true;

    private function __construct()
    {
    }

    /**
     * The default `retryOn`: retry unless the error is marked non-retryable.
     *
     * @return \Closure(\Throwable): bool
     */
    public static function defaultRetryOn(): \Closure
    {
        return static fn (\Throwable $error): bool => AsyncCaller::getRetryable($error) ?? true;
    }

    /**
     * Validate the fields shared by both retry middleware and fill in the defaults.
     *
     * @param array<string, mixed> $config
     * @return array{success: true, data: array{maxRetries: int|float, retryOn: callable|list<class-string<\Throwable>>, backoffFactor: int|float, initialDelayMs: int|float, maxDelayMs: int|float, jitter: bool}}|array{success: false, issues: list<array{path: list<string|int>, code: string, message: string}>}
     */
    public static function parseRetrySchema(array $config): array
    {
        $issues = [];
        $data = [];

        foreach ([
            'maxRetries' => self::DEFAULT_MAX_RETRIES,
            'backoffFactor' => self::DEFAULT_BACKOFF_FACTOR,
            'initialDelayMs' => self::DEFAULT_INITIAL_DELAY_MS,
            'maxDelayMs' => self::DEFAULT_MAX_DELAY_MS,
        ] as $key => $default) {
            $value = $config[$key] ?? $default;
            if (!\is_int($value) && !\is_float($value)) {
                $issues[] = [
                    'path' => [$key],
                    'code' => 'invalid_type',
                    'message' => 'Expected number, received ' . self::receivedType($value),
                ];
            } elseif ($value < 0) {
                $issues[] = ['path' => [$key], 'code' => 'too_small', 'message' => 'Number must be greater than or equal to 0'];
            } else {
                $data[$key] = $value;
            }
        }

        $jitter = $config['jitter'] ?? self::DEFAULT_JITTER;
        if (!\is_bool($jitter)) {
            $issues[] = ['path' => ['jitter'], 'code' => 'invalid_type', 'message' => 'Expected boolean, received ' . self::receivedType($jitter)];
        } else {
            $data['jitter'] = $jitter;
        }

        $retryOn = $config['retryOn'] ?? self::defaultRetryOn();
        $normalised = self::normaliseRetryOn($retryOn);
        if ($normalised === null) {
            $issues[] = ['path' => ['retryOn'], 'code' => 'invalid_union', 'message' => 'Invalid input'];
        } else {
            $data['retryOn'] = $normalised;
        }

        if ($issues !== []) {
            return ['success' => false, 'issues' => $issues];
        }

        /** @var array{maxRetries: int|float, retryOn: callable|list<class-string<\Throwable>>, backoffFactor: int|float, initialDelayMs: int|float, maxDelayMs: int|float, jitter: bool} $data */
        return ['success' => true, 'data' => $data];
    }

    /**
     * `retryOn` is either a predicate or a list of error classes.
     *
     * @return callable|list<class-string<\Throwable>>|null null when it is neither
     */
    private static function normaliseRetryOn(mixed $retryOn): callable|array|null
    {
        if (\is_array($retryOn) && array_is_list($retryOn)) {
            foreach ($retryOn as $class) {
                if (!\is_string($class) || !class_exists($class)) {
                    return \is_callable($retryOn) ? $retryOn : null;
                }
            }

            /** @var list<class-string<\Throwable>> $retryOn */
            return $retryOn;
        }

        return \is_callable($retryOn) ? $retryOn : null;
    }

    private static function receivedType(mixed $value): string
    {
        return match (true) {
            $value === null => 'undefined',
            \is_string($value) => 'string',
            \is_bool($value) => 'boolean',
            \is_array($value) => 'array',
            \is_object($value) => 'object',
            default => get_debug_type($value),
        };
    }
}
