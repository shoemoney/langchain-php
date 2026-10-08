<?php

declare(strict_types=1);

namespace LangChain\StructuredQuery;

/**
 * Options for {@see BasicTranslator}: the allowed operators and comparators.
 *
 * Port of the `TranslatorOpts` type from `@langchain/core/structured_query/base`.
 */
final class TranslatorOpts
{
    /**
     * @param list<string> $allowedOperators
     * @param list<string> $allowedComparators
     */
    public function __construct(
        public array $allowedOperators,
        public array $allowedComparators,
    ) {
    }
}
