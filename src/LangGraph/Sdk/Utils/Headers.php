<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

/**
 * Port of `iterateHeaders` / `mergeHeaders` from `client/base.ts`.
 *
 * Accepted shapes, mirroring `HeadersInit | Record<string, HeaderValue>`:
 *  - an associative array `name => string|null|list<string>`: a name REPLACES whatever earlier
 *    sources set for it, and a `null` value deletes it;
 *  - a list of `[name, value]` tuples: values APPEND (so repeated names are joined with ", ").
 *
 * JS also distinguishes `undefined` (skip) from `null` (delete). A PHP array cannot hold
 * "undefined", so an omitted key is the undefined case and `null` always means delete.
 * Names are lower-cased because `Headers` does the same.
 */
final class Headers
{
    /**
     * @param array<array-key, mixed> $headers
     *
     * @return \Generator<int, array{0: string, 1: string|null}>
     */
    public static function iterate(array $headers): \Generator
    {
        $isTuples = $headers !== [] && array_is_list($headers) && self::allTuples($headers);

        foreach ($headers as $key => $item) {
            if ($isTuples) {
                $name = $item[0] ?? null;
                $values = is_array($item[1] ?? null) ? $item[1] : [$item[1] ?? null];
            } else {
                $name = $key;
                $values = is_array($item) ? $item : [$item];
            }

            if (!is_string($name)) {
                throw new \TypeError('Expected header name to be a string, got ' . get_debug_type($name));
            }

            $didClear = false;
            foreach ($values as $value) {
                if (!$isTuples && !$didClear) {
                    $didClear = true;
                    yield [$name, null];
                }
                yield [$name, $value === null ? null : (string) $value];
            }
        }
    }

    /**
     * @param array<array-key, mixed>|null ...$headerObjects
     *
     * @return array<string, string>
     */
    public static function merge(?array ...$headerObjects): array
    {
        $out = [];

        foreach ($headerObjects as $headers) {
            if ($headers === null || $headers === []) {
                continue;
            }
            foreach (self::iterate($headers) as [$name, $value]) {
                $name = strtolower($name);
                if ($value === null) {
                    unset($out[$name]);
                } else {
                    $out[$name][] = $value;
                }
            }
        }

        ksort($out);

        return array_map(static fn (array $values): string => implode(', ', $values), $out);
    }

    /** @param array<array-key, mixed> $headers */
    private static function allTuples(array $headers): bool
    {
        foreach ($headers as $item) {
            if (!is_array($item) || !array_key_exists(0, $item)) {
                return false;
            }
        }

        return true;
    }
}
