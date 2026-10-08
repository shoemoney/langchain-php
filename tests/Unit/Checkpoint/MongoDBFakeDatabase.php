<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\MongoDB\MongoCollectionInterface;
use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;

/** An in-memory database: collections are created on first use and kept. */
final class MongoDBFakeDatabase implements MongoDatabaseInterface
{
    /** @var array<string, FakeMongoCollection> */
    public array $collections = [];

    /** @var list<string> Every name asked for, in order. */
    public array $requested = [];

    public function collection(string $name): MongoCollectionInterface
    {
        $this->requested[] = $name;

        return $this->collections[$name] ??= new FakeMongoCollection();
    }

    public function fake(string $name): FakeMongoCollection
    {
        $this->collection($name);

        return $this->collections[$name];
    }
}
