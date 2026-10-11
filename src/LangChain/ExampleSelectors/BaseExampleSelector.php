<?php

declare(strict_types=1);

namespace LangChain\ExampleSelectors;

use LangChain\Load\Serializable;

/**
 * Base class for example selectors.
 *
 * Port of `BaseExampleSelector` from `@langchain/core/example_selectors/base`.
 * An example is a map of string keys to values (upstream `Example`).
 */
abstract class BaseExampleSelector extends Serializable
{
    /** @return list<string> */
    public static function lcId(): array
    {
        $parts = explode('\\', static::class);

        return ['langchain_core', 'example_selectors', 'base', (string) end($parts)];
    }

    /** Upstream keeps the default `lc_serializable = false` for every selector. */
    public static function lcSerializable(): bool
    {
        return false;
    }

    /** Upstream selectors are not serializable: they emit `not_implemented`, never constructor kwargs. */
    public function toJson(): array
    {
        return ['lc' => self::LC_SCHEMA_VERSION, 'type' => 'not_implemented', 'id' => static::lcId()];
    }

    /**
     * Add an example to the selector.
     *
     * @param array<string, mixed> $example
     */
    abstract public function addExample(array $example): string|null;

    /**
     * Select examples for the given input variables.
     *
     * @param array<string, mixed> $inputVariables
     *
     * @return list<array<string, mixed>>
     */
    abstract public function selectExamples(array $inputVariables): array;
}
