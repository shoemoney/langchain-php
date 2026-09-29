<?php

declare(strict_types=1);

namespace LangGraph\Channels;

use LangGraph\Errors\EmptyChannelError;
use LangGraph\Errors\InvalidUpdateError;

/**
 * Exposes the value written in the immediately preceding step, then clears.
 *
 * Port of `EphemeralValue` from `langgraph-core/src/channels/ephemeral_value.ts`.
 *
 * This is how a graph sends something to a node *once* without polluting the
 * persistent state — a nudge, a retry flag, a one-shot command. The clearing
 * happens in `update([])`, which Pregel calls every step for every channel
 * regardless of whether anything wrote to it; that call is the whole reason
 * Pregel must not skip unwritten channels.
 *
 * The `guard` flag relaxes the one-write-per-step rule to last-write-wins when
 * a fan-in genuinely can produce several values.
 */
class EphemeralValue extends BaseChannel
{
    public string $lcGraphName = 'EphemeralValue';

    protected bool $guard;

    /** @var array{0: mixed}|array{} */
    protected array $value = [];

    public function __construct(bool $guard = true)
    {
        $this->guard = $guard;
    }

    public function fromCheckpoint(mixed $checkpoint = null): self
    {
        $empty = new static($this->guard ?? true);
        if ($checkpoint !== null) {
            $empty->value = [$checkpoint];
        }

        return $empty;
    }

    public function update(array $values): bool
    {
        if ($values === []) {
            $updated = $this->value !== [];
            // No write this step: wipe, so the value lives exactly one step.
            $this->value = [];

            return $updated;
        }

        if (count($values) > 1 && $this->guard) {
            throw new InvalidUpdateError('EphemeralValue can only receive one value per step.');
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
