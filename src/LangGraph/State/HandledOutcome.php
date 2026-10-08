<?php

declare(strict_types=1);

namespace LangGraph\State;

/**
 * A node's result that came from its error handler rather than from the node itself.
 *
 * Upstream runs a handler as its own task, so only the handler's state write and its `Command` goto
 * fire afterwards, never the failed node's edges. This engine runs the handler inline, so the wrapper
 * marks its result with this class and the failed node's edge, join and conditional-edge writers
 * ({@see SkipWhenHandled}) stand down; the state write-back and the hidden `Command` branch unwrap it.
 *
 * @internal
 */
final class HandledOutcome
{
    public function __construct(public readonly mixed $value)
    {
    }

    public static function unwrap(mixed $value): mixed
    {
        return $value instanceof self ? $value->value : $value;
    }
}
