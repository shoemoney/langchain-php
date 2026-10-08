<?php

declare(strict_types=1);

namespace LangChain\Runnables\Graph;

/**
 * A directed edge of a drawable {@see Graph}.
 *
 * Port of the `Edge` type from `langchain-core/src/runnables/types.ts`. `data` is the edge label and
 * `conditional` marks a dotted (branching) edge; both are absent (`null`) unless set.
 */
final class Edge
{
    public function __construct(
        public string $source,
        public string $target,
        public ?string $data = null,
        public ?bool $conditional = null,
    ) {
    }
}
