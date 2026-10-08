<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\MongoDB;

/** The slice of `MongoDB\Client` the checkpointer uses. */
interface MongoClientInterface
{
    /**
     * Tag this client's handshake metadata.
     *
     * Port of `MongoClient.appendMetadata`.
     *
     * @param array{name?: string, version?: string, platform?: string} $metadata
     */
    public function appendMetadata(array $metadata): void;

    /** A database; `null` selects the connection string's default. */
    public function db(?string $name = null): MongoDatabaseInterface;
}
