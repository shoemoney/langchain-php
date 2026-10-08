<?php

declare(strict_types=1);

namespace LangGraph\Func;

/**
 * A value to return to the caller paired with a separate value to checkpoint.
 *
 * Port of `EntrypointFinal` and `isEntrypointFinal` from `langgraph-core/src/func/types.ts`
 * (the `EntrypointReturnT` / `EntrypointFinalSaveT` helper types are TypeScript-only and have
 * no runtime counterpart).
 *
 * An entrypoint normally saves what it returns. Returning an `EntrypointFinal` decouples the
 * two: `value` goes to the caller, `save` becomes the next invocation's
 * {@see Func::getPreviousState()}.
 */
final class EntrypointFinal
{
    public const LG_TYPE = '__pregel_final';

    public function __construct(
        public readonly mixed $value = null,
        public readonly mixed $save = null,
    ) {
    }

    /** Port of `isEntrypointFinal`. */
    public static function isEntrypointFinal(mixed $value): bool
    {
        return $value instanceof self;
    }
}
