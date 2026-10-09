<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

/**
 * Error helpers from `langchain-mcp-adapters/src/utils/errors.ts`.
 *
 * `UnauthorizedError` belongs to the MCP SDK's OAuth flow, which is not ported, so
 * {@see self::isAuthenticationError()} recognises HTTP 401 only.
 */
final class Errors
{
    /** `.cause` hops {@see self::isAuthenticationError()} follows before giving up. */
    public const MAX_CAUSE_WALK_DEPTH = 3;

    private function __construct()
    {
    }

    /**
     * Port of `isToolException`. Upstream brands its errors so a name-only lookalike is rejected
     * and a copy loaded from another module is accepted; `instanceof` gives the first of those
     * and the second has no PHP equivalent.
     */
    public static function isToolException(mixed $error): bool
    {
        return $error instanceof ToolException;
    }

    /**
     * The HTTP status carried by an error-ish value, with upstream's precedence:
     * `status`, then `code`, then a `(HTTP nnn)` mention in `message`. Anything outside 100-599
     * (or not an integer) is ignored, field by field.
     *
     * @param array<string, mixed>|object|mixed $error
     */
    public static function getHttpErrorCode(mixed $error): ?int
    {
        $fields = self::fields($error);
        if ($fields === null) {
            return null;
        }

        $status = self::httpStatus($fields['status'] ?? null);
        $code = self::httpStatus($fields['code'] ?? null);
        $message = is_string($fields['message'] ?? null) ? $fields['message'] : null;

        $fromMessage = null;
        if ($message !== null && preg_match('/\(HTTP (\d{3})\)/', $message, $match) === 1) {
            $fromMessage = self::httpStatus((int) $match[1]);
        }

        return $status ?? $code ?? $fromMessage;
    }

    /**
     * Whether `$error`, or a cause up to {@see self::MAX_CAUSE_WALK_DEPTH} hops down its chain,
     * is an HTTP 401.
     */
    public static function isAuthenticationError(mixed $error): bool
    {
        $current = $error;
        for ($depth = 0; $depth <= self::MAX_CAUSE_WALK_DEPTH; ++$depth) {
            if (self::getHttpErrorCode($current) === 401) {
                return true;
            }
            if (!$current instanceof \Throwable) {
                return false;
            }
            $current = property_exists($current, 'cause') && $current->cause !== null
                ? $current->cause
                : $current->getPrevious();
        }

        return false;
    }

    public static function createAuthenticationErrorMessage(string $serverName, string $url, string $transport, string $originalError): string
    {
        return "Authentication failed for {$transport} server \"{$serverName}\" at {$url}. "
            . 'Please check your credentials, authorization headers, or OAuth configuration. '
            . "Original error: {$originalError}";
    }

    /** @return array<string, mixed>|null */
    private static function fields(mixed $error): ?array
    {
        if (is_array($error)) {
            return $error;
        }
        if ($error instanceof \Throwable) {
            return [...get_object_vars($error), 'message' => $error->getMessage()];
        }
        if (is_object($error)) {
            return get_object_vars($error);
        }

        return null;
    }

    private static function httpStatus(mixed $value): ?int
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 100 && $value <= 599 ? $value : null;
    }
}
