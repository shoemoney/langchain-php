<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\MongoDB;

/**
 * A BSON binary value, without the driver.
 *
 * Upstream stores `checkpoint`, `metadata` and a write's `value` as BSON `Binary` and
 * reads them back with `.value("utf8")`. This is the seam's stand-in: a driver-backed
 * {@see MongoCollectionInterface} converts it to and from `MongoDB\BSON\Binary`.
 */
final class MongoBinary
{
    public function __construct(public readonly string $data)
    {
    }

    /** The bytes as a string; port of `Binary.value("utf8")`. */
    public function value(): string
    {
        return $this->data;
    }
}
