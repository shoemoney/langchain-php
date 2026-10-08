<?php

declare(strict_types=1);

namespace LangGraph\Cache;

use LangGraph\Checkpoint\Serde\BaseCheckpointSerializer;
use LangGraph\Checkpoint\Serde\JsonPlusSerializer;

/**
 * Abstract base class for node-output caches.
 *
 * Port of `BaseCache` from `@langchain/langgraph-checkpoint`'s `cache/base.ts`.
 *
 * A cache entry is addressed by a full key, `[namespace, key]`, where the
 * namespace is a list of labels. Values pass through a serializer on the way in
 * and out, which is what lets an implementation persist them as bytes.
 *
 * Upstream's methods return promises; here they are synchronous.
 *
 * @template V The cached value type.
 */
abstract class BaseCache
{
    public BaseCheckpointSerializer $serde;

    /**
     * @param BaseCheckpointSerializer|null $serde The serializer; defaults to the JSON-plus serializer.
     */
    public function __construct(?BaseCheckpointSerializer $serde = null)
    {
        $this->serde = $serde ?? new JsonPlusSerializer();
    }

    /**
     * Get the cached values for the given keys. Missing and expired keys are omitted.
     *
     * @param  list<array{0: list<string>, 1: string}>                          $keys
     * @return list<array{key: array{0: list<string>, 1: string}, value: V}>
     */
    abstract public function get(array $keys): array;

    /**
     * Set the cached values for the given keys.
     *
     * Each pair may carry a `ttl` in seconds; without one the entry never expires.
     *
     * @param list<array{key: array{0: list<string>, 1: string}, value: V, ttl?: int|float|null}> $pairs
     */
    abstract public function set(array $pairs): void;

    /**
     * Delete the cached values for the given namespaces; with none, clear everything.
     *
     * @param list<list<string>> $namespaces
     */
    abstract public function clear(array $namespaces): void;
}
