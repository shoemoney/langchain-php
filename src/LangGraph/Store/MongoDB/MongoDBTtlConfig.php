<?php

declare(strict_types=1);

namespace LangGraph\Store\MongoDB;

/**
 * Time-to-live configuration for automatic document expiration.
 *
 * Port of `TTLConfig` from `checkpoint-mongodb/src/store.ts`. Each document's `expiresAt` is
 * set to now plus `defaultTtl` on every put, and on every get when `refreshOnRead` is set.
 */
final class MongoDBTtlConfig
{
    /**
     * @param int  $defaultTtl     Seconds a document lives.
     * @param bool $refreshOnRead  Reset the timer on get, extending the life of frequently read items.
     */
    public function __construct(
        public readonly int $defaultTtl,
        public readonly bool $refreshOnRead = false,
    ) {
    }
}
