<?php

declare(strict_types=1);

namespace LangGraph\Store\Redis;

use LangGraph\Checkpoint\Redis\RedisUtils;

/**
 * Evaluates MongoDB-style filters against stored documents.
 *
 * Port of `FilterBuilder` in `@langchain/langgraph-checkpoint-redis`'s `store.ts`. RediSearch
 * cannot express these operators over a JSON `value`, so the store fetches candidates and
 * filters them here: `$eq $ne $gt $gte $lt $lte $in $nin $exists`, dotted paths for nested
 * values, and plain equality, which also matches a scalar against an array that contains it.
 *
 * JavaScript semantics are kept where they decide a result: `$gt` and friends coerce both
 * sides with `Number()`, equality of a number and a numeric string is loose, and a missing
 * path is "undefined", distinct from an explicit `null`.
 */
final class FilterBuilder
{
    /** The tokenizer's separators, which are also the characters that could close a TEXT clause. */
    private const TEXT_SEPARATORS = '/[\s,.<>{}\[\]"\':;!@#$%^&*()\-+=~|]+/';

    private static ?\stdClass $undefined = null;

    private function __construct()
    {
    }

    /**
     * Whether a document satisfies every entry of the filter.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $filter
     */
    public static function matchesFilter(array $doc, array $filter): bool
    {
        foreach ($filter as $key => $filterValue) {
            if (!self::matchesFieldFilter($doc, (string) $key, $filterValue)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A RediSearch query for the namespace prefix, and whether the filter needs client-side evaluation.
     *
     * Note, as upstream: this is limited by what RediSearch can express, so any operator object
     * sets `useClientFilter`.
     *
     * @param  array<string, mixed>                          $filter
     * @return array{query: string, useClientFilter: bool}
     */
    public static function buildRedisSearchQuery(array $filter, ?string $prefix = null): array
    {
        $queryParts = [];
        $useClientFilter = false;

        if ($prefix !== null && $prefix !== '') {
            $tokens = self::prefixTokens($prefix);
            if ($tokens !== []) {
                $queryParts[] = '@prefix:(' . implode(' ', $tokens) . ')';
            }
        }

        foreach ($filter as $value) {
            if (self::isOperatorObject($value)) {
                $useClientFilter = true;

                break;
            }
        }

        if ($queryParts === []) {
            $queryParts[] = '*';
        }

        return ['query' => implode(' ', $queryParts), 'useClientFilter' => $useClientFilter];
    }

    /**
     * The tokens of a dotted namespace prefix, safe to splice into a TEXT clause.
     *
     * Upstream splits on `.` and `-` only and interpolates the rest, so a label holding `)` or `@`
     * rewrites the query. Splitting on every character the tokenizer treats as a separator
     * (a superset of `.` and `-`) leaves nothing that is syntax; the few characters that remain
     * (`\`, `?`, `/`) go through {@see RedisUtils::escapeRediSearchTagValue()}.
     *
     * @return list<string>
     */
    public static function prefixTokens(string $prefix): array
    {
        $tokens = [];
        foreach (preg_split(self::TEXT_SEPARATORS, $prefix) ?: [] as $token) {
            if ($token !== '') {
                $tokens[] = RedisUtils::escapeRediSearchTagValue($token);
            }
        }

        return $tokens;
    }

    /** @param array<string, mixed> $doc */
    private static function matchesFieldFilter(array $doc, string $key, mixed $filterValue): bool
    {
        $actualValue = self::getNestedValue($doc, $key);

        if (self::isOperatorObject($filterValue)) {
            /** @var array<string, mixed> $filterValue */
            return self::matchesOperators($actualValue, $filterValue);
        }

        return self::isEqual($actualValue, $filterValue);
    }

    /** @param array<string, mixed> $operators */
    private static function matchesOperators(mixed $actualValue, array $operators): bool
    {
        foreach ($operators as $operator => $operatorValue) {
            if (!self::matchesOperator($actualValue, (string) $operator, $operatorValue)) {
                return false;
            }
        }

        return true;
    }

    private static function matchesOperator(mixed $actualValue, string $operator, mixed $operatorValue): bool
    {
        $present = $actualValue !== null && $actualValue !== self::undefined();

        switch ($operator) {
            case '$eq':
                return self::isEqual($actualValue, $operatorValue);
            case '$ne':
                return !self::isEqual($actualValue, $operatorValue);
            case '$gt':
                return $present && self::toNumber($actualValue) > self::toNumber($operatorValue);
            case '$gte':
                return $present && self::toNumber($actualValue) >= self::toNumber($operatorValue);
            case '$lt':
                return $present && self::toNumber($actualValue) < self::toNumber($operatorValue);
            case '$lte':
                return $present && self::toNumber($actualValue) <= self::toNumber($operatorValue);
            case '$in':
                if (!is_array($operatorValue) || !array_is_list($operatorValue)) {
                    return false;
                }
                foreach ($operatorValue as $candidate) {
                    if (self::isEqual($actualValue, $candidate)) {
                        return true;
                    }
                }

                return false;
            case '$nin':
                if (!is_array($operatorValue) || !array_is_list($operatorValue)) {
                    return false;
                }
                foreach ($operatorValue as $candidate) {
                    if (self::isEqual($actualValue, $candidate)) {
                        return false;
                    }
                }

                return true;
            case '$exists':
                $exists = $actualValue !== self::undefined();

                return self::isTruthy($operatorValue) ? $exists : !$exists;
            default:
                // An unknown operator matches nothing, for safety.
                return false;
        }
    }

    private static function isEqual(mixed $a, mixed $b): bool
    {
        if (self::strictlyEqual($a, $b)) {
            return true;
        }
        if ($a === null || $b === null || $a === self::undefined() || $b === self::undefined()) {
            return false;
        }

        $aIsList = is_array($a) && array_is_list($a);
        $bIsList = is_array($b) && array_is_list($b);
        if ($aIsList && $bIsList) {
            /** @var list<mixed> $a */
            /** @var list<mixed> $b */
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $i => $value) {
                if (!self::isEqual($value, $b[$i])) {
                    return false;
                }
            }

            return true;
        }
        if ($aIsList || $bIsList) {
            // A scalar matches an array that contains it (SameValueZero, like `Array.includes`).
            $array = $aIsList ? $a : $b;
            $value = $aIsList ? $b : $a;
            if (is_array($value)) {
                return false;
            }
            /** @var list<mixed> $array */
            foreach ($array as $element) {
                if (self::strictlyEqual($element, $value)) {
                    return true;
                }
            }

            return false;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!self::isEqual($value, array_key_exists($key, $b) ? $b[$key] : self::undefined())) {
                    return false;
                }
            }

            return true;
        }

        return self::looselyEqual($a, $b);
    }

    /** JavaScript `===` for decoded JSON: numbers compare by value whatever their PHP type. */
    private static function strictlyEqual(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        return $a === $b;
    }

    /** JavaScript `==` between two primitives of different types. */
    private static function looselyEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b) || is_object($a) || is_object($b)) {
            return false;
        }
        if (is_bool($a)) {
            return self::looselyEqual((int) $a, $b);
        }
        if (is_bool($b)) {
            return self::looselyEqual($a, (int) $b);
        }
        if (is_string($a) && is_string($b)) {
            return $a === $b;
        }

        return self::toNumber($a) === self::toNumber($b);
    }

    /** JavaScript `Number(value)`; NAN where JavaScript yields NaN. */
    private static function toNumber(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return 0.0;
            }

            return is_numeric($trimmed) ? (float) $trimmed : NAN;
        }
        if (is_array($value)) {
            if ($value === []) {
                return 0.0;
            }

            return count($value) === 1 && array_is_list($value) ? self::toNumber($value[0]) : NAN;
        }

        return NAN;
    }

    /** JavaScript truthiness. */
    private static function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0) {
            return false;
        }

        return !is_float($value) || !is_nan($value);
    }

    /** Whether a filter value is `{ $op: ... }` rather than a literal to compare against. */
    private static function isOperatorObject(mixed $value): bool
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            return false;
        }
        foreach (array_keys($value) as $key) {
            if (str_starts_with((string) $key, '$')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value at a dotted path, or the "undefined" marker when any step is missing.
     *
     * @param array<string, mixed> $obj
     */
    private static function getNestedValue(array $obj, string $path): mixed
    {
        $current = $obj;
        foreach (explode('.', $path) as $key) {
            if ($current === null || $current === self::undefined()) {
                return self::undefined();
            }
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return self::undefined();
            }
            $current = $current[$key];
        }

        return $current;
    }

    private static function undefined(): \stdClass
    {
        return self::$undefined ??= new \stdClass();
    }
}
