<?php

declare(strict_types=1);

namespace LangChain\Stores;

use LangChain\Load\Serializable;

/**
 * Abstract interface for a key-value store.
 *
 * Port of `BaseStore` from `@langchain/core/stores`. A missing key reads back as
 * `null` where upstream returns `undefined`.
 *
 * @template K
 * @template V
 */
abstract class BaseStore extends Serializable
{
    /**
     * Get the values for a set of keys; `null` marks a key that was not found.
     *
     * @param list<K> $keys
     * @return list<V|null>
     */
    abstract public function mget(array $keys): array;

    /**
     * Set a value for each of several keys.
     *
     * @param list<array{0: K, 1: V}> $keyValuePairs
     */
    abstract public function mset(array $keyValuePairs): void;

    /**
     * Delete several keys.
     *
     * @param list<K> $keys
     */
    abstract public function mdelete(array $keys): void;

    /**
     * Yield keys, optionally restricted to those starting with a prefix.
     *
     * @return \Generator<int, K|string>
     */
    abstract public function yieldKeys(?string $prefix = null): \Generator;
}
