<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use LangGraph\Checkpoint\MongoDB\MongoClientInterface;
use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;

/** An in-memory client that records the handshake metadata and the database names asked for. */
final class FakeMongoStoreClient implements MongoClientInterface
{
    /** @var list<array<string, string>> */
    public array $metadata = [];

    /** @var list<string|null> */
    public array $dbNames = [];

    public readonly FakeMongoStoreDatabase $database;

    public function __construct()
    {
        $this->database = new FakeMongoStoreDatabase();
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

    public function store(string $collection = 'store'): FakeMongoStoreCollection
    {
        return $this->database->fake($collection);
    }
}
