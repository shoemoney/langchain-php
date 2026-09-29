<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableBranch;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableEach;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableParallel;
use LangChain\Runnables\RunnableSequence;
use LangChain\Runnables\RunnableWithFallbacks;
use LangChain\Schema\Document;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Runnable::class)]
#[CoversClass(RunnableSequence::class)]
#[CoversClass(RunnableLambda::class)]
#[CoversClass(RunnableParallel::class)]
#[CoversClass(RunnableBranch::class)]
#[CoversClass(RunnableBinding::class)]
#[CoversClass(RunnableWithFallbacks::class)]
#[CoversClass(RunnableEach::class)]
#[CoversClass(RunnableConfig::class)]
final class RunnableTest extends TestCase
{
    // ---- lambda ----------------------------------------------------------

    public function testLambdaPassesInputThrough(): void
    {
        $r = RunnableLambda::from(static fn (int $x): int => $x * 2);
        $this->assertSame(10, $r->invoke(5));
    }

    public function testLambdaSplatsListBoundArgs(): void
    {
        $r = RunnableLambda::from(
            static fn (int $a, int $b): int => $a + $b,
            [1, 2]
        );
        $this->assertSame(3, $r->invoke(null));
    }

    public function testLambdaWithInputAndBoundValue(): void
    {
        $r = RunnableLambda::from(
            static fn (int $x, int $add): int => $x + $add,
            [10]
        );
        $this->assertSame(15, $r->invoke(5));
    }

    public function testLambdaStreamsSingleDefaultChunk(): void
    {
        $r = RunnableLambda::from(static fn (int $x): int => $x * 3);
        $out = iterator_to_array($r->stream(2));
        $this->assertCount(1, $out);
        $this->assertSame([Runnable::CHANNEL_DEFAULT, 6], $out[0]);
    }

    public function testLambdaBatch(): void
    {
        $r = RunnableLambda::from(static fn (int $x): int => $x + 1);
        $this->assertSame([2, 3, 4], $r->batch([1, 2, 3]));
    }

    // ---- sequence --------------------------------------------------------

    public function testSequenceThreadsOutputIntoNextStep(): void
    {
        $seq = RunnableSequence::from([
            RunnableLambda::from(static fn (int $x): int => $x * 2),
            RunnableLambda::from(static fn (int $x): int => $x + 1),
        ]);
        $this->assertSame(11, $seq->invoke(5));
    }

    public function testEmptySequenceReturnsInputUnchanged(): void
    {
        $this->assertSame('x', (new RunnableSequence())->invoke('x'));
    }

    public function testSequenceNamesStepsForTracing(): void
    {
        $seq = RunnableSequence::from([
            ['double', RunnableLambda::from(static fn (int $x): int => $x * 2)],
            ['inc', static fn (int $x): int => $x + 1],
        ]);
        $this->assertSame('double', $seq->names[0]);
        $this->assertSame('inc', $seq->names[1]);
        $this->assertSame(3, $seq->invoke(1));
    }

    public function testSequenceStreamEmitsFirstStepChunksThenFinalValue(): void
    {
        $seq = RunnableSequence::from([
            RunnableLambda::from(static fn (int $x): int => $x * 2),
            RunnableLambda::from(static fn (int $x): int => $x + 1),
        ]);
        $out = iterator_to_array($seq->stream(5));
        // first step yields its value, then the composed result
        $this->assertSame([Runnable::CHANNEL_DEFAULT, 10], $out[0]);
        $this->assertSame([Runnable::CHANNEL_DEFAULT, 11], $out[1]);
    }

    public function testPipeAppendsStep(): void
    {
        $seq = RunnableLambda::from(static fn (int $x): int => $x * 2)
            ->pipe(RunnableLambda::from(static fn (int $x): int => $x + 1));
        $this->assertInstanceOf(RunnableSequence::class, $seq);
        $this->assertSame(7, $seq->invoke(3));
        $this->assertCount(2, $seq->steps);
    }

    // ---- parallel --------------------------------------------------------

    public function testParallelPassesWholeInputToEveryBranch(): void
    {
        // Matches the TS RunnableMap: every branch sees the whole keyed input
        // and picks what it needs. Narrowing to input[$key] would make the
        // canonical {context, question} RAG map impossible.
        $par = RunnableParallel::from([
            'a' => static fn (array $x): int => $x['a'],
            'b' => static fn (array $x): int => $x['b'] * 10,
        ]);
        $result = $par->invoke(['a' => 1, 'b' => 2]);
        $this->assertSame(['a' => 1, 'b' => 20], $result);
    }

    public function testParallelRejectsScalarInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RunnableParallel::from(['a' => static fn ($x) => $x])->invoke('scalar');
    }

    public function testParallelStreamKeysChannelsByBranchName(): void
    {
        $par = RunnableParallel::from([
            'a' => static fn (array $x): int => $x['a'],
            'b' => static fn (array $x): int => $x['b'],
        ]);
        $out = iterator_to_array($par->stream(['a' => 1, 'b' => 2]));
        $this->assertSame([['a', 1], ['b', 2]], $out);
    }

    public function testParallelBuilderAddsBranchesFluently(): void
    {
        $par = RunnableParallel::from([])->add('x', static fn (array $v): int => $v['x'] + 1);
        $this->assertSame(['x' => 4], $par->invoke(['x' => 3]));
    }

    // ---- branch ----------------------------------------------------------

    public function testBranchTakesFirstMatchingCondition(): void
    {
        $branch = (new RunnableBranch())
            ->when(static fn (int $x): bool => $x > 10, static fn (int $x): int => 100)
            ->when(static fn (int $x): bool => $x > 5, static fn (int $x): int => 50)
            ->default(static fn (int $x): int => 0);

        $this->assertSame(100, $branch->invoke(20));
        $this->assertSame(50, $branch->invoke(7));
        $this->assertSame(0, $branch->invoke(1));
    }

    public function testBranchWithoutMatchAndNoDefaultThrows(): void
    {
        $branch = (new RunnableBranch())->when(static fn (): bool => false, static fn (): string => 'x');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No branch matched');
        $branch->invoke(1);
    }

    // ---- binding ---------------------------------------------------------

    public function testBindingMergesConfigUnderCallerConfig(): void
    {
        $seen = null;
        $target = RunnableLambda::from(function (int $x) use (&$seen): int {
            $seen = $GLOBALS['capturedConfig'] ?? null;

            return $x;
        });

        $bound = new RunnableBinding($target, [], ['tags' => ['bound'], 'run_name' => 'bound-name']);
        $bound->invoke(1, new RunnableConfig(tags: ['caller']));

        // The lambda received the merged config; assert via the binding itself.
        $merged = (function () use ($bound) {
            return $bound->mergeConfig(new RunnableConfig(tags: ['caller']));
        })->call($bound);

        $this->assertContains('bound', $merged->tags);
        $this->assertContains('caller', $merged->tags);
        $this->assertSame('bound-name', $merged->runName);
    }

    public function testBindingPassesThroughWhenNoConfig(): void
    {
        $bound = new RunnableBinding(RunnableLambda::from(static fn (int $x): int => $x + 1));
        $this->assertSame(4, $bound->invoke(3));
    }

    // ---- fallbacks -------------------------------------------------------

    public function testFallbacksUsesFirstSuccess(): void
    {
        $flaky = new class extends Runnable {
            public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
            {
                throw new \RuntimeException('primary down');
            }
        };
        $chain = new RunnableWithFallbacks(
            $flaky,
            [RunnableLambda::from(static fn (int $x): int => $x * 2)]
        );
        $this->assertSame(8, $chain->invoke(4));
    }

    public function testFallbacksPropagatesLastErrorWhenAllFail(): void
    {
        $a = new class extends Runnable {
            public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
            {
                throw new \RuntimeException('first');
            }
        };
        $b = new class extends Runnable {
            public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
            {
                throw new \LogicException('second');
            }
        };
        $chain = new RunnableWithFallbacks($a, [$b]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('second');
        $chain->invoke(1);
    }

    // ---- each ------------------------------------------------------------

    public function testEachAppliesRunnableToEveryElement(): void
    {
        $each = new RunnableEach(RunnableLambda::from(static fn (string $s): string => strtoupper($s)));
        $this->assertSame(['A', 'B'], $each->invoke(['a', 'b']));
    }

    public function testEachRejectsScalarInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RunnableEach(RunnableLambda::from(static fn ($x) => $x)))->invoke('nope');
    }

    public function testEachStreamsWithIndexChannel(): void
    {
        $each = new RunnableEach(RunnableLambda::from(static fn (int $x): int => $x));
        $out = iterator_to_array($each->stream([7, 8]));
        $this->assertSame([[0, 7], [1, 8]], $out);
    }

    // ---- config ----------------------------------------------------------

    public function testConfigFromArrayAcceptsBothKeyStyles(): void
    {
        $c = RunnableConfig::fromArray(['tags' => ['a'], 'recursion_limit' => 5, 'maxConcurrency' => 3]);
        $this->assertSame(['a'], $c->tags);
        $this->assertSame(5, $c->recursionLimit);
        $this->assertSame(3, $c->maxConcurrency);
    }

    public function testConfigPassesThroughInstance(): void
    {
        $c = new RunnableConfig();
        $this->assertSame($c, RunnableConfig::fromArray($c));
        $this->assertNull(RunnableConfig::fromArray(null));
    }

    public function testConfigWithDoesNotMutateOriginal(): void
    {
        $a = new RunnableConfig(tags: ['t']);
        $b = $a->with(['tags' => ['u']]);
        $this->assertSame(['t'], $a->tags);
        $this->assertSame(['u'], $b->tags);
    }

    public function testConfigWithSkipsNullOverrides(): void
    {
        $a = new RunnableConfig(runName: 'keep');
        $this->assertSame('keep', $a->with(['run_name' => null])->runName);
    }

    public function testConfigForChildAssignsParentRunId(): void
    {
        $parent = new RunnableConfig(runId: ['parent-1']);
        $child = $parent->forChild('child', 'child-1');
        $this->assertSame('child-1', $child->runId[0]);
        $this->assertSame('parent-1', $child->runIdParent);
    }
}
