<?php

declare(strict_types=1);

namespace LangGraph\Pregel\Checkpoint;


/**
 * A saved checkpoint together with the config that locates it.
 *
 * Port of `CheckpointTuple`.
 */
class CheckpointTuple
{
    /**
     * @param array<string, mixed>                $config         Config identifying this checkpoint.
     * @param Checkpoint                          $checkpoint     The checkpoint itself.
     * @param array<string, mixed>                $metadata       Step, source, parents.
     * @param array<string, mixed>|null           $parentConfig   Config of the preceding checkpoint.
     * @param list<array{0: string, 1: string, 2: mixed}> $pendingWrites Writes not yet folded in.
     */
    public function __construct(
        public array $config,
        public Checkpoint $checkpoint,
        public array $metadata = [],
        public ?array $parentConfig = null,
        public array $pendingWrites = [],
    ) {
    }
}
