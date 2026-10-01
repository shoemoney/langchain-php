<?php

declare(strict_types=1);

namespace LangGraph\Pregel;


/**
 * The per-step context {@see Algorithm::prepareNextTasks()} needs beyond the
 * checkpoint and the node map.
 *
 * Port of `NextTaskExtraFields` from `algo.ts`.
 *
 * Grouped into a class rather than passed as eight positional arguments because
 * two of them — `triggerToNodes` and `updatedChannels` — form a fast path. When
 * both are present the scheduler can skip the whole node list and go straight to
 * the nodes those channels trigger. That optimisation is worth exactly two
 * fields, and it is worth keeping them adjacent and mandatory-together.
 */
final class NextTaskExtraFields
{
    /**
     * @param int                              $step          The superstep being prepared.
     * @param array<string, BaseChannel>       $channels      Live channel instances.
     * @param array<string, PregelNode>        $processes     The node map.
     * @param Checkpoint|null                  $checkpointer  Saver, for nested graphs.
     * @param array<string, mixed>|null        $store         Long-term store.
     * @param array<string, list<string>>|null $triggerToNodes Channel -> node names.
     * @param list<string>|null                $updatedChannels Channels written last step.
     * @param PendingWritesIndex|null          $pendingWritesIndex Pre-built index.
     * @param list<list<mixed>>|null          $resumeMap     Namespace-hash -> resume value.
     * @param callable|null                    $call          Task invocation callback.
     */
    public function __construct(
        public readonly int $step = 0,
        public readonly array $channels = [],
        public readonly array $processes = [],
        public readonly ?object $checkpointer = null,
        public readonly ?array $store = null,
        public readonly ?array $triggerToNodes = null,
        public readonly ?array $updatedChannels = null,
        public readonly ?PendingWritesIndex $pendingWritesIndex = null,
        public readonly ?array $resumeMap = null,
        public readonly mixed $call = null,
    ) {
    }

    /**
     * The index, building it from `$pendingWrites` if it was not pre-built.
     *
     * @param list<array{0: string, 1: string, 2: mixed}>|null $pendingWrites
     */
    public function indexFor(?array $pendingWrites): PendingWritesIndex
    {
        return $this->pendingWritesIndex ?? PendingWritesIndex::build($pendingWrites);
    }
}
