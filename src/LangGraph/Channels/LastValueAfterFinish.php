<?php

declare(strict_types=1);

namespace LangGraph\Channels;

use LangGraph\Errors\EmptyChannelError;

/**
 * Stores the last value received, but only exposes it after `finish()`.
 *
 * Port of `LastValueAfterFinish` from
 * `langgraph-core/src/channels/last_value.ts`.
 *
 * Used where a value must be collected during the run but only published at the
 * end — a trailing summary, an audit record. Once consumed, the value clears.
 */
class LastValueAfterFinish extends BaseChannel
{
    public string $lcGraphName = 'LastValueAfterFinish';

    /** @var array{0: mixed}|array{} */
    protected array $value = [];

    protected bool $finished = false;

    public function fromCheckpoint(mixed $checkpoint = null): self
    {
        $empty = new static();
        if ($checkpoint !== null && is_array($checkpoint) && array_key_exists(0, $checkpoint)) {
            $empty->value = [$checkpoint[0]];
            $empty->finished = (bool) ($checkpoint[1] ?? false);
        }

        return $empty;
    }

    public function update(array $values): bool
    {
        if ($values === []) {
            return false;
        }

        $this->finished = false;
        $this->value = [$values[count($values) - 1]];

        return true;
    }

    public function get(): mixed
    {
        if ($this->value === [] || !$this->finished) {
            throw new EmptyChannelError();
        }

        return $this->value[0];
    }

    /**
     * Checkpoints as `[value, finished]`, or null while still collecting —
     * unlike the base contract this does not throw when empty, because an
     * unfinished channel is a legitimate state to persist.
     */
    public function checkpoint(): mixed
    {
        if ($this->value === []) {
            return null;
        }

        return [$this->value[0], $this->finished];
    }

    public function consume(): bool
    {
        if ($this->finished) {
            $this->finished = false;
            $this->value = [];

            return true;
        }

        return false;
    }

    public function finish(): bool
    {
        if (!$this->finished && $this->value !== []) {
            $this->finished = true;

            return true;
        }

        return false;
    }

    public function isAvailable(): bool
    {
        return $this->value !== [] && $this->finished;
    }
}
