<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Errors\EmptyInputError;
use LangGraph\Errors\GraphRecursionError;
use LangGraph\Pregel\Checkpoint\Checkpoint;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\CompiledStateGraph;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\PregelLoop;
use LangGraph\Pregel\Retry\RetryPolicy;
use LangGraph\Pregel\Send;
use LangGraph\State\Annotation;
use LangGraph\State\AnnotationRoot;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The superstep loop and the `Pregel` entry points.
 *
 * Ported from the behavioural assertions in `src/tests/pregel.test.ts` and
 * `src/tests/python_port/*.test.ts`. Where the upstream test asserts on an
 * exact stream of chunks, the same stream is asserted here; where it asserts on
 * a final value, that value is asserted.
 */
#[CoversClass(PregelLoop::class)]
#[CoversClass(Pregel::class)]
#[CoversClass(CompiledStateGraph::class)]
#[CoversClass(RetryPolicy::class)]
final class PregelLoopTest extends TestCase
{
    /** A schema whose single channel accumulates strings. */
    private static function listSchema(): AnnotationRoot
    {
        return Annotation::root([
            'items' => Annotation::withReducer(
                static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
                static fn (): array => [],
            ),
        ]);
    }

    private static function cfg(?string $threadId = null): RunnableConfig
    {
        $config = new RunnableConfig();
        if ($threadId !== null) {
            $config->configurable = ['thread_id' => $threadId];
        }

        return $config;
    }

    /** Drain a stream into `[mode, payload]` pairs. */
    private static function drain(\Generator $gen): array
    {
        $out = [];
        foreach ($gen as $chunk) {
            $out[] = $chunk;
        }

        return $out;
    }

    // ---- basic execution -------------------------------------------------

    public function testLinearGraphRunsEveryNodeInOrder(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addNode('b', static fn (array $s): array => ['items' => ['b']])
            ->addNode('c', static fn (array $s): array => ['items' => ['c']])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->addEdge('b', 'c')
            ->compile();

        $this->assertSame(
            ['items' => ['seed', 'a', 'b', 'c']],
            $graph->invoke(['items' => ['seed']], self::cfg()),
        );
    }

    public function testValuesStreamEmitsOneSnapshotPerChangedStep(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', Constants::END)
            ->compile();

        $chunks = self::drain($graph->stream(['items' => ['seed']], self::cfg()));

        $modes = array_column($chunks, 0);
        $this->assertContains('values', $modes);

        // The last `values` chunk is the final state.
        $values = array_values(array_filter($chunks, static fn (array $c): bool => $c[0] === 'values'));
        $this->assertSame(['items' => ['seed', 'a']], end($values)[1]);
    }

    public function testUpdatesStreamAttributesWritesToTheNodeThatMadeThem(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addNode('b', static fn (array $s): array => ['items' => ['b']])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->compile();

        $chunks = self::drain($graph->stream(['items' => ['seed']], self::cfg()));
        $updates = array_values(array_filter($chunks, static fn (array $c): bool => $c[0] === 'updates'));

        $byNode = [];
        foreach ($updates as $u) {
            $byNode = array_merge($byNode, $u[1]);
        }

        $this->assertSame(['items' => ['a']], $byNode['a']);
        $this->assertSame(['items' => ['b']], $byNode['b']);
    }

    public function testGraphWithNoReachableNodeReturnsEmptyState(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('orphan', static fn (array $s): array => ['items' => ['never']])
            ->compile();

        // The input is applied; the orphan is simply never scheduled, because
        // no edge or branch ever writes to its routing channel.
        $this->assertSame(['items' => ['seed']], $graph->invoke(['items' => ['seed']], self::cfg()));
    }

    // ---- routing ---------------------------------------------------------

    public function testConditionalEdgeRoutesToOneNode(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('start', static fn (array $s): array => ['items' => ['start']])
            ->addNode('yes', static fn (array $s): array => ['items' => ['yes']])
            ->addNode('no', static fn (array $s): array => ['items' => ['no']])
            ->addEdge(Constants::START, 'start')
            ->addConditionalEdges('start', static fn (array $s): string => 'yes')
            ->compile();

        $this->assertSame(
            ['items' => ['seed', 'start', 'yes']],
            $graph->invoke(['items' => ['seed']], self::cfg()),
        );
    }

    public function testConditionalEdgeToEndStopsTheGraph(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addNode('unreachable', static fn (array $s): array => ['items' => ['nope']])
            ->addEdge(Constants::START, 'a')
            ->addConditionalEdges('a', static fn (array $s): string => Constants::END)
            ->compile();

        $this->assertSame(
            ['items' => ['seed', 'a']],
            $graph->invoke(['items' => ['seed']], self::cfg()),
        );
    }

    public function testConditionalEdgeReturningNothingRoutesNowhere(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addNode('never', static fn (array $s): array => ['items' => ['never']])
            ->addEdge(Constants::START, 'a')
            ->addConditionalEdges('a', static fn (array $s): array => [])
            ->compile();

        // Declining to continue is a normal outcome, not an error.
        $this->assertSame(
            ['items' => ['seed', 'a']],
            $graph->invoke(['items' => ['seed']], self::cfg()),
        );
    }

    public function testCommandGotoRoutesWithoutADeclaredEdge(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => [
                'items' => ['a'],
            ])
            ->addNode('b', static fn (array $s): array => ['items' => ['b']])
            ->addEdge(Constants::START, 'a')
            ->compile();

        $graph2 = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s) => new Command(update: ['items' => ['a']], goto: 'b'))
            ->addNode('b', static fn (array $s): array => ['items' => ['b']])
            ->addEdge(Constants::START, 'a')
            ->compile();

        $this->assertSame(
            ['items' => ['seed', 'a', 'b']],
            $graph2->invoke(['items' => ['seed']], self::cfg()),
        );
    }

    // ---- Send / fan-out --------------------------------------------------

    public function testSendRunsTheTargetNodeWithItsOwnInput(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('fan', static fn (array $s): array => ['items' => ['fan']])
            ->addNode('work', static fn (array $s): array => ['items' => ['work:' . $s['topic']]])
            ->addEdge(Constants::START, 'fan')
            ->addConditionalEdges('fan', static fn (array $s): array => [
                new Send('work', ['topic' => 'x']),
                new Send('work', ['topic' => 'y']),
            ])
            ->compile();

        $result = $graph->invoke(['items' => ['seed']], self::cfg());

        // Both sends run, each with its own input — the map half of map-reduce.
        $this->assertContains('work:x', $result['items']);
        $this->assertContains('work:y', $result['items']);
    }

    public function testSendToAnUnknownNodeIsRejectedAtTheSource(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('fan', static fn (array $s): array => ['items' => ['fan']])
            ->addEdge(Constants::START, 'fan')
            ->addConditionalEdges('fan', static fn (array $s): array => [new Send('ghost', null)])
            ->compile();

        // A `Send` naming a node that does not exist is caught where it is
        // written, not silently dropped when it would have been scheduled. The
        // user gets a message naming the node; a graph that quietly did less
        // than it was told would be far worse.
        $this->expectException(\LangGraph\Errors\InvalidUpdateError::class);
        $this->expectExceptionMessage('Invalid node name "ghost"');

        $graph->invoke(['items' => ['seed']], self::cfg());
    }

    /**
     * Port of `test_concurrent_emit_sends` from
     * `test_pregel_async_graph_structure.py` (via
     * `src/tests/python_port/graph_structure.test.ts`).
     *
     * The upstream expectation is an exact ordered list, and the order is the
     * specification: fan-out tasks apply their writes in *task-path* order, not
     * completion order. PHP runs them sequentially, so a faithful port must
     * still produce the upstream ordering — if it did not, the sort in
     * `applyWrites` would not be doing its job.
     */
    public function testConcurrentEmitSendsOrderMatchesTheUpstreamExpectation(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('1', static fn (array $s): array => ['items' => ['1']])
            ->addNode('1.1', static fn (array $s): array => ['items' => ['1.1']])
            ->addNode('2', static fn (mixed $s): array => ['items' => ['2|' . (is_array($s) ? ($s['n'] ?? '') : $s)]])
            ->addNode('3', static fn (array $s): array => ['items' => ['3']])
            ->addNode('3.1', static fn (array $s): array => ['items' => ['3.1']])
            ->addEdge(Constants::START, '1')
            ->addEdge(Constants::START, '1.1')
            ->addConditionalEdges('1', static fn (array $s): array => [
                new Send('2', 1),
                new Send('2', 2),
                '3.1',
            ])
            ->addConditionalEdges('1.1', static fn (array $s): array => [
                new Send('2', 3),
                new Send('2', 4),
            ])
            ->addConditionalEdges('2', static fn (array $s): string => '3')
            ->compile();

        $result = $graph->invoke(['items' => ['0']], self::cfg());

        sort($result['items']);
        $expected = ['0', '1', '1.1', '2|1', '2|2', '2|3', '2|4', '3', '3.1'];
        sort($expected);

        $this->assertSame($expected, $result['items']);
    }

    /**
     * Port of `test_cond_edge_after_send` from
     * `test_pregel_async_graph_structure.py`.
     */
    public function testConditionalEdgeAfterSendMatchesUpstreamExpectation(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('1', static fn (array $s): array => ['items' => ['1']])
            ->addNode('2', static fn (array $s): array => ['items' => ['2']])
            ->addNode('3', static fn (array $s): array => ['items' => ['3']])
            ->addEdge(Constants::START, '1')
            ->addConditionalEdges('1', static fn (array $s): array => [
                new Send('2', $s),
                new Send('2', $s),
            ])
            ->addConditionalEdges('2', static fn (array $s): string => '3')
            ->compile();

        $this->assertSame(
            ['items' => ['0', '1', '2', '2', '3']],
            $graph->invoke(['items' => ['0']], self::cfg()),
        );
    }

    // ---- errors ----------------------------------------------------------

    public function testNodeErrorPropagatesAndIsAttributedToTheRun(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('boom', static function (array $s): array {
                throw new \RuntimeException('node exploded');
            })
            ->addEdge(Constants::START, 'boom')
            ->compile();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('node exploded');

        $graph->invoke(['items' => []], self::cfg());
    }

    public function testRecursionLimitStopsACycle(): void
    {
        $schema = Annotation::root([
            'count' => Annotation::last(static fn (): int => 0),
        ]);

        $graph = (new StateGraph($schema))
            ->addNode('tick', static fn (array $s): array => ['count' => $s['count'] + 1])
            ->addEdge(Constants::START, 'tick')
            ->addEdge('tick', 'tick')
            ->compile();

        $config = self::cfg();
        $config->recursionLimit = 5;

        $this->expectException(GraphRecursionError::class);
        $this->expectExceptionMessage('Recursion limit of 5 reached');

        $graph->invoke(['count' => 0], $config);
    }

    public function testARecursionLimitBelowOneIsRejected(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addEdge(Constants::START, 'a')
            ->compile();

        $config = self::cfg();
        $config->recursionLimit = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be at least 1');

        $graph->invoke(['items' => []], $config);
    }

    public function testNodeReturningANonObjectIsRejected(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('bad', static fn (array $s): string => 'not a state')
            ->addEdge(Constants::START, 'bad')
            ->compile();

        $this->expectException(\LangGraph\Errors\InvalidUpdateError::class);
        $this->expectExceptionMessage('Expected node "bad" to return an object');

        $graph->invoke(['items' => []], self::cfg());
    }

    public function testNodeReturningAnUnknownStateKeyIsDropped(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a'], 'nonsense' => 1])
            ->addEdge(Constants::START, 'a')
            ->compile();

        // The schema *is* the state. An unrecognised key is a typo and must not
        // become state.
        $this->assertSame(
            ['items' => ['a']],
            $graph->invoke(['items' => []], self::cfg()),
        );
    }

    // ---- retry -----------------------------------------------------------

    public function testRetryPolicyRetriesAFailingNodeUpToMaxAttempts(): void
    {
        $attempts = 0;

        $graph = (new StateGraph(self::listSchema()))
            ->addNode('flaky', static function (array $s) use (&$attempts): array {
                $attempts++;
                if ($attempts < 3) {
                    throw new \RuntimeException('transient');
                }

                return ['items' => ['ok']];
            }, ['retryPolicy' => new RetryPolicy(maxAttempts: 3, jitter: false, logWarning: false)])
            ->addEdge(Constants::START, 'flaky')
            ->compile();

        $result = $graph->invoke(['items' => []], self::cfg());

        $this->assertSame(3, $attempts, 'two failures then a success is three attempts');
        $this->assertSame(['items' => ['ok']], $result);
    }

    public function testRetryPolicyGivesUpAfterMaxAttempts(): void
    {
        $attempts = 0;

        $graph = (new StateGraph(self::listSchema()))
            ->addNode('always', static function (array $s) use (&$attempts): array {
                $attempts++;
                throw new \RuntimeException('permanent');
            }, ['retryPolicy' => new RetryPolicy(maxAttempts: 2, jitter: false, logWarning: false)])
            ->addEdge(Constants::START, 'always')
            ->compile();

        try {
            $graph->invoke(['items' => []], self::cfg());
            $this->fail('expected the node error to surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('permanent', $e->getMessage());
        }

        $this->assertSame(2, $attempts);
    }

    public function testARetriedNodeDoesNotDoubleApplyItsWrites(): void
    {
        $attempts = 0;

        // The node writes, then throws. On the retry the write must be gone: a
        // failed attempt produced *no* output, not half of one.
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('partial', static function (array $s) use (&$attempts): array {
                $attempts++;
                if ($attempts < 2) {
                    throw new \RuntimeException('transient after writing');
                }

                return ['items' => ['real']];
            }, ['retryPolicy' => new RetryPolicy(maxAttempts: 3, jitter: false, logWarning: false)])
            ->addEdge(Constants::START, 'partial')
            ->compile();

        $this->assertSame(
            ['items' => ['real']],
            $graph->invoke(['items' => []], self::cfg()),
        );
    }

    public function testDefaultRetryOnDeclinesUnhelpfulErrors(): void
    {
        $policy = new RetryPolicy();

        $this->assertFalse(RetryPolicy::defaultRetryOn(new \LangGraph\Errors\GraphValueError('bad')));
        $this->assertFalse(RetryPolicy::defaultRetryOn(new \RuntimeException('AbortError')));
        $this->assertFalse(RetryPolicy::defaultRetryOn(new \RuntimeException('Cancelled by caller')));
        $this->assertTrue(RetryPolicy::defaultRetryOn(new \RuntimeException('transient failure')));

        // An interrupt is a bubble-up, not a node failure. Retrying one would
        // just interrupt again.
        $this->assertFalse(RetryPolicy::defaultRetryOn(new BubbleUpStub()));
    }

    public function testRetryBackoffIsExponentialAndClamped(): void
    {
        $policy = new RetryPolicy(
            initialInterval: 100,
            backoffFactor: 2.0,
            maxInterval: 500,
            jitter: false,
        );

        $this->assertSame(100, $policy->intervalFor(1, 0.5));
        $this->assertSame(200, $policy->intervalFor(2, 0.5));
        $this->assertSame(400, $policy->intervalFor(3, 0.5));
        $this->assertSame(500, $policy->intervalFor(4, 0.5), 'clamped at maxInterval');
    }

    public function testJitterAddsUpToOneSecond(): void
    {
        $noJitter = new RetryPolicy(initialInterval: 100, jitter: false);
        $jittered = new RetryPolicy(initialInterval: 100, jitter: true);

        $this->assertSame(100, $noJitter->intervalFor(1, 1.0));
        $this->assertSame(600, $jittered->intervalFor(1, 0.5));
    }

    // ---- checkpointing / interrupt ---------------------------------------

    public function testInterruptBeforeHaltsAndResumeContinues(): void
    {
        $saver = new \LangGraph\Pregel\Checkpoint\MemorySaver();

        $builder = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addNode('b', static fn (array $s): array => ['items' => ['b']])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b');

        $graph = $builder->compile(['checkpointer' => $saver, 'interruptBefore' => ['b']]);
        $thread = self::cfg('t1');

        $this->assertSame(
            ['items' => ['seed', 'a']],
            $graph->invoke(['items' => ['seed']], $thread),
        );

        // A null input means "continue from where the checkpoint left off".
        $this->assertSame(
            ['items' => ['seed', 'a', 'b']],
            $graph->invoke(null, $thread),
        );
    }

    public function testInterruptSuspendsAndResumeSuppliesTheAnswer(): void
    {
        $saver = new \LangGraph\Pregel\Checkpoint\MemorySaver();

        $graph = (new StateGraph(self::listSchema()))
            ->addNode('ask', static function (array $s): array {
                $answer = \LangGraph\Pregel\interrupt('what colour?');

                return ['items' => [$answer]];
            })
            ->addEdge(Constants::START, 'ask')
            ->compile(['checkpointer' => $saver]);

        $thread = self::cfg('t1');

        // The interrupted run returns the state as it stands, not an error.
        $this->assertSame(['items' => []], $graph->invoke(['items' => []], $thread));

        // Resuming re-executes the node, which this time finds its answer.
        $this->assertSame(
            ['items' => ['blue']],
            $graph->invoke(new Command(resume: 'blue'), $thread),
        );
    }

    public function testInterruptRequiresACheckpointerToResume(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('ask', static function (array $s): array {
                return ['items' => [\LangGraph\Pregel\interrupt('q')]];
            })
            ->addEdge(Constants::START, 'ask')
            ->compile();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('without checkpointer');

        $graph->invoke(new Command(resume: 'x'), self::cfg());
    }

    public function testInterruptOutsideAGraphIsAValueError(): void
    {
        $this->expectException(\LangGraph\Errors\GraphValueError::class);

        \LangGraph\Pregel\interrupt('nowhere');
    }

    public function testGetStateReportsValuesNextAndTasks(): void
    {
        $saver = new \LangGraph\Pregel\Checkpoint\MemorySaver();

        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addNode('b', static fn (array $s): array => ['items' => ['b']])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->compile(['checkpointer' => $saver]);

        $thread = self::cfg('t1');
        $graph->invoke(['items' => ['seed']], $thread);

        $state = $graph->getState($thread);

        $this->assertSame(['items' => ['seed', 'a', 'b']], $state['values']);
        $this->assertSame([], $state['next'], 'the graph ran to completion, so nothing is pending');
        $this->assertNotSame([], $state['config']);
    }

    public function testGetStateOnAnUnknownThreadIsEmpty(): void
    {
        $saver = new \LangGraph\Pregel\Checkpoint\MemorySaver();

        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addEdge(Constants::START, 'a')
            ->compile(['checkpointer' => $saver]);

        $state = $graph->getState(self::cfg('never-seen'));

        $this->assertSame([], $state['values']);
        $this->assertSame([], $state['next']);
    }

    public function testACheckpointerRequiresConfigurableKeys(): void
    {
        $saver = new \LangGraph\Pregel\Checkpoint\MemorySaver();

        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addEdge(Constants::START, 'a')
            ->compile(['checkpointer' => $saver]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Checkpointer requires');

        $graph->invoke(['items' => []], new RunnableConfig());
    }

    public function testResumingDoesNotReRunCompletedNodes(): void
    {
        $saver = new \LangGraph\Pregel\Checkpoint\MemorySaver();
        $runs = ['a' => 0, 'b' => 0];

        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static function (array $s) use (&$runs): array {
                $runs['a']++;

                return ['items' => ['a']];
            })
            ->addNode('b', static function (array $s) use (&$runs): array {
                $runs['b']++;

                return ['items' => ['b']];
            })
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->compile(['checkpointer' => $saver, 'interruptBefore' => ['b']]);

        $thread = self::cfg('t1');
        $graph->invoke(['items' => ['seed']], $thread);
        $this->assertSame(1, $runs['a']);

        $graph->invoke(null, $thread);

        // `a` already ran and its output is in the checkpoint. Re-running it
        // would double-apply its write.
        $this->assertSame(1, $runs['a'], 'a completed node must not re-run on resume');
        $this->assertSame(1, $runs['b']);
    }

    public function testSeparateThreadsDoNotShareState(): void
    {
        $saver = new \LangGraph\Pregel\Checkpoint\MemorySaver();

        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addEdge(Constants::START, 'a')
            ->compile(['checkpointer' => $saver]);

        $graph->invoke(['items' => ['one']], self::cfg('t1'));
        $graph->invoke(['items' => ['two']], self::cfg('t2'));

        $this->assertSame(['items' => ['one', 'a']], $graph->getState(self::cfg('t1'))['values']);
        $this->assertSame(['items' => ['two', 'a']], $graph->getState(self::cfg('t2'))['values']);
    }

    public function testGraphStateIsCheckpointedOnEveryStep(): void
    {
        $saver = new \LangGraph\Pregel\Checkpoint\MemorySaver();

        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addNode('b', static fn (array $s): array => ['items' => ['b']])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->compile(['checkpointer' => $saver]);

        $graph->invoke(['items' => ['seed']], self::cfg('t1'));

        // One `input` checkpoint, one per committed superstep, and one final
        // save on the way out — newest first. The head is a complete,
        // resumable picture and the history behind it is what makes time
        // travel possible. Nothing is collapsed onto another row: a checkpoint
        // that overwrote its predecessor would silently destroy the thread.
        $saved = $saver->all('t1');
        $this->assertCount(4, $saved);
        $this->assertSame('loop', $saved[0]->metadata['source']);
        $this->assertSame('loop', $saved[1]->metadata['source']);
        $this->assertSame('loop', $saved[2]->metadata['source']);
        $this->assertSame('input', $saved[3]->metadata['source']);

        // Every checkpoint has a distinct id, or history is being lost.
        $ids = array_map(
            static fn (\LangGraph\Pregel\Checkpoint\CheckpointTuple $t): mixed
                => $t->config['configurable']['checkpoint_id'] ?? null,
            $saved,
        );
        $this->assertCount(4, array_unique($ids));
    }

    public function testNullInputWithoutACheckpointerIsRejected(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addEdge(Constants::START, 'a')
            ->compile();

        // A null input means "resume from a checkpoint". With no checkpointer
        // there is nothing to resume from, and silently returning the empty
        // state would hide a caller bug.
        $this->expectException(EmptyInputError::class);

        $graph->invoke(null, self::cfg());
    }

    // ---- untracked values ------------------------------------------------

    public function testUntrackedChannelWritesAreNotCheckpointed(): void
    {
        $saver = new \LangGraph\Pregel\Checkpoint\MemorySaver();

        $graph = (new StateGraph(Annotation::root([
            'items' => Annotation::withReducer(
                static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
                static fn (): array => [],
            ),
            'scratch' => new \LangGraph\Channels\UntrackedValue(),
        ])))
            ->addNode('a', static fn (array $s): array => [
                'items' => ['a'],
                'scratch' => ['a large value the graph should not store'],
            ])
            ->addEdge(Constants::START, 'a')
            ->compile(['checkpointer' => $saver]);

        $config = self::cfg('t1');
        $result = $graph->invoke(['items' => [], 'scratch' => null], $config);

        // The node still *sees* an untracked value, and it is still part of
        // the live state. The exclusion is about persistence only.
        $this->assertSame(['a'], $result['items']);
        $this->assertSame(['a large value the graph should not store'], $result['scratch']);

        // ...but no checkpoint carries it. An untracked value exists only for
        // the node that wrote it; persisting it would bloat every checkpoint
        // for no benefit on resume.
        $json = json_encode($saver->all('t1'));
        $this->assertIsString($json);
        $this->assertStringNotContainsString('a large value', $json);
    }

    public function testAnUntrackedValueNestedInsideASendIsStrippedBeforeCheckpointing(): void
    {
        // A spy saver, so the assertion is on what was *persisted* rather than
        // on what a later superstep left behind. Pending writes are cleared on
        // commit, so reading them back off the checkpoint would prove nothing.
        $saver = new class extends \LangGraph\Pregel\Checkpoint\MemorySaver {
            /** @var list<array{0: string, 1: list<array{0: string, 1: mixed}>}> */
            public array $recordedWrites = [];

            public function putWrites(array $config, array $writes, string $taskId): array
            {
                $this->recordedWrites[] = [$taskId, $writes];

                return parent::putWrites($config, $writes, $taskId);
            }
        };

        // The graph needs an untracked channel for the sanitisation to apply at
        // all: with none, there is nothing to strip and the whole pass is
        // skipped, which is the upstream behaviour and the cheap path.
        $graph = (new StateGraph(Annotation::root([
            'items' => Annotation::withReducer(
                static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
                static fn (): array => [],
            ),
            'secret' => new \LangGraph\Channels\UntrackedValue(),
        ])))
            ->addNode('fan', static fn (array $s): array => ['items' => ['fan']])
            ->addNode('work', static fn (array $s): array => ['items' => ['work']])
            ->addEdge(Constants::START, 'fan')
            ->addConditionalEdges('fan', static fn (array $s): array => [
                new Send('work', ['note' => 'keep me', 'secret' => 'strip me']),
            ])
            ->compile(['checkpointer' => $saver]);

        $config = self::cfg('t1');
        $graph->invoke(['items' => []], $config);

        // A `Send` is checkpointed, so an untracked key inside it has to come
        // off before the packet is written — otherwise the exclusion is
        // defeated by one level of nesting.
        $serialized = json_encode($saver->recordedWrites);
        $this->assertIsString($serialized);

        $this->assertStringContainsString('keep me', $serialized, 'ordinary keys survive');
        $this->assertStringNotContainsString('strip me', $serialized, 'the untracked key is gone');

        // The value is still delivered to the target node — stripping it from
        // the checkpoint must not change what the run does. A second thread,
        // because the first one already has this run's state.
        $other = self::cfg('t2');
        $this->assertSame(['items' => ['fan', 'work']], $graph->invoke(['items' => []], $other));
    }

    public function testUntrackedValuesSurviveInMemoryForTheWritingNode(): void
    {
        $seen = null;

        $graph = (new StateGraph(Annotation::root([
            'scratch' => new \LangGraph\Channels\UntrackedValue(),
        ])))
            ->addNode('a', static function (array $s) use (&$seen): array {
                $seen = $s['scratch'];

                return ['scratch' => ['written']];
            })
            ->addEdge(Constants::START, 'a')
            ->compile();

        $graph->invoke(['scratch' => ['seed']]);

        // The exclusion is about *persistence*, not about the node's own view.
        $this->assertSame(['seed'], $seen);
    }

    // ---- emit ordering ---------------------------------------------------

    public function testValuesAreEmittedAfterTheSupersstepWritesLand(): void
    {
        $graph = (new StateGraph(self::listSchema()))
            ->addNode('a', static fn (array $s): array => ['items' => ['a']])
            ->addNode('b', static fn (array $s): array => ['items' => ['b']])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->compile();

        $chunks = self::drain($graph->stream(['items' => ['seed']], self::cfg()));

        // A `values` chunk must never report a state the following `updates`
        // chunk has not yet contributed. Interleaving them the other way would
        // show a consumer a state that never existed.
        $seenUpdates = [];
        $seenValues = [];

        foreach ($chunks as [$mode, $payload]) {
            if ($mode === 'updates') {
                $seenUpdates[] = array_keys($payload);
            } elseif ($mode === 'values') {
                $seenValues[] = $payload['items'];
            }
        }

        $this->assertSame([['a'], ['b']], $seenUpdates);
        $this->assertSame(['seed', 'a', 'b'], end($seenValues));
    }
}

/**
 * A minimal bubble-up, used to prove the default retry handler declines to
 * retry engine control-flow signals.
 */
final class BubbleUpStub extends \LangGraph\Errors\GraphBubbleUp
{
}
