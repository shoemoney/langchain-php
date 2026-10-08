<?php

declare(strict_types=1);

namespace LangGraph\Channels;

use LangGraph\Errors\EmptyChannelError;

/**
 * A configurable pub/sub topic: many writers, many readers, per-step scope.
 *
 * Port of `Topic` from `langgraph-core/src/channels/topic.ts`.
 *
 * The channel a `Send` lands in. Two switches define its behaviour:
 *
 *  - `accumulate` (default false) — when false the buffer is cleared at the
 *    start of every step, so a subscriber sees only what was published *this*
 *    step. This is what makes a topic a fan-out signal rather than a log.
 *  - `unique` (default false) — de-duplicate against a `seen` set that outlives
 *    the current buffer, so a value cannot be delivered twice across a resume.
 *
 * The checkpoint shape mirrors the Python implementation: a flat value list for
 * the common case, and `[seen, values]` only when `unique` is on, because that
 * is the only case where history the buffer does not hold must survive.
 */
class Topic extends BaseChannel
{
    public string $lcGraphName = 'Topic';

    public bool $unique = false;

    public bool $accumulate = false;

    /** @var list<mixed> Values currently buffered. */
    public array $values = [];

    /**
     * Membership set for `unique`.
     *
     * {@see ValueSet} rather than a plain array: PHP key coercion would collapse
     * `0`, `"0"`, `false` and `null` onto one key, where JavaScript's Set treats
     * them as four distinct members. It also holds the real values, so a
     * checkpoint can return them instead of the internal fingerprints.
     */
    protected ValueSet $seen;

    public function __construct(bool $unique = false, bool $accumulate = false)
    {
        $this->unique = $unique;
        $this->accumulate = $accumulate;
        $this->seen = new ValueSet();
    }

    public function fromCheckpoint(mixed $checkpoint = null): self
    {
        $empty = new static($this->unique, $this->accumulate);
        $empty->seen = new ValueSet();
        if ($checkpoint === null) {
            return $empty;
        }

        // Only `unique: true` checkpoints are the two-element [seen, values]
        // shape; a non-unique list of two array values (e.g. two Sends) is flat.
        // Upstream's isUniqueTopicCheckpoint also reads [seen, values] for non-unique topics to
        // support legacy pre-flat JS checkpoints; this port drops that path on purpose because it
        // has no legacy pre-flat checkpoints.
        if (
            $this->unique
            && is_array($checkpoint)
            && count($checkpoint) === 2
            && is_array($checkpoint[0])
            && array_key_exists(1, $checkpoint)
            && is_array($checkpoint[1])
        ) {
            $empty->seen = new ValueSet($checkpoint[0]);
            $empty->values = array_values($checkpoint[1]);

            return $empty;
        }

        if (is_array($checkpoint)) {
            $empty->values = array_values($checkpoint);
            // A flat checkpoint carries no `seen` history, so seed it from the
            // restored buffer; otherwise `unique` could re-deliver on resume.
            if ($empty->unique) {
                $empty->seen = new ValueSet($empty->values);
            }
        }

        return $empty;
    }

    public function update(array $values): bool
    {
        $updated = false;

        if (!$this->accumulate) {
            $updated = $this->values !== [];
            $this->values = [];
        }

        // Writers may publish a single value or a list of them; flatten one level
        // so both read the same downstream.
        $flat = [];
        foreach ($values as $v) {
            if (is_array($v)) {
                foreach ($v as $inner) {
                    $flat[] = $inner;
                }
            } else {
                $flat[] = $v;
            }
        }

        if ($flat === []) {
            return $updated;
        }

        if ($this->unique) {
            foreach ($flat as $value) {
                if (!$this->seen->has($value)) {
                    $updated = true;
                    $this->seen->add($value);
                    $this->values[] = $value;
                }
            }

            return $updated;
        }

        $updated = true;
        foreach ($flat as $value) {
            $this->values[] = $value;
        }

        return $updated;
    }

    public function get(): mixed
    {
        if ($this->values === []) {
            throw new EmptyChannelError();
        }

        return $this->values;
    }

    /**
     * @return list<mixed>|array{0: list<mixed>, 1: list<mixed>}
     */
    public function checkpoint(): mixed
    {
        if ($this->unique) {
            return [$this->seen->toArray(), $this->values];
        }

        return $this->values;
    }

    public function isAvailable(): bool
    {
        return $this->values !== [];
    }
}
