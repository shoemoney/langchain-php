<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangGraph\Agents\Middleware;

/**
 * Automatic fallback to alternative models when the primary model fails.
 *
 * Port of `modelFallbackMiddleware` from `langchain/src/agents/middleware/modelFallback.ts`.
 *
 * The fallbacks are tried in order after the primary model throws; the first success wins, and when the last
 * fallback fails too its error is thrown. A model is a chat model instance; a "provider:model" string needs
 * `initChatModel`, which this port does not have yet (WP-20), so it fails the same way `Agent::create` does
 * and counts as a failed fallback.
 *
 * ```
 * $agent = Agent::create([
 *     'model' => $primary,
 *     'middleware' => [ModelFallbackMiddleware::create($cheaper, $cheapest)],
 * ]);
 * ```
 */
final class ModelFallbackMiddleware
{
    private function __construct()
    {
    }

    /**
     * @param mixed ...$fallbackModels The models to fall back to, in order.
     * @return array<string, mixed> the middleware
     */
    public static function create(mixed ...$fallbackModels): array
    {
        $fallbackModels = array_values($fallbackModels);

        return Middleware::create([
            'name' => 'modelFallbackMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use ($fallbackModels): mixed {
                try {
                    return $handler($request);
                } catch (\Throwable $error) {
                    $last = \count($fallbackModels) - 1;
                    foreach ($fallbackModels as $i => $fallbackModel) {
                        try {
                            if (\is_string($fallbackModel)) {
                                throw new \RuntimeException(\sprintf(
                                    'Cannot resolve the model "%s": model id strings need initChatModel, which is not ported yet. Pass a chat model instance.',
                                    $fallbackModel,
                                ));
                            }

                            return $handler([...$request, 'model' => $fallbackModel]);
                        } catch (\Throwable $fallbackError) {
                            if ($i === $last) {
                                throw $fallbackError;
                            }
                            // Otherwise, continue to the next fallback.
                        }
                    }

                    throw $error;
                }
            },
        ]);
    }
}
