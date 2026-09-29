<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * A synthetic task carrying the run's input as if a node had written it.
 *
 * Port of the `{ name: INPUT, writes: inputWrites, triggers: [] }` object
 * `loop.ts` builds inline in two places.
 *
 * The input goes through the same fold as everything else — same sorting, same
 * channel semantics — rather than having its own shortcut. That is the point:
 * a special path for input would be a second implementation of "write to a
 * channel", and the two would drift.
 */
final class PregelInputWrites implements WritesProtocol
{
    /**
     * @param list<array{0: string, 1: mixed}> $writes
     */
    public function __construct(
        public readonly array $writes = [],
        public readonly string $name = Constants::INPUT,
    ) {
    }

    public function writesName(): string
    {
        return $this->name;
    }

    public function triggers(): array
    {
        // The input is not triggered by a channel, so no `versions_seen` entry
        // is recorded and no channel is consumed on its behalf.
        return [];
    }

    public function writesList(): array
    {
        return $this->writes;
    }

    public function path(): ?TaskPath
    {
        return null;
    }
}
