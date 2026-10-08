<?php

declare(strict_types=1);

namespace LangChain\StructuredQuery;

use LangChain\Utils\Js;

/**
 * Value helpers for filters.
 *
 * Port of `@langchain/core/structured_query/utils`. JavaScript's number model
 * is reproduced where the algorithm depends on it: `parseInt` / `parseFloat`
 * prefix parsing and `Number#toString` round-tripping, which is how
 * {@see self::castValue()} decides whether a string "is really" a number.
 */
final class Utils
{
    private function __construct()
    {
    }

    /**
     * A plain record: an associative array or a non-closure object.
     *
     * An empty PHP array cannot be told from an empty JS array, so it reports
     * false here; {@see self::isFilterEmpty()} handles it explicitly.
     */
    public static function isObject(mixed $obj): bool
    {
        if (is_array($obj)) {
            return $obj !== [] && !Js::isList($obj);
        }

        return is_object($obj) && !($obj instanceof \Closure);
    }

    /**
     * Whether a filter (closure, record, string or null) is empty.
     *
     * A PHP array is a JS object here: an empty array is the empty filter `{}`.
     * Any non-empty array is non-empty.
     */
    public static function isFilterEmpty(mixed $filter): bool
    {
        if ($filter === null || $filter === false || $filter === 0 || $filter === 0.0 || $filter === '') {
            return true;
        }
        if (is_string($filter)) {
            return false;
        }
        if ($filter instanceof \Closure) {
            return false;
        }
        if (is_array($filter)) {
            return $filter === [];
        }
        if (is_object($filter)) {
            return get_object_vars($filter) === [];
        }

        return false;
    }

    public static function isInt(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return fmod($value, 1.0) === 0.0;
        }
        if (is_string($value)) {
            $n = self::parseInt($value);
            if ($n === null) {
                return false;
            }

            return fmod((float) $n, 1.0) === 0.0 && self::numberToString($n) === $value;
        }

        return false;
    }

    public static function isFloat(mixed $value): bool
    {
        if (is_int($value)) {
            return false;
        }
        if (is_float($value)) {
            return fmod($value, 1.0) !== 0.0;
        }
        if (is_string($value)) {
            $n = self::parseFloat($value);
            if ($n === null) {
                return false;
            }

            return fmod($n, 1.0) !== 0.0 && self::numberToString($n) === $value;
        }

        return false;
    }

    /**
     * A string that cannot be parsed into a number that prints back identically.
     */
    public static function isString(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $n = self::parseFloat($value);

        return $n === null || self::numberToString($n) !== $value;
    }

    public static function isBoolean(mixed $value): bool
    {
        return is_bool($value);
    }

    /**
     * Cast a string-or-number to a real string or number.
     *
     * An LLM may return an integer or float as a string, and many vector
     * databases cannot compare a number held in a string.
     *
     * @throws \Error "Unsupported value type" for null, arrays, objects.
     */
    public static function castValue(mixed $input): string|int|float|bool
    {
        if (self::isString($input)) {
            /** @var string $input */
            return $input;
        }
        if (self::isInt($input)) {
            $n = is_string($input) ? self::parseInt($input) : $input;

            return is_float($n) && abs($n) < 9.0e18 ? (int) $n : $n;
        }
        if (self::isFloat($input)) {
            return is_string($input) ? (float) self::parseFloat($input) : $input;
        }
        if (self::isBoolean($input)) {
            return (bool) $input;
        }

        throw new \Error('Unsupported value type');
    }

    /**
     * `parseInt(s, 10)`: the leading integer prefix, null for NaN.
     */
    private static function parseInt(string $s): int|float|null
    {
        if (preg_match('/^[\x09\x0A\x0B\x0C\x0D\x20]*([+-]?\d+)/', $s, $m) !== 1) {
            return null;
        }
        $digits = $m[1];
        if (abs((float) $digits) < 9.0e18) {
            $int = (int) $digits;

            return $int === 0 && $digits[0] === '-' ? -0.0 : $int;
        }

        return (float) $digits;
    }

    /**
     * `parseFloat(s)`: the leading decimal prefix, null for NaN.
     */
    private static function parseFloat(string $s): ?float
    {
        $re = '/^[\x09\x0A\x0B\x0C\x0D\x20]*([+-]?(?:Infinity|(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?))/';
        if (preg_match($re, $s, $m) !== 1) {
            return null;
        }
        $token = $m[1];
        if (str_ends_with($token, 'Infinity')) {
            return $token[0] === '-' ? -INF : INF;
        }

        return (float) $token;
    }

    /**
     * ECMAScript `Number#toString` for the shortest round-trip form.
     */
    private static function numberToString(int|float $n): string
    {
        if (is_int($n)) {
            return (string) $n;
        }
        if (is_nan($n)) {
            return 'NaN';
        }
        if (is_infinite($n)) {
            return $n > 0 ? 'Infinity' : '-Infinity';
        }
        if ($n === 0.0) {
            return '0';
        }

        $sign = $n < 0 ? '-' : '';
        $repr = strtolower((string) json_encode(abs($n), JSON_PRESERVE_ZERO_FRACTION));
        $exp = 0;
        if (str_contains($repr, 'e')) {
            [$repr, $e] = explode('e', $repr);
            $exp = (int) $e;
        }
        [$int, $frac] = array_pad(explode('.', $repr), 2, '');
        $digits = ltrim($int . $frac, '0');
        $lead = strlen($int . $frac) - strlen($digits);
        $digits = rtrim($digits, '0');
        // value = 0.DIGITS * 10^point
        $point = strlen($int) - $lead + $exp;
        $k = strlen($digits);

        if ($k <= $point && $point <= 21) {
            $out = $digits . str_repeat('0', $point - $k);
        } elseif (0 < $point && $point <= 21) {
            $out = substr($digits, 0, $point) . '.' . substr($digits, $point);
        } elseif (-6 < $point && $point <= 0) {
            $out = '0.' . str_repeat('0', -$point) . $digits;
        } else {
            $e = $point - 1;
            $es = ($e < 0 ? '-' : '+') . abs($e);
            $out = $k === 1 ? $digits . 'e' . $es : $digits[0] . '.' . substr($digits, 1) . 'e' . $es;
        }

        return $sign . $out;
    }
}
