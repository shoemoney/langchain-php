<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

use LangChain\Utils\Http\HttpException;

/**
 * Port of `utils/error.ts`.
 *
 * JS tells a network failure by `error.name === "TypeError"` plus the message. PHP has no such
 * convention: a transport failure surfaces as an `HttpException` with status 0 (nothing came back),
 * or a `TypeError` from a custom fetch, so both count as the "name" half of the check.
 */
final class ErrorUtils
{
    public static function isError(mixed $error): bool
    {
        return $error instanceof \Throwable;
    }

    public static function isNetworkError(mixed $error): bool
    {
        if (!self::isError($error)) {
            return false;
        }

        $isTransportShape = $error instanceof \TypeError
            || ($error instanceof HttpException && $error->status === 0);
        if (!$isTransportShape) {
            return false;
        }

        $message = strtolower($error->getMessage());
        $cause = $error->getPrevious();
        $causeMessage = $cause === null ? '' : strtolower($cause->getMessage());

        foreach (['fetch', 'network', 'connection', 'error sending request', 'load failed', 'terminated'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return str_contains($causeMessage, 'other side closed') || str_contains($causeMessage, 'socket');
    }
}
