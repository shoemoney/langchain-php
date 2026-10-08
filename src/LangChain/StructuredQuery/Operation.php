<?php

declare(strict_types=1);

namespace LangChain\StructuredQuery;

/**
 * A logical operator applied to a list of filter directives.
 *
 * Port of `Operation` from `@langchain/core/structured_query/ir`.
 */
class Operation extends FilterDirective
{
    public string $exprName = 'Operation';

    /**
     * @param string                    $operator One of {@see Operators}.
     * @param list<FilterDirective>|null $args
     */
    public function __construct(
        public string $operator,
        public ?array $args = null,
    ) {
    }
}
