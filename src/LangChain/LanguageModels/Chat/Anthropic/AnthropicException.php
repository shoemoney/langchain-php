<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic;

use LangChain\Utils\Http\HttpException;

/**
 * An error reported by the Anthropic API.
 *
 * Anthropic's error body is `{"type": "error", "error": {"type", "message"}}`,
 * where the inner `type` is the machine-usable part (`invalid_request_error`,
 * `overloaded_error`, `rate_limit_error`, …) and the outer one is always the
 * literal `"error"`. Both are kept.
 */
final class AnthropicException extends HttpException
{
    /**
     * @param array<string, mixed> $providerError The inner `error` object.
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
            : 'Anthropic request to ' . $url . ' failed with status ' . $status . '.';

        $type = $error['type'] ?? null;
        if (is_string($type) && $type !== '') {
            $message .= ' (type: ' . $type . ')';
        }

        return new self($message, $status, $body, $error, $previous);
    }
}
