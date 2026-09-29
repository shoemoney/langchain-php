<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Serde;

use LangGraph\Channels\DeltaSnapshot;

/**
 * JSON in, values out — the read half of the JSON-plus envelope.
 *
 * Port of `_reviver` and `reviveConstructorRecord` from `jsonplus.ts`.
 *
 * ## Reviving is bottom-up
 *
 * The upstream code cannot walk top-down. A `toJSON()` on a value replaces that
 * value outright, and the replacer the encoder uses does not delegate to it — so
 * by the time the encoder sees a parent object, its children are already plain
 * JSON. The decoder therefore starts at the *innermost* values and rebuilds
 * outward, so an envelope nested inside an envelope is already a live object by
 * the time its parent is examined.
 *
 * ## A record is data, not code
 *
 * Every `lc: 2` record is checked against a closed set of shapes before anything
 * is constructed from it. A record that does not match — old, malformed, or
 * written by something hostile — is returned *as the record itself*. It stays
 * inert. That is the upstream rule and it is the important one: a checkpoint is
 * attacker-reachable input on any system that resumes a user-supplied thread id,
 * so a constructor record must never be a request to build something.
 */
final class JsonPlusDecoder
{
    /**
     * Revive a decoded JSON value.
     *
     * @throws \JsonException When the payload is not valid JSON.
     */
    public function decode(string $json): mixed
    {
        return $this->revive(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    /** Revive an already-decoded value. */
    public function revive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = $this->revive($item);
            }

            return $out;
        }

        $revived = [];
        foreach ($value as $key => $item) {
            $revived[$key] = $this->revive($item);
        }

        if (self::isUndefinedRecord($revived)) {
            return null;
        }

        if (self::isDeltaSnapshotRecord($revived)) {
            // The wrapped value has already been revived by the walk above.
            return new DeltaSnapshot($revived['value']);
        }

        if (self::isConstructorRecord($revived)) {
            return $this->reviveConstructorRecord($revived) ?? $revived;
        }

        if (LcConstructorLoader::isSerializedConstructor($revived)) {
            return LcConstructorLoader::load($revived);
        }

        return $revived;
    }

    /**
     * @param array<string, mixed> $value
     *
     * @phpstan-assert-if-true array{lc: int, type: string, id: mixed, method?: mixed, args?: mixed} $value
     */
    private static function isConstructorRecord(array $value): bool
    {
        return ($value['lc'] ?? null) === 2 && ($value['type'] ?? null) === 'constructor';
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function isUndefinedRecord(array $value): bool
    {
        return ($value['lc'] ?? null) === 2 && ($value['type'] ?? null) === 'undefined';
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function isDeltaSnapshotRecord(array $value): bool
    {
        return ($value['lc'] ?? null) === 2
            && ($value['type'] ?? null) === 'delta_snapshot'
            && array_key_exists('value', $value);
    }

    /**
     * Build the value a constructor record describes, or null to leave it inert.
     *
     * Each branch validates *everything* it is about to use. A `Map` whose
     * entries are not all pairs is not a `Map`; a `Uint8Array` whose bytes are
     * not integers in range is not bytes. In every such case the record is
     * returned untouched, which is what keeps a hostile payload from being
     * partially honoured.
     *
     * @param array<string, mixed> $record
     */
    private function reviveConstructorRecord(array $record): mixed
    {
        $id = $record['id'] ?? null;
        if (!is_array($id) || count($id) !== 1) {
            return null;
        }
        $name = (string) $id[0];
        $method = $record['method'] ?? null;
        $args = $record['args'] ?? null;
        $hasNoMethod = $method === null;

        if ($name === 'Set' && $hasNoMethod) {
            $entries = $this->singleArrayArg($args);

            return $entries ?? $record;
        }

        if ($name === 'Map' && $hasNoMethod) {
            $entries = $this->singleArrayArg($args);
            if ($entries === null) {
                return null;
            }
            $map = [];
            foreach ($entries as $entry) {
                if (!is_array($entry) || !array_is_list($entry) || count($entry) !== 2) {
                    return null;
                }
                $map[self::mapKey($entry[0])] = $entry[1];
            }

            return $map;
        }

        if ($name === 'Error' && $hasNoMethod) {
            if (!is_array($args) || count($args) !== 1 || !is_string($args[0])) {
                return null;
            }

            // The PHP analogue of `new Error(message)`. Only the message is
            // persisted, so only the message comes back.
            return new \RuntimeException($args[0]);
        }

        if ($name === 'Uint8Array' && ($hasNoMethod || $method === 'from')) {
            $bytes = $this->singleArrayArg($args);
            if ($bytes === null) {
                return null;
            }
            $packed = '';
            foreach ($bytes as $byte) {
                if (!is_int($byte) || $byte < 0 || $byte > 255) {
                    return null;
                }
                $packed .= chr($byte);
            }

            return $packed;
        }

        // `RegExp` and anything else: the record stays inert. A JS regular
        // expression is not a PCRE pattern, and translating one by hand would
        // produce a matcher that silently disagrees with the one that wrote it.
        return null;
    }

    /**
     * The single list-of-values argument a `Set`/`Map`/`Uint8Array` record must
     * carry, or null when the shape is wrong.
     *
     * @return list<mixed>|null
     */
    private function singleArrayArg(mixed $args): ?array
    {
        if (!is_array($args) || !array_is_list($args) || count($args) !== 1) {
            return null;
        }
        if (!is_array($args[0]) || !array_is_list($args[0])) {
            return null;
        }

        return $args[0];
    }

    /**
     * The array key for a `Map` entry.
     *
     * A PHP array can only key on int or string, so a boolean or null key is
     * folded the way PHP itself folds array literals. The value is stored under
     * that key; the original type is not recoverable, which is the same
     * information loss PHP's own arrays have.
     */
    private static function mapKey(mixed $key): int|string
    {
        return match (true) {
            is_int($key), is_string($key) => $key,
            is_bool($key) => (int) $key,
            $key === null => '',
            default => json_encode($key, JSON_THROW_ON_ERROR),
        };
    }
}
