<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\MongoDB\MongoClientInterface;
use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;

/** An in-memory client that records the handshake metadata and the database name asked for. */
final class MongoDBFakeClient implements MongoClientInterface
{
    /** @var list<array<string, string>> */
    public array $metadata = [];

    /** @var list<string|null> */
    public array $dbNames = [];

    public readonly MongoDBFakeDatabase $database;

    public function __construct()
    {
        $this->database = new MongoDBFakeDatabase();
    }

    public function appendMetadata(array $metadata): void
    {
        $this->metadata[] = $metadata;
    }

    public function db(?string $name = null): MongoDatabaseInterface
    {
        $this->dbNames[] = $name;

        return $this->database;
    }

    public function checkpoints(): FakeMongoCollection
    {
        return $this->database->fake('checkpoints');
    }

    public function writes(): FakeMongoCollection
    {
        return $this->database->fake('checkpoint_writes');
    }
}
