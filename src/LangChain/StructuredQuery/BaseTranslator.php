<?php

declare(strict_types=1);

namespace LangChain\StructuredQuery;

/**
 * Blueprint for translators from the IR to a vector-store filter dialect.
 *
 * Port of `BaseTranslator` from `@langchain/core/structured_query/base`. The
 * `VectorStore` type parameter is dropped: vector stores are not ported, and the
 * filter type is `mixed` with a per-translator docblock.
 */
abstract class BaseTranslator extends Visitor
{
    /**
     * Format an operator or comparator as a string in the target dialect.
     */
    abstract public function formatFunction(string $func): string;

    /**
     * Merge two filters into one.
     *
     * @param mixed                    $defaultFilter       The default filter.
     * @param mixed                    $generatedFilter     The generated filter.
     * @param string                   $mergeType           `and`, `or` or `replace`.
     * @param bool                     $forceDefaultFilter  Use the default filter even if the generated one is empty.
     *
     * @return mixed The merged filter, or null if both are empty.
     */
    abstract public function mergeFilters(
        mixed $defaultFilter,
        mixed $generatedFilter,
        string $mergeType = 'and',
        bool $forceDefaultFilter = false,
    ): mixed;
}
