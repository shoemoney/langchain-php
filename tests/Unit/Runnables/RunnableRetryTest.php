<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableRetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `RunnableRetry` (`libs/langchain-core/src/runnables/base.ts`), reached through
 * `Runnable::withRetry()`, which upstream defines as `new RunnableRetry({ bound: this, … })` — and
 * `RunnableRetry` itself EXTENDS `RunnableBinding`, which this port already has.
 *
 * Semantics read from upstream source rather than assumed:
 *   - `maxAttemptNumber` defaults to **3**;
 *   - a failing attempt calls the `onFailedAttempt` handler with the error;
 *   - attempts after the first are tagged `retry:attempt:<n>` on the run.
 */
#[CoversClass(RunnableRetry::class)]
final class RunnableRetryTest extends TestCase
{
    private function counting(int &$calls, int $failUntil = 0): Runnable
    {
        return new RunnableLambda(static function (mixed $x) use (&$calls, $failUntil): string {
            ++$calls;

            return $calls <= $failUntil
                ? throw new \RuntimeException('attempt ' . $calls)
                : 'ok';
        });
    }

    /** RED before the fix: `withRetry()` does not exist. */
    public function testASucceedingRunnableIsNotRetried(): void
    {
        $calls = 0;
        $out = $this->counting($calls)->withRetry()->invoke('x');

        self::assertSame('ok', $out);
        self::assertSame(1, $calls, 'a first-attempt success must not be retried');
    }

    public function testAFailingRunnableIsRetriedUntilItSucceeds(): void
    {
        $calls = 0;
        $out = $this->counting($calls, 2)->withRetry()->invoke('x');

        self::assertSame('ok', $out);
        self::assertSame(3, $calls, 'two failures then a success means three attempts');
    }

    public function testRetriesStopAtTheAttemptLimitAndRethrow(): void
    {
        $calls = 0;
        $r = $this->counting($calls, 99)->withRetry(['stopAfterAttempt' => 2]);

        $this->expectException(\RuntimeException::class);
        try {
            $r->invoke('x');
        } finally {
            self::assertSame(2, $calls, 'stopAfterAttempt=2 means exactly two attempts, then the error propagates');
        }
    }

    public function testTheFailedAttemptHandlerSeesTheError(): void
    {
        $calls = 0;
        $seen = [];
        $r = $this->counting($calls, 99)->withRetry([
            'stopAfterAttempt' => 2,
            'onFailedAttempt' => static function (\Throwable $e) use (&$seen): void { $seen[] = $e->getMessage(); },
        ]);

        try {
            $r->invoke('x');
        } catch (\RuntimeException) {
            // expected
        }

        // BOTH attempts fail here (stopAfterAttempt=2), so the handler runs TWICE. The first
        // expectation written for this was `['attempt 1']` on the reasoning that "the last failure
        // propagates rather than being reported" — true of a THREE-attempt run, not of a two-attempt
        // one, and the code was right. The rule is: the handler sees every failed attempt, and the
        // final error propagates to the caller INSTEAD of going to the handler.
        self::assertSame(['attempt 1', 'attempt 2'], $seen, 'the handler sees every failed attempt');
    }

    /** CONTROL: the default attempt count is 3, so a permanently-failing run makes exactly 3 attempts. */
    public function testTheDefaultAttemptCountIsThree(): void
    {
        $calls = 0;
        $r = $this->counting($calls, 99);

        try {
            $r->withRetry()->invoke('x');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame(3, $calls, 'upstream defaults maxAttemptNumber to 3');
    }
}
