<?php

declare(strict_types=1);

namespace LangChain\StructuredQuery;

/**
 * A node in the structured-query tree.
 *
 * Port of the abstract `Expression` from `@langchain/core/structured_query/ir`.
 * Dispatch is by `exprName`, exactly as upstream, rather than by double
 * dispatch on the class.
 */
abstract class Expression
{
    /** One of `Operation`, `Comparison`, `StructuredQuery`. */
    public string $exprName;

    public function accept(Visitor $visitor): mixed
    {
        if ($this->exprName === 'Operation' && $this instanceof Operation) {
            return $visitor->visitOperation($this);
        }
        if ($this->exprName === 'Comparison' && $this instanceof Comparison) {
            return $visitor->visitComparison($this);
        }
        if ($this->exprName === 'StructuredQuery' && $this instanceof StructuredQuery) {
            return $visitor->visitStructuredQuery($this);
        }

        throw new \Error('Unknown Expression type');
    }
}
