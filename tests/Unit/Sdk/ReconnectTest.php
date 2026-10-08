<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangGraph\Sdk\Utils\Reconnect;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/reconnect.test.ts`.
 */
#[CoversClass(Reconnect::class)]
final class ReconnectTest extends TestCase
{
    public function testCapsExponentialBackoff(): void
    {
        $this->assertLessThanOrEqual(6000, Reconnect::reconnectDelayMs(1));
        $this->assertLessThanOrEqual(6000, Reconnect::reconnectDelayMs(10));
    }

    public function testCapHoldsAtTheWorstJitterToo(): void
    {
        $almostOne = static fn (): float => 0.999999;

        $this->assertLessThan(6000, Reconnect::reconnectDelayMs(10, $almostOne));
        $this->assertGreaterThan(5999, Reconnect::reconnectDelayMs(10, $almostOne));
    }

    public function testDelayDoublesFromTheBaseUntilTheCap(): void
    {
        $noJitter = static fn (): float => 0.0;

        $this->assertSame([1000.0, 2000.0, 4000.0, 5000.0, 5000.0], array_map(
            static fn (int $attempt): float => (float) Reconnect::reconnectDelayMs($attempt, $noJitter),
            [1, 2, 3, 4, 10],
        ));
    }

    public function testJitterIsAddedOnTopOfTheCappedDelay(): void
    {
        $half = static fn (): float => 0.5;

        $this->assertSame(1500.0, (float) Reconnect::reconnectDelayMs(1, $half));
    }
}
