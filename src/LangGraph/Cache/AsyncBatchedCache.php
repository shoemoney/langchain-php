<?php

declare(strict_types=1);

namespace LangGraph\Cache;

/**
 * Synchronous facade for the internal Pregel cache queue.
 *
 * Backend calls settle before returning, so stop() is a repeatable no-op
 * barrier. Calls remain valid after stop(), and backend ownership is retained
 * by the caller. The inherited default serializer is unused: only the borrowed
 * backend serializes values.
 *
 * This facade does not serialize overlapping, suspended Fibers or implement
 * the source Promise queue and asynchronous void-set rejection timing. Callers
 * must manage that concurrency themselves.
 *
 * @internal
 */
class AsyncBatchedCache extends BaseCache
{
    public function __construct(private BaseCache $cache)
    {
        parent::__construct();
    }

    public function get(array $keys): array
    {
        return $this->cache->get($keys);
    }

    public function set(array $pairs): void
    {
        $this->cache->set($pairs);
    }

    public function clear(array $namespaces): void
    {
        $this->cache->clear($namespaces);
    }

    public function stop(): void
    {
        // All non-suspending synchronous operations have already settled.
    }
}
