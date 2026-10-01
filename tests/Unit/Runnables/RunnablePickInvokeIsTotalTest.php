<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\RunnablePick;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `RunnablePick::invoke()` cannot throw, so a `returnExceptions` fixture for it would be VACUOUS.
 *
 * Iteration 431 found that the `BatchReturnExceptionsEverywhereTest` provider had a case labelled
 * "RunnablePick" which never constructed one: `Runnable::pick()` is
 * `return $this->pipe(new RunnablePick($keys))`, so the returned object is a `RunnableSequence`. The
 * obvious repair — construct `new RunnablePick(...)` directly and add it to the provider — would have
 * been WRONG, and this test exists to say why.
 *
 * `RunnablePick::invoke()` is total. A missing key yields `null`, and a non-array input yields `null`,
 * mirroring upstream `_pick` (base.ts:3374-3383), which maps keys and filters out `undefined` rather
 * than raising. So `batch()` on a direct `RunnablePick` cannot produce a single Throwable: a
 * `returnExceptions` case would count zero errors and pass identically whether the flag was honoured or
 * ignored. That is a test that cannot fail, which is the defect this loop has spent its run removing.
 *
 * The corollary is that `RunnablePick::batch()`'s missing `returnExceptions` handling is a LATENT
 * divergence, not a live bug — unobservable through the public surface while `invoke()` stays total. It
 * is recorded in PORT_STATUS rather than fixed, because a fix here could not be mutation-verified: there
 * is no input that makes the flag observable.
 */
#[CoversNothing]
final class RunnablePickInvokeIsTotalTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}> inputs that a naive fixture might assume throw
     */
    public static function nonThrowingInputs(): iterable
    {
        yield 'missing key' => [['a' => 1]];
        yield 'empty array' => [[]];
        yield 'non-array scalar' => ['a string'];
        yield 'null' => [null];
        yield 'int' => [7];
    }

    #[DataProvider('nonThrowingInputs')]
    public function testInvokeReturnsNullRatherThanThrowing(mixed $input): void
    {
        $pick = new RunnablePick('missing');

        $result = $pick->invoke($input);

        self::assertNull(
            $result,
            'RunnablePick::invoke() returned something for an input upstream would leave undefined; if this '
                . 'now throws or returns a value, a returnExceptions fixture for RunnablePick stops being '
                . 'vacuous and this test should be revisited.',
        );
    }

    public function testTheMultiKeyFormAlsoFiltersInsteadOfThrowing(): void
    {
        $pick = new RunnablePick(['present', 'absent']);

        // Upstream keeps a present-but-null value and drops undefined ones (base.ts:3379-3381).
        self::assertSame(['present' => null], $pick->invoke(['present' => null]));
        self::assertNull($pick->invoke(['other' => 1]), 'all keys missing yields undefined upstream');
    }
}
