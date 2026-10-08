<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Redis;

/**
 * Key expiry for the Redis savers.
 *
 * Port of the `TTLConfig` interface from `@langchain/langgraph-checkpoint-redis`.
 */
final class TtlConfig
{
    /**
     * @param float|int|null $defaultTtl     Time to live in MINUTES; null or 0 disables expiry.
     * @param bool           $refreshOnRead  Re-arm the TTL whenever a checkpoint is read.
     */
    public function __construct(
        public readonly float|int|null $defaultTtl = null,
        public readonly bool $refreshOnRead = false,
    ) {
    }

    /** Whether any expiry is configured. */
    public function enabled(): bool
    {
        return $this->defaultTtl !== null && $this->defaultTtl > 0;
    }

    /** The TTL in whole seconds (`Math.floor(defaultTTL * 60)`). */
    public function seconds(): int
    {
        return (int) floor(($this->defaultTtl ?? 0) * 60);
    }
}
