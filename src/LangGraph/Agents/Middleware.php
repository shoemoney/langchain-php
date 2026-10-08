<?php

declare(strict_types=1);

namespace LangGraph\Agents;

use LangGraph\Agents\Middleware\Types;

/**
 * Creates middleware for an agent.
 *
 * Port of `createMiddleware` from `langchain/src/agents/middleware.ts`.
 *
 * ```
 * $auth = Middleware::create([
 *     'name' => 'AuthMiddleware',
 *     'stateSchema' => ['type' => 'object', 'properties' => ['isAuthenticated' => ['type' => 'boolean', 'default' => false]]],
 *     'contextSchema' => ['type' => 'object', 'properties' => ['userId' => ['type' => 'string']], 'required' => ['userId']],
 *     'beforeModel' => static function (array $state, Runtime $runtime): ?array {
 *         if (!$state['isAuthenticated']) {
 *             throw new \Exception('Not authenticated');
 *         }
 *         return null;
 *     },
 * ]);
 * ```
 *
 * Upstream infers the types of the hooks from the schemas; here the schemas are JSON Schema arrays (or an
 * `AnnotationRoot` for `stateSchema`, which is how reducers are expressed) and a middleware is the array this
 * returns. The recognised keys are:
 *
 *  - `name` (required): the name of the middleware, unique within an agent;
 *  - `stateSchema`: the middleware state, persisted between invocations;
 *  - `contextSchema`: the middleware context, read-only and not persisted;
 *  - `tools`: additional tools registered by the middleware;
 *  - `streamTransformers`: stream transformer factories registered by the middleware (recorded on the
 *    middleware; this port has no transformer protocol to merge them into, see `ReactAgent`);
 *  - `wrapToolCall`: `fn(array $request, callable $handler): ToolMessage|Command`. Wraps tool execution, to
 *    modify the call, handle errors and retry, post-process the result, cache, log, or return a `Command`.
 *    `$request` is `['toolCall' => …, 'tool' => …, 'state' => …, 'runtime' => Runtime]`;
 *  - `wrapModelCall`: `fn(array $request, callable $handler): AIMessage|Command`. Wraps the model invocation.
 *    `$request` carries `model`, `messages`, `systemPrompt`, `systemMessage`, `tools`, `toolChoice`, `state`,
 *    `runtime`, `responseFormat` and `modelSettings`; `$handler` takes a (possibly modified) request;
 *  - `beforeAgent`, `afterAgent`: run once at the start / end of an invocation;
 *  - `beforeModel`, `afterModel`: run before / after every model call. Each is `fn(array $state, Runtime $runtime)`
 *    returning a state update (optionally with `jumpTo`) or null, or `['hook' => callable, 'canJumpTo' => [...]]`.
 */
final class Middleware
{
    private const KEYS = [
        'name',
        'stateSchema',
        'contextSchema',
        'tools',
        'streamTransformers',
        'wrapToolCall',
        'wrapModelCall',
        'beforeAgent',
        'beforeModel',
        'afterModel',
        'afterAgent',
    ];

    private function __construct()
    {
    }

    /**
     * Port of `createMiddleware`.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed> the middleware
     * @throws \InvalidArgumentException for a missing name or an unknown key
     */
    public static function create(array $config): array
    {
        if (!\is_string($config['name'] ?? null) || $config['name'] === '') {
            throw new \InvalidArgumentException('A middleware requires a non-empty "name".');
        }

        $unknown = array_diff(array_keys($config), self::KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown middleware option(s): ' . implode(', ', $unknown));
        }

        $middleware = [Types::MIDDLEWARE_BRAND => true];
        foreach (self::KEYS as $key) {
            if (($config[$key] ?? null) !== null) {
                $middleware[$key] = $config[$key];
            }
        }

        return $middleware;
    }
}
