<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * A linear chain of runnables: `a | b | c`.
 *
 * Port of `RunnableSequence` from `@langchain_core/runnables`.
 *
 * `invoke` threads each step's output into the next. The step names double as
 * trace labels, so a chain reports as `RunnableSequence > prompt > model` in a
 * trace rather than as an anonymous blob — which is most of what makes LCEL
 * debuggable.
 */
class RunnableSequence extends Runnable
{
    /** @var list<RunnableInterface> */
    public array $steps;

    /** @var list<string> Explicit step labels, when the caller supplied them. */
    public array $names;

    /**
     * @param list<RunnableInterface> $steps
     * @param list<string>            $names
     */
    public function __construct(array $steps = [], array $names = [])
    {
        $this->steps = array_values($steps);
        $this->names = array_values($names);
    }

    /**
     * Build a sequence from runnable-likes: a RunnableInterface, a callable, or
     * a 2-tuple of `[name, runnableLike]`.
     *
     * @param list<mixed> $steps
     */
    public static function from(array $steps): self
    {
        $runnables = [];
        $names = [];
        foreach ($steps as $step) {
            // See coerceToRunnable(): the list check is what stops a named
            // two-key map from indexing a key 0 that does not exist.
            if (is_array($step) && array_is_list($step) && count($step) === 2 && is_string($step[0])) {
                $names[] = $step[0];
                $runnables[] = coerceToRunnable($step[1]);
                continue;
            }
            $r = coerceToRunnable($step);
            $names[] = $r->getName();
            $runnables[] = $r;
        }

        return new self($runnables, $names);
    }

    public function getName(): string
    {
        return 'RunnableSequence';
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        foreach ($this->steps as $i => $step) {
            $input = $step->invoke($input, $this->stepConfig($config, $i));
        }

        return $input;
    }

    /**
     * The config a sequence hands to step N.
     *
     * WHAT THIS DOES NOW, stated first because a reader — or a reviewer — skims:
     * it preserves the caller's `runName` and records the step as a TAG.
     *
     *     $tag = 'seq:step:' . ($index + 1);
     *     return $config === null
     *         ? (new RunnableConfig())->with(['tags' => [$tag]])
     *         : $config->forChild(null)->with(['tags' => array_merge($config->tags, [$tag])]);
     *
     * Upstream records the step in the child CALLBACK manager
     * (langchain-core/src/runnables/base.ts:1982-1985):
     *
     *     patchConfig(config, { callbacks: runManager?.getChild(`seq:step:${i + 1}`) })
     *
     * This port does not thread a callback manager through sequences, so `tags` is
     * the carrier available. That is a documented divergence — a tag is a label,
     * not an identity — recorded in PORT_STATUS under Known non-exact behaviours.
     *
     * ---------------------------------------------------------------------
     * HISTORY — none of the following describes the code above. It is kept
     * because the two defects were stacked and the order mattered, and because
     * deleting this note would leave the next reader unable to tell why a
     * forChild() call takes a null argument.
     *
     * An earlier version called `$config->forChild('seq:step:' . ($index + 1))`,
     * and forChild() assigns `run_name` — so the step tagging overwrote the name
     * it was annotating. Measured on an assembled structured-output pipeline named
     * `pull_person`: `runName = "seq:step:1"` with `options = {"runName" =>
     * "pull_person"}`, silently, because Run::name() falls back to the component
     * id and then the run type.
     *
     * A first attempt at removing it was reverted having measured NULL instead of
     * the bound name, and it was wrongly concluded the loss was upstream of this
     * method. It was not: the bind slot was ALSO wrong at that time, so there was
     * no name to preserve, and a preserved-but-absent name is indistinguishable
     * from a clobbered one until exactly one cause is removed.
     * ---------------------------------------------------------------------
     */
    private function stepConfig(?RunnableConfig $config, int $index): ?RunnableConfig
    {
        $tag = 'seq:step:' . ($index + 1);

        if ($config === null) {
            return (new RunnableConfig())->with(['tags' => [$tag]]);
        }

        return $config->forChild(null)->with([
            'tags' => array_merge($config->tags, [$tag]),
        ]);
    }

    /**
     * Stream every step, in order, yielding each step's own chunks.
     *
     * Each step is STREAMED — not invoked — and the previous step's
     * default-channel chunk becomes the next step's input, which is what upstream
     * does (langchain-core/src/runnables/base.ts:2109-2126).
     *
     * This docblock previously said "only the *first* step streams, then feed the
     * rest eagerly", which described the code as it was BEFORE that rewrite: the
     * body changed and this text did not, so it described a method this class no
     * longer had. Leaving it was the same mistake as the fix comments elsewhere in
     * this repo — a comment that keeps narrating behaviour the code no longer has.
     */
    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        $steps = $this->steps;
        if ($steps === []) {
            return;
        }

        // Every step is STREAMED, not just the first, and each step's output
        // becomes the next step's input. Upstream's loop (base.ts:2109-2126)
        // does exactly this: it calls `step.stream(stepInput, config)` for
        // every i, yields every chunk that step produces, and carries the
        // default-channel chunk forward as the next `stepInput`.
        //
        // This used to stream the first step and INVOKE the rest, which
        // collapsed `prompt | model` to a single final AIMessage. Measured
        // before the fix, with a two-content-chunk SSE body:
        //
        //   prompt | model  -> ChatPromptValue, then AIMessage 'Hello'
        //   model alone     -> 'Hel', 'lo', ''
        //
        // so the most common shape in the library emitted ONE chunk where the
        // model emits three. A lambda hides this, because RunnableLambda has
        // no stream() of its own and yields a single chunk either way — which
        // is why the suite never saw it.
        $lastOutput = null;
        $stepInput = $input;

        foreach ($steps as $i => $step) {
            $stepConfig = $this->stepConfig($config, $i);
            $sawChunk = false;

            foreach ($step->stream($stepInput, $stepConfig) as $pair) {
                [$channel, $chunk] = $pair;
                $sawChunk = true;
                if ($channel === self::CHANNEL_DEFAULT) {
                    $lastOutput = $chunk;
                }

                yield $pair;
            }

            // A step that emitted nothing is invoked rather than skipped, so a
            // step which declines to stream still contributes its value and the
            // chain does not silently lose a link.
            if (!$sawChunk) {
                $lastOutput = $step->invoke($stepInput, $stepConfig);
                yield [self::CHANNEL_DEFAULT, $lastOutput];
            }

            $stepInput = $lastOutput;
        }

        // `$lastOutput` is what the NEXT step receives. No "did a value come out" flag is consulted,
        // and none is needed here: this method yields whatever the steps yielded, so a chain whose last
        // step legitimately returns `null` yields that `null` faithfully and `stream()` agrees with
        // `invoke()`.
        //
        // A `$haveOutput` flag used to sit here, assigned three times and read by NOTHING, under a comment
        // claiming it was "emitted on the did-a-value-come-out question". Removed in 445: the comment
        // documented a mechanism the code never implemented, and a green suite after removal is the proof
        // it was dead rather than merely unread.
        //
        // The gap that flag was evidently meant to cover is REAL and still open: `$lastOutput` is not reset
        // per step, so a step yielding only NON-DEFAULT channels leaves the previous step's value in place
        // and the step after it is handed that stale value. Upstream cannot hit this because it chains
        // each step's `transform()` onto the PREVIOUS STEP'S GENERATOR and never feeds a return value
        // forward (`base.ts:2110-2124`) — there is no `$stepInput = $lastOutput` there at all. Fixing it
        // means adopting generator piping, not reviving this flag. See PORT_STATUS rows 443/444 and 442,
        // which are one design question.
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return $this->batchEach($inputs, $config, $options);
    }

    public function pipe(RunnableInterface $next): RunnableSequence
    {
        $seq = new self(
            array_merge($this->steps, [$next]),
            array_merge($this->names, [$next->getName()])
        );

        return $seq;
    }
}
