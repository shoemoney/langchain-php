<?php

declare(strict_types=1);

namespace LangGraph\Pregel\Utils;

use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableSequence;
use LangGraph\Pregel\Pregel;

/**
 * Finding a compiled graph that is hiding inside a node's runnable.
 *
 * Port of `langgraph-core/src/pregel/utils/subgraph.ts`.
 *
 * A node is usually a plain function, but a compiled graph is also a runnable, so it can be a node as
 * is, or sit inside a `RunnableSequence` that wraps it (a prompt piped into a subgraph, say). The
 * engine needs to know which nodes contain a graph so it can namespace their checkpoints and surface
 * their interrupts; this is the detector.
 */
final class Subgraph
{
    private function __construct()
    {
    }

    /**
     * Whether a value is a compiled Pregel graph.
     *
     * Upstream tests the `lg_is_pregel` marker rather than `instanceof`, so a graph rebuilt by
     * another module (a different copy of the class) is still recognised. A PHP object carrying
     * `lgIsPregel === true` is accepted for the same reason.
     */
    public static function isPregelLike(mixed $x): bool
    {
        if ($x instanceof Pregel) {
            return true;
        }

        return is_object($x) && (($x->lgIsPregel ?? false) === true);
    }

    /**
     * The first Pregel graph found in `$candidate`, looking through nested sequences breadth-first.
     *
     * Returns null when the runnable contains none.
     */
    public static function findSubgraphPregel(RunnableInterface $candidate): ?object
    {
        // An index loop, not `foreach`: a by-value foreach walks a snapshot, so steps appended while
        // iterating (the nested sequences' steps) would never be visited.
        $candidates = [$candidate];
        for ($i = 0; $i < count($candidates); $i++) {
            $current = $candidates[$i];
            if (self::isPregelLike($current)) {
                return $current;
            }
            if ($current instanceof RunnableSequence) {
                foreach ($current->steps as $step) {
                    $candidates[] = $step;
                }
            }
        }

        return null;
    }
}
