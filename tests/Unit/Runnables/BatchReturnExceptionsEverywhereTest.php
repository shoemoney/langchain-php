<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableAssign;
use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableParallel;
use LangChain\Runnables\RunnableSequence;
use LangChain\Runnables\RunnableWithFallbacks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `returnExceptions` is honoured by `Runnable::batch()` and was IGNORED by the other eight
 * `batch()` implementations (iteration 408). So the option was real, documented and tested, and inert
 * on every composition type — which is where a caller would actually reach for it.
 *
 * This test drives EVERY implementation, so a ninth implementation that forgets the option fails here
 * rather than in a caller's batch.
 */
#[CoversClass(Runnable::class)]
final class BatchReturnExceptionsEverywhereTest extends TestCase
{
    /** A runnable that fails on the second item and succeeds on the others. */
    private function flaky(): Runnable
    {
        $seen = 0;

        return new RunnableLambda(static function (mixed $x) use (&$seen): string {
            ++$seen;

            return $seen === 2 ? throw new \RuntimeException('item 2') : 'ok';
        });
    }

    /** @return iterable<string, array{RunnableInterface}> */
    public static function implementations(): iterable
    {
        $flaky = static fn (): Runnable => self::makeFlaky();

        yield 'Runnable (via a lambda)' => [$flaky()];
        yield 'RunnableSequence' => [new RunnableSequence([$flaky()])];
        yield 'RunnableWithFallbacks' => [new RunnableWithFallbacks($flaky())];
        // RunnableBinding binds the FLAKY runnable, not a pass-through. An earlier version of this
        // fixture bound `fn($x) => $x`, which never fails, so there was no Throwable to count and the
        // data set failed for a reason that had nothing to do with returnExceptions — the same
        // wrong-fixture failure 409 recorded, and the reason 410 had to correct 409's headline count.
        yield 'RunnableBinding' => [$flaky()->bind()];
        yield 'RunnablePick' => [$flaky()->pick('a')];
        yield 'RunnableParallel' => [new RunnableParallel(['k' => $flaky()])];
        // RunnableAssign wraps a RunnableParallel, so the parallel must actually contain
        // something that can fail — an empty one produces no results and no errors to count.
        yield 'RunnableAssign' => [new RunnableAssign(new RunnableParallel(['k' => $flaky()]))];
    }

    private static function makeFlaky(): Runnable
    {
        $seen = 0;

        return new RunnableLambda(static function (mixed $x) use (&$seen): string {
            ++$seen;

            return $seen === 2 ? throw new \RuntimeException('item 2') : 'ok';
        });
    }

    /**
     * The half `returnExceptions` testing cannot see.
     *
     * Upstream's `Runnable.batch` is `inputs.map(...)` then `Promise.all(...)` (`base.ts`), which
     * ALWAYS yields a list — string keys on `inputs` are discarded. `RunnableSequence` and
     * `RunnableWithFallbacks` currently iterate `foreach ($inputs as $i => …)` and PRESERVE them, so a
     * string-keyed batch returns a string-keyed array where upstream returns a list. Nothing errors; the
     * shape is simply different.
     *
     * Without this assertion a fix that preserved keys everywhere would pass every `returnExceptions`
     * assertion above while still diverging.
     */
    #[DataProvider('keyPreservingImplementations')]
    public function testAStringKeyedBatchStillComesBackAsAList(mixed $input): void
    {
        $r = self::makeFlaky();
        $out = $r->batch($input, null, ['returnExceptions' => true]);

        self::assertSame(
            [0, 1, 2],
            array_keys($out),
            'upstream yields a list from inputs.map(...) + Promise.all(...), so string keys are dropped',
        );
    }

    /** @return iterable<string, array{list<string>}> */
    public static function keyPreservingImplementations(): iterable
    {
        yield 'list input' => [['a', 'b', 'c']];
        yield 'string-keyed input' => [['x' => 'a', 'y' => 'b', 'z' => 'c']];
    }

    #[DataProvider('implementations')]
    public function testEveryImplementationHonoursReturnExceptions(RunnableInterface $r): void
    {
        $results = $r->batch(['a', 'b', 'c'], null, ['returnExceptions' => true]);

        $errors = array_filter($results, static fn (mixed $r): bool => $r instanceof \Throwable);

        self::assertCount(
            1,
            $errors,
            'every batch() implementation must honour returnExceptions, not just Runnable::batch()',
        );
    }
}
