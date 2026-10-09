<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use LangGraph\Checkpoint\MongoDB\MongoCollectionInterface;
use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;

final class MongoDBStoreDriverDatabase implements MongoDatabaseInterface
{
    public function __construct(public readonly \MongoDB\Database $database)
    {
    }

    public function collection(string $name): MongoCollectionInterface
    {
        return new MongoDBStoreDriverCollection($this->database->selectCollection($name));
    }
}
