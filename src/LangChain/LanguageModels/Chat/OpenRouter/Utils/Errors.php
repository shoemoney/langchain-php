<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenRouter\Utils;

use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;

/**
 * Builds typed {@see OpenRouterError}s from failed HTTP exchanges.
 *
 * Port of `OpenRouterError.fromResponse` from `@langchain/openrouter`
 * (`utils/errors.ts`). The factory lives here, in the file named for the
 * module, because PHP allows one type per file and the three error classes each
 * have their own.
 *
 * ## Differences from the TypeScript original
 *
 *  - `fromResponse` is synchronous: {@see HttpResponse} has already read the body.
 *  - `fetch`'s `Response.statusText` has no counterpart on {@see HttpResponse}, so the
 *    caller may pass one; otherwise the standard reason phrase for the status is used.
 */
final class Errors
{
    private const REASON_PHRASES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        408 => 'Request Timeout',
        413 => 'Payload Too Large',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    private function __construct()
    {
    }

    /**
     * Attempts to parse the body as JSON (OpenRouter's standard
     * `{ error: { message, code, metadata } }` shape). Falls back to the raw HTTP
     * status text when the body is missing or unparseable.
     *
     * Status-code mapping:
     *  - 401 / 403 -> {@see OpenRouterAuthError}
     *  - 429       -> {@see OpenRouterRateLimitError}
     *  - anything else -> {@see OpenRouterError}
     */
    public static function fromResponse(HttpResponse $response, string $statusText = ''): OpenRouterError
    {
        $body = json_decode($response->body, true);
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : null;

        return self::build($response->status, $error, $response->headers(), $statusText);
    }

    /**
     * The same, for a transport that raised instead of returning (the streaming path).
     */
    public static function fromHttpException(HttpException $e): OpenRouterError
    {
        $body = json_decode($e->body, true);
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : null;

        return self::build($e->status, $error, [], '', $e);
    }

    /**
     * @param array<string, mixed>|null $error
     * @param array<string, string>     $headers
     */
    private static function build(int $status, ?array $error, array $headers, string $statusText, ?\Throwable $previous = null): OpenRouterError
    {
        $text = $statusText !== '' ? $statusText : (self::REASON_PHRASES[$status] ?? '');
        $baseMessage = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : "HTTP {$status}: {$text}";

        $metadata = is_array($error['metadata'] ?? null) ? $error['metadata'] : null;
        $metadataStr = $metadata !== null ? ' | metadata: ' . json_encode($metadata, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) : '';
        $message = $baseMessage . $metadataStr;
        $code = is_int($error['code'] ?? null) ? $error['code'] : $status;

        return match (true) {
            $status === 401 || $status === 403 => new OpenRouterAuthError($message, $status, $code, $metadata, $headers, $previous),
            $status === 429 => new OpenRouterRateLimitError($message, $status, $code, $metadata, $headers, $previous),
            default => new OpenRouterError($message, $status, $code, $metadata, $headers, $previous),
        };
    }
}
