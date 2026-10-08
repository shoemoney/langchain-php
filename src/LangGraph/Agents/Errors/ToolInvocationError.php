<?php

declare(strict_types=1);

namespace LangGraph\Agents\Errors;

/**
 * Raised when a tool call is throwing an error.
 *
 * Port of `ToolInvocationError` from `langchain/src/agents/errors.ts`.
 *
 * Upstream embeds `error.stack` in the message, so the model sees the original failure; the PHP
 * equivalent is the throwable's string form (message plus trace). The tool call's `args` are encoded
 * as a JSON object, so an empty argument map reads `{}` as it does upstream, not `[]`.
 */
class ToolInvocationError extends \Exception
{
    public const BRAND = 'ToolInvocationError';

    public readonly string $brand;

    public readonly \Throwable $toolError;

    /**
     * @param array<string, mixed> $toolCall
     */
    public function __construct(mixed $toolError, public readonly array $toolCall)
    {
        $error = $toolError instanceof \Throwable ? $toolError : new \Exception(self::stringify($toolError));
        $args = $toolCall['args'] ?? null;
        $encodedArgs = $args === [] || $args === null
            ? '{}'
            : (string) json_encode($args, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        parent::__construct(
            "Error invoking tool '" . (string) ($toolCall['name'] ?? '') . "' with kwargs " . $encodedArgs
            . ' with error: ' . (string) $error . "\n Please fix the error and try again.",
            0,
            $error,
        );

        $this->brand = self::BRAND;
        $this->toolError = $error;
    }

    /** Whether the value is a ToolInvocationError. */
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
