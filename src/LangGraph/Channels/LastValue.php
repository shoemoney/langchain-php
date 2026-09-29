<?php

declare(strict_types=1);

namespace LangGraph\Channels;

use LangGraph\Errors\EmptyChannelError;
use LangGraph\Errors\InvalidUpdateError;

/**
 * Stores the last value received; accepts at most one write per step.
 *
 * Port of `LastValue` from `langgraph-core/src/channels/last_value.ts`.
 *
 * This is the channel behind every ordinary state key in a `StateGraph`. The
 * single-value-per-step restriction is the mechanism that *catches conflicting
 * concurrent writes at runtime* rather than letting them silently race: if two
 * branches both write `state.foo` in one superstep, the engine raises
 * `InvalidUpdateError` instead of picking a winner arbitrarily.
 *
 * @template TValue
 * @extends BaseChannel
 */
class LastValue extends BaseChannel
{
    public string $lcGraphName = 'LastValue';

    /**
     * Wrapped in a single-element list so that an update of `null` is
     * distinguishable from no update at all. The JS original makes the same
     * choice, and it is easy to get wrong: without it, clearing a channel by
     * writing null would silently read as "nothing happened".
     *
     * @var array{0: TValue}|array{}
     */
    protected array $value = [];

    /** @var (callable(): mixed)|null */
    protected $initialValueFactory;

    /**
     * @param (callable(): TValue)|null $initialValueFactory
     */
    public function __construct(?callable $initialValueFactory = null)
    {
        $this->initialValueFactory = $initialValueFactory;
        if ($initialValueFactory !== null) {
            $this->value = [$initialValueFactory()];
        }
    }

    public function fromCheckpoint(mixed $checkpoint = null): self
    {
        $empty = new static($this->initialValueFactory ?? null);
        if ($checkpoint !== null) {
            $empty->value = [$checkpoint];
        }

        return $empty;
    }

    /**
     * @param list<mixed> $values
     */
    public function update(array $values): bool
    {
        if ($values === []) {
            return false;
        }
        if (count($values) > 1) {
            throw new InvalidUpdateError(
                'LastValue can only receive one value per step.',
                ['lc_error_code' => 'INVALID_CONCURRENT_GRAPH_UPDATE']
            );
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
