<?php

declare(strict_types=1);

namespace LangChain\Indexing;

/**
 * Tracks which documents have been written to a vector store, and when.
 *
 * Port of `RecordManagerInterface` from `@langchain/core/indexing/record_manager`.
 * Times are seconds as a float, from the manager's own clock.
 */
interface RecordManagerInterface
{
    /** Arbitrary value, used for generating namespaced UUIDs. */
    public const UUIDV5_NAMESPACE = '10f90ea3-90a4-4962-bf75-83a0f3c1c62a';

    /** Create the backing schema. */
    public function createSchema(): void;

    /** The manager's current time. */
    public function getTime(): float;

    /**
     * Upsert keys.
     *
     * @param list<string>                                                 $keys
     * @param array{groupIds?: list<string|null>, timeAtLeast?: float|int} $updateOptions
     *
     * @throws \InvalidArgumentException when `timeAtLeast` is in the future or `groupIds` does not match `keys`
     */
    public function update(array $keys, array $updateOptions = []): void;

    /**
     * Whether each key exists, in the order given.
     *
     * @param list<string> $keys
     *
     * @return list<bool>
     */
    public function exists(array $keys): array;

    /**
     * List keys, newest-agnostic, in insertion order.
     *
     * @param array{before?: float|int, after?: float|int, groupIds?: list<string|null>, limit?: int} $options
     *
     * @return list<string>
     */
    public function listKeys(array $options = []): array;

    /**
     * @param list<string> $keys
     */
    public function deleteKeys(array $keys): void;
}
