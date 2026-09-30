<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint;

use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;

/**
 * A snapshot of every channel's state at one superstep boundary.
 *
 * Port of the `Checkpoint` interface from `@langchain/langgraph-checkpoint`.
 *
 * This type extends the engine's own checkpoint so a value written by this
 * library and one written by the engine are the same type: the saver contract in
 * {@see BaseCheckpointSaver} speaks in the engine's class, and a checkpointer
 * that persisted some *other* checkpoint shape would not satisfy it.
 *
 * What this layer adds is the on-disk representation. A checkpoint crosses a
 * process boundary, so it needs a field-for-field mapping to and from the
 * `snake_case` record the TypeScript runtime writes — {@see self::toArray()} and
 * {@see self::fromArray()}. Those names are the wire format; the PHP property
 * names are not, and the two are deliberately kept apart so a property rename
 * could never silently invalidate stored checkpoints.
 */
class Checkpoint extends PregelCheckpoint
{
    /**
     * The wire representation: the exact record the TypeScript saver persists.
     *
     * @return array{
     *     v: int,
     *     id: string,
     *     ts: string,
     *     channel_values: array<string, mixed>,
     *     channel_versions: array<string, int|string>,
     *     versions_seen: array<string, array<string, int|string>>
     * }
     */
    public function toArray(): array
    {
        return [
            'v' => $this->v,
            'id' => $this->id,
            'ts' => $this->ts,
            'channel_values' => $this->channelValues,
            // These two, and the inner maps of `versions_seen`, are ALWAYS
            // maps — every consumer reads them as `[$name] => ...`, never as a
            // positional list. PHP has one array type, so a fresh checkpoint
            // wrote `channel_versions: []` where the JavaScript side reads a
            // map and finds an array, and a cross-runtime resume then compares
            // the wrong shape.
            //
            // Forcing `{}` is safe HERE in a way it is not for
            // `channel_values`, which genuinely can hold a list channel and
            // where `{}` would corrupt a legitimately empty one. That is why
            // `channel_values` is deliberately left alone above.
            'channel_versions' => (object) $this->channelVersions,
            'versions_seen' => (object) array_map(
                static fn (mixed $inner): object => (object) (is_array($inner) ? $inner : []),
                $this->versionsSeen,
            ),
        ];
    }

    /**
     * Rebuild a checkpoint from its wire representation.
     *
     * Missing channel maps default to empty, which is what makes a checkpoint
     * written by an older format (or a hand-written fixture) loadable rather than
     * fatal.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // A record that is PRESENT but malformed is not an empty checkpoint.
        // Defaulting it to one resumed the graph from blank state with no error
        // at load time — the read-side twin of refusing an unserialisable write.
        // A genuinely absent record (`$data === []`) is left alone: that is a
        // saver asking about a thread with no checkpoints, and it still gets an
        // empty checkpoint, exactly as upstream's `emptyCheckpoint()` does.
        self::rejectCorrupt($data);

        $v = $data['v'] ?? CheckpointConstants::CHECKPOINT_VERSION;
        if (is_float($v) || (is_int($v) && $v > CheckpointConstants::CHECKPOINT_VERSION)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported checkpoint version %s; this build reads up to version %d.',
                var_export($v, true),
                CheckpointConstants::CHECKPOINT_VERSION,
            ));
        }

        return new self(
            v: (int) $v,
            id: (string) ($data['id'] ?? ''),
            ts: (string) ($data['ts'] ?? ''),
            channelValues: self::mapOf($data['channel_values'] ?? []),
            channelVersions: self::versionsOf($data['channel_versions'] ?? []),
            versionsSeen: self::versionsSeenOf($data['versions_seen'] ?? []),
        );
    }

    /**
     * Refuse a record whose id or channel maps are the wrong type.
     *
     * Only fires when the key is PRESENT. A missing key is tolerated so an old
     * format, or a hand-written fixture, still loads; a key holding a string
     * where a map belongs is corruption, and loading it as an empty map is how
     * a resume silently begins from nothing.
     *
     * @param array<string, mixed> $data
     */
    private static function rejectCorrupt(array $data): void
    {
        if (array_key_exists('id', $data) && !is_string($data['id'])) {
            throw new \InvalidArgumentException(sprintf(
                'Checkpoint id must be a string, got %s.',
                get_debug_type($data['id']),
            ));
        }

        foreach (['channel_values', 'channel_versions', 'versions_seen'] as $key) {
            if (array_key_exists($key, $data) && !is_array($data[$key])) {
                throw new \InvalidArgumentException(sprintf(
                    'Checkpoint %s must be a map, got %s — loading it as empty would resume the graph'
                    . ' from blank state.',
                    $key,
                    get_debug_type($data[$key]),
                ));
            }
        }
    }

    /** A copy of the same concrete class, so a subclass survives `copy()`. */
    public function copy(): static
    {
        return new static(
            v: $this->v,
            id: $this->id,
            ts: $this->ts,
            channelValues: $this->channelValues,
            channelVersions: $this->channelVersions,
            versionsSeen: self::deepCopy($this->versionsSeen),
        );
    }

    /** A fresh checkpoint, as a brand-new run starts from. Port of `emptyCheckpoint`. */
    public static function empty(): self
    {
        return new self(
            v: CheckpointConstants::CHECKPOINT_VERSION,
            id: CheckpointId::uuid6(0),
            ts: gmdate('Y-m-d\TH:i:s.v\Z'),
            channelValues: [],
            channelVersions: [],
            versionsSeen: [],
        );
    }

    /**
     * A recursive copy of an array structure.
     *
     * Port of `deepCopy`. Two checkpoints must never alias: `applyWrites` mutates
     * `versionsSeen` in place, so sharing a nested array between a stored
     * checkpoint and a working copy would rewrite history.
     *
     * @template T
     *
     * @param  T $value
     * @return T
     */
    public static function deepCopy(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $copy = [];
        foreach ($value as $key => $item) {
            $copy[$key] = self::deepCopy($item);
        }

        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, int|string>
     */
    private static function versionsOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $versions = [];
        foreach ($value as $channel => $version) {
            $versions[(string) $channel] = is_int($version) ? $version : (string) $version;
        }

        return $versions;
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    private static function versionsSeenOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $seen = [];
        foreach ($value as $node => $channels) {
            $seen[(string) $node] = self::versionsOf($channels);
        }

        return $seen;
    }
}
