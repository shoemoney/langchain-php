<?php

declare(strict_types=1);

namespace LangChain\Tests\Integration\Checkpoint;

use LangGraph\Checkpoint\MongoDB\MongoBinary;
use LangGraph\Checkpoint\MongoDB\MongoCollectionInterface;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;

/** Adapts `MongoDB\Collection` to {@see MongoCollectionInterface}, converting binary and dates. */
final class MongoDBDriverCollection implements MongoCollectionInterface
{
    public function __construct(private readonly \MongoDB\Collection $collection)
    {
    }

    public function find(array $filter = [], array $sort = [], ?int $limit = null): array
    {
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        if ($sort !== []) {
            $options['sort'] = $sort;
        }
        if ($limit !== null) {
            $options['limit'] = $limit;
        }

        return array_map(
            fn (array $doc): array => $this->fromBson($doc),
            $this->collection->find($filter, $options)->toArray(),
        );
    }

    public function findOne(array $filter, array $sort = []): ?array
    {
        return $this->find($filter, $sort, 1)[0] ?? null;
    }

    public function updateOne(array $filter, array $update, array $options = []): void
    {
        $this->collection->updateOne($filter, $this->toBson($update), $options);
    }

    public function insertMany(array $documents): void
    {
        $this->collection->insertMany($this->toBson($documents));
    }

    public function deleteMany(array $filter): int
    {
        return $this->collection->deleteMany($filter)->getDeletedCount() ?? 0;
    }

    public function createIndex(array $keys, array $options = []): string
    {
        return $this->collection->createIndex($keys, $options);
    }

    private function toBson(mixed $value): mixed
    {
        if ($value instanceof MongoBinary) {
            return new Binary($value->value(), Binary::TYPE_GENERIC);
        }
        if ($value instanceof \DateTimeInterface) {
            return new UTCDateTime($value);
        }
        if (is_array($value)) {
            return array_map($this->toBson(...), $value);
        }

        return $value;
    }

    private function fromBson(mixed $value): mixed
    {
        if ($value instanceof Binary) {
            return new MongoBinary($value->getData());
        }
        if (is_array($value)) {
            return array_map($this->fromBson(...), $value);
        }

        return $value;
    }
}
