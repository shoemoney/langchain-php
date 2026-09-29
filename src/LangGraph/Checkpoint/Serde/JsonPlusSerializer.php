<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Serde;



/**
 * The serializer every saver in this library uses.
 *
 * Port of `JsonPlusSerializer` from `@langchain/langgraph-checkpoint`'s
 * `serde/jsonplus.ts`.
 *
 * The contract is a *tagged* pair rather than a bare string: {@see self::dumpsTyped()}
 * returns `['json', $payload]` for a document and `['bytes', $payload]` for raw
 * bytes, and {@see self::loadsTyped()} is driven by that tag. Storing the tag
 * beside the value is what lets a saver hold real binary blobs — a tool
 * argument, an attachment — in the same column as a JSON document without one
 * being mistaken for the other on the way back out.
 *
 * The work is split between {@see JsonPlusEncoder} and {@see JsonPlusDecoder};
 * this class owns the tagging and nothing else.
 *
 * @extends BaseCheckpointSerializer<mixed>
 */
final class JsonPlusSerializer extends BaseCheckpointSerializer
{
    public function __construct(
        private readonly JsonPlusEncoder $encoder = new JsonPlusEncoder(),
        private readonly JsonPlusDecoder $decoder = new JsonPlusDecoder(),
    ) {
    }

    /**
     * Serialise a value with its type tag.
     *
     * A top-level value that is not valid UTF-8 is bytes, and is written as
     * bytes. Anything else goes through the JSON-plus envelope, which is where
     * nested bytes, `Throwable`s, snapshots, `Send`s and LangChain objects are
     * rewritten into their record form.
     *
     * @return array{0: string, 1: string}
     */
    public function dumpsTyped(mixed $data): array
    {
        if (JsonPlusEncoder::isBinaryString($data)) {
            return ['bytes', $data];
        }

        return ['json', $this->encoder->encode($data)];
    }

    /**
     * Deserialise a value from its type tag and payload.
     *
     * @throws \InvalidArgumentException When the tag is neither `json` nor
     *                                   `bytes` — an unknown tag means the
     *                                   payload was written by a different
     *                                   serializer, and reading it as one or the
     *                                   other would fabricate a value.
     */
    public function loadsTyped(string $type, string $data): mixed
    {
        return match ($type) {
            'bytes' => $data,
            'json' => $this->decoder->decode($data),
            default => throw new \InvalidArgumentException("Unknown serialization type: {$type}"),
        };
    }
}
