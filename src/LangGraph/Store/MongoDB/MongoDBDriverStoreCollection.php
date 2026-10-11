<?php

declare(strict_types=1);

namespace LangGraph\Store\MongoDB;

use LangGraph\Checkpoint\MongoDB\MongoDBDriverCollection;
use MongoDB\BSON\UTCDateTime;

/**
 * Optional mongodb/mongodb collection adapter for the store's array-based seam.
 *
 * Reads force the inherited all-array typeMap and decode BSON dates at millisecond precision.
 * The caller owns borrowed native handles. Binary subtype loss and codec asymmetry are inherited.
 */
class MongoDBDriverStoreCollection extends MongoDBDriverCollection implements MongoStoreCollectionInterface
{
    // mongodb/mongodb 2.1.0 Operation/FindOneAndUpdate.php:39-40; no optional class load required.
    private const RETURN_DOCUMENT_BEFORE = 1;
    private const RETURN_DOCUMENT_AFTER = 2;

    public function aggregate(array $pipeline): array
    {
        return array_map(
            fn (array $document): array => $this->fromBson($document),
            $this->nativeCollection()->aggregate($this->toBson($pipeline), $this->readOptions([]))->toArray(),
        );
    }

    public function bulkWrite(array $operations): void
    {
        $native = [];
        foreach ($operations as $index => $operation) {
            $type = count($operation) === 1 ? array_key_first($operation) : null;
            $spec = $type === null ? [] : ($operation[$type] ?? []);
            $native[] = match ($type) {
                'updateOne' => ['updateOne' => [
                    $this->toBson($spec['filter']),
                    $this->toBson($spec['update']),
                    ['upsert' => ($spec['upsert'] ?? false) === true],
                ]],
                'deleteOne' => ['deleteOne' => [$this->toBson($spec['filter'])]],
                default => throw new \InvalidArgumentException("Unsupported bulk operation at index {$index}."),
            };
        }

        // The store returns void; never inspect an unacknowledged bulk result.
        $this->nativeCollection()->bulkWrite($native);
    }

    public function createSearchIndex(array $definition): string
    {
        return $this->nativeCollection()->createSearchIndex(
            $this->toBson($definition['definition']),
            array_intersect_key($definition, ['name' => 1, 'type' => 1]),
        );
    }

    public function findOneAndUpdate(array $filter, array $update, array $options = []): ?array
    {
        $returnDocument = match ($options['returnDocument'] ?? 'before') {
            'before' => self::RETURN_DOCUMENT_BEFORE,
            'after' => self::RETURN_DOCUMENT_AFTER,
            default => throw new \InvalidArgumentException('returnDocument must be "before" or "after".'),
        };
        $document = $this->nativeCollection()->findOneAndUpdate(
            $this->toBson($filter),
            $this->toBson($update),
            ['returnDocument' => $returnDocument] + $this->readOptions([]),
        );

        return $document === null ? null : $this->fromBson($document);
    }

    protected function decodeDate(mixed $value): ?\DateTimeInterface
    {
        return $value instanceof UTCDateTime ? \DateTimeImmutable::createFromInterface($value->toDateTime()) : null;
    }
}
