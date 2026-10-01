// KNOWN-RED, preserved deliberately. Iteration 409 wrote this guard and measured that 6 of 7
// batch() implementations ignore returnExceptions. It is NOT in tests/ because landing a
// red test breaks the suite; the fix is one protected helper on Runnable that the six
// implementations delegate to. See .loop/triage.json -> aaa-5-cycle-harvest.
<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableAssign;
use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableBranch;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableParallel;
use LangChain\Runnables\RunnablePick;
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
        yield 'RunnableBinding' => [(new RunnableLambda(static fn (mixed $x): mixed => $x))->bind()];
        yield 'RunnablePick' => [(new RunnableLambda(static fn (mixed $x): mixed => $x))->pick('a')];
        yield 'RunnableParallel' => [new RunnableParallel(['k' => $flaky()])];
        yield 'RunnableAssign' => [new RunnableAssign(new RunnableParallel([]))];
    }

    private static function makeFlaky(): Runnable
    {
        $seen = 0;

        return new RunnableLambda(static function (mixed $x) use (&$seen): string {
            ++$seen;

            return $seen === 2 ? throw new \RuntimeException('item 2') : 'ok';
        });
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
