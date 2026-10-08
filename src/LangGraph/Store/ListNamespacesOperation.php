<?php

declare(strict_types=1);

namespace LangGraph\Store;

/**
 * Operation to list and filter namespaces in the store.
 *
 * Port of `ListNamespacesOperation` from `store/base.ts`.
 */
final class ListNamespacesOperation implements Operation
{
    /**
     * @param list<MatchCondition>|null $matchConditions Every condition must match.
     * @param int|null                  $maxDepth        Truncate namespaces to this many labels, then de-duplicate.
     */
    public function __construct(
        public readonly ?array $matchConditions,
        public readonly ?int $maxDepth,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }
}
