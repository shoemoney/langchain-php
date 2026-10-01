<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnablePick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `RunnablePick` (`libs/langchain-core/src/runnables/base.ts`), reached through
 * `Runnable::pick()`, which upstream defines as `this.pipe(new RunnablePick(keys))`.
 *
 * Upstream's `_pick` semantics, transcribed from source rather than guessed:
 *   - a STRING key returns `input[key]`;
 *   - an ARRAY of keys returns `{key: input[key]}` for every key whose value is **not undefined**;
 *   - if NO key survives, the result is `undefined` — and `_transform` then yields nothing at all,
 *     which is why the all-missing case is a behaviour and not an edge case.
 */
#[CoversClass(RunnablePick::class)]
final class RunnablePickTest extends TestCase
{
    private function runnable(): Runnable
    {
        return new RunnableLambda(static fn (mixed $x): mixed => $x);
    }

    /** RED before the fix: `pick()` does not exist. */
    public function testASingleStringKeySelectsThatField(): void
    {
        $out = $this->runnable()->pick('a')->invoke(['a' => 1, 'b' => 2]);

        self::assertSame(1, $out);
    }

    public function testAnArrayOfKeysReturnsOnlyThoseFields(): void
    {
        $out = $this->runnable()->pick(['a', 'c'])->invoke(['a' => 1, 'b' => 2, 'c' => 3]);

        self::assertSame(['a' => 1, 'c' => 3], $out);
    }

    /** Upstream filters keys whose value is `undefined`; PHP's equivalent is a missing array key. */
    public function testMissingKeysAreDroppedFromAnArraySelection(): void
    {
        $out = $this->runnable()->pick(['a', 'nope'])->invoke(['a' => 1, 'b' => 2]);

        self::assertSame(['a' => 1], $out);
    }

    /** Upstream returns `undefined` when nothing survives, and `_transform` yields nothing. */
    public function testNoSurvivingKeysYieldsNothing(): void
    {
        $out = $this->runnable()->pick(['x', 'y'])->invoke(['a' => 1]);

        self::assertNull($out, 'nothing survived the pick, so there is no value to return');
    }

    /** CONTROL: without a pick, the runnable passes its input straight through. */
    public function testTheUnpickedRunnableIsUnaffected(): void
    {
        self::assertSame(['a' => 1], $this->runnable()->invoke(['a' => 1]));
    }
}
