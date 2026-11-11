<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\MongoDB;

/** Wraps a borrowed native database without changing the client's options or ownership. */
class MongoDBDriverDatabase implements MongoDatabaseInterface
{
    public function __construct(private readonly \MongoDB\Database $database)
    {
    }

    public function collection(string $name): MongoCollectionInterface
    {
        return $this->wrapCollection($this->nativeDatabase()->selectCollection($name));
    }

    /** @return \MongoDB\Database */
    protected function nativeDatabase(): object
    {
        return $this->database;
    }

    /** @param \MongoDB\Collection $collection */
    protected function wrapCollection(object $collection): MongoCollectionInterface
    {
        return new MongoDBDriverCollection($collection);
    }
}
