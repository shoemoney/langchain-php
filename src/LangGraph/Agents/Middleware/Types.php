<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangGraph\Agents\Utils as AgentUtils;

/**
 * The runtime-relevant parts of `langchain/src/agents/middleware/types.ts` and `agents/constants.ts`.
 *
 * Nearly all of upstream's `types.ts` (861 lines) is type-level inference over Zod and `StateSchema`
 * (`InferMiddlewareState`, `InferMergedState`, ...) and has no PHP form. What survives at runtime is the
 * brand, the set of jump targets, and the shape of a middleware. A middleware is an array (what
 * `Middleware::create()` returns) or an object exposing the same names:
 *
 *  - `name`: unique within an agent;
 *  - `stateSchema`: an `AnnotationRoot` or a JSON Schema array; persisted between invocations;
 *  - `contextSchema`: a JSON Schema array; read-only, filtered from the run context;
 *  - `tools`: tools the middleware registers;
 *  - `streamTransformers`: stream transformer factories (recorded, see `Middleware::create()`);
 *  - `wrapToolCall`: `fn(array $request, callable $handler): ToolMessage|Command`;
 *  - `wrapModelCall`: `fn(array $request, callable $handler): AIMessage|Command`;
 *  - `beforeAgent`, `beforeModel`, `afterModel`, `afterAgent`: `fn(array $state, Runtime $runtime): ?array`, or
 *    `['hook' => callable, 'canJumpTo' => list<string>]` when the hook may jump.
 *
 * A hook returning `null` leaves the state alone. A returned array is a state update and may carry `jumpTo`.
 */
final class Types
{
    /** Upstream's `MIDDLEWARE_BRAND` (`Symbol.for("AgentMiddleware")`), kept as the array key that marks a middleware. */
    public const MIDDLEWARE_BRAND = 'AgentMiddleware';

    /** The user facing jump targets (`JUMP_TO_TARGETS` in `agents/constants.ts`). */
    public const JUMP_TO_TARGETS = ['model', 'tools', 'end'];

    /** The hooks that run as graph nodes, in the order a middleware registers them. */
    public const NODE_HOOKS = ['beforeAgent', 'beforeModel', 'afterModel', 'afterAgent'];

    private function __construct()
    {
    }

    /**
     * Whether a value is a middleware created by {@see \LangGraph\Agents\Middleware::create()}, or a
     * compatible array/object with a name.
     */
    public static function isMiddleware(mixed $value): bool
    {
        if (\is_array($value)) {
            return ($value[self::MIDDLEWARE_BRAND] ?? false) === true || \is_string($value['name'] ?? null);
        }

        return \is_object($value) && \is_string(AgentUtils::middlewareValue($value, 'name'));
    }
}
