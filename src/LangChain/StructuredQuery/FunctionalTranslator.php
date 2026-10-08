<?php

declare(strict_types=1);

namespace LangChain\StructuredQuery;

use Closure;
use LangChain\Schema\Document;

/**
 * Translates the IR into `Closure(Document): bool` filters over metadata.
 *
 * Port of `FunctionalTranslator` from `@langchain/core/structured_query/functional`.
 *
 * JS semantics kept on purpose: `===` is strict across types (but `1` and
 * `1.0` are the same number), relational operators on two strings compare
 * code units (never numerically, unlike PHP's `<` on numeric strings), and on
 * mixed types coerce through numbers where NaN makes every comparison false.
 * Operation arguments are all evaluated (upstream `reduce` does not
 * short-circuit the translation, nor the call).
 */
class FunctionalTranslator extends BaseTranslator
{
    public function __construct()
    {
        $this->allowedOperators = [Operators::AND, Operators::OR];
        $this->allowedComparators = [
            Comparators::EQ,
            Comparators::NE,
            Comparators::GT,
            Comparators::GTE,
            Comparators::LT,
            Comparators::LTE,
        ];
    }

    public function formatFunction(string $func = ''): string
    {
        throw new \Error('Not implemented');
    }

    /**
     * @param string $inputType A JS `typeof` name: string, number, boolean.
     *
     * @return list<string>
     */
    public function getAllowedComparatorsForType(string $inputType): array
    {
        return match ($inputType) {
            'string', 'number' => [
                Comparators::EQ,
                Comparators::NE,
                Comparators::GT,
                Comparators::GTE,
                Comparators::LT,
                Comparators::LTE,
            ],
            'boolean' => [Comparators::EQ, Comparators::NE],
            default => throw new \Error("Unsupported data type: {$inputType}"),
        };
    }

    /**
     * @return Closure(mixed, mixed): bool
     */
    public function getComparatorFunction(string $comparator): Closure
    {
        return match ($comparator) {
            Comparators::EQ => static fn (mixed $a, mixed $b): bool => self::strictEquals($a, $b),
            Comparators::NE => static fn (mixed $a, mixed $b): bool => !self::strictEquals($a, $b),
            Comparators::GT => static fn (mixed $a, mixed $b): bool => (self::relate($a, $b) ?? 0) > 0,
            Comparators::GTE => static fn (mixed $a, mixed $b): bool => (self::relate($a, $b) ?? -1) >= 0,
            Comparators::LT => static fn (mixed $a, mixed $b): bool => (self::relate($a, $b) ?? 0) < 0,
            Comparators::LTE => static fn (mixed $a, mixed $b): bool => (self::relate($a, $b) ?? 1) <= 0,
            default => throw new \Error('Unknown comparator'),
        };
    }

    /**
     * @return Closure(bool, bool): bool
     */
    public function getOperatorFunction(string $operator): Closure
    {
        return match ($operator) {
            Operators::AND => static fn (bool $a, bool $b): bool => $a && $b,
            Operators::OR => static fn (bool $a, bool $b): bool => $a || $b,
            default => throw new \Error('Unknown operator'),
        };
    }

    /**
     * @return Closure(Document): bool
     */
    public function visitOperation(Operation $operation): Closure
    {
        $operator = $operation->operator;
        $args = $operation->args;
        if (!in_array($operator, $this->allowedOperators, true)) {
            throw new \Error('Operator not allowed');
        }
        $operatorFunction = $this->getOperatorFunction($operator);

        return function (Document $document) use ($args, $operatorFunction): bool {
            if ($args === null) {
                return true;
            }
            $acc = true;
            foreach ($args as $arg) {
                $result = $arg->accept($this);
                if (!$result instanceof Closure) {
                    throw new \Error('Filter is not a function');
                }
                $acc = $operatorFunction($acc, $result($document));
            }

            return $acc;
        };
    }

    /**
     * @return Closure(Document): bool
     */
    public function visitComparison(Comparison $comparison): Closure
    {
        $comparator = $comparison->comparator;
        $attribute = $comparison->attribute;
        $value = $comparison->value;
        if (!in_array($comparator, $this->allowedComparators, true)) {
            throw new \Error('Comparator not allowed');
        }
        $type = self::typeOf($value);
        if (!in_array($comparator, $this->getAllowedComparatorsForType($type), true)) {
            throw new \Error("'{$comparator}' comparator not allowed to be used with {$type}");
        }
        $comparatorFunction = $this->getComparatorFunction($comparator);

        return static function (Document $document) use ($attribute, $comparator, $value, $comparatorFunction): bool {
            if (!array_key_exists($attribute, $document->metadata)) {
                return $comparator === Comparators::NE;
            }

            return $comparatorFunction($document->metadata[$attribute], Utils::castValue($value));
        };
    }

    /**
     * @return array{filter?: Closure(Document): bool}
     */
    public function visitStructuredQuery(StructuredQuery $structuredQuery): array
    {
        if ($structuredQuery->filter === null) {
            return [];
        }
        $filterFunction = $structuredQuery->filter->accept($this);
        if (!$filterFunction instanceof Closure) {
            throw new \Error('Structured query filter is not a function');
        }

        return ['filter' => $filterFunction];
    }

    /**
     * `$forceDefaultFilter` is accepted for signature compatibility and, as
     * upstream, ignored.
     *
     * @param Closure(Document): bool|null $defaultFilter
     * @param Closure(Document): bool|null $generatedFilter
     *
     * @return Closure(Document): bool|null
     */
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
            if ($mergeType === 'and') {
                return null;
            }

            return $defaultFilter;
        }

        if ($mergeType === 'and') {
            return static fn (Document $document): bool => $defaultFilter($document) && $generatedFilter($document);
        }
        if ($mergeType === 'or') {
            return static fn (Document $document): bool => $defaultFilter($document) || $generatedFilter($document);
        }

        throw new \Error('Unknown merge type');
    }

    /** JS `typeof`: null and arrays are "object". */
    private static function typeOf(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            default => 'object',
        };
    }

    /** JS `a === b`. */
    private static function strictEquals(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        return $a === $b;
    }

    /**
     * JS relational comparison: sign of a-b, or null when either side is NaN.
     */
    private static function relate(mixed $a, mixed $b): ?int
    {
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }
        $x = self::toNumber($a);
        $y = self::toNumber($b);
        if (is_nan($x) || is_nan($y)) {
            return null;
        }

        return $x <=> $y;
    }

    private static function toNumber(mixed $v): float
    {
        return match (true) {
            is_int($v), is_float($v) => (float) $v,
            is_bool($v) => $v ? 1.0 : 0.0,
            $v === null => 0.0,
            is_string($v) => self::stringToNumber($v),
            default => NAN,
        };
    }

    private static function stringToNumber(string $s): float
    {
        $t = trim($s, " \t\n\r\x0B\x0C");
        if ($t === '') {
            return 0.0;
        }
        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/', $t) === 1) {
            return (float) $t;
        }
        if (preg_match('/^[+-]?Infinity$/', $t) === 1) {
            return $t[0] === '-' ? -INF : INF;
        }

        return NAN;
    }
}
