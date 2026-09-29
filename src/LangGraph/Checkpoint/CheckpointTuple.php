<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint;

use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;
use LangGraph\Pregel\Checkpoint\CheckpointTuple as PregelCheckpointTuple;

/**
 * A saved checkpoint together with the config that locates it.
 *
 * Port of `CheckpointTuple` from `@langchain/langgraph-checkpoint`.
 *
 * A tuple is the unit a saver hands back, and it carries four things that are
 * only meaningful together: the checkpoint, the metadata explaining where it came
 * from, the config of the checkpoint *before* it, and the writes that were
 * recorded against it but never folded in. Dropping any one of them turns a
 * resume into either a wrong answer or a crash.
 *
 * `parentConfig` is the interesting one: it is how a saver that has no other way
 * of knowing the ancestry still lets the caller walk backwards.
 */
class CheckpointTuple extends PregelCheckpointTuple
{
    /**
     * The wire representation of the tuple's own fields.
     *
     * The `config` shapes are arrays, so the tuple itself needs no serializer
     * beyond that; this exists so a caller can round-trip a tuple through
     * storage of its own.
     *
     * @return array{
     *     config: array<string, mixed>,
     *     metadata: array<string, mixed>,
     *     parent_config: array<string, mixed>|null,
     *     pending_writes: list<array{0: string, 1: string, 2: mixed}>
     * }
     */
    public function toArray(): array
    {
        return [
            'config' => $this->config,
            'metadata' => $this->metadata,
            'parent_config' => $this->parentConfig,
            'pending_writes' => array_map(
                static fn (array $write): array => [$write[0], $write[1], $write[2] ?? null],
                array_values($this->pendingWrites),
            ),
        ];
    }

    /**
     * Rebuild a tuple from its wire representation.
     *
     * @param array<string, mixed> $data
     * @param PregelCheckpoint     $checkpoint The decoded checkpoint; supplied by
     *                                           the caller because decoding it is the
     *                                           saver's job, not the tuple's.
     */
    public static function fromArray(array $data, PregelCheckpoint $checkpoint): self
    {
        return new self(
            config: is_array($data['config'] ?? null) ? $data['config'] : [],
            checkpoint: $checkpoint,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            parentConfig: is_array($data['parent_config'] ?? null) ? $data['parent_config'] : null,
            pendingWrites: array_map(
                static fn (mixed $write): array => [(string) $write[0], (string) $write[1], $write[2] ?? null],
                array_values(is_array($data['pending_writes'] ?? null) ? $data['pending_writes'] : []),
            ),
        );
    }
}
