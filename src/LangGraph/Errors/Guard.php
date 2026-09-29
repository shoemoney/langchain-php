<?php

declare(strict_types=1);

namespace LangGraph\Errors;

/**
 * Type guards for the engine's control-flow errors.
 *
 * Port of `isGraphInterrupt`, `isGraphBubbleUp`, `isGraphDrained`,
 * `isParentCommand` and `isNodeError` from `langgraph-core/src/errors.ts`.
 *
 * The TS original tests `e.name === Unminifiable_name` because a thrown value
 * can cross a worker boundary and lose its class. PHP has typed exceptions and
 * no such boundary, so these are `instanceof` checks — which is strictly more
 * precise, not less.
 */
final class Guard
{
    private function __construct()
    {
    }

    /** True for interrupts raised by the engine or by a node. */
    public static function isGraphInterrupt(mixed $e): bool
    {
        return $e instanceof GraphInterrupt;
    }

    /** True for any engine control-flow signal. */
    public static function isGraphBubbleUp(mixed $e): bool
    {
        return $e instanceof GraphBubbleUp;
    }

    public static function isGraphDrained(mixed $e): bool
    {
        return $e instanceof GraphDrained;
    }

    public static function isParentCommand(mixed $e): bool
    {
        return $e instanceof ParentCommand;
    }

    public static function isNodeError(mixed $e): bool
    {
        return $e instanceof NodeError;
    }
}
