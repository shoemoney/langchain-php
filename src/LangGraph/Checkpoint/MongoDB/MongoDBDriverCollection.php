<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\MongoDB;

use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;

/**
 * Native collection adapter for the array-based checkpointer seam.
 *
 * Writes convert binary bytes and dates recursively in arrays and stdClass documents,
 * retaining empty objects. Reads force document-to-array mapping, including empty documents.
 * Binary subtype information is discarded by the generic MongoBinary seam. Other native
 * BSON values remain intact. A directly wrapped collection's codec may encode writes,
 * but the explicit read typeMap bypasses that codec. The caller owns the borrowed handles.
 */
class MongoDBDriverCollection implements MongoCollectionInterface
{
    public function __construct(private readonly \MongoDB\Collection $collection)
    {
    }

    public function find(array $filter = [], array $sort = [], ?int $limit = null): array
    {
        $options = $this->readOptions($sort);
        if ($limit !== null) {
            $options['limit'] = $limit;
        }

        return array_map(
            fn (array $document): array => $this->fromBson($document),
            $this->nativeCollection()->find($this->toBson($filter), $options)->toArray(),
        );
    }

    public function findOne(array $filter, array $sort = []): ?array
    {
        $document = $this->nativeCollection()->findOne($this->toBson($filter), $this->readOptions($sort));

        return $document === null ? null : $this->fromBson($document);
    }

    public function updateOne(array $filter, array $update, array $options = []): void
    {
        $this->nativeCollection()->updateOne($this->toBson($filter), $this->toBson($update), $options);
    }

    public function insertMany(array $documents): void
    {
        $this->nativeCollection()->insertMany($this->toBson($documents));
    }

    public function deleteMany(array $filter): int
    {
        $result = $this->nativeCollection()->deleteMany($this->toBson($filter));

        // Unacknowledged writes (w=0) have no count; reading it throws in the driver.
        // The delete was sent, so allow the saver to continue deleting its writes.
        return $result->isAcknowledged() ? $result->getDeletedCount() : 0;
    }

    public function createIndex(array $keys, array $options = []): string
    {
        return $this->nativeCollection()->createIndex($keys, $options);
    }

    /** @return \MongoDB\Collection */
    protected function nativeCollection(): object
    {
        return $this->collection;
    }

    protected function encodeBinary(MongoBinary $binary): object
    {
        return new Binary($binary->value(), Binary::TYPE_GENERIC);
    }

    protected function encodeDate(\DateTimeInterface $date): object
    {
        return new UTCDateTime($date);
    }

    protected function decodeBinary(mixed $value): ?MongoBinary
    {
        return $value instanceof Binary ? new MongoBinary($value->getData()) : null;
    }

    /** Store adapters decode dates; the checkpoint adapter leaves native dates intact. */
    protected function decodeDate(mixed $value): ?\DateTimeInterface
    {
        return null;
    }

    /** @param array<string, int> $sort
     *  @return array<string, mixed>
     */
    protected function readOptions(array $sort): array
    {
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        if ($sort !== []) {
            $options['sort'] = $sort;
        }

        return $options;
    }

    protected function toBson(mixed $value): mixed
    {
        if ($value instanceof MongoBinary) {
            return $this->encodeBinary($value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $this->encodeDate($value);
        }
        if (is_array($value)) {
            return array_map($this->toBson(...), $value);
        }
        if ($value instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $field) {
                $copy->{$key} = $this->toBson($field);
            }

            return $copy;
        }

        return $value;
    }

    protected function fromBson(mixed $value): mixed
    {
        $binary = $this->decodeBinary($value);
        if ($binary !== null) {
            return $binary;
        }
        $date = $this->decodeDate($value);
        if ($date !== null) {
            return $date;
        }
        if (is_array($value)) {
            return array_map($this->fromBson(...), $value);
        }
        if ($value instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $field) {
                $copy->{$key} = $this->fromBson($field);
            }

            return $copy;
        }

        return $value;
    }
}
