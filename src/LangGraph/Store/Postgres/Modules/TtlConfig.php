<?php

declare(strict_types=1);

namespace LangGraph\Store\Postgres\Modules;

/**
 * Time-to-live behaviour for {@see \LangGraph\Store\Postgres\PostgresStore}.
 *
 * Port of `TTLConfig` from `store/modules/types.ts`. All durations are minutes.
 */
final class TtlConfig
{
    /**
     * @param int|float|null $defaultTtl            Default time to live for items, in minutes.
     * @param bool           $refreshOnRead         Push an item's expiry forward whenever it is read.
     * @param int|float|null $sweepIntervalMinutes  How often expired items are swept; see {@see TtlManager}.
     */
    public function __construct(
        public readonly int|float|null $defaultTtl = null,
        public readonly bool $refreshOnRead = false,
        public readonly int|float|null $sweepIntervalMinutes = null,
    ) {
    }
}
