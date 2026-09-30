<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableSequence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunnableSequence::class)]
final class RunnableSequenceStepTagTest extends TestCase
{
    /**
     * A chain whose last step legitimately returns `null` must still emit it.
     *
     * `stream()` gated on `$lastOutput !== null`, so a null result produced
     * nothing while `invoke()` returned it faithfully — the two disagreed, and
     * a consumer could not tell "the chain returned null" from "it returned
     * nothing". Upstream separates the two with an explicit `undefined`
     * sentinel (`base.ts:2126`), which PHP's single null cannot express; the
     * "was a value produced" question is now asked directly.
     */
    public function testANullFinalOutputIsStillEmitted(): void
    {
        $sequence = new RunnableSequence([new TaggingSpy(), new NullSpy()]);

        self::assertNull($sequence->invoke('x'), 'invoke() returns null faithfully');

        $emitted = [];
        foreach ($sequence->stream('x') as [, $value]) {
            $emitted[] = $value;
        }

        self::assertSame(['step1', null], $emitted, 'the final null must reach the consumer');
    }

    /**
     * Each step is tagged so a trace can attribute a run to the step that
     * produced it. Upstream does this at five sites (`seq:step:N`); the stored
     * names were otherwise dead data — written by the constructor, extended by
     * `pipe()`, read by nothing.
     */
    public function testEveryStepIsTaggedWithItsPosition(): void
    {
        $steps = [new PassthroughSpy('a'), new PassthroughSpy('b'), new PassthroughSpy('c')];
        (new RunnableSequence($steps))->invoke('x', new RunnableConfig(runName: 'root'));

        foreach ($steps as $i => $step) {
            self::assertSame(['seq:step:' . ($i + 1)], $step->seen, 'step ' . $i . ' lost its tag');
        }
    }

    /**
     * The tag must survive a caller that supplies no config at all.
     */
    public function testStepsAreTaggedEvenWithoutACallerConfig(): void
    {
        $steps = [new PassthroughSpy('a'), new PassthroughSpy('b')];
        (new RunnableSequence($steps))->invoke('x');

        self::assertSame(['seq:step:1'], $steps[0]->seen);
        self::assertSame(['seq:step:2'], $steps[1]->seen);
    }
}

/** Passes its input through, recording the config it was given. */
class PassthroughSpy extends Runnable
{
    /** @var list<?string> */
    public array $seen = [];

    public function __construct(private readonly string $tag)
    {
    }

    public function getName(): string
    {
        return $this->tag;
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        // Records the CARRIER, not the destination: the step tag is a label and
        // now travels in `config->tags`, matching where the code puts it. The
        // previous spy recorded `runName`, which is precisely the field the fix
        // stopped writing to — a spy that watches the old carrier would have gone
        // quietly blank instead of failing.
        foreach ($config?->tags ?? [] as $t) {
            if (is_string($t) && str_starts_with($t, 'seq:step:')) {
                $this->seen[] = $t;
            }
        }

        return $input;
    }
}

/** Emits a token, so the first step contributes real content. */
class TaggingSpy extends Runnable
{
    public function getName(): string
    {
        return 'tagging';
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        return 'step1';
    }
}

/** A step whose whole purpose is to return null. */
class NullSpy extends Runnable
{
    public function getName(): string
    {
        return 'null-step';
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        return null;
    }
}
