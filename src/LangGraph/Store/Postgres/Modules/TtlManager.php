<?php

declare(strict_types=1);

namespace LangGraph\Store\Postgres\Modules;

/**
 * Time-to-live housekeeping: sweeping expired items.
 *
 * Port of `store/modules/ttl-manager.ts`. Upstream starts a `setInterval` timer;
 * PHP has no background timer inside a request, so {@see self::start()} instead
 * arms a schedule that {@see self::tick()} honours: the store calls `tick()` as it
 * handles operations, and a sweep runs once the configured interval has elapsed.
 */
final class TtlManager
{
    private bool $running = false;

    private ?float $lastSweep = null;

    public function __construct(private readonly DatabaseCore $core)
    {
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }
        $this->running = true;
        $this->lastSweep = microtime(true);
    }

    public function stop(): void
    {
        $this->running = false;
        $this->lastSweep = null;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /** Sweep if started and the sweep interval has passed since the last sweep. */
    public function tick(): void
    {
        if (!$this->running) {
            return;
        }
        $intervalSeconds = (($this->core->ttlConfig?->sweepIntervalMinutes ?: 60)) * 60;
        if (microtime(true) - ($this->lastSweep ?? 0.0) < $intervalSeconds) {
            return;
        }
        $this->lastSweep = microtime(true);
        $this->sweepExpiredItems();
    }

    /**
     * Delete every expired item.
     *
     * @return int Number of items removed.
     */
    public function sweepExpiredItems(): int
    {
        return $this->core->query(
            'DELETE FROM ' . $this->core->storeTable() . ' WHERE expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP',
        )->rowCount();
    }
}
