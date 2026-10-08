<?php

declare(strict_types=1);

namespace LangChain\Tests\Integration\Checkpoint;

use LangGraph\Checkpoint\MongoDB\MongoCollectionInterface;
use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;

final class MongoDBDriverDatabase implements MongoDatabaseInterface
{
    public function __construct(private readonly \MongoDB\Database $database)
    {
    }

    public function collection(string $name): MongoCollectionInterface
    {
        return new MongoDBDriverCollection($this->database->selectCollection($name));
    }
}
