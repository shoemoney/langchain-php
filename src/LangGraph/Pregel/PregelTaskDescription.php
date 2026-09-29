<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * The public description of a task, as surfaced in streams and snapshots.
 *
 * Port of `PregelTaskDescription`.
 *
 * Deliberately narrower than {@see PregelExecutableTask}: it carries the id,
 * name, interrupts, and path, and nothing about *how* the task runs. A task
 * description is what a caller is allowed to see; the runnable and config
 * behind it are engine-internal.
 */
final class PregelTaskDescription
{
    /**
     * @param string                            $id         Deterministic task id.
     * @param string                            $name       Node name.
     * @param list<array{id: string|null, value: mixed}> $interrupts Pending interrupts.
     * @param TaskPath|null                     $path       Where the task sits in the step.
     */
    public function __construct(
        public readonly string $id = '',
        public readonly string $name = '',
        public readonly array $interrupts = [],
        public readonly ?TaskPath $path = null,
    ) {
    }

    /** @return array{id: string, name: string, interrupts: list<mixed>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'interrupts' => $this->interrupts,
        ];
    }
}
