<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware\Provider\Anthropic;

use LangGraph\Agents\ConfigurableModelInterface;
use LangGraph\Agents\Middleware;

/**
 * Prompt caching for Anthropic models.
 *
 * Port of `anthropicPromptCachingMiddleware` from `langchain/src/agents/middleware/provider/anthropic/promptCaching.ts`.
 *
 * Once the conversation has `minMessagesToCache` messages (the system prompt counts as one) the request's
 * model settings gain `cache_control: {type: ephemeral, ttl}`, which the Anthropic client forwards as the
 * request's top-level `cache_control` so the provider caches the processed prompt prefix. The messages
 * themselves are untouched.
 *
 * Options (each may also be overridden per run through the run context, which wins): `enableCaching`
 * (default true), `ttl` ("5m" or "1h", default "5m"), `minMessagesToCache` (default 3) and
 * `unsupportedModelBehavior` ("ignore", "warn" (default) or "raise") for a model that is not Anthropic. The
 * model is recognised by the name it reports (`ChatAnthropic`) or, for a configurable model, by the model it
 * resolves to.
 *
 * ```
 * $agent = Agent::create([
 *     'model' => $chatAnthropic,
 *     'middleware' => [PromptCachingMiddleware::create(['ttl' => '1h', 'minMessagesToCache' => 5])],
 * ]);
 * ```
 */
final class PromptCachingMiddleware
{
    private const DEFAULT_ENABLE_CACHING = true;
    private const DEFAULT_TTL = '5m';
    private const DEFAULT_MIN_MESSAGES_TO_CACHE = 3;
    private const DEFAULT_UNSUPPORTED_MODEL_BEHAVIOR = 'warn';

    private function __construct()
    {
    }

    /**
     * @param array{enableCaching?: bool, ttl?: string, minMessagesToCache?: int|float, unsupportedModelBehavior?: string}|null $middlewareOptions
     * @return array<string, mixed> the middleware
     */
    public static function create(?array $middlewareOptions = null): array
    {
        $options = $middlewareOptions ?? [];

        return Middleware::create([
            'name' => 'PromptCachingMiddleware',
            'contextSchema' => [
                'type' => 'object',
                'properties' => [
                    'enableCaching' => ['type' => 'boolean'],
                    'ttl' => ['type' => 'string', 'enum' => ['5m', '1h']],
                    'minMessagesToCache' => ['type' => 'number'],
                    'unsupportedModelBehavior' => ['type' => 'string', 'enum' => ['ignore', 'warn', 'raise']],
                ],
            ],
            'wrapModelCall' => static function (array $request, callable $handler) use ($options): mixed {
                $context = self::contextOf($request['runtime'] ?? null);

                // Prefer the runtime context over the middleware options over the defaults.
                $enableCaching = $context['enableCaching'] ?? $options['enableCaching'] ?? self::DEFAULT_ENABLE_CACHING;
                $ttl = $context['ttl'] ?? $options['ttl'] ?? self::DEFAULT_TTL;
                $minMessagesToCache = $context['minMessagesToCache'] ?? $options['minMessagesToCache'] ?? self::DEFAULT_MIN_MESSAGES_TO_CACHE;
                $unsupportedModelBehavior = $context['unsupportedModelBehavior'] ?? $options['unsupportedModelBehavior'] ?? self::DEFAULT_UNSUPPORTED_MODEL_BEHAVIOR;

                $model = $request['model'] ?? null;
                if (!$enableCaching || !\is_object($model)) {
                    return $handler($request);
                }

                $modelName = $model->getName();
                $provider = null;
                if ($model instanceof ConfigurableModelInterface) {
                    $resolvedName = $model->getModelInstance()->getName();
                    $provider = self::providerOf($resolvedName);
                    $isAnthropic = $resolvedName === 'ChatAnthropic';
                } else {
                    $isAnthropic = $modelName === 'ChatAnthropic';
                }

                if (!$isAnthropic) {
                    $modelInfo = $provider !== null ? "{$modelName} ({$provider})" : $modelName;
                    $baseMessage = "Unsupported model '{$modelInfo}'. Prompt caching requires an Anthropic model";

                    if ($unsupportedModelBehavior === 'raise') {
                        throw new PromptCachingMiddlewareError("{$baseMessage} (e.g., 'anthropic:claude-4-0-sonnet').");
                    }
                    if ($unsupportedModelBehavior === 'warn') {
                        trigger_error(
                            "PromptCachingMiddleware: Skipping caching for {$modelName}. Consider switching to an Anthropic model for caching benefits.",
                            \E_USER_WARNING,
                        );
                    }

                    return $handler($request);
                }

                $systemPrompt = $request['systemPrompt'] ?? null;
                $messagesCount = \count((array) ($request['state']['messages'] ?? [])) + ($systemPrompt !== null && $systemPrompt !== '' ? 1 : 0);

                if ($messagesCount < $minMessagesToCache) {
                    return $handler($request);
                }

                // The client puts `cache_control` on the request body when it formats the call, which avoids
                // touching message content blocks earlier (for instance while a streamed reply is reassembled).
                return $handler([
                    ...$request,
                    'modelSettings' => [
                        ...(array) ($request['modelSettings'] ?? []),
                        'cache_control' => ['type' => 'ephemeral', 'ttl' => $ttl],
                    ],
                ]);
            },
        ]);
    }

    /** @return array<string, mixed> */
    private static function contextOf(mixed $runtime): array
    {
        $context = \is_object($runtime) && property_exists($runtime, 'context') ? $runtime->context : null;

        return \is_array($context) ? $context : [];
    }

    /** The provider key a configurable model reports: `ChatOpenAI` is "openai". */
    private static function providerOf(string $modelName): string
    {
        return strtolower(preg_replace('/^Chat/', '', $modelName) ?? $modelName);
    }
}
