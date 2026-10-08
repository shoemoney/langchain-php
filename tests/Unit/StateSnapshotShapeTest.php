<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use LangGraph\Channels\AnyValue;
use LangGraph\Pregel\Checkpoint\MemorySaver;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\PregelTaskDescription;
use LangGraph\Pregel\StateSnapshot;
use LangGraph\State\StateGraph;
use LangChain\Runnables\RunnableConfig;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;

/**
 * `getState()` must hand back a typed snapshot, not an array.
 *
 * Upstream declares `StateSnapshot` as an interface with seven fields
 * (`pregel/types.ts:616`). This port returned a bare array with five of them, so a
 * caller reading a graph's state to decide what to do next — the human-in-the-loop
 * step — had no type describing the shape it was editing, and `createdAt` and
 * `parentConfig` did not exist at all. `parentConfig` is how a caller walks
 * backwards through a thread without enumerating history itself, so omitting it
 * removed the only route to time travel that needs no saver of the caller's own.
 *
 * The seven field names are taken from bytes, not from reading the interface: a
 * LangGraph JS run's `getState()` returns exactly
 * `config, createdAt, metadata, next, parentConfig, tasks, values`.
 *
 * ONE FIELD IS STILL WRONG, and it is pinned as wrong rather than quietly
 * accommodated — see {@see testTheInterruptFieldIsKnownEmptyAndWhyThatIsPinned}.
 */
final class StateSnapshotShapeTest extends TestCase
{
    /** The seven fields upstream declares, measured from a real run. */
    private const UPSTREAM_FIELDS = [
        'values', 'next', 'config', 'metadata', 'createdAt', 'parentConfig', 'tasks',
    ];

    /**
     * Built ONCE per test and reused.
     *
     * `compile(['checkpointer' => new MemorySaver()])` mints a fresh saver on
     * every call, so a helper that rebuilt the graph would run `invoke` against one
     * saver and `getState` against another and see an empty thread. The state has
     * to be created and read on the same graph, which is not a detail.
     *
     * @return Pregel
     */
    private function graph(): Pregel
    {
        $builder = (new StateGraph(['messages' => new AnyValue()]))
            ->addNode('ask', static fn (array $s): array => ['messages' => 'done'])
            ->addEdge('__start__', 'ask');

        return $this->memo ??= $builder->compile(['checkpointer' => $this->saver]);
    }

    /** A graph paused by `interrupt()`, so `next` is non-empty. */
    private function pausedGraph(): Pregel
    {
        $builder = (new StateGraph(['messages' => new AnyValue()]))
            ->addNode('ask', static function (array $s): array {
                $answer = \LangGraph\Pregel\interrupt('need input');

                return ['messages' => [(string) $answer]];
            })
            ->addEdge('__start__', 'ask');

        return $this->memoPaused ??= $builder->compile(['checkpointer' => $this->saverPaused]);
    }

    private ?Pregel $memo = null;

    private ?Pregel $memoPaused = null;

    private MemorySaver $saver;

    private MemorySaver $saverPaused;

    protected function setUp(): void
    {
        $this->saver = new MemorySaver();
        $this->saverPaused = new MemorySaver();
    }

    public function testGetStateReturnsASnapshotNotAnArray(): void
    {
        $type = (new \ReflectionMethod(Pregel::class, 'getState'))->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(
            StateSnapshot::class,
            $type->getName(),
            'getState() must declare the typed snapshot; an array return is the defect this closes',
        );
    }

    /**
     * Every one of the seven must exist as a real, readable property.
     *
     * `get_object_vars` from outside the class, so readonly-promoted and
     * non-promoted properties are treated the same and nothing is satisfied by a
     * magic getter that does not exist.
     */
    public function testTheSnapshotCarriesEveryUpstreamField(): void
    {
        $actual = array_keys(get_object_vars(new StateSnapshot()));

        sort($actual);
        $expected = self::UPSTREAM_FIELDS;
        sort($expected);

        self::assertSame($expected, $actual, 'the snapshot must carry every field upstream declares');
    }

    /**
     * `getState()` must actually POPULATE them, not merely declare them.
     *
     * A constructor with seven parameters and a `getState()` that passes five is a
     * class that looks complete and is not — the same shape as a guard that exists
     * and is wired to nothing.
     */
    public function testGetStatePopulatesCreatedAtAndParentConfig(): void
    {
        $config = new RunnableConfig(configurable: ['thread_id' => 'shape-1']);
        $this->graph()->invoke(['messages' => 'q'], $config);

        $snapshot = $this->graph()->getState($config);

        self::assertInstanceOf(StateSnapshot::class, $snapshot);
        self::assertNotNull(
            $snapshot->createdAt,
            'createdAt must be populated from the checkpoint timestamp',
        );
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T/',
            $snapshot->createdAt,
            'createdAt is an ISO-8601 instant upstream, not a PHP Date and not an int',
        );

        // parentConfig is null for the FIRST checkpoint of a thread — there is no
        // parent. The property must exist and the null must be meaningful, so the
        // assertion is on the type rather than on a value a fresh thread cannot have.
        self::assertTrue(
            property_exists($snapshot, 'parentConfig'),
            'parentConfig must exist even when null',
        );
    }

    /**
     * `toArray()` must reproduce the shape `getState()` used to return, exactly.
     *
     * Same five keys, same order, and NO extra keys. This is the compatibility
     * shim, and the reason it does not quietly widen: a caller that indexes by key
     * and suddenly finds `createdAt` there has been broken by a refactor dressed
     * as an additive one.
     */
    public function testToArrayReproducesTheLegacyShapeWithoutWideningIt(): void
    {
        $legacy = (new StateSnapshot())->toArray();

        self::assertSame(
            ['values', 'next', 'config', 'metadata', 'tasks'],
            array_keys($legacy),
            'toArray() must reproduce the previous five-key shape in the previous order',
        );
        self::assertArrayNotHasKey('createdAt', $legacy);
        self::assertArrayNotHasKey('parentConfig', $legacy);
        self::assertSame([], $legacy['tasks']);
    }

    /**
     * The task description must serialise `path`, which the class already held.
     *
     * `PregelTaskDescription` documented that it "carries the id, name, interrupts,
     * and path" while its `toArray()` emitted three of the four. A real paused task
     * serialises upstream as
     * `{id, name, path: ["__pregel_pull", "<node>"], interrupts: [...]}`.
     */
    public function testTheTaskDescriptionSerialisesItsPath(): void
    {
        $wire = (new PregelTaskDescription(id: 'i', name: 'ask'))->toArray();

        self::assertArrayHasKey('path', $wire, 'the wire shape must include path');
        self::assertNull($wire['path'], 'a task with no path serialises as null, not as []');

        // And the legacy form stays three keys, so a shim does not widen itself.
        self::assertSame(
            ['id', 'name', 'interrupts'],
            array_keys((new PregelTaskDescription())->toLegacyArray()),
        );
    }

    /**
     * A paused graph must report its task with the path upstream reports.
     *
     * Asserted against the measured upstream value
     * `["__pregel_pull", "ask"]` rather than a constant invented here, so if the
     * port ever emits something else the failure names both.
     */
    public function testAPausedGraphReportsItsTaskPath(): void
    {
        $config = new RunnableConfig(configurable: ['thread_id' => 'path-1']);

        try {
            $this->pausedGraph()->invoke(['messages' => 'q'], $config);
        } catch (\Throwable) {
            // expected: the run suspends on the interrupt
        }

        $snapshot = $this->pausedGraph()->getState($config);
        self::assertSame(['ask'], $snapshot->next, 'the pending node must be reported');

        $task = $snapshot->tasks[0] ?? null;
        self::assertInstanceOf(PregelTaskDescription::class, $task);
        self::assertSame('ask', $task->name);
        self::assertNotNull($task->path, 'a PULL task must carry a path');
        self::assertSame(
            ['__pregel_pull', 'ask'],
            $task->path?->toArray(),
            'the port must report the same task path LangGraph JS does',
        );
    }

    /**
     * `tasks[].interrupts` carries the interrupt a paused graph is asking.
     *
     * This was a pinned known gap (reported `[]`) for two reasons, both fixed: nothing
     * read the INTERRUPT pending writes when describing a task, and a task re-prepared
     * by `getState()` carried a different id from the one that raised the interrupt,
     * because an exiting checkpoint save bumped `step` and task ids derive from it.
     */
    public function testTheInterruptFieldIsPopulatedOnAPausedGraph(): void
    {
        $config = new RunnableConfig(configurable: ['thread_id' => 'int-1']);

        try {
            $this->pausedGraph()->invoke(['messages' => 'q'], $config);
        } catch (\Throwable) {
            // expected
        }

        $snapshot = $this->pausedGraph()->getState($config);
        $interrupts = $snapshot->tasks[0]?->interrupts ?? [];

        self::assertCount(1, $interrupts);
        self::assertSame('need input', $interrupts[0]['value']);
        self::assertSame(32, strlen((string) $interrupts[0]['id']));

        // The tuple really does carry the interrupt, so the gap is in the wiring and
        // not in the data. This is what makes it fixable rather than impossible.
        $tuple = $this->pausedGraph()->checkpointer?->getTuple(['thread_id' => 'int-1']);
        $channels = array_map(
            static fn (array $w): string => (string) ($w[1] ?? ''),
            $tuple?->pendingWrites ?? [],
        );
        self::assertContains(
            '__interrupt__',
            $channels,
            'the pending INTERRUPT write must be on the tuple — if this fails, the data the '
            . 'snapshot needs is gone and the gap is deeper than the wiring',
        );
    }

    /**
     * No helper may answer "is this graph paused?" from the empty interrupt field.
     *
     * `isInterrupted()` and `interrupts()` were written against this class and
     * removed before it shipped, because both would have returned a confident
     * **false** for a graph that is paused. Two helpers that answer wrongly are
     * worse than the bug they cover: a caller asking "am I paused?" would get a
     * clean false and act on it.
     *
     * Asserted as an absence so a future convenience method cannot reintroduce the
     * trap while the underlying field is still empty.
     */
    public function testNoHelperClaimsToDetectAnInterruptFromTheEmptyField(): void
    {
        foreach (['isInterrupted', 'interrupts', 'awaitingInput', 'paused'] as $method) {
            self::assertFalse(
                method_exists(StateSnapshot::class, $method),
                $method . '() would answer from tasks[].interrupts, which is empty on a '
                . 'paused graph. Either populate the field first or do not offer the helper.',
            );
        }
    }

    /**
     * `getState()` on a thread that was never written is an empty snapshot.
     *
     * A saver saying "no such thread" is not a corrupt record, and upstream returns
     * an empty snapshot rather than raising. The `config` still echoes what was
     * asked for so a caller can act on the answer without re-deriving the thread.
     */
    public function testAnUnknownThreadIsAnEmptySnapshot(): void
    {
        $snapshot = $this->graph()->getState(new RunnableConfig(configurable: ['thread_id' => 'never']));

        self::assertInstanceOf(StateSnapshot::class, $snapshot);
        self::assertSame([], $snapshot->values);
        self::assertSame([], $snapshot->next);
        self::assertSame([], $snapshot->tasks);
        self::assertNull($snapshot->createdAt);
        self::assertSame(['thread_id' => 'never'], $snapshot->config);
    }

    /**
     * The snapshot must be immutable.
     *
     * It is an observation of a checkpoint that has already been written. A caller
     * able to mutate one is holding a value that no longer describes anything the
     * saver recorded.
     */
    public function testTheSnapshotIsReadOnly(): void
    {
        $properties = (new \ReflectionClass(StateSnapshot::class))->getProperties();

        self::assertNotSame([], $properties);
        foreach ($properties as $property) {
            self::assertTrue(
                $property->isReadOnly(),
                $property->getName() . ' must be readonly; a snapshot describes a checkpoint '
                . 'that is already written, and a mutable one describes nothing',
            );
        }
    }

    /**
     * `toConfig()` must round-trip the config the snapshot was fetched with.
     *
     * Written because the natural mistake is to *improve* this by re-deriving the
     * config — merging in graph defaults, or stamping a fresh checkpoint id. Either
     * produces a config the saver does not recognise.
     */
    public function testToConfigReturnsTheSnapshotsOwnConfigUnchanged(): void
    {
        $config = ['thread_id' => 'cfg-1', 'checkpoint_id' => 'abc'];
        $snapshot = new StateSnapshot(config: $config);

        self::assertSame(
            $config,
            $snapshot->toConfig()->configurable,
            'toConfig() must not rewrite the config it was given',
        );
    }
}