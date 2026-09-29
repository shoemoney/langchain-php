<?php

declare(strict_types=1);

namespace LangGraph\Errors;

/**
 * Raised when a channel is read before it has a value.
 *
 * Port of `EmptyChannelError`. This is a *load-bearing* exception, not an
 * incidental one: `BaseChannel::isAvailable()` is defined as "call get() and
 * catch this", so the type of the throw is the availability check.
 */
class EmptyChannelError extends BaseLangGraphError
{
    /**
     * A marker that survives minification, mirroring the JS original's
     * `unminifiable_name`. Detection is by class in PHP, but the constant is
     * kept because serialized traces and cross-runtime errors carry the string.
     */
    public const UNMINIFIABLE_NAME = 'EmptyChannelError';

    public function __construct(string $message = 'Channel is empty', array $fields = [])
    {
        parent::__construct($message, $fields + ['lc_error_code' => 'EMPTY_CHANNEL']);
    }
}
