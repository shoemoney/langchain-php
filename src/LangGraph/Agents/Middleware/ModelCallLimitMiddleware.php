<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangGraph\Agents\Middleware;

/**
 * Creates a middleware to limit the number of model calls at both thread and run levels.
 *
 * Port of `modelCallLimitMiddleware` from `langchain/src/agents/middleware/modelCallLimit.ts`.
 *
 * It supports two limits: a thread-level limit (the total number of model calls across a conversation
 * thread) and a run-level limit (calls within one invocation). Before each model call the middleware checks
 * the counters against the limits; when one is reached it either ends the agent with an explanatory AI
 * message (`exitBehavior` `end`, the default) or throws a {@see ModelCallLimitMiddlewareError} (`error`).
 *
 * The limits can also come from the run context (`threadLimit`, `runLimit`, `exitBehavior`), which wins over
 * the options.
 *
 * ```
 * $agent = Agent::create([
 *     'model' => $model,
 *     'tools' => [$myTool],
 *     'middleware' => [ModelCallLimitMiddleware::create(['threadLimit' => 10, 'runLimit' => 3])],
 * ]);
 * ```
 *
 * Differences from upstream: `beforeModel` returns null rather than echoing the whole state back when no
 * limit is hit (the same "leave the state alone", without re-sending every message through the reducer),
 * and the deprecated `throw` exit behavior warns through `E_USER_DEPRECATED` instead of `console.warn`.
 */
final class ModelCallLimitMiddleware
{
    private const DEFAULT_EXIT_BEHAVIOR = 'end';

    private function __construct()
    {
    }

    /**
     * @param array{threadLimit?: int|float|null, runLimit?: int|float|null, exitBehavior?: 'error'|'end'|null} $middlewareOptions
     * @return array<string, mixed> the middleware
     */
    public static function create(array $middlewareOptions = []): array
    {
        return Middleware::create([
            'name' => 'ModelCallLimitMiddleware',
            'contextSchema' => [
                'type' => 'object',
                'properties' => [
                    'threadLimit' => ['type' => 'number'],
                    'runLimit' => ['type' => 'number'],
                    'exitBehavior' => ['type' => 'string', 'enum' => ['error', 'end']],
                ],
            ],
            'stateSchema' => [
                'type' => 'object',
                'properties' => [
                    'threadModelCallCount' => ['type' => 'number', 'default' => 0],
                    'runModelCallCount' => ['type' => 'number', 'default' => 0],
                ],
            ],
            'beforeModel' => [
                'canJumpTo' => ['end'],
                'hook' => static function (array $state, mixed $runtime) use ($middlewareOptions): ?array {
                    $context = self::contextOf($runtime);

                    $exitBehavior = $context['exitBehavior'] ?? $middlewareOptions['exitBehavior'] ?? self::DEFAULT_EXIT_BEHAVIOR;
                    if ($exitBehavior === 'throw') {
                        trigger_error("The 'throw' exit behavior is deprecated. Please use 'error' instead.", \E_USER_DEPRECATED);
                        $exitBehavior = 'error';
                    }

                    $threadLimit = $context['threadLimit'] ?? $middlewareOptions['threadLimit'] ?? null;
                    $runLimit = $context['runLimit'] ?? $middlewareOptions['runLimit'] ?? null;

                    $threadCount = $state['threadModelCallCount'] ?? 0;
                    $runCount = $state['runModelCallCount'] ?? 0;

                    if ((\is_int($threadLimit) || \is_float($threadLimit)) && $threadLimit <= $threadCount) {
                        return self::exceeded(new ModelCallLimitMiddlewareError(['threadLimit' => $threadLimit, 'threadCount' => $threadCount]), $exitBehavior);
                    }
                    if ((\is_int($runLimit) || \is_float($runLimit)) && $runLimit <= $runCount) {
                        return self::exceeded(new ModelCallLimitMiddlewareError(['runLimit' => $runLimit, 'runCount' => $runCount]), $exitBehavior);
                    }

                    return null;
                },
            ],
            'afterModel' => static fn (array $state): array => [
                'runModelCallCount' => ($state['runModelCallCount'] ?? 0) + 1,
                'threadModelCallCount' => ($state['threadModelCallCount'] ?? 0) + 1,
            ],
            'afterAgent' => static fn (): array => ['runModelCallCount' => 0],
        ]);
    }

    /**
     * @return array{jumpTo: 'end', messages: list<AIMessage>}
     * @throws ModelCallLimitMiddlewareError unless the exit behavior is `end`
     */
    private static function exceeded(ModelCallLimitMiddlewareError $error, mixed $exitBehavior): array
    {
        if ($exitBehavior === 'end') {
            return ['jumpTo' => 'end', 'messages' => [new AIMessage($error->getMessage())]];
        }

        throw $error;
    }

    /** @return array<string, mixed> */
    private static function contextOf(mixed $runtime): array
    {
        $context = \is_object($runtime) ? ($runtime->context ?? null) : (\is_array($runtime) ? ($runtime['context'] ?? null) : null);

        return \is_array($context) ? $context : (\is_object($context) ? get_object_vars($context) : []);
    }
}
