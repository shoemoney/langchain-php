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
            'channel_versions' => $this->channelVersions,
            'versions_seen' => $this->versionsSeen,
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
        return new self(
            v: (int) ($data['v'] ?? CheckpointConstants::CHECKPOINT_VERSION),
            id: (string) ($data['id'] ?? ''),
            ts: (string) ($data['ts'] ?? ''),
            channelValues: self::mapOf($data['channel_values'] ?? []),
            channelVersions: self::versionsOf($data['channel_versions'] ?? []),
            versionsSeen: self::versionsSeenOf($data['versions_seen'] ?? []),
        );
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
