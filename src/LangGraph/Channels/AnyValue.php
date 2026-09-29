<?php

declare(strict_types=1);

namespace LangGraph\Channels;

use LangGraph\Errors\EmptyChannelError;

/**
 * Stores the last value received, assuming concurrent writes agree.
 *
 * Port of `AnyValue` from `langgraph-core/src/channels/any_value.ts`.
 *
 * The contract with the graph author is "if several nodes write this channel in
 * one step, they will all write the same value". Unlike {@see LastValue},
 * multiple writes do not raise — the last one simply wins. That makes it the
 * right channel for a *derived* value that every parallel branch recomputes
 * identically, and the wrong one for anything a human is editing.
 *
 * Note the deliberate difference from every other channel: `update([])` CLEARS
 * the value. AnyValue is a "current step only" channel, so an empty update means
 * no branch wrote it, and holding a stale value would be wrong.
 */
class AnyValue extends BaseChannel
{
    public string $lcGraphName = 'AnyValue';

    /** @var array{0: mixed}|array{} */
    protected array $value = [];

    public function __construct()
    {
    }

    public function fromCheckpoint(mixed $checkpoint = null): self
    {
        $empty = new static();
        if ($checkpoint !== null) {
            $empty->value = [$checkpoint];
        }

        return $empty;
    }

    public function update(array $values): bool
    {
        if ($values === []) {
            $updated = $this->value !== [];
            $this->value = [];

            return $updated;
        }

        $this->value = [$values[count($values) - 1]];

        return true;
    }

    public function get(): mixed
    {
        if ($this->value === []) {
            throw new EmptyChannelError();
        }

        return $this->value[0];
    }

    public function checkpoint(): mixed
    {
        if ($this->value === []) {
            throw new EmptyChannelError();
        }

        return $this->value[0];
    }

    public function isAvailable(): bool
    {
        return $this->value !== [];
    }
}
