<?php

declare(strict_types=1);

namespace LangGraph\Store\MongoDB;

use LangGraph\Checkpoint\MongoDB\MongoCollectionInterface;

/**
 * The slice of a MongoDB collection {@see MongoDBStore} uses beyond the checkpointer's.
 *
 * Extends the saver's seam rather than editing it: `find`, `findOne`, `updateOne`,
 * `deleteMany` and `createIndex` come from {@see MongoCollectionInterface}. A driver-backed
 * implementation converts {@see \DateTimeInterface} values to and from BSON dates.
 */
interface MongoStoreCollectionInterface extends MongoCollectionInterface
{
    /**
     * Run an aggregation pipeline and return every resulting document.
     *
     * @param  list<array<string, mixed>> $pipeline
     * @return list<array<string, mixed>>
     */
    public function aggregate(array $pipeline): array;

    /**
     * Apply write operations in order. Each entry is `['updateOne' => ['filter', 'update', 'upsert']]`
     * or `['deleteOne' => ['filter']]`.
     *
     * @param list<array<string, array<string, mixed>>> $operations
     */
    public function bulkWrite(array $operations): void;

    /**
     * Create an Atlas search index and return as soon as the build is scheduled.
     *
     * @param  array<string, mixed> $definition `name`, `type` and `definition`.
     * @return string               The index name.
     */
    public function createSearchIndex(array $definition): string;

    /**
     * Update the first matching document and return it.
     *
     * @param  array<string, mixed>              $filter
     * @param  array<string, mixed>              $update
     * @param  array{returnDocument?: 'before'|'after'} $options
     * @return array<string, mixed>|null         Null when nothing matched.
     */
    public function findOneAndUpdate(array $filter, array $update, array $options = []): ?array;
}
