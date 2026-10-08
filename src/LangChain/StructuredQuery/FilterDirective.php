<?php

declare(strict_types=1);

namespace LangChain\StructuredQuery;

/**
 * A filter node: a {@see Comparison} or an {@see Operation}.
 *
 * Port of the abstract `FilterDirective` from `@langchain/core/structured_query/ir`.
 */
abstract class FilterDirective extends Expression
{
}
