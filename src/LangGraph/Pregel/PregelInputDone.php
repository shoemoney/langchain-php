<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * Marks the run's input as consumed, with nothing to resume.
 *
 * Port of the `INPUT_DONE` symbol in `loop.ts`.
 *
 * The loop needs three distinguishable input states and JS expresses them with
 * symbols. PHP has no symbol type that survives this round trip cleanly, so
 * they are singletons: identity comparison replaces `===` on a symbol, and the
 * class name documents what the state means.
 */
final class PregelInputDone
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
