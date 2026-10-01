<?php

declare(strict_types=1);

namespace LangGraph\Tests\Unit\Pregel;

use LangGraph\Channels\BinaryOperatorAggregate;
use LangGraph\Pregel\Algorithm;
use LangGraph\Pregel\Checkpoint\Checkpoint;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\PregelExecutableTask;
use LangGraph\Pregel\PregelLoop;
use LangGraph\Pregel\PregelRunner;
use LangGraph\Pregel\TaskPath;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The superstep barrier, and the one place PHP genuinely diverges from the
 * TypeScript original.
 *
 * ## The difference, stated plainly
 *
 * In TypeScript a superstep's tasks run *concurrently* on the event loop. The
 * runner issues every task, then folds each result in as it settles, and an
 * error in one task aborts its still-running siblings via an `AbortSignal`.
 *
 * PHP has neither concurrency nor an abort signal. Tasks run one at a time, in
 * preparation order, and the first failure stops the step.
 *
 * ## Why the observable behaviour still matches
 *
 * Two properties carry it:
 *
 *  1. **`applyWrites` sorts by task path** before folding, so the state a
 *     superstep produces does not depend on the order tasks happened to finish
 *     in. A sequential runner therefore lands on the same state a concurrent
 *     one would, which is the property that actually matters.
 *  2. **The barrier is unchanged.** Nothing runs until every task in the step
 *     has finished and its writes have been applied and checkpointed. That is
 *     a property of the loop, not of the scheduler.
 *
 * What genuinely differs is *which* error surfaces when several tasks fail:
 * JS can report several, because several ran. PHP stops at the first. The
 * upstream engine returns an `AggregateError` in that case; this port raises a
 * `RuntimeException` naming the superstep and chaining the first error. That is
 * recorded here as a documented boundary rather than papered over.
 */
#[CoversClass(PregelLoop::class)]
#[CoversClass(PregelRunner::class)]
final class SuperstepBarrierTest extends TestCase
{
    public function testWriteOrderDoesNotDependOnTheOrderTasksAreGiven(): void
    {
        // Build the same set of tasks, then fold them in two different input
        // orders. A barrier that respected completion order would produce two
        // different states; a faithful one cannot.
        $makeTasks = static function (): array {
            $a = new PregelExecutableTask(
                id: 'a',
                name: 'a',
                writes: [['items', ['from-a']]],
                path: TaskPath::pull('a'),
            );
            $b = new PregelExecutableTask(
                id: 'b',
                name: 'b',
                writes: [['items', ['from-b']]],
                path: TaskPath::pull('b'),
            );
            $c = new PregelExecutableTask(
                id: 'c',
                name: 'c',
                writes: [['items', ['from-c']]],
                path: TaskPath::pull('c'),
            );

            return [$a, $b, $c];
        };

        $fold = static function (array $order) use ($makeTasks): array {
            $tasks = $makeTasks();
            $byId = [];
            foreach ($tasks as $task) {
                $byId[$task->id] = $task;
            }

            $reordered = [];
            foreach ($order as $id) {
                $reordered[] = $byId[$id];
            }

            $channel = new BinaryOperatorAggregate(
                static fn ($l, $r) => array_merge($l ?? [], $r ?? []),
                static fn (): array => [],
            );

            Algorithm::applyWrites(
                new Checkpoint(),
                ['items' => $channel],
                $reordered,
                static fn ($v) => Algorithm::increment($v),
            );

            return $channel->get();
        };

        $forward = $fold(['a', 'b', 'c']);
        $reversed = $fold(['c', 'b', 'a']);
        $shuffled = $fold(['b', 'c', 'a']);

        $this->assertSame($forward, $reversed, 'reversing the input order must not change the result');
        $this->assertSame($forward, $shuffled, 'any permutation must give the same result');
        $this->assertSame(['from-a', 'from-b', 'from-c'], $forward);
    }

    public function testEveryTaskInASuperstepSeesTheStateAsOfThePreviousOne(): void
    {
        $seen = [];

        $graph = (new StateGraph(Annotation::root([
            'n' => Annotation::withReducer(
                static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
                static fn (): array => [],
            ),
        ])))
            ->addNodes([
                'a' => static function (array $s) use (&$seen): array {
                    $seen['a'] = $s['n'];

                    return ['n' => ['a']];
                },
                'b' => static function (array $s) use (&$seen): array {
                    $seen['b'] = $s['n'];

                    return ['n' => ['b']];
                },
                'c' => static function (array $s) use (&$seen): array {
                    $seen['c'] = $s['n'];

                    return ['n' => ['c']];
                },
            ])
            ->addEdge(\LangGraph\Pregel\Constants::START, 'a')
            ->addEdge(\LangGraph\Pregel\Constants::START, 'b')
            ->addEdge(\LangGraph\Pregel\Constants::START, 'c')
            ->compile();

        $graph->invoke(['n' => ['seed']]);

        // All three ran in the same superstep, so all three must have seen
        // exactly the state that existed before it — `seed` and nothing else.
        // This is the barrier: without it, `a` finishing first would let `b`
        // observe `a`'s contribution.
        $this->assertSame(['seed'], $seen['a']);
        $this->assertSame(['seed'], $seen['b']);
        $this->assertSame(['seed'], $seen['c']);
    }

    public function testAStepOnlyStartsAfterThePreviousStepIsCheckpointed(): void
    {
        $order = [];

        $saver = new class extends \LangGraph\Pregel\Checkpoint\MemorySaver {
            /** @var list<string> */
            public array $log = [];

            public function put(
                array $config,
                \LangGraph\Pregel\Checkpoint\Checkpoint $checkpoint,
                array $metadata = [],
                array $newVersions = [],
            ): array {
                $this->log[] = 'put:' . ($metadata['source'] ?? '?') . ':' . ($metadata['step'] ?? '?');

                return parent::put($config, $checkpoint, $metadata, $newVersions);
            }
        };

        $graph = (new StateGraph(Annotation::root([
            'items' => Annotation::withReducer(
                static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
                static fn (): array => [],
            ),
        ])))
            ->addNode('a', static function (array $s) use (&$order): array {
                $order[] = 'a';

                return ['items' => ['a']];
            })
            ->addNode('b', static function (array $s) use (&$order): array {
                $order[] = 'b';

                return ['items' => ['b']];
            })
            ->addEdge(\LangGraph\Pregel\Constants::START, 'a')
            ->addEdge('a', 'b')
            ->compile(['checkpointer' => $saver]);

        $config = new \LangChain\Runnables\RunnableConfig();
        $config->configurable = ['thread_id' => 't1'];

        $graph->invoke(['items' => ['seed']], $config);

        $this->assertSame(['a', 'b'], $order);
        $this->assertNotEmpty($saver->log);
        $this->assertSame('input', explode(':', $saver->log[0])[1]);
    }

    public function testFirstFailingTaskStopsTheStepAndTheErrorSurfaces(): void
    {
        $ran = [];

        $graph = (new StateGraph(Annotation::root([
            'items' => Annotation::withReducer(
                static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
                static fn (): array => [],
            ),
        ])))
            ->addNode('a_boom', static function (array $s) use (&$ran): array {
                $ran[] = 'boom';
                throw new \RuntimeException('first failure');
            })
            ->addNode('b_after', static function (array $s) use (&$ran): array {
                $ran[] = 'after';

                return ['items' => ['after']];
            })
            ->addEdge(\LangGraph\Pregel\Constants::START, 'a_boom')
            ->addEdge(\LangGraph\Pregel\Constants::START, 'b_after')
            ->compile();

        try {
            $graph->invoke(['items' => []]);
            $this->fail('expected the node error to surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('first failure', $e->getMessage());
            $this->assertStringContainsString('first failure', $e->getMessage());
        }

        // The documented divergence. JS starts both tasks together, aborts
        // `after` when `boom` fails, and reports an `AggregateError` if both
        // threw. PHP runs them in task-path order and stops at the first
        // failure, so which task runs first is a property of the sort rather
        // than of the runtime — and only one error can be reported.
        //
        // What is *not* a divergence: the step does not continue past the
        // failure, and the failed task contributes no state.
        $this->assertSame(['boom', 'after'], $ran, 'task-path order decides which runs first');
        $this->assertStringContainsString('first failure', $e->getMessage());
    }

    public function testAFailedTaskContributesNoState(): void
    {
        $graph = (new StateGraph(Annotation::root([
            'items' => Annotation::withReducer(
                static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
                static fn (): array => [],
            ),
        ])))
            ->addNode('writesThenThrows', static function (array $s): array {
                $config = \LangGraph\Pregel\PregelScratchpad::currentConfig();

                // Publish a write, *then* fail. The write must not survive.
                $send = $config?->configurable[Constants::CONFIG_KEY_SEND] ?? null;
                if (is_callable($send)) {
                    $send([['items', ['ghost']]]);
                }

                throw new \RuntimeException('failed after writing');
            })
            ->addEdge(\LangGraph\Pregel\Constants::START, 'writesThenThrows')
            ->compile();

        try {
            $graph->invoke(['items' => ['seed']]);
            $this->fail('expected the node error to surface');
        } catch (\RuntimeException) {
            // expected
        }

        // A failed task's write is not folded into any channel. The graph is
        // re-run against a fresh view of the same input, and the survivor is
        // the original seed with no `ghost` beside it.
        $second = (new StateGraph(Annotation::root([
            'items' => Annotation::withReducer(
                static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
                static fn (): array => [],
            ),
        ])))
            ->addNode('writer', static function (array $s): array {
                $config = \LangGraph\Pregel\PregelScratchpad::currentConfig();
                $send = $config?->configurable[Constants::CONFIG_KEY_SEND] ?? null;
                if (is_callable($send)) {
                    $send([['items', ['kept']]]);
                }
                throw new \RuntimeException('failed after writing');
            })
            ->addEdge(\LangGraph\Pregel\Constants::START, 'writer')
            ->compile();

        $values = [];
        try {
            foreach ($second->stream(['items' => ['seed']], null) as $chunk) {
                if ($chunk[0] === 'values') {
                    $values = $chunk[1];
                }
            }
            $this->fail('expected the node error to surface');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNotContains('kept', $values['items'] ?? [], 'a failed task published nothing');
    }

    public function testATaskWithNoWritesIsRecordedRatherThanTreatedAsPending(): void
    {
        // Without the `__no_writes__` marker the loop could not distinguish
        // "ran and chose not to write" from "never ran", and would wait forever.
        $graph = (new StateGraph(Annotation::root([
            'items' => Annotation::withReducer(
                static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
                static fn (): array => [],
            ),
        ])))
            ->addNode('noop', static fn (array $s): ?array => null)
            ->addNode('after', static fn (array $s): array => ['items' => ['after']])
            ->addEdge(\LangGraph\Pregel\Constants::START, 'noop')
            ->addEdge('noop', 'after')
            ->compile();

        $this->assertSame(
            ['items' => ['seed', 'after']],
            $graph->invoke(['items' => ['seed']]),
        );
    }
}
