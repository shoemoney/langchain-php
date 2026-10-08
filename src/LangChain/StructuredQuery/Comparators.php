<?php

declare(strict_types=1);

namespace LangChain\StructuredQuery;

/**
 * Comparison operators of the structured-query IR.
 *
 * Port of the `Comparators` map and `Comparator` union from
 * `@langchain/core/structured_query/ir`. Upstream has no `in` / `nin`;
 * neither does this port.
 */
final class Comparators
{
    public const EQ = 'eq';
    public const NE = 'ne';
    public const LT = 'lt';
    public const GT = 'gt';
    public const LTE = 'lte';
    public const GTE = 'gte';

    private function __construct()
    {
    }

    /**
     * The upstream `Comparators` object: name to value.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'eq' => self::EQ,
            'ne' => self::NE,
            'lt' => self::LT,
            'gt' => self::GT,
            'lte' => self::LTE,
            'gte' => self::GTE,
        ];
    }

    /** Upstream `func in Comparators`. */
    public static function has(string $name): bool
    {
        return array_key_exists($name, self::all());
    }
}
