<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * What the {@see Mux} needs from a run stream to settle its output: upstream's `RESOLVE_VALUES` and
 * `REJECT_VALUES` symbol-keyed methods.
 */
interface StreamHandle
{
    public function resolveValues(mixed $values): void;

    public function rejectValues(mixed $error): void;
}
