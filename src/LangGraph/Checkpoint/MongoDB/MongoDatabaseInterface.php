<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\MongoDB;

/** The slice of `MongoDB\Database` the checkpointer uses. */
interface MongoDatabaseInterface
{
    public function collection(string $name): MongoCollectionInterface;
}
