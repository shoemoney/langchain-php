<?php

declare(strict_types=1);

namespace LangGraph\Agents\Errors;

use LangGraph\Errors\Guard;

/**
 * Error thrown when a middleware fails.
 *
 * Port of `MiddlewareError` from `langchain/src/agents/errors.ts`.
 *
 * Use {@see self::wrap()} to create instances. The constructor is private to ensure that
 * GraphBubbleUp errors (like GraphInterrupt) are never wrapped. The original error rides along as
 * `getPrevious()`, which is upstream's `cause`.
 */
final class MiddlewareError extends \Exception
{
    public const BRAND = 'MiddlewareError';

    public readonly string $brand;

    /** Upstream's `this.name`: the wrapped error's name, or `<Middleware>Error` for a non-error. */
    public readonly string $errorName;

    private function __construct(mixed $error, string $middlewareName)
    {
        $isError = $error instanceof \Throwable;
        parent::__construct($isError ? $error->getMessage() : self::stringify($error), 0, $isError ? $error : null);

        $this->brand = self::BRAND;
        $this->errorName = $isError
            ? (new \ReflectionClass($error))->getShortName()
            : ucfirst($middlewareName) . 'Error';
    }

    /**
     * Wrap an error in a MiddlewareError, unless it's a GraphBubbleUp error (like GraphInterrupt)
     * which should propagate unchanged.
     */
    public static function wrap(mixed $error, string $middlewareName): \Throwable
    {
        if (Guard::isGraphBubbleUp($error)) {
            /** @var \Throwable $error */
            return $error;
        }

        return new self($error, $middlewareName);
    }

    /** Whether the value is a MiddlewareError. */
    public static function isInstance(mixed $error): bool
    {
        return $error instanceof self;
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_scalar($value) || $value === null => (string) json_encode($value),
            $value instanceof \Stringable => (string) $value,
            default => get_debug_type($value),
        };
    }
}
