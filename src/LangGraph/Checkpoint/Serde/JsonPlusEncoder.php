<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Serde;

use LangGraph\Channels\DeltaSnapshot;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;
use LangGraph\Pregel\Send;

/**
 * Values in, JSON out — the write half of the JSON-plus envelope.
 *
 * Port of `_default` and the `stringify` call in `jsonplus.ts`, together with
 * the vendored `fast-safe-stringify` the TypeScript original inlines.
 *
 * ## Why this is not `json_encode`
 *
 * `json_encode` is lossy in exactly the ways a checkpoint cannot tolerate. It
 * cannot represent a byte string that is not valid UTF-8, and it has no way to
 * say "this array was a `Set`" or "this array was a `Map`" — three distinctions a
 * resumed run depends on. So this walks the value first and rewrites the few
 * types that need an envelope, then hands the result to `json_encode`:
 *
 * ```
 * ["a" => new Set([1, 2])]      →  {"a": [1, 2]}                  (PHP array)
 * ["a" => new Error("boom")]    →  {"a": {"lc":2,"type":"constructor","id":["Error"],…}}
 * ["a" => "\xFF\xFE"]           →  {"a": {"lc":2,"type":"constructor","id":["Uint8Array"],…}}
 * ```
 *
 * The `lc: 2` records are the TypeScript runtime's own envelope format, byte for
 * byte, so a checkpoint written here is readable by the JS savers and vice versa.
 *
 * ## Circular references
 *
 * `fast-safe-stringify` walks the graph first and replaces any value that is
 * already on the current path with `"[Circular]"`. PHP arrays are copied by
 * value, so the reference cycles that exist in JavaScript only arise through
 * objects — which is where the cycle detection lives. The replacement is
 * *sticky*: once a cycle is found, that object serialises as `"[Circular]"`
 * everywhere else in the same payload too, matching the upstream output byte for
 * byte.
 */
final class JsonPlusEncoder
{
    /** What a value that closes a reference cycle is written as. */
    public const CIRCULAR_NODE = '[Circular]';

    /** What a value past the depth limit is written as. */
    public const LIMIT_NODE = '[...]';

    /**
     * The deepest nesting encoded before truncation.
     *
     * The upstream default is effectively unbounded, which PHP cannot match for
     * self-referencing *arrays* (only objects can form a cycle, and those are
     * detected exactly). This bound is the backstop for a pathological
     * by-reference array, and is far beyond any real checkpoint.
     */
    public const DEPTH_LIMIT = 512;

    /**
     * Serialise a value to a JSON document.
     *
     * @throws \JsonException When a value survives the walk but still cannot be
     *                        encoded — a broken payload should fail loudly here,
     *                        not be written as `null` and resurrected as a bug on
     *                        the next resume.
     */
    public function encode(mixed $value): string
    {
        $cycles = [];
        $encoded = $this->walk($value, [], $cycles, 0);

        return json_encode(
            $encoded,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * Whether a string is bytes rather than text.
     *
     * The analogue of "is this a `Uint8Array` or a `TextEncoder` string". PHP has
     * one string type, and UTF-8 validity is what tells the two apart without
     * guessing.
     */
    public static function isBinaryString(mixed $value): bool
    {
        return is_string($value) && !mb_check_encoding($value, 'UTF-8');
    }

    /**
     * Recursively rewrite a value into something `json_encode` can represent.
     *
     * `$cycles` is by reference so that an edge which closed a cycle is
     * remembered for every other reference to the same object. The upstream
     * implementation gets that for free by mutating the shared object in place;
     * this walks a copy, so it records the *edge* instead. Recording the object
     * rather than the edge would be wrong: a shared object is usually not itself
     * a cycle, and short-circuiting it would flatten a whole subtree into a
     * marker.
     *
     * @param list<int>                 $path   Object ids currently being encoded.
     * @param array<int, array<string, true>> $cycles  `(object id, property)` slots
     *                                                 already replaced by a marker.
     */
    private function walk(
        mixed $value,
        array $path,
        array &$cycles,
        int $depth,
        ?int $parentId = null,
        ?string $parentKey = null,
    ): mixed
    {
        if ($value instanceof \JsonSerializable) {
            // The upstream replacer sees the result of `toJSON`, not the object.
            $value = $value->jsonSerialize();
        }

        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            // `JSON.stringify` writes these as null; `json_encode` would throw.
            return null;
        }

        if (is_resource($value)) {
            return null;
        }

        if ($value instanceof Checkpoint || $value instanceof PregelCheckpoint) {
            // A checkpoint is a typed record, not a bag of properties: its wire
            // names are the ones the TypeScript runtime reads.
            //
            // BOTH Checkpoint classes are matched. There are two — `LangGraph\Checkpoint\Checkpoint`
            // and `LangGraph\Pregel\Checkpoint\Checkpoint` — and the Pregel engine writes the
            // second one. Matching only the first left the Pregel checkpoint walking as a plain object,
            // which is how an empty `channel_versions` reached the bytes as `[]` while the cast that
            // was supposed to prevent exactly that sat correct and unused one class over.
            $value = $value->toArray();
        } else {
            $replacement = $this->envelopeFor($value);
            if ($replacement !== null) {
                $value = $replacement;
            }
        }

        if (is_array($value)) {
            if ($depth >= self::DEPTH_LIMIT) {
                return self::LIMIT_NODE;
            }
            $out = [];
            foreach ($value as $key => $item) {
                // A PHP array has no identity, so there is no edge to record a
                // closure on; only object properties can be remembered.
                $out[$key] = $this->walk($item, $path, $cycles, $depth + 1);
            }

            return $out;
        }

        if (is_object($value)) {
            if ($depth >= self::DEPTH_LIMIT) {
                return self::LIMIT_NODE;
            }
            $id = spl_object_id($value);
            if (isset($path[$id])) {
                // The value closes a cycle. The *edge* that reached it is what
                // becomes the marker, so every other reference to the same slot
                // serialises the same way.
                if ($parentId !== null && $parentKey !== null) {
                    $cycles[$parentId][$parentKey] = true;
                }

                return self::CIRCULAR_NODE;
            }
            $path[$id] = true;

            $out = [];
            foreach (get_object_vars($value) as $key => $item) {
                if (isset($cycles[$id][(string) $key])) {
                    $out[$key] = self::CIRCULAR_NODE;
                    continue;
                }
                $out[$key] = $this->walk($item, $path, $cycles, $depth + 1, $id, (string) $key);
            }

            // `get_object_vars()` called from here sees only PUBLIC properties.
            // An object whose state is private, and which offers no
            // serialisation contract this encoder can honour, therefore walks to
            // an empty array and is stored as `{}` — the checkpoint resumes with
            // that channel's state GONE, no error at write time, and nothing in
            // the trace to say so.
            //
            // A silent loss here is the worst outcome available: the damage
            // surfaces several graph runs later as an unrelated symptom.
            // Refusing to write is honest — the caller learns their channel value
            // is not serialisable while they can still fix it.
            //
            // `Serializable` is refused too. PHP's own contract would have this
            // encoder call `serialize()`, and nothing here revives it back, so
            // honouring half a contract is exactly the silent loss above.
            if ($out === []) {
                $names = array_map(
                    static fn (\ReflectionProperty $p): string => $p->getName(),
                    (new \ReflectionObject($value))->getProperties(),
                );

                if ($names !== []) {
                    throw new \InvalidArgumentException(sprintf(
                        'Refusing to serialise %s: it has non-public state (%s) and this encoder cannot'
                        . ' revive it, so it would be stored as an empty object and the channel value lost'
                        . ' silently on resume. Store an array, a JsonSerializable value, or a scalar.',
                        $value::class,
                        implode(', ', $names),
                    ));
                }
            }

            // An object with no public properties walked to nothing, and
            // returning the empty ARRAY made `json_encode` write `[]`. But the
            // value was an object: `json_encode((object) [])` is `{}`, and that
            // difference is the whole wire contract for a map.
            //
            // It is not cosmetic. A brand-new thread checkpoints with an empty
            // `channel_versions` and an empty `versions_seen`, so this is the
            // FIRST thing written, not an edge case — and a JS reader given `[]`
            // where it expects an object cannot read the checkpoint back. The
            // cast in `Checkpoint::toArray()` was already correct; the walk
            // here was undoing it one layer down.
            //
            // Non-empty needs no help: a string-keyed PHP array already encodes
            // as an object.
            return $out === [] ? new \stdClass() : $out;
        }

        return $value;
    }

    /**
     * The `lc: 2` envelope for a value that JSON cannot carry, or null if the
     * value needs no envelope.
     *
     * Port of `_default`. A returned array is the replacement; `null` means "keep
     * the value as it is".
     */
    private function envelopeFor(mixed $value): ?array
    {
        if ($value instanceof DeltaSnapshot) {
            // The wrapped value keeps being walked, so nested serializable types
            // inside the snapshot are encoded normally.
            return ['lc' => 2, 'type' => 'delta_snapshot', 'value' => $value->value];
        }

        if ($value instanceof Send) {
            return $this->sendPacket($value->node, $value->args, $value->timeout);
        }

        if ($value instanceof \Throwable) {
            return $this->encodeConstructorArgs('Error', null, [$value->getMessage()]);
        }

        if (self::isBinaryString($value)) {
            // `from` is the legacy tag the JS runtime writes for bytes; the
            // validated byte list is the whole payload.
            return $this->encodeConstructorArgs('Uint8Array', 'from', [array_values(unpack('C*', (string) $value))]);
        }

        if (is_array($value) && ($value['lg_name'] ?? null) === Send::LG_NAME) {
            return $this->sendPacket(
                (string) ($value['node'] ?? ''),
                $value['args'] ?? null,
                $value['timeout'] ?? null,
            );
        }

        return null;
    }

    /**
     * @param list<mixed> $args
     *
     * @return array{lc: int, type: string, id: list<string>, method: string|null, args: list<mixed>, kwargs: array<string, mixed>}
     */
    private function encodeConstructorArgs(string $name, ?string $method, array $args): array
    {
        return [
            'lc' => 2,
            'type' => 'constructor',
            'id' => [$name],
            'method' => $method,
            'args' => $args,
            'kwargs' => [],
        ];
    }

    /**
     * @return array{node: string, args: mixed, timeout?: mixed}
     */
    private function sendPacket(string $node, mixed $args, mixed $timeout): array
    {
        $packet = ['node' => $node, 'args' => $args];
        if ($timeout !== null) {
            $packet['timeout'] = $timeout;
        }

        return $packet;
    }
}
