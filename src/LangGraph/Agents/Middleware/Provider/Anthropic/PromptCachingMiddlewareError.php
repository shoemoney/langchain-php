<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware\Provider\Anthropic;

/**
 * Raised when prompt caching is asked of a model that is not Anthropic and `unsupportedModelBehavior` is "raise".
 *
 * Port of the `PromptCachingMiddlewareError` class in `provider/anthropic/promptCaching.ts`.
 */
final class PromptCachingMiddlewareError extends \Exception
{
}
