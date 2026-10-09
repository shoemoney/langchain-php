<?php

declare(strict_types=1);

namespace LangChain\Errors;

use LangChain\Utils\AsyncCaller;

/**
 * The combined input to a language model exceeded its context window.
 *
 * Port of `ContextOverflowError` from `langchain-core/src/errors/index.ts`. Upstream's `LangChainError`
 * base and `ns.brand` hierarchy have no PHP form, so this roots at `\RuntimeException`; the brand check
 * `isInstance()` is kept as a thin `instanceof` guard. The same oversized input fails identically, so the
 * error is stamped non-retryable at construction (it needs trimming, not another attempt).
 */
class ContextOverflowError extends \RuntimeException
{
    public string $name = 'ContextOverflowError';

    public function __construct(?string $message = null, ?\Throwable $cause = null)
    {
        parent::__construct($message ?? "Input exceeded the model's context window.", 0, $cause);
        AsyncCaller::stampRetryable($this, false);
    }

    /**
     * Wrap an existing error: its message is copied and it becomes the cause.
     */
    public static function fromError(\Throwable $error): self
    {
        return new self($error->getMessage(), $error);
    }

    public static function isInstance(mixed $value): bool
    {
        return $value instanceof self;
    }
}
