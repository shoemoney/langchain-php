<?php

declare(strict_types=1);

namespace LangChain\StructuredQuery;

/**
 * Logical operators of the structured-query IR.
 *
 * Port of the `Operators` map and `Operator` union from
 * `@langchain/core/structured_query/ir`. The union type becomes string
 * constants; the map becomes {@see self::all()}.
 */
final class Operators
{
    public const AND = 'and';
    public const OR = 'or';
    public const NOT = 'not';

    private function __construct()
    {
    }

    /**
     * The upstream `Operators` object: name to value.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return ['and' => self::AND, 'or' => self::OR, 'not' => self::NOT];
    }

    /** Upstream `func in Operators`. */
    public static function has(string $name): bool
    {
        return array_key_exists($name, self::all());
    }
}
