<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Runnables\RunnableLambda;
use LangChain\Utils\AsyncCaller;
use LangChain\Utils\Promise;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/async_caller.test.ts`.
 *
 * The retry loop sleeps through an injected recorder, so retry-on-429 is asserted
 * (the recorded backoff is at least the server's Retry-After) without real waiting.
 * `ContextOverflowError` belongs to the unported errors module and is replaced by a
 * plain exception that is marked non-retryable.
 */
#[CoversClass(AsyncCaller::class)]
final class AsyncCallerTest extends TestCase
{
    /** @var list<int|float> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->sleeps = [];
    }

    private function caller(int $maxRetries): AsyncCaller
    {
        return new AsyncCaller(maxRetries: $maxRetries, sleeper: function (int|float $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    /**
     * A callable that plays `$outcomes` in order (a Throwable is thrown, anything else returned)
     * and then repeats the last one. `$calls` counts invocations.
     *
     * @param list<mixed> $outcomes
     */
    private static function script(array $outcomes, mixed &$calls): \Closure
    {
        $calls = 0;

        return static function () use ($outcomes, &$calls): mixed {
            $outcome = $outcomes[min($calls, count($outcomes) - 1)];
            $calls++;
            if ($outcome instanceof \Throwable) {
                throw $outcome;
            }

            return $outcome;
        };
    }

    /**
     * @return mixed what the handler left behind: the thrown error, or the untouched input
     */
    private function handle(mixed $error): mixed
    {
        try {
            $this->caller(0)->onFailedAttempt($error);
        } catch (\Throwable $thrown) {
            return $thrown;
        }

        return $error;
    }

    // ------------------------------------------------------------------ call()

    public function testDefaultFailedAttemptHandlerHandlesNullError(): void
    {
        $this->caller(0)->onFailedAttempt(null);
        $this->addToAssertionCount(1);
    }

    public function testDefaultFailedAttemptHandlerThrowsInsufficientQuotaError(): void
    {
        $err = new ProviderError('Insufficient quota', error: ['code' => 'insufficient_quota']);
        $callable = self::script([$err], $calls);

        try {
            $this->caller(0)->call($callable);
            $this->fail('expected the quota error');
        } catch (\Throwable $thrown) {
            $this->assertSame('InsufficientQuotaError', AsyncCaller::errorName($thrown));
            $this->assertSame('Insufficient quota', $thrown->getMessage());
        }
    }

    public function testPassesOnArgumentsAndReturnsReturnValue(): void
    {
        $callable = static fn (int $a, int $b): array => [$b, $a];

        $this->assertSame([2, 1], $callable(1, 2));
        $this->assertSame([2, 1], $this->caller(0)->call($callable, 1, 2));
    }

    public function testRetriesOnFailure(): void
    {
        $callable = self::script([new \RuntimeException('error'), [2, 1]], $calls);

        $this->assertSame([2, 1], $this->caller(2)->call($callable));
        $this->assertSame(2, $calls);
    }

    public function testAwaitsAPromiseReturnedByTheCallable(): void
    {
        $this->assertSame('ok', $this->caller(0)->call(static fn (): Promise => Promise::resolved('ok')));
    }

    public function testRejectedPromiseIsRetriedLikeAThrow(): void
    {
        $calls = 0;
        $callable = static function () use (&$calls): Promise {
            return ++$calls === 1 ? Promise::rejected(new \RuntimeException('flaky')) : Promise::resolved('fine');
        };

        $this->assertSame('fine', $this->caller(1)->call($callable));
        $this->assertSame(2, $calls);
    }

    public function testBackoffDoublesBetweenAttempts(): void
    {
        $callable = self::script([new \RuntimeException('x'), new \RuntimeException('x'), new \RuntimeException('x'), 'done'], $calls);

        $this->assertSame('done', $this->caller(3)->call($callable));
        $this->assertCount(3, $this->sleeps);
        // randomize: delay = (1..2) * 1000 * 2^n
        foreach ([1000, 2000, 4000] as $n => $base) {
            $this->assertGreaterThanOrEqual($base, $this->sleeps[$n]);
            $this->assertLessThanOrEqual($base * 2, $this->sleeps[$n]);
        }
    }

    public function testExhaustedRetriesRethrowTheLastError(): void
    {
        $callable = self::script([new \RuntimeException('first'), new \RuntimeException('second'), new \RuntimeException('last')], $calls);

        try {
            $this->caller(2)->call($callable);
            $this->fail('expected the error');
        } catch (\RuntimeException $e) {
            $this->assertSame('last', $e->getMessage());
        }
        $this->assertSame(3, $calls);
    }

    public function testATypeErrorIsABugAndNeverRetried(): void
    {
        $callable = self::script([new \TypeError('wrong type')], $calls);

        $this->expectException(\TypeError::class);
        try {
            $this->caller(3)->call($callable);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testCustomFailedAttemptHandlerCanStopRetrying(): void
    {
        $callable = self::script([new \RuntimeException('transient')], $calls);
        $caller = new AsyncCaller(
            maxRetries: 5,
            onFailedAttempt: static function (mixed $error): never {
                throw new \DomainException('give up');
            },
            sleeper: static fn () => null,
        );

        try {
            $caller->call($callable);
            $this->fail('expected the handler error');
        } catch (\DomainException $e) {
            $this->assertSame('give up', $e->getMessage());
        }
        $this->assertSame(1, $calls);
    }

    // -------------------------------------------------- default handler verdicts

    public function testDefaultFailedAttemptHandlerTreatsAbortErrorAsNonRetryable(): void
    {
        $err = new ProviderError('AbortError: user cancelled', name: 'AbortError');
        $callable = self::script([$err], $calls);

        $this->expectExceptionMessage('AbortError');
        try {
            $this->caller(2)->call($callable);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testDefaultFailedAttemptHandlerTreatsEconnabortedAsNonRetryable(): void
    {
        $err = new ProviderError('Request timed out', errorCode: 'ECONNABORTED');
        $callable = self::script([$err], $calls);

        $this->expectExceptionMessage('Request timed out');
        try {
            $this->caller(2)->call($callable);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testDefaultFailedAttemptHandlerTreats4xxErrorsAsNonRetryable(): void
    {
        $callable = self::script([new ProviderError('Bad Request', response: ['status' => 400])], $calls);

        $this->expectExceptionMessage('Bad Request');
        try {
            $this->caller(2)->call($callable);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testDefaultFailedAttemptHandlerTreatsDirectStatusAsNonRetryable(): void
    {
        $callable = self::script([new ProviderError('Bad Request', status: 400)], $calls);

        $this->expectExceptionMessage('Bad Request');
        try {
            $this->caller(2)->call($callable);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testDefaultFailedAttemptHandlerRetriesOn5xxWithDirectStatus(): void
    {
        $callable = self::script([new ProviderError('Internal Server Error', status: 500), null], $calls);

        $this->assertNull($this->caller(2)->call($callable));
        $this->assertSame(2, $calls);
    }

    public function testDefaultFailedAttemptHandlerPrioritizesResponseStatusOverDirectStatus(): void
    {
        $err = new ProviderError('Conflict', status: 500, response: ['status' => 409]);
        $callable = self::script([$err], $calls);

        $this->expectExceptionMessage('Conflict');
        try {
            $this->caller(2)->call($callable);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testDefaultFailedAttemptHandlerAbortsHeaderless429sWithCapacityError(): void
    {
        $callable = self::script([new ProviderError('Too Many Requests', status: 429)], $calls);

        try {
            $this->caller(2)->call($callable);
            $this->fail('expected a capacity error');
        } catch (\Throwable $e) {
            $this->assertSame('RateLimitCapacityError', AsyncCaller::errorName($e));
            $this->assertSame('capacity', AsyncCaller::rateLimitMetadata($e)['rateLimitType']);
            $this->assertSame('headerless_429', AsyncCaller::rateLimitMetadata($e)['rateLimitReason']);
        }
        $this->assertSame(1, $calls);
    }

    public function testDefaultFailedAttemptHandlerAbortsQuotaStyle429sWithoutRetryAfter(): void
    {
        $err = new ProviderError('You exceeded your current quota, please check your plan and billing details.', status: 429);
        $callable = self::script([$err], $calls);

        try {
            $this->caller(3)->call($callable);
            $this->fail('expected a quota error');
        } catch (\Throwable $e) {
            $this->assertSame('RateLimitQuotaExhaustedError', AsyncCaller::errorName($e));
            $this->assertSame('stop', AsyncCaller::rateLimitMetadata($e)['rateLimitType']);
            $this->assertSame('quota_message', AsyncCaller::rateLimitMetadata($e)['rateLimitReason']);
        }
        $this->assertSame(1, $calls);
    }

    public function testDefaultFailedAttemptHandlerAbortsHeaderlessStatusCode429s(): void
    {
        $callable = self::script([new ProviderError('Rate limit exceeded', statusCode: 429)], $calls);

        try {
            $this->caller(2)->call($callable);
            $this->fail('expected a capacity error');
        } catch (\Throwable $e) {
            $this->assertSame('RateLimitCapacityError', AsyncCaller::errorName($e));
            $this->assertSame('headerless_429', AsyncCaller::rateLimitMetadata($e)['rateLimitReason']);
        }
        $this->assertSame(1, $calls);
    }

    public function testDefaultFailedAttemptHandlerAbortsWhenRetryAfterSuggestsQuotaExhaustion(): void
    {
        $err = new ProviderError('Quota exhausted', status: 429, headers: ['retry-after' => '120']);
        $callable = self::script([$err], $calls);

        try {
            $this->caller(3)->call($callable);
            $this->fail('expected a quota error');
        } catch (\Throwable $e) {
            $this->assertSame('RateLimitQuotaExhaustedError', AsyncCaller::errorName($e));
        }
        $this->assertSame(1, $calls);
    }

    public function testRetryAfterOnA429IsHonouredAsTheBackoffFloor(): void
    {
        $err = new ProviderError('Too Many Requests', status: 429, headers: ['retry-after' => '2']);
        $callable = self::script([$err, null], $calls);

        $this->assertNull($this->caller(2)->call($callable));
        $this->assertSame(2, $calls);
        $this->assertSame(2000, AsyncCaller::rateLimitMetadata($err)['retryAfterMs']);
        $this->assertCount(1, $this->sleeps);
        $this->assertGreaterThanOrEqual(2000, $this->sleeps[0]);
    }

    public function testRetryAfterLargerThanTheBackoffWins(): void
    {
        $err = new ProviderError('Too Many Requests', status: 429, headers: ['Retry-After' => '45']);
        $callable = self::script([$err, 'ok'], $calls);

        $this->assertSame('ok', $this->caller(2)->call($callable));
        $this->assertSame([45000], $this->sleeps);
    }

    public function testExtractsRetryAfterFromAHeadersObject(): void
    {
        $headers = new class () {
            public function get(string $name): ?string
            {
                return $name === 'retry-after' ? '3' : null;
            }
        };
        $err = new ProviderError('Too Many Requests', status: 429, headers: $headers);
        $callable = self::script([$err, null], $calls);

        $this->assertNull($this->caller(2)->call($callable));
        $this->assertSame(2, $calls);
        $this->assertSame(3000, AsyncCaller::rateLimitMetadata($err)['retryAfterMs']);
    }

    public function testExtractsRetryAfterFromResponseHeaders(): void
    {
        $err = new ProviderError(
            'Too Many Requests',
            statusCode: 429,
            response: ['status' => 429, 'headers' => ['retry-after' => '5']],
        );
        $callable = self::script([$err, null], $calls);

        $this->assertNull($this->caller(2)->call($callable));
        $this->assertSame(2, $calls);
        $this->assertSame(5000, AsyncCaller::rateLimitMetadata($err)['retryAfterMs']);
    }

    public function testRetryAfterIsParsedFromTheMessageWhenThereIsNoHeader(): void
    {
        $err = new ProviderError('Rate limit reached. Please try again in 1.5s.', status: 429);
        $callable = self::script([$err, 'ok'], $calls);

        $this->assertSame('ok', $this->caller(2)->call($callable));
        $this->assertSame(1500.0, AsyncCaller::rateLimitMetadata($err)['retryAfterMs']);
        $this->assertGreaterThanOrEqual(1500, $this->sleeps[0]);
    }

    // ------------------------------------------------------- parseRetryAfterMs

    public function testParsesIntegerSeconds(): void
    {
        $this->assertSame(30000, AsyncCaller::parseRetryAfterMs('30'));
    }

    public function testParsesZero(): void
    {
        $this->assertSame(0, AsyncCaller::parseRetryAfterMs('0'));
    }

    public function testReturnsNullForNullOrEmpty(): void
    {
        $this->assertNull(AsyncCaller::parseRetryAfterMs(null));
        $this->assertNull(AsyncCaller::parseRetryAfterMs(''));
        $this->assertNull(AsyncCaller::parseRetryAfterMs('  '));
    }

    public function testParsesHttpDateFormat(): void
    {
        $result = AsyncCaller::parseRetryAfterMs(gmdate('D, d M Y H:i:s', time() + 10) . ' GMT');

        $this->assertGreaterThan(8000, $result);
        $this->assertLessThanOrEqual(10000, $result);
    }

    public function testReturnsZeroForPastHttpDate(): void
    {
        $this->assertSame(0, AsyncCaller::parseRetryAfterMs(gmdate('D, d M Y H:i:s', time() - 5) . ' GMT'));
    }

    public function testReturnsNullForUnparseableValue(): void
    {
        $this->assertNull(AsyncCaller::parseRetryAfterMs('not-a-number-or-date'));
    }

    // ------------------------------------------------------ classifyRateLimitError

    public function testClassifiesInsufficientQuotaCodesAsStop(): void
    {
        $error = new ProviderError('Insufficient quota', status: 429, error: ['code' => 'insufficient_quota']);

        $this->assertEquals(['action' => 'stop', 'reason' => 'insufficient_quota'], AsyncCaller::classifyRateLimitError($error));
    }

    public function testClassifiesQuotaAndBillingMessagesAsStop(): void
    {
        $error = new ProviderError('You exceeded your current quota, please check your plan and billing details.', statusCode: 429);

        $this->assertEquals(['action' => 'stop', 'reason' => 'quota_message'], AsyncCaller::classifyRateLimitError($error));
    }

    public function testClassifiesLongRetryAfterAsCapacityPressure(): void
    {
        $error = new ProviderError('Too Many Requests', status: 429, headers: ['retry-after' => '120']);

        $this->assertEquals(
            ['action' => 'capacity', 'retryAfterMs' => 120000, 'reason' => 'retry_after_too_large'],
            AsyncCaller::classifyRateLimitError($error),
        );
    }

    public function testClassifiesShortRetryAfterAsWait(): void
    {
        $error = new ProviderError('Too Many Requests', status: 429, headers: ['retry-after' => '4']);

        $this->assertSame(
            ['action' => 'wait', 'retryAfterMs' => 4000, 'reason' => 'retry_after_hint'],
            AsyncCaller::classifyRateLimitError($error),
        );
    }

    public function testClassifiesHeaderless429sAsCapacityPressure(): void
    {
        $this->assertSame(
            ['action' => 'capacity', 'reason' => 'headerless_429'],
            AsyncCaller::classifyRateLimitError(new ProviderError('Rate limit exceeded', statusCode: 429)),
        );
    }

    public function testNon429IsNotARateLimit(): void
    {
        $this->assertNull(AsyncCaller::classifyRateLimitError(new ProviderError('boom', status: 500)));
        $this->assertNull(AsyncCaller::classifyRateLimitError(new \RuntimeException('plain')));
    }

    // -------------------------------------------------------- retryability marking

    /**
     * @return array<string, array{int}>
     */
    public static function nonRetryableStatuses(): array
    {
        $cases = [];
        foreach ([400, 401, 402, 403, 404, 405, 406, 407, 409] as $status) {
            $cases["status $status"] = [$status];
        }

        return $cases;
    }

    #[DataProvider('nonRetryableStatuses')]
    public function testMarksNonRetryableStatuses(int $status): void
    {
        $this->assertFalse(AsyncCaller::getRetryable($this->handle(new ProviderError('nope', status: $status))));
    }

    public function testMarksAnAbortedCallNonRetryable(): void
    {
        $this->assertFalse(AsyncCaller::getRetryable($this->handle(new ProviderError('boom', name: 'AbortError'))));
    }

    public function testMarksInsufficientQuotaNonRetryable(): void
    {
        $this->assertFalse(AsyncCaller::getRetryable($this->handle(new ProviderError('Insufficient quota', error: ['code' => 'insufficient_quota']))));
    }

    public function testMarksARateLimitWithARetryHintRetryable(): void
    {
        $error = new ProviderError('Rate limit exceeded', status: 429, headers: ['retry-after' => '4']);

        $this->assertTrue(AsyncCaller::getRetryable($this->handle($error)));
    }

    public function testMarksHeaderlessCapacityPressureRetryable(): void
    {
        $this->assertTrue(AsyncCaller::getRetryable($this->handle(new ProviderError('Rate limit exceeded', statusCode: 429))));
    }

    public function testLeavesAnUnrecognizedErrorUnmarked(): void
    {
        $this->assertNull(AsyncCaller::getRetryable($this->handle(new \RuntimeException('who knows'))));
    }

    public function testLeavesA500UnmarkedSoOuterRetriesStillApply(): void
    {
        $this->assertNull(AsyncCaller::getRetryable($this->handle(new ProviderError('server', status: 500))));
    }

    public function testMarksAnUnmarked413NonRetryable(): void
    {
        $this->assertFalse(AsyncCaller::getRetryable($this->handle(new ProviderError('too large', status: 413))));
    }

    public function testStampRetryableIgnoresNonObjects(): void
    {
        $this->assertSame('x', AsyncCaller::stampRetryable('x', false));
        $this->assertNull(AsyncCaller::getRetryable('x'));
    }

    // ------------------------------------------------------------- 413 and marks

    public function testDoesNotRetryAnUnmarkedPayloadTooLargeError(): void
    {
        $callable = self::script([new ProviderError('payload too large', status: 413)], $calls);

        $this->expectExceptionMessage('payload too large');
        try {
            $this->caller(3)->call($callable);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testStopsRetryingAnErrorMarkedNonRetryableByTheCallable(): void
    {
        $err = AsyncCaller::stampRetryable(new ProviderError('payload too large', status: 413), false);
        $callable = self::script([$err], $calls);

        $this->expectExceptionMessage('payload too large');
        try {
            $this->caller(3)->call($callable);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testStopsRetryingAMarkedErrorWithNoStatusAtAll(): void
    {
        $callable = self::script([AsyncCaller::stampRetryable(new \OverflowException('context overflow'), false)], $calls);

        $this->expectException(\OverflowException::class);
        try {
            $this->caller(3)->call($callable);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testStillRetriesAnErrorMarkedRetryable(): void
    {
        $callable = self::script([AsyncCaller::stampRetryable(new \RuntimeException('upstream busy'), true), null], $calls);

        $this->assertNull($this->caller(2)->call($callable));
        $this->assertSame(2, $calls);
    }

    public function testStillRetriesAnUnmarkedError(): void
    {
        $callable = self::script([new \RuntimeException('transient'), null], $calls);

        $this->assertNull($this->caller(2)->call($callable));
        $this->assertSame(2, $calls);
    }

    // ----------------------------------------------------- per-call maxRetries

    public function testHonorsAPerCallOverrideOfZero(): void
    {
        $callable = self::script([new \RuntimeException('boom')], $calls);

        try {
            $this->caller(5)->callWithOptions(['maxRetries' => 0], $callable);
            $this->fail('expected the error');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame(1, $calls);
    }

    public function testHonorsAPerCallOverrideAboveZero(): void
    {
        $callable = self::script([new \RuntimeException('boom')], $calls);

        try {
            $this->caller(5)->callWithOptions(['maxRetries' => 2], $callable);
            $this->fail('expected the error');
        } catch (\RuntimeException) {
        }
        $this->assertSame(3, $calls);
    }

    public function testFallsBackToTheConfiguredValueWhenNotOverridden(): void
    {
        $callable = self::script([new \RuntimeException('boom')], $calls);

        try {
            $this->caller(2)->callWithOptions([], $callable);
            $this->fail('expected the error');
        } catch (\RuntimeException) {
        }
        $this->assertSame(3, $calls);
    }

    public function testCallIsUnaffectedByTheOption(): void
    {
        $callable = self::script([new \RuntimeException('boom')], $calls);

        try {
            $this->caller(1)->call($callable);
            $this->fail('expected the error');
        } catch (\RuntimeException) {
        }
        $this->assertSame(2, $calls);
    }

    public function testAnOverrideOfZeroSkipsTheRetryAfterWaitEntirely(): void
    {
        $err = new ProviderError('rate limited', status: 429, headers: ['retry-after' => '30']);
        $callable = self::script([$err], $calls);

        try {
            $this->caller(3)->callWithOptions(['maxRetries' => 0], $callable);
            $this->fail('expected the error');
        } catch (\Throwable $e) {
            $this->assertSame('rate limited', $e->getMessage());
        }
        $this->assertSame(1, $calls);
        $this->assertSame([], $this->sleeps);
    }

    // ------------------------------------------------------------------- signal

    public function testAnAlreadyAbortedSignalNeverCallsTheCallable(): void
    {
        $callable = self::script(['x'], $calls);

        try {
            $this->caller(2)->callWithOptions(['signal' => static fn (): bool => true], $callable);
            $this->fail('expected an abort');
        } catch (\Throwable $e) {
            $this->assertSame('AbortError', AsyncCaller::errorName($e));
        }
        $this->assertSame(0, $calls);
    }

    public function testASignalThatFiresDuringAFailureStopsTheRetries(): void
    {
        $aborted = false;
        $calls = 0;
        $callable = static function () use (&$aborted, &$calls): never {
            $calls++;
            $aborted = true;
            throw new \RuntimeException('flaky');
        };

        try {
            $this->caller(5)->callWithOptions(['signal' => static function () use (&$aborted): bool {
                return $aborted;
            }], $callable);
            $this->fail('expected an abort');
        } catch (\Throwable $e) {
            $this->assertSame('AbortError', AsyncCaller::errorName($e));
        }
        $this->assertSame(1, $calls);
    }

    public function testASignalMayCarryItsOwnReason(): void
    {
        $reason = new \DomainException('deadline');

        $this->expectExceptionObject($reason);
        $this->caller(1)->callWithOptions(['signal' => static fn (): \Throwable => $reason], static fn (): int => 1);
    }

    // -------------------------------------------------------------- end to end

    public function testRetriesARunnableChainThroughA429AndHonoursRetryAfter(): void
    {
        $attempts = 0;
        $flaky = RunnableLambda::from(static function (string $input) use (&$attempts): string {
            if (++$attempts === 1) {
                throw new ProviderError('Too Many Requests', status: 429, headers: ['retry-after' => '7']);
            }

            return strtoupper($input);
        });
        $chain = $flaky->pipe(RunnableLambda::from(static fn (string $s): string => $s . '!'));

        $result = $this->caller(3)->call(static fn (): mixed => $chain->invoke('hello'));

        $this->assertSame('HELLO!', $result);
        $this->assertSame(2, $attempts);
        $this->assertCount(1, $this->sleeps);
        $this->assertGreaterThanOrEqual(7000, $this->sleeps[0]);
    }

    public function testAChainThatHitsABadRequestIsNotRetried(): void
    {
        $attempts = 0;
        $chain = RunnableLambda::from(static function () use (&$attempts): never {
            $attempts++;
            throw new ProviderError('invalid request', status: 400);
        });

        try {
            $this->caller(4)->call(static fn (): mixed => $chain->invoke('x'));
            $this->fail('expected the error');
        } catch (ProviderError $e) {
            $this->assertSame(400, $e->status);
        }
        $this->assertSame(1, $attempts);
        $this->assertSame([], $this->sleeps);
    }
}
