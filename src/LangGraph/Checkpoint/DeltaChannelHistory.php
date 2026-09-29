<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint;

/**
 * One channel's contribution across a checkpoint's ancestor chain.
 *
 * Port of `DeltaChannelHistory` from `@langchain/langgraph-checkpoint`.
 *
 * A delta channel stores a snapshot plus the writes since it, rather than the
 * whole accumulated value. Reconstructing it at resume time means walking
 * backwards from the checkpoint being resumed, collecting one channel's writes at
 * each ancestor until an ancestor has a stored value for it — that value is the
 * `seed`, and the walk stops there.
 *
 * `seed` being absent is meaningful, not a bug: the walk reached the root without
 * ever finding a stored value, which the consumer reads as "start empty".
 */
final class DeltaChannelHistory
{
    /**
     * @param list<array{0: string, 1: string, 2: mixed}> $writes On-path deltas, oldest first.
     * @param bool                                        $hasSeed Whether a stored value terminated the walk.
     * @param mixed                                       $seed    The stored value, when there was one.
     */
    public function __construct(
        public readonly array $writes = [],
        public readonly bool $hasSeed = false,
        public readonly mixed $seed = null,
    ) {
    }

    /**
     * @param list<array{0: string, 1: string, 2: mixed}> $writes
     */
    public static function withoutSeed(array $writes = []): self
    {
        return new self($writes);
    }

    public static function withSeed(mixed $seed, array $writes = []): self
    {
        return new self($writes, true, $seed);
    }
}
