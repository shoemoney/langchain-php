<?php

declare(strict_types=1);

namespace LangGraph\Channels;

/**
 * A materialized snapshot of a {@see DeltaChannel}'s accumulated value.
 *
 * Port of `DeltaSnapshot` from `@langchain/langgraph-checkpoint`
 * (`libs/checkpoint/src/serde/types.ts`), which `channels/delta.ts` imports
 * to interpret checkpoint blobs.
 *
 * Upstream detects it structurally through the `lg_name` marker so the type
 * survives serialization round-trips and cross-package duplication; that is
 * preserved here via {@see self::isSnapshot()}.
 *
 * @template TValue The accumulated channel value.
 */
final class DeltaSnapshot
{
    public const LG_NAME = 'DeltaSnapshot';

    /**
     * @param mixed $value The materialized accumulated value.
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
     * Structural type guard for {@see DeltaSnapshot}.
     *
     * @param mixed $value Value to inspect.
     *
     * @phpstan-assert-if-true DeltaSnapshot $value
     */
    public static function isSnapshot(mixed $value): bool
    {
        if ($value instanceof self) {
            return true;
        }

        return is_object($value)
            && property_exists($value, 'lgName')
            && $value->lgName === self::LG_NAME;
    }
}
