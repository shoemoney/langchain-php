<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\MongoDB;

/**
 * The slice of a MongoDB collection the checkpointer uses.
 *
 * `mongodb` is not a dependency of this package, so {@see MongoDBSaver} talks to this
 * seam instead of `MongoDB\Collection`. A thin adapter over the real driver implements
 * it for production; the tests implement it in memory.
 *
 * Filters, sorts and updates use MongoDB's own operator syntax (`$lt`, `$set`,
 * `$setOnInsert`, `$currentDate`, dotted paths such as `metadata_search.source`).
 * Documents are associative arrays; binary fields are {@see MongoBinary}.
 */
interface MongoCollectionInterface
{
    /**
     * Matching documents.
     *
     * @param  array<string, mixed> $filter
     * @param  array<string, int>   $sort   Field => 1 (ascending) or -1 (descending), applied in order.
     * @param  int|null             $limit  Null means no limit.
     * @return list<array<string, mixed>>
     */
    public function find(array $filter = [], array $sort = [], ?int $limit = null): array;

    /**
     * The first document matching, in `$sort` order, or null.
     *
     * @param  array<string, mixed> $filter
     * @param  array<string, int>   $sort
     * @return array<string, mixed>|null
     */
    public function findOne(array $filter, array $sort = []): ?array;

    /**
     * Update the first matching document.
     *
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update  Operators: `$set`, `$setOnInsert`, `$currentDate`.
     * @param array{upsert?: bool} $options When `upsert` is set and nothing matches, a document is
     *                                      created from the filter's equality fields plus the update.
     */
    public function updateOne(array $filter, array $update, array $options = []): void;

    /**
     * @param list<array<string, mixed>> $documents
     */
    public function insertMany(array $documents): void;

    /**
     * Delete every matching document.
     *
     * @param  array<string, mixed> $filter
     * @return int The number deleted.
     */
    public function deleteMany(array $filter): int;

    /**
     * Create an index.
     *
     * @param  array<string, int>   $keys    Field => 1 or -1.
     * @param  array<string, mixed> $options `name`, `expireAfterSeconds`.
     * @return string               The index name.
     */
    public function createIndex(array $keys, array $options = []): string;
}
