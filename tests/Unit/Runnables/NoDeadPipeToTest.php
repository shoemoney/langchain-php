<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `pipeTo()` is gone, and this is why it must not come back.
 *
 * It promised "feed this runnable's output into a callback rather than another
 * runnable" and did neither half. The body built a `RunnableLambda` around an
 * IDENTITY callable and passed the user's callback as `['func' => $fn]` — which
 * is a bound TEMPLATE VARIABLE, not a callback override
 * (`RunnableLambda::__construct(callable $func, array $bound)`).
 *
 * Measured: `(new SomeRunnable)->pipeTo($fn)` then `->invoke('A')` returned
 * `'A'` unchanged and `$fn` was never invoked. `$this` never ran either, so
 * there was no output to feed anything.
 *
 * It had no callers anywhere in `src/` or `tests/`, and upstream has no
 * counterpart at all — `runnables/base.ts` defines no `pipeTo`, only `pipe` and
 * `then`. So it was invented API in a port whose rule is fidelity, and the
 * invented API was broken. Removal is safer than repair: a caller who wants this
 * writes `->pipe(new RunnableLambda($fn))`, which is upstream-shaped and runs.
 */
#[CoversClass(RunnableLambda::class)]
final class NoDeadPipeToTest extends TestCase
{
    public function testPipeToDoesNotExist(): void
    {
        self::assertFalse(
            method_exists(RunnableInterface::class, 'pipeTo'),
            'pipeTo was a public no-op with no callers and no upstream counterpart; it must not return',
        );
    }

    /**
     * The upstream-shaped replacement actually works — which is the reason the
     * removal is safe rather than merely tidy.
     */
    public function testPipeWithALambdaIsTheWorkingEquivalent(): void
    {
        $seen = null;
        $receiver = new RunnableLambda(static fn (string $in): string => $in . '-processed');
        $callback = new RunnableLambda(static function (string $in) use (&$seen): string {
            $seen = $in;

            return $in . '-seen';
        });

        $out = $receiver->pipe($callback)->invoke('A');

        self::assertSame('A-processed-seen', $out);
        self::assertSame('A-processed', $seen, 'the callback must receive the RECEIVER output, not the raw input');
    }
}
