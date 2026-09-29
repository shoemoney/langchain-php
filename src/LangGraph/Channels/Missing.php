<?php

declare(strict_types=1);

namespace LangGraph\Channels;

/**
 * A value object standing in for the JavaScript `undefined` value.
 *
 * PHP has no `undefined`: a parameter's default and a channel's "no value yet"
 * state both have to reuse `null`, which is *also* a perfectly valid channel
 * value. Every upstream site written as `typeof checkpoint !== "undefined"` or
 * `this.value === undefined` is therefore expressed here as
 * `!$x instanceof Missing`, and a default parameter is declared as
 * `mixed $checkpoint = new Missing()` (PHP 8.1 "new in initializers").
 *
 * This keeps `null` and "absent" distinct, which the upstream test suite
 * depends on — e.g. `LastValue::fromCheckpoint(null)` must restore a channel
 * whose value *is* `null`, not an empty channel.
 *
 * This mirrors Python LangGraph's `MISSING` sentinel.
 */
final class Missing
{
    /**
     * True when the value represents JavaScript `undefined`.
     *
     * @param mixed $value Value to test.
     */
    public static function isMissing(mixed $value): bool
    {
        return $value instanceof self;
    }
}
