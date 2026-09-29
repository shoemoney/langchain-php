<?php

declare(strict_types=1);

namespace LangChain\Prompts;

/**
 * An error raised when a prompt's input does not match the shape it declares.
 *
 * Port of the `InputFormatError` name the TypeScript original assigns to the
 * errors thrown by `MessagesPlaceholder`. The distinction it draws is the useful
 * one: the value you supplied was not acceptable *as prompt input*, which is a
 * different problem from a bug elsewhere in the code.
 *
 * A missing key and an un-coercible value are both this, because from the
 * caller's side they are the same mistake.
 */
class InputFormatError extends \RuntimeException
{
    /** The exception "name" the TypeScript original sets. */
    public string $errorName = 'InputFormatError';

    /** The `lc_error_code` carried over from the underlying coercion failure. */
    public ?string $lcErrorCode = null;
}
