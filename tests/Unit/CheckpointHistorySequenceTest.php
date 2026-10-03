<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use LangGraph\Channels\AnyValue;
use LangGraph\Pregel\Checkpoint\MemorySaver;
use LangGraph\State\StateGraph;
use LangChain\Runnables\RunnableConfig;
use PHPUnit\Framework\TestCase;

/**
 * A thread's checkpoint history must be exactly the sequence upstream writes.
 *
 * This guards SILENT HISTORY LOSS, which is the failure class that matters most in
 * a durable-execution engine and the one hardest to notice: nothing throws, a resume
 * still works, and the only symptom is that a step you can no longer travel back to
 * is gone.
 *
 * WHAT WAS WRONG, measured against LangGraph JS 1.4.18 on the same two-node graph:
 *
 *     upstream                   -1 (input), 0, 1, 2      4 checkpoints
 *     this port, as it was       -1 (input), 0, 1, 3      4 checkpoints
 *
 * Step 2 had been OVERWRITTEN and replaced by a step 3 that never ran a superstep.
 * The head held the right final state, which is why resume kept working and why no
 * test failed — the thread looked healthy and had lost a superstep.
 *
 * TWO MISSING CONDITIONS, and the second only became visible once the first was fixed:
 *
 * 1. `finishAndHandleError()` passed `exiting: true` UNCONDITIONALLY. Upstream's
 *    guard is `const exiting = this.checkpointMetadata === inputMetadata` — an
 *    IDENTITY test on the metadata object, true only while the loop still holds the
 *    object it was handed. PHP arrays have no identity, so it has to be rebuilt.
 *    `exiting` makes `putCheckpoint()` REUSE the current checkpoint id rather than
 *    mint one, so a false positive overwrites a row instead of appending.
 *
 * 2. Upstream gates the final save on `durability === "exit"`, and durability
 *    **defaults to "async"** (`pregel/index.ts:1922-1927`) — so on a default run
 *    upstream does not write one at all. Fixing only (1) produced
 *    `-1, 0, 1, 2, 3`: five rows, correct content, one spurious. That intermediate
 *    state is the reason both conditions are asserted here rather than one.
 *
 * The comment directly above `putCheckpoint()` predicted this exactly — "a false
 * positive would overwrite successive supersteps onto a single row and silently
 * destroy the thread's history". It was right, and it happened anyway.
 */
final class CheckpointHistorySequenceTest extends TestCase
{
    /**
     * Run a two-node graph and return its checkpoint steps, oldest first.
     *
     * Oldest-first deliberately: a newest-first list reads the same whether history
     * is complete or truncated at the tail, and this failure IS at the tail.
     *
     * @return list<int|string>
     */
    private function steps(): array
    {
        $saver = new MemorySaver();

        $builder = (new StateGraph(['messages' => new AnyValue()]))
            ->addNode('a', static fn (array $s): array => ['messages' => 'A'])
            ->addNode('b', static fn (array $s): array => ['messages' => 'B'])
            ->addEdge('__start__', 'a')
            ->addEdge('a', 'b')
            ->addEdge('b', '__end__');

        $graph = $builder->compile(['checkpointer' => $saver]);

        $graph->invoke(['messages' => 'q'], new RunnableConfig(configurable: ['thread_id' => 'seq']));

        // list() is newest-first; reverse so the sequence reads forwards.
        $tuples = array_reverse($saver->list(['thread_id' => 'seq']));

        return array_map(
            static fn (object $t): int|string => $t->metadata['step'] ?? 'missing',
            $tuples,
        );
    }

    /**
     * The whole point: the step sequence, oldest first.
     *
     * Compared against the sequence recorded from LangGraph JS, not a constant
     * invented here. A skipped step or a spurious extra one is invisible to every
     * other assertion in this repository — the head is correct either way, resume
     * works either way, and only the HISTORY differs.
     */
    public function testTheCheckpointStepSequenceMatchesUpstream(): void
    {
        self::assertSame(
            [-1, 0, 1, 2],
            $this->steps(),
            'a two-node graph must leave exactly these checkpoints, oldest first: an input '
            . 'checkpoint at -1 and one per superstep. LangGraph JS records precisely this '
            . 'sequence. A missing step is DESTROYED HISTORY — time travel to that point '
            . 'becomes impossible and nothing reports it.',
        );
    }

    /**
     * No step may be skipped, whatever else changes.
     *
     * Stated separately because it is the property that was broken, and expressing
     * it as "the sequence must be contiguous" survives a future change to how many
     * supersteps a graph of this shape takes — which a fixed expected list would
     * report as a failure and leave the real question unanswered.
     */
    public function testTheStepSequenceIsContiguous(): void
    {
        $steps = $this->steps();
        $numeric = array_values(array_filter($steps, 'is_int'));
        sort($numeric);

        for ($i = 1; $i < count($numeric); ++$i) {
            self::assertSame(
                $numeric[$i - 1] + 1,
                $numeric[$i],
                'checkpoint steps must be contiguous — a gap means a superstep was overwritten '
                . 'rather than appended',
            );
        }

        self::assertSame(
            -1,
            $numeric[0],
            'the oldest checkpoint is the input, at step -1',
        );
    }

    /**
     * Every checkpoint must be reachable and distinct.
     *
     * A cheap second reading of the same property: if a step were overwritten, two
     * rows would carry the same id or a step would appear twice. Asserting the ids
     * are distinct catches the overwrite directly, which the step list cannot — a
     * destroyed row leaves no trace in a list of what remains.
     */
    public function testEveryCheckpointHasADistinctId(): void
    {
        $saver = new MemorySaver();
        $builder = (new StateGraph(['messages' => new AnyValue()]))
            ->addNode('a', static fn (array $s): array => ['messages' => 'A'])
            ->addNode('b', static fn (array $s): array => ['messages' => 'B'])
            ->addEdge('__start__', 'a')
            ->addEdge('a', 'b')
            ->addEdge('b', '__end__');

        $graph = $builder->compile(['checkpointer' => $saver]);
        $graph->invoke(['messages' => 'q'], new RunnableConfig(configurable: ['thread_id' => 'ids']));

        $ids = array_map(
            static fn (object $t): string => (string) $t->checkpoint->id,
            $saver->list(['thread_id' => 'ids']),
        );

        self::assertSame(
            count($ids),
            count(array_unique($ids)),
            'two checkpoints share an id, so one was overwritten rather than appended to',
        );
    }

    /**
     * The final save must stay gated, and the gate must be the one upstream uses.
     *
     * Asserted on the source because the behaviour is "a save that does not happen",
     * which no output comparison can distinguish from a save that was skipped for
     * some other reason.
     */
    public function testTheFinalSaveIsGatedOnTheLoopStillHoldingInputMetadata(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/LangGraph/Pregel/PregelLoop.php',
        );

        self::assertMatchesRegularExpression(
            '/function finishAndHandleError.*?exiting:\s*true/s',
            $source,
            'sanity: the interrupting path must still save on the way out',
        );

        self::assertDoesNotMatchRegularExpression(
            '/\(\$error !== null \|\| !\$this->isNested\)\s*\)\s*\{\s*\$this->putCheckpoint/',
            $source,
            'the final save must not run on a NORMAL finish. Upstream gates it on '
            . '`durability === "exit"`, and durability defaults to "async" '
            . '(pregel/index.ts:1922-1927), so a default run writes no final checkpoint — '
            . 'the last per-superstep checkpoint already records the final state.',
        );
    }

    /**
     * An interrupted run must still record where it paused.
     *
     * The counterpart to the gate above, and the reason the gate cannot simply be
     * "never save on finish": a thread paused on `interrupt()` is not finished, and
     * the checkpoint that says so is the one a caller resumes from. Removing the
     * final save must not remove the ability to resume.
     */
    public function testAnInterruptedRunStillRecordsWhereItPaused(): void
    {
        $saver = new MemorySaver();
        $builder = (new StateGraph(['messages' => new AnyValue()]))
            ->addNode('ask', static function (array $s): array {
                $answer = \LangGraph\Pregel\interrupt('need input');

                return ['messages' => [(string) $answer]];
            })
            ->addEdge('__start__', 'ask');

        $graph = $builder->compile(['checkpointer' => $saver]);
        $config = new RunnableConfig(configurable: ['thread_id' => 'pause']);

        try {
            $graph->invoke(['messages' => 'q'], $config);
        } catch (\Throwable) {
            // expected: the run suspends
        }

        $tuple = $saver->getTuple(['thread_id' => 'pause']);
        self::assertNotNull($tuple, 'an interrupted run must leave a resumable checkpoint');
        self::assertNotSame([], $tuple->pendingWrites, 'the pending question must be recorded');
        self::assertSame(
            'ask',
            $graph->getState($config)->next[0] ?? null,
            'getState must still report the node waiting to be resumed',
        );
    }

    /**
     * A completed run must be resumable in the sense that matters: its head is the
     * final state, and re-running from the thread does not invent a step.
     *
     * The cheap regression check for the whole class — if a future change mints ids
     * differently, this is what notices.
     */
    public function testACompletedRunEndsAtStepTwoWithNoExtraCheckpoint(): void
    {
        $steps = $this->steps();

        self::assertSame(
            2,
            end($steps),
            'the head checkpoint is the last superstep, not a synthetic save after it',
        );
        self::assertCount(
            4,
            $steps,
            'one input checkpoint plus one per superstep, and nothing else',
        );
    }
}
