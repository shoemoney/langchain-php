<?php

declare(strict_types=1);

namespace LangChain\StructuredQuery;

/**
 * A free-text query plus an optional metadata filter.
 *
 * Port of `StructuredQuery` from `@langchain/core/structured_query/ir`.
 */
class StructuredQuery extends Expression
{
    public string $exprName = 'StructuredQuery';

    public function __construct(
        public string $query,
        public ?FilterDirective $filter = null,
    ) {
    }
}
