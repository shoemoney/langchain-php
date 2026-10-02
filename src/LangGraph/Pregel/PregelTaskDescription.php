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

    /**
     * The wire shape, which INCLUDES `path`.
     *
     * The class docblock says it "carries the id, name, interrupts, and path", and
     * upstream's `PregelTaskDescription` serialises all four. `toArray()` omitted
     * `path` while the property existed one line above it, so a caller reading the
     * array could not see the field the object held. Nothing in `src/` or `tests/`
     * called this method, so nothing depended on the narrower shape.
     *
     * Measured against LangGraph JS, a paused task serialises as
     * `{id, name, path: ["__pregel_pull", "<node>"], interrupts: [{id, value}]}`.
     *
     * `path` is a list of segments, matching upstream's variadic task path. A
     * `Send`-driven task has no path and serialises as null rather than as an empty
     * list, because "no path" and "a path with nothing in it" are different states
     * and upstream keeps them apart.
     *
     * @return array{id: string, name: string, path: list<string>|null, interrupts: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'path' => $this->path?->toArray(),
            'interrupts' => $this->interrupts,
        ];
    }

    /**
     * The three-key shape `Pregel::getState()` returned before snapshots were typed.
     *
     * Kept separate from {@see self::toArray()} on purpose: the legacy form is a
     * compatibility shape, and widening it quietly is how a shim stops being one.
     *
     * @return array{id: string, name: string, interrupts: list<mixed>}
     */
    public function toLegacyArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'interrupts' => $this->interrupts,
        ];
    }
}
