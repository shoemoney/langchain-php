<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use LangGraph\Store\MongoDB\MongoStoreCollectionInterface;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;

/**
 * Adapts `MongoDB\Collection` to {@see MongoStoreCollectionInterface}, converting dates both ways.
 *
 * Test-only, and written without a server to run it against here: the live specs that use it
 * skip unless `LANGGRAPH_MONGO_URI` and ext-mongodb are present.
 */
final class MongoDBStoreDriverCollection implements MongoStoreCollectionInterface
{
    private const TYPE_MAP = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];

    public function __construct(public readonly \MongoDB\Collection $collection)
    {
    }

    public function find(array $filter = [], array $sort = [], ?int $limit = null): array
    {
        $options = self::TYPE_MAP;
        if ($sort !== []) {
            $options['sort'] = $sort;
        }
        if ($limit !== null) {
            $options['limit'] = $limit;
        }

        return array_map($this->fromBson(...), $this->collection->find($this->toBson($filter), $options)->toArray());
    }

    public function findOne(array $filter, array $sort = []): ?array
    {
        return $this->find($filter, $sort, 1)[0] ?? null;
    }

    public function updateOne(array $filter, array $update, array $options = []): void
    {
        $this->collection->updateOne($this->toBson($filter), $this->toBson($update), $options);
    }

    public function insertMany(array $documents): void
    {
        $this->collection->insertMany($this->toBson($documents));
    }

    public function deleteMany(array $filter): int
    {
        return $this->collection->deleteMany($this->toBson($filter))->getDeletedCount() ?? 0;
    }

    public function createIndex(array $keys, array $options = []): string
    {
        return $this->collection->createIndex($keys, $options);
    }

    public function aggregate(array $pipeline): array
    {
        return array_map(
            $this->fromBson(...),
            iterator_to_array($this->collection->aggregate($this->toBson($pipeline), self::TYPE_MAP), false),
        );
    }

    public function bulkWrite(array $operations): void
    {
        $converted = [];
        foreach ($operations as $operation) {
            if (isset($operation['updateOne'])) {
                $spec = $operation['updateOne'];
                $converted[] = ['updateOne' => [$this->toBson($spec['filter']), $this->toBson($spec['update']), ['upsert' => $spec['upsert'] ?? false]]];
            } else {
                $converted[] = ['deleteOne' => [$this->toBson($operation['deleteOne']['filter'])]];
            }
        }
        $this->collection->bulkWrite($converted);
    }

    public function createSearchIndex(array $definition): string
    {
        return $this->collection->createSearchIndex(
            $definition['definition'],
            ['name' => $definition['name'], 'type' => $definition['type']],
        );
    }

    public function findOneAndUpdate(array $filter, array $update, array $options = []): ?array
    {
        $after = ($options['returnDocument'] ?? 'before') === 'after';
        $doc = $this->collection->findOneAndUpdate($this->toBson($filter), $this->toBson($update), self::TYPE_MAP + [
            'returnDocument' => $after ? FindOneAndUpdate::RETURN_DOCUMENT_AFTER : FindOneAndUpdate::RETURN_DOCUMENT_BEFORE,
        ]);

        return $doc === null ? null : $this->fromBson($doc);
    }

    private function toBson(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return new UTCDateTime($value);
        }

        return is_array($value) ? array_map($this->toBson(...), $value) : $value;
    }

    private function fromBson(mixed $value): mixed
    {
        if ($value instanceof UTCDateTime) {
            return \DateTimeImmutable::createFromInterface($value->toDateTime());
        }

        return is_array($value) ? array_map($this->fromBson(...), $value) : $value;
    }
}
