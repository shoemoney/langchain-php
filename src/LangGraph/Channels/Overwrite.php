<?php

declare(strict_types=1);

namespace LangGraph\Channels;

/**
 * Bypass a reducer and write the wrapped value directly to a channel.
 *
 * Port of the `OVERWRITE` constant, the `OverwriteValue` interface, the
 * `Overwrite` class, and the `_getOverwriteValue` / `_isOverwriteValue`
 * helpers in `langgraph-core/src/constants.ts`.
 *
 * Receiving multiple `Overwrite` values for the same channel in a single
 * super-step raises an {@see \LangGraph\Errors\InvalidUpdateError}.
 *
 * A JavaScript `Overwrite` instance is a plain object carrying the
 * `__overwrite__` key, so the wire format and the class instance are the same
 * shape. That is reproduced here by making {@see self::toArray()} emit exactly
 * the object a node would return over the wire, and by having the detection
 * helpers accept the instance, the wire array, and the discriminator form
 * produced when another runtime (e.g. a Python dataclass routed through the
 * LangGraph API server) has erased the typed instance.
 *
 * @template TValue The type of the value being overwritten.
 */
final class Overwrite
{
    /** Discriminator key for the wire/object format. */
    public const OVERWRITE = '__overwrite__';

    public const LG_NAME = 'Overwrite';

    /**
     * @param mixed $value The value written directly, bypassing the reducer.
     *
     * @phpstan-param TValue $value
     */
    public function __construct(public readonly mixed $value)
    {
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * The wire representation: `{ "__overwrite__": <value> }`.
     *
     * @return array{__overwrite__: mixed}
     */
    public function toArray(): array
    {
        return [self::OVERWRITE => $this->value];
    }

    /**
     * Structural check for the class instance or the wire-format array.
     *
     * Port of `Overwrite::isInstance`.
     *
     * @param mixed $value Value to test.
     */
    public static function isInstance(mixed $value): bool
    {
        if ($value instanceof self) {
            return true;
        }

        if (is_array($value)) {
            return array_key_exists(self::OVERWRITE, $value);
        }

        if (is_object($value)) {
            return property_exists($value, 'lgName') && $value->lgName === self::LG_NAME;
        }

        return false;
    }

    /**
     * Detect and unwrap an `Overwrite`.
     *
     * Port of `_getOverwriteValue`. Returns `[true, $unwrapped]` when the value
     * is an `Overwrite` (instance, wire array, or `{type, value}` discriminator
     * record) and `[false, null]` otherwise.
     *
     * @param mixed $value Value to inspect.
     *
     * @return array{bool, mixed} Tuple of [isOverwrite, unwrapped value].
     */
    public static function getValueOf(mixed $value): array
    {
        if (is_array($value)) {
            if (array_key_exists(self::OVERWRITE, $value)) {
                return [true, $value[self::OVERWRITE]];
            }

            if (($value['type'] ?? null) === self::OVERWRITE && array_key_exists('value', $value)) {
                return [true, $value['value']];
            }

            return [false, null];
        }

        if (is_object($value) && !$value instanceof \stdClass) {
            if (property_exists($value, self::OVERWRITE)) {
                return [true, $value->{self::OVERWRITE}];
            }
        }

        return [false, null];
    }

    /**
     * Type guard matching {@see self::getValueOf()}.
     *
     * Port of `_isOverwriteValue`.
     *
     * @param mixed $value Value to inspect.
     */
    public static function isOverwriteValue(mixed $value): bool
    {
        return self::getValueOf($value)[0];
    }
}
