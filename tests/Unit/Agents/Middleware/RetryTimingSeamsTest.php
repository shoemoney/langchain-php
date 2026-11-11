<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangGraph\Agents\Middleware\ModelRetryMiddleware;
use LangGraph\Agents\Middleware\ToolRetryMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exact schedules through both retry loops, using a virtual clock driven by their injected sleeper. */
#[CoversClass(ModelRetryMiddleware::class)]
#[CoversClass(ToolRetryMiddleware::class)]
final class RetryTimingSeamsTest extends TestCase
{
    /** @return iterable<string, array{class-string, string}> */
    public static function factories(): iterable
    {
        yield 'model' => [ModelRetryMiddleware::class, 'wrapModelCall'];
        yield 'tool' => [ToolRetryMiddleware::class, 'wrapToolCall'];
    }

    #[DataProvider('factories')]
    public function testCappedBackoffAndRetryAfterFloorAreAppliedBeforeTheNextAttempt(string $factory, string $hook): void
    {
        $now = 0.0;
        $attemptTimes = [];
        $delays = [];
        $middleware = $factory::create(['maxRetries' => 3, 'initialDelayMs' => 100, 'backoffFactor' => 2, 'maxDelayMs' => 150, 'jitter' => false], static function (int|float $delay) use (&$now, &$delays): void {
            $delays[] = $delay;
            $now += $delay;
        });
        $result = $middleware[$hook](['toolCall' => ['name' => 'search', 'id' => 'call']], static function () use (&$now, &$attemptTimes): string {
            $attemptTimes[] = $now;
            if (\count($attemptTimes) < 4) {
                throw new class ('rate limited') extends \Exception {
                    public int $retryAfterMs = 175;
                };
            }

            return 'success';
        });

        self::assertSame('success', $result);
        self::assertSame([175, 175, 175], $delays);
        self::assertSame([0.0, 175.0, 350.0, 525.0], $attemptTimes);
    }

    #[DataProvider('factories')]
    public function testZeroDelayMakesNoSleepCallsAndExhaustionAddsNoFinalWait(string $factory, string $hook): void
    {
        $sleepCalls = 0;
        $attempts = 0;
        $middleware = $factory::create(['maxRetries' => 2, 'initialDelayMs' => 0, 'jitter' => false], static function () use (&$sleepCalls): void { ++$sleepCalls; });
        $middleware[$hook](['toolCall' => ['name' => 'search', 'id' => 'call']], static function () use (&$attempts): never {
            ++$attempts;

            throw new \Exception('failure');
        });

        self::assertSame(3, $attempts);
        self::assertSame(0, $sleepCalls);
    }

    #[DataProvider('factories')]
    public function testSleepIsOnlyCalledBetweenFailedAttempts(string $factory, string $hook): void
    {
        $events = [];
        $middleware = $factory::create(['maxRetries' => 2, 'initialDelayMs' => 25, 'jitter' => false], static function (int|float $delay) use (&$events): void { $events[] = ['sleep', $delay]; });
        $middleware[$hook](['toolCall' => ['name' => 'search', 'id' => 'call']], static function () use (&$events): never {
            $events[] = ['attempt'];

            throw new \Exception('failure');
        });

        self::assertSame([['attempt'], ['sleep', 25.0], ['attempt'], ['sleep', 50.0], ['attempt']], $events);
    }

    #[DataProvider('factories')]
    public function testNonRetryableFailureDoesNotCallTheSleeper(string $factory, string $hook): void
    {
        $sleepCalls = 0;
        $middleware = $factory::create(['retryOn' => static fn (): bool => false], static function () use (&$sleepCalls): void { ++$sleepCalls; });
        $middleware[$hook](['toolCall' => ['name' => 'search', 'id' => 'call']], static fn (): never => throw new \Exception('failure'));

        self::assertSame(0, $sleepCalls);
    }

    #[DataProvider('factories')]
    public function testTheSleeperArgumentMustBeCallable(string $factory, string $hook): void
    {
        $this->expectException(\TypeError::class);

        $factory::create([], 'not_a_callable');
    }
}
