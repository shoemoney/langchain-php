<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenRouter\Utils;

/**
 * Thrown on authentication or authorization failures.
 *
 * Created by {@see Errors::fromResponse()} for HTTP 401/403 responses, and
 * thrown directly by the {@see \LangChain\LanguageModels\Chat\OpenRouter\ChatOpenRouter}
 * constructor when no API key is provided.
 */
class OpenRouterAuthError extends OpenRouterError
{
    public string $name = 'OpenRouterAuthError';
}
