<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\SystemMessage;
use LangGraph\Agents\Middleware;

/**
 * Dynamic system prompt middleware that sets the system message from a function before each model call.
 *
 * Port of `dynamicSystemPromptMiddleware` from `langchain/src/agents/middleware/dynamicSystemPrompt.ts`.
 *
 * The function receives the agent state and the runtime (with the run context) and returns a string or a
 * {@see SystemMessage}, which is appended to the request's system message. Anything else raises.
 *
 * ```
 * $middleware = DynamicSystemPromptMiddleware::create(
 *     static fn (array $state, Runtime $runtime): string => 'You are a helpful assistant. Region: ' . ($runtime->context['region'] ?? 'n/a'),
 * );
 * ```
 */
final class DynamicSystemPromptMiddleware
{
    private function __construct()
    {
    }

    /**
     * @param callable(array<string, mixed>, mixed): (string|SystemMessage) $fn
     * @return array<string, mixed> the middleware
     */
    public static function create(callable $fn): array
    {
        return Middleware::create([
            'name' => 'DynamicSystemPromptMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use ($fn): mixed {
                $systemPrompt = $fn((array) ($request['state'] ?? []), $request['runtime'] ?? null);

                if (!\is_string($systemPrompt) && !$systemPrompt instanceof SystemMessage) {
                    throw new \RuntimeException('dynamicSystemPromptMiddleware function must return a string or SystemMessage');
                }

                return $handler([
                    ...$request,
                    'systemMessage' => Utils::concatSystemMessage($request['systemMessage'], $systemPrompt),
                ]);
            },
        ]);
    }
}
