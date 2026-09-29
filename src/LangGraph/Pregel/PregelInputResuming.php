<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * Marks the run as continuing a previous one.
 *
 * Port of the `INPUT_RESUMING` symbol in `loop.ts`. Distinct from
 * {@see PregelInputDone} because a resuming run must not re-run `_first()`'s
 * input handling: the input is not new state, it is a continuation.
 */
final class PregelInputResuming
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        self::$instance ??= new self();

        return self::$instance;
    }
}
