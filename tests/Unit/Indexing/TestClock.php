<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Indexing;

use LangChain\Indexing\InMemoryRecordManager;

/**
 * A hand-cranked clock, so index runs are separated by exactly the time a test says.
 */
final class TestClock
{
    public float $now = 1000.0;

    public function manager(): InMemoryRecordManager
    {
        return new InMemoryRecordManager(fn (): float => $this->now);
    }

    public function advance(float $seconds = 10.0): void
    {
        $this->now += $seconds;
    }
}
