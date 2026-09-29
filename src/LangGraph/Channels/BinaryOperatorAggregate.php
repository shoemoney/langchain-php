<?php

declare(strict_types=1);

namespace LangGraph\Channels;

use LangGraph\Errors\EmptyChannelError;
use LangGraph\Errors\InvalidUpdateError;

/**
 * Folds a binary operator over the current value and each incoming update.
 *
 * Port of `BinaryOperatorAggregate` from `langgraph-core/src/channels/binop.ts`.
 *
 * This is the channel behind every accumulating state key — `add_messages`,
 * `operator.add`, a counter. Where {@see LastValue} rejects concurrent writes,
 * this one *merges* them, which is why it is the correct default for any state
 * that parallel branches both contribute to.
 *
 * The reduction order is the order values arrive in, which Pregel guarantees is
 * the order nodes were scheduled. That is why the operator must be associative
 * and commutative in practice, and why the upstream comment calls this out.
 *
 * @template TValue
 * @extends BaseChannel
 */
class BinaryOperatorAggregate extends BaseChannel
{
    public string $lcGraphName = 'BinaryOperatorAggregate';

    protected mixed $value = null;

    protected bool $hasValue = false;

    /** @var callable(mixed, mixed): mixed */
    protected $operator;

    /** @var (callable(): mixed)|null */
    protected $initialValueFactory;

    /**
     * @param callable(mixed, mixed): mixed $operator
     * @param (callable(): mixed)|null       $initialValueFactory
     */
    public function __construct(callable $operator, ?callable $initialValueFactory = null)
    {
        $this->operator = $operator;
        $this->initialValueFactory = $initialValueFactory;
        if ($initialValueFactory !== null) {
            $this->value = $initialValueFactory();
            $this->hasValue = true;
        }
    }

    public function fromCheckpoint(mixed $checkpoint = null): self
    {
        $empty = new static($this->operator, $this->initialValueFactory ?? null);
        if ($checkpoint !== null || ($this->initialValueFactory ?? null) === null) {
            if ($checkpoint !== null) {
                $empty->value = $checkpoint;
                $empty->hasValue = true;
            }
        }

        return $empty;
    }

    public function update(array $values): bool
    {
        if ($values === []) {
            return false;
        }

        $remaining = $values;

        if (!$this->hasValue) {
            $first = $remaining[0];
            if (Overwrite::isOverwriteValue($first)) {
                [, $overwritten] = Overwrite::getValueOf($first);
                $this->value = $overwritten;
            } else {
                $this->value = $first;
            }
            $this->hasValue = true;
            $remaining = array_slice($remaining, 1);
        }

        $seenOverwrite = false;
        foreach ($remaining as $incoming) {
            if (Overwrite::isOverwriteValue($incoming)) {
                if ($seenOverwrite) {
                    throw new InvalidUpdateError('Can receive only one Overwrite value per step.');
                }
                [, $overwritten] = Overwrite::getValueOf($incoming);
                $this->value = $overwritten;
                $seenOverwrite = true;
                continue;
            }

            if (!$seenOverwrite && $this->hasValue) {
                $this->value = ($this->operator)($this->value, $incoming);
            }
        }

        return true;
    }

    public function get(): mixed
    {
        if (!$this->hasValue) {
            throw new EmptyChannelError();
        }

        return $this->value;
    }

    public function checkpoint(): mixed
    {
        if (!$this->hasValue) {
            throw new EmptyChannelError();
        }

        return $this->value;
    }

    public function isAvailable(): bool
    {
        return $this->hasValue;
    }

    /**
     * Two aggregates are equal when they fold with the same operator.
     *
     * This mirrors the Python implementation, which compares operator
     * references — two graphs declaring `operator.add` are interchangeable even
     * though the closures are distinct objects.
     */
    public function equals(BaseChannel $other): bool
    {
        if ($this === $other) {
            return true;
        }
        if (!$other instanceof self) {
            return false;
        }

        return $this->operator === $other->operator;
    }
}
