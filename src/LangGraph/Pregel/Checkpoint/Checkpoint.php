<?php

declare(strict_types=1);

namespace LangGraph\Pregel\Checkpoint;

/**
 * A snapshot of every channel's state at one superstep boundary.
 *
 * Port of the `Checkpoint` interface from `@langchain/langgraph-checkpoint`.
 *
 * The three maps are the whole persistence model, and each answers a different
 * question:
 *
 *  - `channel_values` — *what* is the state? A materialised snapshot.
 *  - `channel_versions` — *when* did each channel last change? A monotonic
 *    counter per channel. This is what lets a resumed run decide whether a
 *    node has already seen the current value of its triggers.
 *  - `versions_seen` — *what has each node already consumed*? Per node, the
 *    channel versions it observed. Scheduling is then pure arithmetic: a node
 *    runs when some trigger's version is newer than the version it last saw.
 *
 * The separation matters for correctness, not just storage. It is why an
 * unchanged channel does not re-trigger a node, and why re-running from an old
 * checkpoint replays exactly the nodes that were live at that point.
 */
class Checkpoint
{
    /**
     * @param int                          $v               Checkpoint format version.
     * @param string                       $id              Checkpoint id (uuid6).
     * @param string                       $ts              ISO-8601 creation time.
     * @param array<string, mixed>         $channelValues   Materialised channel state.
     * @param array<string, int|string>    $channelVersions Per-channel version counters.
     * @param array<string, array<string, int|string>> $versionsSeen Per-node seen versions.
     */
    public function __construct(
        public int $v = 4,
        public string $id = '',
        public string $ts = '',
        public array $channelValues = [],
        public array $channelVersions = [],
        public array $versionsSeen = [],
    ) {
    }

    /**
     * A shallow-ish copy, safe to mutate.
     *
     * Port of `copyCheckpoint`. `versionsSeen` is deep-copied and the other two
     * maps are copied one level, because `applyWrites` mutates all three in
     * place and two checkpoints must not alias.
     */
    /**
     * The wire shape, mirroring upstream's `toJson()`.
     *
     * `channel_versions` and `versions_seen` are MAPS upstream — object literals in `toJson()` — so
     * they must reach the bytes as `{}`. PHP encodes an empty array as `[]`, and a JavaScript reader
     * handed `[]` where it expects an object cannot read the checkpoint back. A brand-new thread writes
     * both empty, so this is the FIRST thing a checkpoint contains rather than an edge case.
     *
     * This method exists because the `(object)` cast added for exactly this defect lives on the OTHER
     * `Checkpoint` class (`src/LangGraph/Checkpoint/Checkpoint.php`), and the Pregel engine writes
     * THIS one — so the fix was present, correct and documented, and absent from every checkpoint the
     * engine actually writes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => $this->v,
            'id' => $this->id,
            'ts' => $this->ts,
            'channelValues' => $this->channelValues,
            'channelVersions' => (object) $this->channelVersions,
            // Recurse ONE level. `versionsSeen` is typed `array<string, array<string, int|string>>` —
            // every inner value is a map — so every inner value must be an object when empty, exactly
            // like the outer level. Casting only the outer level fixed `versionsSeen: []` and left
            // `versionsSeen: {"fan_out": []}` for any task whose only trigger is a `Send` push, because
            // `json_encode` writes a PHP `[]` as a JSON array and a JavaScript reader cannot read that
            // back as the map it is.
            'versionsSeen' => (object) array_map(
                static fn (array $inner): object => (object) $inner,
                $this->versionsSeen,
            ),
        ];
    }

    public function copy(): self
    {
        $versionsSeen = [];
        foreach ($this->versionsSeen as $node => $seen) {
            $versionsSeen[$node] = is_array($seen) ? $seen : [];
        }

        return new self(
            v: $this->v,
            id: $this->id,
            ts: $this->ts,
            channelValues: $this->channelValues,
            channelVersions: $this->channelVersions,
            versionsSeen: $versionsSeen,
        );
    }

    /**
     * The value of one channel in this checkpoint, or a {@see \LangGraph\Channels\Missing}
     * marker when the channel had no value.
     */
    public function channelValue(string $channel): mixed
    {
        return $this->channelValues[$channel] ?? new \LangGraph\Channels\Missing();
    }
}
