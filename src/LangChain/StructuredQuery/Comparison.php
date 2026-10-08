<?php

declare(strict_types=1);

namespace LangChain\StructuredQuery;

/**
 * `attribute <comparator> value`.
 *
 * Port of `Comparison` from `@langchain/core/structured_query/ir`.
 *
 * @template ValueTypes of string|int|float|bool
 */
class Comparison extends FilterDirective
{
    public string $exprName = 'Comparison';

    /**
     * @param string     $comparator One of {@see Comparators}.
     * @param ValueTypes $value
     */
    public function __construct(
        public string $comparator,
        public string $attribute,
        public mixed $value,
    ) {
    }
}
