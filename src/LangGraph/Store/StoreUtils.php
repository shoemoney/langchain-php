<?php

declare(strict_types=1);

namespace LangGraph\Store;

/**
 * Path, filter and similarity helpers shared by the stores.
 *
 * Port of `store/utils.ts`. JavaScript semantics that matter to a store are kept
 * deliberately: `Number()` coercion in the numeric filter operators, the
 * `JSON.stringify(value, null, 2)` text used for whole-document embeddings, and
 * `String()` for scalar leaves.
 */
final class StoreUtils
{
    private const FILTER_OPERATORS = ['$eq', '$ne', '$gt', '$gte', '$lt', '$lte', '$in', '$nin'];

    private function __construct()
    {
    }

    /**
     * Tokenize a JSON path into parts.
     *
     * `"metadata.title"` becomes `["metadata", "title"]` and
     * `"chapters[*].content"` becomes `["chapters", "[*]", "content"]`. Bracketed
     * and braced groups are kept whole, nesting included.
     *
     * @return list<string>
     */
    public static function tokenizePath(string $path): array
    {
        if ($path === '') {
            return [];
        }

        $tokens = [];
        $current = '';
        $length = strlen($path);
        $i = 0;

        while ($i < $length) {
            $char = $path[$i];

            if ($char === '[' || $char === '{') {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
                $open = $char;
                $close = $char === '[' ? ']' : '}';
                $depth = 1;
                $group = $open;
                $i++;
                while ($i < $length && $depth > 0) {
                    if ($path[$i] === $open) {
                        $depth++;
                    } elseif ($path[$i] === $close) {
                        $depth--;
                    }
                    $group .= $path[$i];
                    $i++;
                }
                $tokens[] = $group;
                continue;
            }

            if ($char === '.') {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
            } else {
                $current .= $char;
            }
            $i++;
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /**
     * Compare an item's value against a filter value, supporting operators.
     *
     * A filter value whose keys are all operators (`$eq`, `$ne`, `$gt`, `$gte`,
     * `$lt`, `$lte`, `$in`, `$nin`) is evaluated operator by operator; anything
     * else is a direct strict comparison.
     */
    public static function compareValues(mixed $itemValue, mixed $filterValue): bool
    {
        if (self::isFilterOperators($filterValue)) {
            foreach (self::asMap($filterValue) as $op => $value) {
                $op = (string) $op;
                $holds = match ($op) {
                    '$eq' => self::strictEquals($itemValue, $value),
                    '$ne' => !self::strictEquals($itemValue, $value),
                    '$gt' => self::toNumber($itemValue) > self::toNumber($value),
                    '$gte' => self::toNumber($itemValue) >= self::toNumber($value),
                    '$lt' => self::toNumber($itemValue) < self::toNumber($value),
                    '$lte' => self::toNumber($itemValue) <= self::toNumber($value),
                    '$in' => is_array($value) && self::includes($value, $itemValue),
                    '$nin' => !is_array($value) || !self::includes($value, $itemValue),
                    default => false,
                };
                if (!$holds) {
                    return false;
                }
            }

            return true;
        }

        return self::strictEquals($itemValue, $filterValue);
    }

    /**
     * Extract text from a value at a JSON path.
     *
     * Supports simple paths (`"field1.field2"`), array indexing (`"[0]"`,
     * `"[*]"`, `"[-1]"`), wildcards (`"*"`) and multi-field selection
     * (`"{field1,nested.field2}"`). `"$"` or an empty path yields the whole
     * document as indented JSON.
     *
     * @param  list<string>|string $path
     * @return list<string>
     */
    public static function getTextAtPath(mixed $obj, array|string $path): array
    {
        if ($path === '' || $path === '$') {
            return [self::stringify($obj)];
        }
        $tokens = is_array($path) ? $path : self::tokenizePath($path);

        return self::extractFromObj($obj, $tokens, 0);
    }

    /**
     * Cosine similarity between two vectors of equal length.
     *
     * @param list<float|int> $vector1
     * @param list<float|int> $vector2
     *
     * @throws \InvalidArgumentException When the lengths differ.
     */
    public static function cosineSimilarity(array $vector1, array $vector2): float
    {
        if (count($vector1) !== count($vector2)) {
            throw new \InvalidArgumentException('Vectors must have the same length');
        }

        $dot = 0.0;
        $mag1 = 0.0;
        $mag2 = 0.0;
        foreach ($vector1 as $i => $value) {
            $dot += $value * $vector2[$i];
            $mag1 += $value * $value;
            $mag2 += $vector2[$i] * $vector2[$i];
        }
        $mag1 = sqrt($mag1);
        $mag2 = sqrt($mag2);

        if ($mag1 === 0.0 || $mag2 === 0.0) {
            return 0.0;
        }

        return $dot / ($mag1 * $mag2);
    }

    /**
     * `JSON.stringify(value, null, 2)`: two-space indentation, unescaped unicode and slashes.
     */
    public static function stringify(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
            | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        if ($json === false) {
            return 'null';
        }

        // PHP indents with four spaces; JSON.stringify(_, null, 2) uses two. A string
        // value cannot start a line (newlines are escaped), so only indentation matches.
        return (string) preg_replace_callback(
            '/^( +)/m',
            static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)),
            $json,
        );
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private static function extractFromObj(mixed $obj, array $tokens, int $pos): array
    {
        if ($pos >= count($tokens)) {
            if (is_string($obj) || is_int($obj) || is_float($obj) || is_bool($obj)) {
                return [self::scalarToString($obj)];
            }
            if ($obj === null) {
                return [];
            }
            if (is_array($obj) || is_object($obj)) {
                return [self::stringify($obj)];
            }

            return [];
        }

        $token = $tokens[$pos];
        $results = [];
        if ($pos === 0 && $token === '$') {
            $results[] = self::stringify($obj);
        }

        if (str_starts_with($token, '[') && str_ends_with($token, ']')) {
            if (!is_array($obj) || !array_is_list($obj)) {
                return [];
            }

            $index = substr($token, 1, -1);
            if ($index === '*') {
                foreach ($obj as $item) {
                    array_push($results, ...self::extractFromObj($item, $tokens, $pos + 1));
                }
            } elseif (preg_match('/^\s*([+-]?\d+)/', $index, $m) === 1) {
                $idx = (int) $m[1];
                if ($idx < 0) {
                    $idx = count($obj) + $idx;
                }
                if ($idx >= 0 && $idx < count($obj)) {
                    array_push($results, ...self::extractFromObj($obj[$idx], $tokens, $pos + 1));
                }
            }
        } elseif (str_starts_with($token, '{') && str_ends_with($token, '}')) {
            if (!is_array($obj) && !is_object($obj)) {
                return [];
            }

            foreach (explode(',', substr($token, 1, -1)) as $field) {
                $nested = self::tokenizePath(trim($field));
                if ($nested === []) {
                    continue;
                }
                $current = $obj;
                $found = true;
                foreach ($nested as $nestedToken) {
                    $map = is_array($current) || is_object($current) ? self::asMap($current) : null;
                    if ($map !== null && array_key_exists($nestedToken, $map)) {
                        $current = $map[$nestedToken];
                    } else {
                        $found = false;
                        break;
                    }
                }
                if (!$found) {
                    continue;
                }
                if (is_string($current) || is_int($current) || is_float($current) || is_bool($current)) {
                    $results[] = self::scalarToString($current);
                } elseif ($current === null || is_array($current) || is_object($current)) {
                    $results[] = self::stringify($current);
                }
            }
        } elseif ($token === '*') {
            if (is_array($obj) || is_object($obj)) {
                foreach (self::asMap($obj) as $value) {
                    array_push($results, ...self::extractFromObj($value, $tokens, $pos + 1));
                }
            }
        } elseif (is_array($obj) || is_object($obj)) {
            $map = self::asMap($obj);
            if (array_key_exists($token, $map)) {
                array_push($results, ...self::extractFromObj($map[$token], $tokens, $pos + 1));
            }
        }

        return $results;
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function asMap(array|object $value): array
    {
        return is_array($value) ? $value : get_object_vars($value);
    }

    private static function isFilterOperators(mixed $value): bool
    {
        if (!is_array($value) && !is_object($value)) {
            return false;
        }
        foreach (array_keys(self::asMap($value)) as $key) {
            if (!in_array((string) $key, self::FILTER_OPERATORS, true)) {
                return false;
            }
        }

        return true;
    }

    /** `===` as JavaScript means it: 1 and 1.0 are the same number. */
    private static function strictEquals(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        return $a === $b;
    }

    /** @param array<int|string, mixed> $haystack */
    private static function includes(array $haystack, mixed $needle): bool
    {
        foreach ($haystack as $candidate) {
            if (self::strictEquals($candidate, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** JavaScript `Number(value)`; NAN when there is no numeric reading. */
    private static function toNumber(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if ($value === null) {
            return 0.0;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return 0.0;
            }

            return is_numeric($trimmed) ? (float) $trimmed : NAN;
        }

        return NAN;
    }

    /** JavaScript `String(value)` for a scalar. */
    private static function scalarToString(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NaN';
            }
            if (is_infinite($value)) {
                return $value > 0 ? 'Infinity' : '-Infinity';
            }

            return (string) json_encode($value);
        }

        return (string) $value;
    }
}
