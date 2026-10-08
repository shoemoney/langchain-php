<?php

declare(strict_types=1);

namespace LangChain\StructuredQuery;

/**
 * Visits the nodes of a structured-query expression tree.
 *
 * Port of the abstract `Visitor` from `@langchain/core/structured_query/ir`.
 * The upstream `VisitOperationOutput` / `VisitComparisonOutput` /
 * `VisitStructuredQueryOutput` type members become `mixed` returns here; each
 * concrete translator documents its own.
 *
 * `$allowedOperators` / `$allowedComparators` are abstract properties upstream;
 * here they are plain public arrays that every subclass initialises.
 */
abstract class Visitor
{
    /** @var list<string> */
    public array $allowedOperators = [];

    /** @var list<string> */
    public array $allowedComparators = [];

    abstract public function visitOperation(Operation $operation): mixed;

    abstract public function visitComparison(Comparison $comparison): mixed;

    abstract public function visitStructuredQuery(StructuredQuery $structuredQuery): mixed;
}
