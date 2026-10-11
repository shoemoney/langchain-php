<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * The public description of a task, as surfaced in streams and snapshots.
 *
 * Port of `PregelTaskDescription`.
 *
 * Carries public task observations, including remote error, child state and result.
 * The runnable and execution config behind {@see PregelExecutableTask} remain engine-internal.
 * Local construction keeps the original id, name, interrupts and path defaults.
 */
final class PregelTaskDescription
{
    private readonly bool $resultPresent;

    /**
     * @param string                            $id         Deterministic task id.
     * @param string                            $name       Node name.
     * @param list<array{id: string|null, value: mixed}> $interrupts Pending interrupts.
     * @param TaskPath|null                     $path       Where the task sits in the step.
     * @param mixed                             $error      The mapped remote error, or null when absent.
     * @param StateSnapshot|array<string, mixed>|null $state Child snapshot or checkpoint reference.
     * @param mixed                             $result     Task result, including null.
     * @param bool                              $resultPresent Distinguishes absent from explicit null.
     */
    public function __construct(
        public readonly string $id = '',
        public readonly string $name = '',
        public readonly array $interrupts = [],
        public readonly ?TaskPath $path = null,
        public readonly mixed $error = null,
        public readonly StateSnapshot|array|null $state = null,
        public readonly mixed $result = null,
        bool $resultPresent = false,
    ) {
        $this->resultPresent = $resultPresent || $result !== null;
    }

    /** Whether a result was supplied, including an explicit null. */
    public function hasResult(): bool
    {
        return $this->resultPresent;
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
