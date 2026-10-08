<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenRouter\Utils;

use LangChain\Utils\Http\HttpResponse;

/**
 * Base error for all OpenRouter API errors.
 *
 * Port of `OpenRouterError` from `@langchain/openrouter` (`utils/errors.ts`).
 *
 * {@see OpenRouterAuthError} and {@see OpenRouterRateLimitError} are the
 * specific failure modes. Use {@see self::fromResponse()} to let the library
 * pick the right subclass from an HTTP response, or throw this class directly
 * for generic API failures.
 *
 * Upstream has no `LangChainError` here to extend (this port has no such base),
 * so the hierarchy roots at `\RuntimeException`. Upstream's `isInstance()`
 * brand check exists to dodge cross-realm `instanceof` pitfalls that PHP does
 * not have; use plain `instanceof`.
 *
 * `$name` mirrors the JS `name` field. {@see \LangChain\Utils\AsyncCaller}
 * reads it when classifying a failed attempt, so it must stay the class's own
 * name rather than be left to the caller's guess.
 */
class OpenRouterError extends \RuntimeException
{
    public string $name = 'OpenRouterError';

    /**
     * @param array<string, mixed>  $metadata Additional error metadata returned by the API.
     * @param array<string, string> $headers  Response headers of the failed request (lower-cased names).
     */
    public function __construct(
        string $message,
        /** HTTP status code, if available. */
        public readonly ?int $statusCode = null,
        /** HTTP or API error code, if available. */
        ?int $code = null,
        public readonly ?array $metadata = null,
        public readonly ?array $headers = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code ?? 0, $previous);
    }

    /**
     * Creates a typed error from an HTTP response.
     *
     * @see Errors::fromResponse()
     */
    public static function fromResponse(HttpResponse $response, string $statusText = ''): self
    {
        return Errors::fromResponse($response, $statusText);
    }
}
