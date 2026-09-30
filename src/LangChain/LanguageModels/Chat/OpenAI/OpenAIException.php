<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI;

use LangChain\Utils\Http\HttpException;

/**
 * An error reported by the OpenAI API.
 *
 * The API returns errors as a JSON body of the shape
 * `{"error": {"message", "type", "param", "code"}}` on a 4xx/5xx status. The
 * whole of it is preserved on `providerError` because the useful part is
 * usually in `code` — a `model_not_found` and a `context_length_exceeded` both
 * arrive with a message the caller already knows and a code they do not.
 */
final class OpenAIException extends HttpException
{
    /**
     * @param array<string, mixed> $providerError The `error` object, verbatim.
     */
    public function __construct(
        string $message,
        int $status,
        string $body,
        public readonly array $providerError = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $body, $previous);
    }

    /**
     * Build the exception from a failed response.
     *
     * A body that is not the documented error shape still produces a usable
     * message: an HTML error page from an edge proxy has no `error` object, and
     * "status 502" plus the body beats "status 502" alone.
     */
    public static function fromResponse(
        string $body,
        int $status,
        string $url,
        ?\Throwable $previous = null,
    ): self {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];

        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : 'OpenAI request to ' . $url . ' failed with status ' . $status . '.';

        $code = $error['code'] ?? null;
        if (is_string($code) && $code !== '') {
            $message .= ' (code: ' . $code . ')';
        }

        return new self($message, $status, $body, $error, $previous);
    }
}
