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

    /**
     * A scalar is passed straight through to every branch.
     *
     * This used to assert the opposite — that a non-array input was rejected.
     * Upstream's `RunnableMap.invoke` applies no such check: it hands whatever it
     * was given to each branch. Rejecting scalars made `{raw: llm}`, the first
     * step of every `includeRaw` structured-output pipeline, impossible to use
     * with the string input that is by far the most common thing passed to a
     * model.
     */
    public function testParallelPassesScalarInputToEveryBranch(): void
    {
        $result = RunnableParallel::from([
            'a' => static fn (mixed $x): string => 'saw:' . $x,
            'b' => static fn (mixed $x): int => strlen((string) $x),
        ])->invoke('scalar');

        $this->assertSame(['a' => 'saw:scalar', 'b' => 6], $result);
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

    /**
     * `transform()` yields one pair per inner chunk — it does NOT gather.
     *
     * Upstream's base `Runnable.transform` (langchainjs
     * `libs/langchain-core/src/runnables/base.ts:659-671`) accumulates every
     * chunk through `_concatOutputChunks` and then yields the SINGLE gathered
     * result. The port's `Runnable::transform` (Runnable.php:66-73) is a pure
     * pass-through, so a three-item input yields three pairs where upstream
     * yields one.
     *
     * This pins the port's CURRENT behaviour rather than endorsing it. The
     * divergence is real and recorded; what it does not decide is whether a
     * caller can OBSERVE it, because a consumer that CONCATENATES sees the same
     * text either way while one that counts chunks or reads the first value
     * early does not. The port's own standard, stated in
     * `BaseTransformOutputParser`'s docblock, is to match "the contract the
     * TypeScript original exposes" — so the open question is which observable
     * contract `transform` presents, and this test makes the present one
     * explicit instead of leaving it unstated. An unstated contract is what let
     * the divergence survive two portings unnoticed.
     */
    public function testTransformYieldsOnePairPerInnerChunk(): void
    {
        $lambda = RunnableLambda::from(static fn (string $s): string => strtoupper($s));

        $pairs = iterator_to_array($lambda->transform(['a', 'b', 'c']), false);

        self::assertCount(3, $pairs, 'one pair per input item — the port streams rather than gathers');
        self::assertSame(['A', 'B', 'C'], array_map(static fn (array $p): mixed => $p[1], $pairs));
    }


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

    /**
     * A sequence must stream EVERY step, not just the first.
     *
     * `prompt | model` is the library's most common shape and it used to emit
     * the prompt's chunk and then ONE collapsed final AIMessage, because
     * RunnableSequence streamed the first step and invoked the rest. Measured
     * before the fix with a two-content-chunk SSE body:
     *
     *   prompt | model  ->  ChatPromptValue, then AIMessage 'Hello'
     *   model alone     ->  'Hel', 'lo', ''
     *
     * A RunnableLambda cannot see this — it has no stream() of its own and
     * yields a single chunk either way — so the regression has to use a step
     * that actually streams incrementally, which in this port means a chat
     * model.
     */
    public function testASequenceStreamsEveryStepNotJustTheFirst(): void
    {
        $e = static fn (array $d): string => "data: " . json_encode($d) . "\n\n";
        $sse = $e(['id' => 'x', 'model' => 'm', 'choices' => [['index' => 0, 'delta' => ['content' => 'Hel']]]])
            . $e(['id' => 'x', 'model' => 'm', 'choices' => [['index' => 0, 'delta' => ['content' => 'lo']]]])
            . $e(['id' => 'x', 'model' => 'm', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]])
            . "data: [DONE]\n\n";

        $http = new class ($sse) implements \LangChain\Utils\Http\HttpClient {
            public function __construct(private string $sse) {}

            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \LangChain\Utils\Http\HttpResponse
            {
                return new \LangChain\Utils\Http\HttpResponse(200, [], json_encode(['id' => 'x', 'model' => 'm', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => 'stop']]]));
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                yield $this->sse;

                return;
            }
        };

        $chain = \LangChain\Prompts\ChatPromptTemplate::fromTemplate('say hi')
            ->pipe(new \LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]));

        $texts = [];
        foreach ($chain->stream([]) as $pair) {
            $chunk = $pair[1];
            if (is_object($chunk) && property_exists($chunk, 'content') && is_string($chunk->content)) {
                $texts[] = $chunk->content;
            }
        }

        // The model's own chunks must survive the sequence, not be collapsed
        // into one final message.
        $this->assertSame(['Hel', 'lo', ''], $texts);
    }

    /**
     * A named two-key map must not be probed for a key 0 it does not have.
     *
     * The `[name, runnable]` tuple is positional by definition, so recognising it
     * requires `count() === 2` AND a list. Checking only the count let a named
     * map such as `['a' => $one, 'b' => $one]` reach `$thing[0]` inside the type
     * test, which raised `Warning: Undefined array key 0` — twice, once here and
     * once in `coerceToRunnable()` — before the clean InvalidArgumentException.
     *
     * PHPUnit turns that warning into a failure, so the assertion below covers
     * the warning as well as the exception: pre-fix, this test dies on the
     * warning rather than reaching the expectException.
     */
    public function testANamedTwoKeyMapThrowsCleanlyWithoutAKeyZeroWarning(): void
    {
        $one = new RunnableLambda(static fn (int $x): int => $x);

        // PHPUnit REPORTS a warning rather than failing on one, so the first
        // version of this test was green against the broken code — the
        // mutation survived with "OK, but there were issues! ... Warnings: 2".
        // A warning that does not fail is a warning nobody sees, so the handler
        // below turns any diagnostic raised inside the call into an exception
        // and lets the assertion below check that none was raised at all.
        $diagnostics = [];
        set_error_handler(static function (int $no, string $msg) use (&$diagnostics): bool {
            $diagnostics[] = $msg;

            return true;
        });

        try {
            RunnableSequence::from(['p' => ['a' => $one, 'b' => $one]]);
            $thrown = null;
        } catch (\InvalidArgumentException $e) {
            $thrown = $e;
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $diagnostics, 'a named map must not be probed for a key 0');
        $this->assertInstanceOf(\InvalidArgumentException::class, $thrown);
    }

    /**
     * The positional `[name, runnable]` tuple the guard exists to recognise must
     * keep working — a list check that rejected every two-element array would
     * "fix" the warning by breaking the feature.
     */
    public function testThePositionalNameRunnableTupleStillCoerces(): void
    {
        $one = new RunnableLambda(static fn (int $x): int => $x);

        $sequence = RunnableSequence::from([['named', $one]]);

        $this->assertSame(5, $sequence->invoke(5));
    }

    /**
     * A branch condition must receive the caller's config.
     *
     * Upstream coerces each condition to a RunnableLike and invokes it as
     * `condition.invoke(input, patchConfig(config, {callbacks:
     * runManager?.getChild(`condition:${i + 1}`)}))` (branch.ts:152-161). This
     * port called conditions as `$condition($input)`, so a condition could never
     * observe the config — it could not read tags, metadata or `configurable`,
     * and could not participate in tracing at all.
     *
     * The second test below is the important one: a one-argument condition must
     * still be invoked with one argument, or the fix would break every existing
     * condition in the library.
     */
    public function testABranchConditionReceivesTheCallersConfig(): void
    {
        $seen = null;
        $capturing = static function ($input, $config) use (&$seen): bool {
            $seen = $config;

            return true;
        };

        $branch = new RunnableBranch([
            [$capturing, RunnableLambda::from(static fn (): string => 'matched')],
        ]);

        $branch->invoke('in', new RunnableConfig(tags: ['probe']));

        $this->assertInstanceOf(RunnableConfig::class, $seen);
        $this->assertContains('probe', $seen->tags);
    }

    public function testAOneArgumentBranchConditionStillReceivesExactlyOneArgument(): void
    {
        $branch = new RunnableBranch([
            [static fn (string $x): bool => $x === 'in', RunnableLambda::from(static fn (): string => 'legacy')],
        ]);

        $this->assertSame('legacy', $branch->invoke('in'));
    }

    public function testAVariadicBranchConditionReceivesTheConfig(): void
    {
        $count = 0;
        $variadic = static function (...$args) use (&$count): bool {
            $count = count($args);

            return true;
        };

        $branch = new RunnableBranch([
            [$variadic, RunnableLambda::from(static fn (): string => 'variadic')],
        ]);

        $branch->invoke('in', new RunnableConfig(tags: ['probe']));

        $this->assertSame(2, $count);
    }
}
