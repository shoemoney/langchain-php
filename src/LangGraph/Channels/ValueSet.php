<?php

declare(strict_types=1);

namespace LangGraph\Channels;

/**
 * A minimal `Set` with JavaScript SameValueZero membership semantics.
 *
 * The barrier channels and `Topic::seen` hold a `Set<Value>` upstream. PHP has
 * no set type, and a plain associative array keyed by the value is wrong:
 * `$set[0]`, `$set["0"]`, `$set[false]` and `$set[null]` all collapse onto the
 * same key under PHP's key coercion, so `0`, `"0"`, `false` and `null` would
 * become indistinguishable where JavaScript treats them as four distinct
 * members.
 *
 * This class therefore stores members in a list and keys them with
 * {@see self::key()}, which reproduces SameValueZero:
 *
 *  - scalars and arrays compare by value (`serialize()` keeps `int`, `float`,
 *    `string`, `bool` and `null` distinct);
 *  - integral floats are normalised to ints so `1.0` and `1` are the same
 *    member, as they are in JavaScript (`-0.0` likewise folds onto `0`);
 *  - objects (including closures) compare by identity (`spl_object_id()`),
 *    which is what a JavaScript `Set` does for object members.
 *
 * Insertion order is preserved, as it is for a JavaScript `Set`, so
 * `toArray()` feeds `checkpoint()` the same ordering upstream produces.
 */
final class ValueSet
{
    /** @var array<string, mixed> Map of normalized key => member value. */
    private array $members = [];

    /**
     * @param iterable<mixed> $values Initial members, iterated in order.
     */
    public function __construct(iterable $values = [])
    {
        foreach ($values as $value) {
            $this->add($value);
        }
    }

    /**
     * Add a member. Adding an existing member is a no-op, exactly as in JS.
     *
     * @param mixed $value Member to add.
     */
    public function add(mixed $value): void
    {
        $this->members[self::key($value)] = $value;
    }

    /**
     * @param mixed $value Member to test for.
     */
    public function has(mixed $value): bool
    {
        return array_key_exists(self::key($value), $this->members);
    }

    /**
     * @param mixed $value Member to remove.
     *
     * @return bool True when the member was present and removed.
     */
    public function delete(mixed $value): bool
    {
        $key = self::key($value);
        if (!array_key_exists($key, $this->members)) {
            return false;
        }

        unset($this->members[$key]);

        return true;
    }

    public function size(): int
    {
        return count($this->members);
    }

    public function isEmpty(): bool
    {
        return $this->members === [];
    }

    /** Remove every member. */
    public function clear(): void
    {
        $this->members = [];
    }

    /**
     * All members, in insertion order.
     *
     * @return list<mixed>
     */
    public function toArray(): array
    {
        return array_values($this->members);
    }

    /**
     * Normalize a value into a collision-free string key.
     *
     * @param mixed $value Value to key.
     */
    private static function key(mixed $value): string
    {
        if (is_object($value)) {
            return 'o:' . spl_object_id($value);
        }

        if (is_resource($value)) {
            return 'r:' . (int) $value;
        }

        // JavaScript has a single number type: 1 and 1.0 are the same member,
        // and -0 === 0. PHP distinguishes int from float, so BOTH sides have to
        // agree on one key form — an int must use the same `i:` prefix a folded
        // float uses, or `1` and `1.0` silently become two members.
        if (is_int($value)) {
            return 'i:' . $value;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return 'i:' . (int) $value;
        }

        // Non-finite floats (NAN, INF, -INF) keep their own deterministic
        // serialize() form; each is stable across calls.
        return 's:' . serialize($value);
    }
}
