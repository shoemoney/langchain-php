<?php

declare(strict_types=1);

namespace LangGraph\Channels;

use LangGraph\Errors\EmptyChannelError;
use LangGraph\Errors\InvalidUpdateError;

/**
 * Stores the last value received, and is never persisted.
 *
 * Port of `UntrackedValueChannel` from
 * `langgraph-core/src/channels/untracked_value.ts`.
 *
 * Holds state for the duration of a run without ever writing it to a
 * checkpoint, so restoring from a checkpoint starts it empty. That is the point:
 * a database handle, a warm cache, a run-scoped token. Persisting any of those
 * would either fail or be a security problem.
 */
class UntrackedValue extends BaseChannel
{
    public string $lcGraphName = 'UntrackedValue';

    protected bool $guard;

    /** @var array{0: mixed}|array{} */
    protected array $value = [];

    /** @var (callable(): mixed)|null */
    protected $initialValueFactory;

    /**
     * @param bool $guard When true, multiple writes in one step raise. When
     *                    false the last write wins.
     * @param (callable(): mixed)|null $initialValueFactory
     */
    public function __construct(bool $guard = false, ?callable $initialValueFactory = null)
    {
        $this->guard = $guard;
        $this->initialValueFactory = $initialValueFactory;
        if ($initialValueFactory !== null) {
            $this->value = [$initialValueFactory()];
        }
    }

    /**
     * Always restores empty: this channel has no checkpoint representation, so
     * a resume deliberately loses whatever the previous run held.
     */
    public function fromCheckpoint(mixed $checkpoint = null): self
    {
        $factory = $this->initialValueFactory ?? null;

        return new static($this->guard ?? false, $factory);
    }

    public function update(array $values): bool
    {
        if ($values === []) {
            $updated = $this->value !== [];
            $this->value = [];

            return $updated;
        }

        if (count($values) > 1 && $this->guard) {
            throw new InvalidUpdateError('UntrackedValue can only receive one value per step.');
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

    /** Always null — this is what "untracked" means at the persistence layer. */
    public function checkpoint(): mixed
    {
        return null;
    }

    public function isAvailable(): bool
    {
        return $this->value !== [];
    }
}
