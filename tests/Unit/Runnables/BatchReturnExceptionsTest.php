<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `batchOptions.returnExceptions` — reported as a defect by reviewers in cycles 3, 4 and 5, and refused
 * by this loop three times on the grounds that the port's own docblock said the option was IGNORED.
 *
 * That refusal was wrong, and it is the same mistake as the runName thread: **a docblock saying "this
 * option is accepted and ignored" is a description of a GAP, not a justification for it.** Upstream
 * implements it (`base.ts:240-241` documents the parameter; `:281` is
 * `if (batchOptions?.returnExceptions) { … }`), so the port is simply missing it.
 *
 * With the option unset, upstream's default is to THROW on the first failure — which is what the port
 * already does, and is pinned here so a fix cannot quietly change the default.
 */
#[CoversClass(RunnableInterface::class)]
final class BatchReturnExceptionsTest extends TestCase
{
    private function runnable(): RunnableInterface
    {
        return new RunnableLambda(static function (mixed $x): string {
            if ($x === 'bad') {
                throw new \RuntimeException('item failed');
            }

            return 'ok:' . $x;
        });
    }

    /** CONTROL: the default must keep throwing — this is upstream's documented default. */
    public function testTheDefaultThrowsOnTheFirstFailure(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->runnable()->batch(['a', 'bad', 'c']);
    }

    /** RED before the fix: the option is accepted and ignored, so this throws instead of returning. */
    public function testReturnExceptionsYieldsTheThrowableInPlaceOfTheResult(): void
    {
        $results = $this->runnable()->batch(['a', 'bad', 'c'], null, ['returnExceptions' => true]);

        self::assertCount(3, $results);
        self::assertSame('ok:a', $results[0]);
        self::assertInstanceOf(\RuntimeException::class, $results[1]);
        self::assertSame('item failed', $results[1]->getMessage());
        self::assertSame('ok:c', $results[2], 'a failure must not stop the remaining items');
    }

    /** Control: with the option set and nothing failing, behaviour is unchanged. */
    public function testReturnExceptionsChangesNothingWhenNothingFails(): void
    {
        $results = $this->runnable()->batch(['a', 'b'], null, ['returnExceptions' => true]);

        self::assertSame(['ok:a', 'ok:b'], $results);
    }
}
