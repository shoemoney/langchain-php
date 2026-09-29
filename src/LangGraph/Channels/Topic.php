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
     * Membership set for `unique`: internal key => the original value.
     *
     * The value is stored alongside the key so a checkpoint can return real
     * values; returning the keys would corrupt the value list on restore.
     *
     * @var array<string, mixed>
     */
    protected array $seen = [];

    public function __construct(bool $unique = false, bool $accumulate = false)
    {
        $this->unique = $unique;
        $this->accumulate = $accumulate;
    }

    public function fromCheckpoint(mixed $checkpoint = null): self
    {
        $empty = new static($this->unique, $this->accumulate);
        if ($checkpoint === null) {
            return $empty;
        }

        // `unique: true` checkpoints are the two-element [seen, values] shape.
        if (
            is_array($checkpoint)
            && count($checkpoint) === 2
            && is_array($checkpoint[0])
            && array_key_exists(1, $checkpoint)
            && is_array($checkpoint[1])
        ) {
            foreach ($checkpoint[0] as $v) {
                $empty->seen[self::key($v)] = $v;
            }
            $empty->values = array_values($checkpoint[1]);

            return $empty;
        }

        if (is_array($checkpoint)) {
            $empty->values = array_values($checkpoint);
            // A flat checkpoint carries no `seen` history, so seed it from the
            // restored buffer; otherwise `unique` could re-deliver on resume.
            if ($empty->unique) {
                foreach ($empty->values as $v) {
                    $empty->seen[self::key($v)] = $v;
                }
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
                $k = self::key($value);
                if (!isset($this->seen[$k])) {
                    $updated = true;
                    $this->seen[$k] = $value;
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
            return [array_values($this->seen), $this->values];
        }

        return $this->values;
    }

    public function isAvailable(): bool
    {
        return $this->values !== [];
    }

    /**
     * A stable identity for `seen` membership.
     *
     * PHP arrays have no reference identity, so scalars are keyed by a
     * type-tagged string and objects by their serialized form. Without the type
     * tag, the integer 1 and the string "1" would collide.
     */
    private static function key(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return get_debug_type($value) . ':' . var_export($value, true);
        }

        return 'complex:' . md5(serialize($value));
    }
}
