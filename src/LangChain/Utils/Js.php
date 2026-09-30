<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * Type discrimination with JavaScript's taxonomy.
 *
 * The TypeScript source is peppered with `typeof x === "object"` checks that
 * mean "a plain record, not an array". PHP's `is_array()` cannot tell a JSON
 * object from a JSON array, so these helpers restore the distinction the ported
 * algorithms actually depend on — notably {@see self::mergeDicts()}, which
 * branches on "object vs array" at every level.
 */
final class Js
{
    private function __construct()
    {
    }

    /**
     * A JS value type name: null, boolean, number, string, array, object.
     */
    public static function typeOf(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value) || is_float($value)) {
            return 'number';
        }
        if (is_string($value)) {
            return 'string';
        }
        if (is_array($value)) {
            return self::isList($value) ? 'array' : 'object';
        }

        return 'object';
    }

    /**
     * True when the array has sequential integer keys from zero, which is how
     * PHP distinguishes a JSON array from a JSON object.
     *
     * @param array<mixed> $value
     */
    public static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * A JS "plain object" — a non-list array, which is how PHP models one.
     *
     * @return array<string, mixed>
     */
    public static function asObject(mixed $value): ?array
    {
        if (!is_array($value) || self::isList($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * `JSON.stringify`, with PHP's failure mode made to match JavaScript's.
     *
     * JavaScript's `JSON.stringify` throws a `TypeError` on a circular
     * structure and on a BigInt; PHP's `json_encode` returns `false`, and
     * `JSON_PARTIAL_OUTPUT_ON_ERROR` quietly substitutes `null`. Both mean the
     * provider receives something other than what the caller passed, which is
     * the one outcome nobody can debug from the far end.
     *
     * @throws \InvalidArgumentException when the value cannot be represented.
     */
    public static function encode(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(
                'Value could not be encoded as JSON for the provider: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }
}
