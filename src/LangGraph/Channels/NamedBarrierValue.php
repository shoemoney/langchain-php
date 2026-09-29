<?php

declare(strict_types=1);

namespace LangGraph\Channels;

use LangGraph\Errors\EmptyChannelError;
use LangGraph\Errors\InvalidUpdateError;

/**
 * Blocks until every named node has written, then makes the value available.
 *
 * Port of `NamedBarrierValue` from
 * `langgraph-core/src/channels/named_barrier_value.ts`.
 *
 * This is the join primitive. Because Pregel runs every node in a superstep
 * concurrently, a downstream node reading a channel that two upstream nodes
 * both write would otherwise see a torn value. A barrier channel holds the
 * reader until every named participant has reported, so the downstream node
 * sees the fully-merged state instead.
 *
 * The channel's own value is `void` — it carries no payload. It is a signal, not
 * a value.
 */
class NamedBarrierValue extends BaseChannel
{
    public string $lcGraphName = 'NamedBarrierValue';

    /** Node names this barrier waits for. */
    private array $names;

    /** Node names observed so far. */
    private array $seen = [];

    /**
     * @param list<string> $names
     */
    public function __construct(array $names = [])
    {
        $this->names = array_values($names);
    }

    public function fromCheckpoint(mixed $checkpoint = null): self
    {
        $empty = new static($this->names ?? []);
        if (is_array($checkpoint)) {
            $empty->seen = array_values($checkpoint);
        }

        return $empty;
    }

    public function update(array $values): bool
    {
        $updated = false;
        foreach ($values as $nodeName) {
            if (!in_array($nodeName, $this->names, true)) {
                throw new InvalidUpdateError(sprintf(
                    'Value %s not in names %s',
                    json_encode($nodeName),
                    json_encode($this->names)
                ));
            }
            if (!in_array($nodeName, $this->seen, true)) {
                $this->seen[] = $nodeName;
                $updated = true;
            }
        }

        return $updated;
    }

    /**
     * @throws EmptyChannelError while any participant is outstanding
     */
    public function get(): mixed
    {
        if (!$this->barrierSatisfied()) {
            throw new EmptyChannelError();
        }

        return null;
    }

    /** @return list<string> */
    public function checkpoint(): mixed
    {
        if (!$this->barrierSatisfied()) {
            throw new EmptyChannelError();
        }

        return $this->seen;
    }

    public function isAvailable(): bool
    {
        return $this->barrierSatisfied();
    }

    /**
     * Set equality, order-insensitive — the JS original compares sizes and
     * membership rather than sequence, because arrival order is nondeterministic
     * under concurrency.
     */
    private function barrierSatisfied(): bool
    {
        $seen = array_values(array_unique($this->seen));

        return count($seen) === count($this->names)
            && count(array_diff($seen, $this->names)) === 0;
    }
}
