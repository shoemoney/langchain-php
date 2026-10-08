<?php

declare(strict_types=1);

namespace LangChain\Memory;

/**
 * Abstract base class for memory in a chain: state carried between runs and
 * injected into the inputs of later runs.
 *
 * Port of `BaseMemory` from `@langchain/core/memory`, with upstream's module
 * level helpers (`getInputValue`, `getOutputValue`, `getPromptInputKey`) as
 * static methods. `InputValues`, `OutputValues` and `MemoryVariables` are all
 * `array<string, mixed>`.
 */
abstract class BaseMemory
{
    /** @return list<string> */
    abstract public function memoryKeys(): array;

    /**
     * Load the memory variables for the given input values.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    abstract public function loadMemoryVariables(array $values): array;

    /**
     * Save the context of one run.
     *
     * @param array<string, mixed> $inputValues
     * @param array<string, mixed> $outputValues
     */
    abstract public function saveContext(array $inputValues, array $outputValues): void;

    /**
     * @param array<string, mixed> $values
     */
    private static function getValue(array $values, ?string $key = null): mixed
    {
        if ($key !== null) {
            return $values[$key] ?? null;
        }
        if (count($values) === 1) {
            return $values[array_key_first($values)];
        }

        return null;
    }

    /**
     * Select the input value to remember: the only one, or the one named by
     * `$inputKey`. Throws when it is ambiguous or falsy, as upstream does.
     *
     * @param array<string, mixed> $inputValues
     */
    public static function getInputValue(array $inputValues, ?string $inputKey = null): mixed
    {
        $value = self::getValue($inputValues, $inputKey);
        if (!self::truthy($value)) {
            throw new \RuntimeException(sprintf(
                'input values have %d keys, you must specify an input key or pass only 1 key as input',
                count($inputValues),
            ));
        }

        return $value;
    }

    /**
     * Select the output value to remember. An empty string is a legitimate
     * output (unlike an input), so only a missing value throws.
     *
     * @param array<string, mixed> $outputValues
     */
    public static function getOutputValue(array $outputValues, ?string $outputKey = null): mixed
    {
        $value = self::getValue($outputValues, $outputKey);
        if (!self::truthy($value) && $value !== '') {
            throw new \RuntimeException(sprintf(
                'output values have %d keys, you must specify an output key or pass only 1 key as output',
                count($outputValues),
            ));
        }

        return $value;
    }

    /**
     * The one prompt input key, excluding memory variables and `stop`.
     *
     * @param array<string, mixed> $inputs
     * @param list<string>         $memoryVariables
     */
    public static function getPromptInputKey(array $inputs, array $memoryVariables): string
    {
        $promptInputKeys = array_values(array_filter(
            array_map('strval', array_keys($inputs)),
            static fn (string $key): bool => !in_array($key, $memoryVariables, true) && $key !== 'stop',
        ));
        if (count($promptInputKeys) !== 1) {
            throw new \RuntimeException(sprintf('One input key expected, but got %d', count($promptInputKeys)));
        }

        return $promptInputKeys[0];
    }

    /**
     * JavaScript truthiness for the values these helpers see: null, false, 0,
     * NaN and the empty string are falsy; the string "0", empty arrays and
     * objects are truthy.
     */
    private static function truthy(mixed $value): bool
    {
        if (is_array($value)) {
            return true;
        }
        if (is_float($value) && is_nan($value)) {
            return false;
        }

        if (is_string($value)) {
            return $value !== '';
        }

        return (bool) $value;
    }
}
