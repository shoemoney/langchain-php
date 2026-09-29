<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Utils\Js;

/**
 * RFC 6902 diffing, the operation vocabulary used by streaming parsers.
 *
 * Port of `compare` / `_areEquals` from `@langchain/core/utils/json_patch`,
 * which in turn inlines `fast-json-patch`.
 *
 * `compare($prev, $next)` produces the minimal set of operations that turn one
 * decoded JSON value into another. Two rules from the original matter for the
 * parser tests and are preserved here: existing keys are walked in *reverse*
 * (so removals and replacements are emitted before additions) and equal-length
 * objects stop after the replace pass, because without a removal there can be
 * nothing else to add.
 */
final class JsonPatch
{
    private function __construct()
    {
    }

    /**
     * Structural equality, with types compared strictly.
     *
     * This is the guard that stops a streaming parser from re-emitting a value
     * it already emitted: `1` and `"1"`, and `{"a":1}` and `{"a":"1"}`, are
     * different documents.
     */
    public static function deepEquals(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if (is_array($a) && is_array($b)) {
            $aIsList = Js::isList($a);
            $bIsList = Js::isList($b);

            if ($aIsList !== $bIsList) {
                return false;
            }

            if (count($a) !== count($b)) {
                return false;
            }

            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !self::deepEquals($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        // Scalars, and mismatched kinds, are compared by `===` above.
        return false;
    }

    /**
     * JavaScript truthiness, which differs from PHP's in one load-bearing way:
     * an empty array *and* an empty object are both truthy in JS, so `[]` is not
     * a stand-in for `undefined` and must not be treated as "no value yet".
     */
    public static function isFalsy(mixed $value): bool
    {
        return match (true) {
            $value === null, $value === false => true,
            is_array($value) => false,
            is_string($value) => $value === '',
            default => $value === 0 || $value === 0.0,
        };
    }

    /**
     * The operations that turn `$prev` into `$next`.
     *
     * @return list<array<string, mixed>>
     */
    public static function compare(mixed $prev, mixed $next): array
    {
        $patches = [];
        self::generate($prev, $next, $patches, '');

        return $patches;
    }

    /**
     * Escape one JSON Pointer path segment (RFC 6901 §3).
     */
    public static function escapePathComponent(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $key);
    }

    /**
     * @param list<array<string, mixed>> $patches
     */
    private static function generate(mixed $mirror, mixed $obj, array &$patches, string $path): void
    {
        if ($mirror === $obj) {
            return;
        }

        $mirrorIsList = is_array($mirror) && Js::isList($mirror);
        $objIsList = is_array($obj) && Js::isList($obj);

        /** @var array<array-key, mixed> $mirrorMap */
        $mirrorMap = is_array($mirror) ? $mirror : [];
        /** @var array<array-key, mixed> $objMap */
        $objMap = is_array($obj) ? $obj : [];

        $newKeys = array_keys($objMap);
        $oldKeys = array_keys($mirrorMap);
        $changed = false;
        $deleted = false;

        for ($t = count($oldKeys) - 1; $t >= 0; $t--) {
            $key = $oldKeys[$t];
            $oldVal = $mirrorMap[$key];

            if (array_key_exists($key, $objMap) && $objMap[$key] !== null) {
                $newVal = $objMap[$key];

                // An empty PHP array is still an array (and an empty JS object is
                // still an object), so the recursion happens for both — that is
                // what turns an empty array gaining an element into a patch on the
                // element rather than on the array.
                if (
                    is_array($oldVal)
                    && is_array($newVal)
                    && Js::isList($oldVal) === Js::isList($newVal)
                ) {
                    self::generate($oldVal, $newVal, $patches, $path . '/' . self::escapePathComponent((string) $key));
                } elseif ($oldVal !== $newVal) {
                    $changed = true;
                    $patches[] = [
                        'op' => 'replace',
                        'path' => $path . '/' . self::escapePathComponent((string) $key),
                        'value' => $newVal,
                    ];
                }
            } elseif ($mirrorIsList === $objIsList) {
                $patches[] = ['op' => 'remove', 'path' => $path . '/' . self::escapePathComponent((string) $key)];
                $deleted = true;
            } else {
                $patches[] = ['op' => 'replace', 'path' => $path, 'value' => $obj];
                $changed = true;
            }
        }

        if (!$deleted && count($newKeys) === count($oldKeys)) {
            return;
        }

        foreach ($newKeys as $key) {
            if (!array_key_exists($key, $mirrorMap) && $objMap[$key] !== null) {
                $patches[] = [
                    'op' => 'add',
                    'path' => $path . '/' . self::escapePathComponent((string) $key),
                    'value' => $objMap[$key],
                ];
            }
        }
    }
}
