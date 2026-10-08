<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenRouter\Utils;

/**
 * Thrown when the API returns HTTP 429 (Too Many Requests).
 *
 * Callers can catch this specifically to implement back-off / retry logic
 * without catching unrelated API errors.
 */
class OpenRouterRateLimitError extends OpenRouterError
{
    public string $name = 'OpenRouterRateLimitError';
}
