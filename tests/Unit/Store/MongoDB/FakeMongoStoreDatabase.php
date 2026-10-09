<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use LangGraph\Checkpoint\MongoDB\MongoCollectionInterface;
use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;

/** An in-memory database whose collections are {@see FakeMongoStoreCollection}s, created on first use. */
final class FakeMongoStoreDatabase implements MongoDatabaseInterface
{
    /** @var array<string, FakeMongoStoreCollection> */
    public array $collections = [];

    public function collection(string $name): MongoCollectionInterface
    {
        return $this->fake($name);
    }

    public function fake(string $name): FakeMongoStoreCollection
    {
        return $this->collections[$name] ??= new FakeMongoStoreCollection();
    }
}
