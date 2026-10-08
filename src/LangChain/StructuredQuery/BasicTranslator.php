<?php

declare(strict_types=1);

namespace LangChain\StructuredQuery;

/**
 * Translates the IR into a Mongo-style `$and` / `$eq` filter record.
 *
 * Port of `BasicTranslator` from `@langchain/core/structured_query/base`.
 *
 * - `visitOperation`  returns `['$and' => [...]]`
 * - `visitComparison` returns `[attribute => ['$eq' => value]]`
 * - `visitStructuredQuery` returns `['filter' => ...]` or `[]`
 */
class BasicTranslator extends BaseTranslator
{
    public function __construct(?TranslatorOpts $opts = null)
    {
        $this->allowedOperators = $opts->allowedOperators ?? [
            Operators::AND,
            Operators::OR,
        ];
        $this->allowedComparators = $opts->allowedComparators ?? [
            Comparators::EQ,
            Comparators::NE,
            Comparators::GT,
            Comparators::GTE,
            Comparators::LT,
            Comparators::LTE,
        ];
    }

    /**
     * @throws \Error when the function is not allowed or unknown.
     */
    public function formatFunction(string $func): string
    {
        if (Comparators::has($func)) {
            if ($this->allowedComparators !== [] && !in_array($func, $this->allowedComparators, true)) {
                throw new \Error(sprintf(
                    'Comparator %s not allowed. Allowed comparators: %s',
                    $func,
                    implode(', ', $this->allowedComparators)
                ));
            }
        } elseif (Operators::has($func)) {
            if ($this->allowedOperators !== [] && !in_array($func, $this->allowedOperators, true)) {
                throw new \Error(sprintf(
                    'Operator %s not allowed. Allowed operators: %s',
                    $func,
                    implode(', ', $this->allowedOperators)
                ));
            }
        } else {
            throw new \Error('Unknown comparator or operator');
        }

        return '$' . $func;
    }

    /**
     * @return array<string, list<mixed>|null>
     */
    public function visitOperation(Operation $operation): array
    {
        $args = $operation->args === null
            ? null
            : array_map(fn (FilterDirective $arg): mixed => $arg->accept($this), array_values($operation->args));

        return [$this->formatFunction($operation->operator) => $args];
    }

    /**
     * @return array<string, array<string, string|int|float|bool>>
     */
    public function visitComparison(Comparison $comparison): array
    {
        return [
            $comparison->attribute => [
                $this->formatFunction($comparison->comparator) => Utils::castValue($comparison->value),
            ],
        ];
    }

    /**
     * @return array{filter?: mixed}
     */
    public function visitStructuredQuery(StructuredQuery $structuredQuery): array
    {
        $next = [];
        if ($structuredQuery->filter !== null) {
            $next = ['filter' => $structuredQuery->filter->accept($this)];
        }

        return $next;
    }

    public function mergeFilters(
        mixed $defaultFilter,
        mixed $generatedFilter,
        string $mergeType = 'and',
        bool $forceDefaultFilter = false,
    ): mixed {
        if (Utils::isFilterEmpty($defaultFilter) && Utils::isFilterEmpty($generatedFilter)) {
            return null;
        }
        if (Utils::isFilterEmpty($defaultFilter) || $mergeType === 'replace') {
            if (Utils::isFilterEmpty($generatedFilter)) {
                return null;
            }

            return $generatedFilter;
        }
        if (Utils::isFilterEmpty($generatedFilter)) {
            if ($forceDefaultFilter) {
                return $defaultFilter;
            }
            if ($mergeType === 'and') {
                return null;
            }

            return $defaultFilter;
        }
        if ($mergeType === 'and') {
            return ['$and' => [$defaultFilter, $generatedFilter]];
        }
        if ($mergeType === 'or') {
            return ['$or' => [$defaultFilter, $generatedFilter]];
        }

        throw new \Error('Unknown merge type');
    }
}
