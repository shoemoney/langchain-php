<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Serde;

/**
 * How a saver turns a value into bytes and back.
 *
 * Port of `SerializerProtocol` from `@langchain/langgraph-checkpoint`'s
 * `serde/base.ts`.
 *
 * The interesting part of the interface is the *type tag*. `dumpsTyped()` returns
 * a pair, and the tag travels with the data instead of being inferred on read.
 * A value that is a byte string and a value that is a JSON document containing a
 * byte string are not the same thing, and only the tag tells them apart.
 *
 * Every saver method is asymmetric on purpose: {@see self::dumpsTyped()} is
 * always paired with a {@see self::loadsTyped()} of the same tag, and a saver that
 * discards the tag cannot read back what it wrote.
 *
 * @template-covariant TValue The value type this serializer round-trips.
 */
abstract class BaseCheckpointSerializer
{
    /**
     * Serialise a value.
     *
     * @param  TValue                $data
     * @return array{0: string, 1: string} A `[type, payload]` pair, where the
     *                                     payload is a binary-safe string.
     */
    abstract public function dumpsTyped(mixed $data): array;

    /**
     * Deserialise a value previously produced by {@see self::dumpsTyped()}.
     *
     * @param  string                $type The tag returned by the matching dump.
     * @param  string                $data The payload, exactly as it was dumped.
     * @return TValue
     *
     * @throws \InvalidArgumentException When the type tag is not one this
     *                                   serializer knows how to read.
     */
    abstract public function loadsTyped(string $type, string $data): mixed;
}
