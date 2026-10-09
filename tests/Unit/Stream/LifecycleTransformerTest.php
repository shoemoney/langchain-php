<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stream;

use LangChain\Tests\Unit\Stream\Support\StreamHelpers;
use LangGraph\Stream\Mux;
use LangGraph\Stream\Transformers\LifecycleTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langgraph-core/src/stream/transformers/lifecycle.test.ts`.
 *
 * `getTerminalStatusOverride` is synchronous here (no event loop), so the `setTimeout` waits upstream uses
 * to let async finalize resolve are gone.
 */
#[CoversClass(LifecycleTransformer::class)]
final class LifecycleTransformerTest extends TestCase
{
    use StreamHelpers;

    /**
     * @return array{0: Mux, 1: LifecycleTransformer}
     */
    private static function install(array $options = []): array
    {
        $mux = new Mux();
        $transformer = new LifecycleTransformer($options);
        $mux->addTransformer($transformer);

        return [$mux, $transformer];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function drain(Mux $mux): array
    {
        return self::collect($mux->events->toAsyncIterable());
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private static function lifecycleOf(array $events): array
    {
        return array_values(array_filter($events, static fn (array $e): bool => $e['method'] === 'lifecycle'));
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private static function firstLifecycle(array $events, string $segment, string $status): ?array
    {
        foreach ($events as $e) {
            if ($e['method'] === 'lifecycle' && ($e['params']['namespace'][0] ?? null) === $segment && $e['params']['data']['event'] === $status) {
                return $e;
            }
        }

        return null;
    }

    public function testEmitsRootLifecycleRunningOnRegisterAndCompletedOnFinalize(): void
    {
        [$mux, $transformer] = self::install(['rootGraphName' => 'myGraph']);

        $transformer->finalize();
        $mux->close();

        $lifecycle = self::lifecycleOf(self::drain($mux));
        $this->assertCount(2, $lifecycle);
        $this->assertSame([], $lifecycle[0]['params']['namespace']);
        $this->assertSame('running', $lifecycle[0]['params']['data']['event']);
        $this->assertSame('myGraph', $lifecycle[0]['params']['data']['graph_name']);
        $this->assertSame([], $lifecycle[1]['params']['namespace']);
        $this->assertSame('completed', $lifecycle[1]['params']['data']['event']);
    }

    public function testSkipsRootEmissionWhenEmitRootOnRegisterIsFalse(): void
    {
        [$mux, $transformer] = self::install(['emitRootOnRegister' => false]);

        $transformer->finalize();
        $mux->close();

        $this->assertCount(0, self::lifecycleOf(self::drain($mux)));
    }

    public function testSynthesizesStartedForUnseenPrefixesBeforeTheTriggeringEvent(): void
    {
        [$mux, $transformer] = self::install(['emitRootOnRegister' => false]);

        $mux->push(['agent:0'], self::makeEvent('values', ['agent:0'], ['x' => 1]));
        $transformer->finalize();
        $mux->close();

        $events = self::drain($mux);
        $this->assertSame('lifecycle', $events[0]['method']);
        $this->assertSame(['agent:0'], $events[0]['params']['namespace']);
        $this->assertSame('started', $events[0]['params']['data']['event']);
        $this->assertSame('agent', $events[0]['params']['data']['graph_name']);
        $this->assertSame('values', $events[1]['method']);
    }

    public function testEmitsLifecycleCompletedForChildNamespacesAfterAParentUpdatesNodeEvent(): void
    {
        [$mux, $transformer] = self::install(['emitRootOnRegister' => false]);

        $mux->push(['researcher:abc'], self::makeEvent('values', ['researcher:abc']));
        $mux->push([], self::makeEvent('updates', [], ['foo' => 1], 'researcher'));
        // The next event flushes the pending completion.
        $mux->push([], self::makeEvent('values', [], ['final' => true]));
        $transformer->finalize();
        $mux->close();

        $events = self::drain($mux);
        $completed = self::firstLifecycle($events, 'researcher:abc', 'completed');
        $this->assertNotNull($completed);

        $updatesIdx = null;
        $completedIdx = null;
        foreach ($events as $i => $e) {
            if ($updatesIdx === null && $e['method'] === 'updates' && $e['params']['namespace'] === []) {
                $updatesIdx = $i;
            }
            if ($completedIdx === null && $e === $completed) {
                $completedIdx = $i;
            }
        }
        $this->assertNotNull($updatesIdx);
        $this->assertGreaterThan($updatesIdx, $completedIdx, "the child's completed must come after the parent updates");
    }

    public function testPrefersExactTasksResultIdsOverPendingUpdatesNodeCompletions(): void
    {
        [$mux] = self::install(['emitRootOnRegister' => false]);

        $mux->push(['researcher:a'], self::makeEvent('values', ['researcher:a']));
        $mux->push(['researcher:b'], self::makeEvent('values', ['researcher:b']));
        // This ambiguous update would otherwise complete the oldest researcher child.
        $mux->push([], self::makeEvent('updates', [], ['result' => 'b'], 'researcher'));
        $mux->push([], self::makeEvent('tasks', [], ['id' => 'b', 'name' => 'researcher', 'result' => ['result' => 'b'], 'interrupts' => []]));
        // The next event flushes the exact completion from the task result.
        $mux->push([], self::makeEvent('values', [], ['final' => true]));
        $mux->close();

        $events = self::drain($mux);
        $indexOf = static function (callable $match) use ($events): int {
            foreach ($events as $i => $e) {
                if ($match($e)) {
                    return $i;
                }
            }

            return -1;
        };
        $taskResultIdx = $indexOf(static fn (array $e): bool => $e['method'] === 'tasks' && $e['params']['data']['id'] === 'b');
        $finalValuesIdx = $indexOf(static fn (array $e): bool => $e['method'] === 'values' && $e['params']['namespace'] === [] && ($e['params']['data']['final'] ?? false) === true);
        $completedIdx = $indexOf(static fn (array $e): bool => $e['method'] === 'lifecycle' && ($e['params']['namespace'][0] ?? null) === 'researcher:b' && $e['params']['data']['event'] === 'completed');
        $otherChildCompletedIdx = $indexOf(static fn (array $e): bool => $e['method'] === 'lifecycle' && ($e['params']['namespace'][0] ?? null) === 'researcher:a' && $e['params']['data']['event'] === 'completed');

        $this->assertGreaterThan($taskResultIdx, $completedIdx);
        $this->assertLessThan($finalValuesIdx, $completedIdx);
        // The ambiguous update must not complete the oldest sibling before the exact task result identifies the real child.
        $this->assertGreaterThan($finalValuesIdx, $otherChildCompletedIdx);
    }

    public function testEmitsASingleCompletionWhenTasksResultAndUpdatesNodeBothSignalTheSameChild(): void
    {
        [$mux] = self::install(['emitRootOnRegister' => false]);

        $mux->push(['researcher:abc'], self::makeEvent('values', ['researcher:abc']));
        $mux->push([], self::makeEvent('tasks', [], ['id' => 'abc', 'name' => 'researcher', 'result' => ['ok' => true], 'interrupts' => []]));
        // Flushes the task-result completion, then must not enqueue another one.
        $mux->push([], self::makeEvent('updates', [], ['ok' => true], 'researcher'));
        $mux->push([], self::makeEvent('values', [], ['final' => true]));
        $mux->close();

        $completedForChild = array_filter(
            self::lifecycleOf(self::drain($mux)),
            static fn (array $e): bool => ($e['params']['namespace'][0] ?? null) === 'researcher:abc' && $e['params']['data']['event'] === 'completed',
        );

        $this->assertCount(1, $completedForChild);
    }

    public function testCascadesFailedStatusToAllStillStartedNamespacesOnFail(): void
    {
        [$mux, $transformer] = self::install();

        $mux->push(['a:0'], self::makeEvent('values', ['a:0']));
        $mux->push(['a:0', 'b:0'], self::makeEvent('values', ['a:0', 'b:0']));
        $transformer->fail(new \RuntimeException('boom'));
        $mux->close();

        $failed = array_values(array_filter(
            self::lifecycleOf(self::drain($mux)),
            static fn (array $e): bool => $e['params']['data']['event'] === 'failed',
        ));
        // Failed for [a:0], [a:0, b:0], and root.
        $this->assertCount(3, $failed);
        $rootFailed = array_values(array_filter($failed, static fn (array $e): bool => $e['params']['namespace'] === []));
        $this->assertCount(1, $rootFailed);
        $this->assertSame('boom', $rootFailed[0]['params']['data']['error']);
    }

    public function testSuppressesUpstreamLifecycleStartedEventsAndReEmitsWithStashedCause(): void
    {
        [$mux, $transformer] = self::install(['emitRootOnRegister' => false]);

        $cause = ['type' => 'tool_call'];
        $mux->push(['tools:t1'], self::makeEvent('lifecycle', ['tools:t1'], ['event' => 'started', 'graph_name' => 'tools', 'cause' => $cause]));
        $transformer->finalize();
        $mux->close();

        $startedForTool = array_values(array_filter(
            self::lifecycleOf(self::drain($mux)),
            static fn (array $e): bool => ($e['params']['namespace'][0] ?? null) === 'tools:t1' && $e['params']['data']['event'] === 'started',
        ));
        // The upstream event is suppressed; one authoritative `started` carrying the stashed cause remains.
        $this->assertCount(1, $startedForTool);
        $this->assertSame($cause, $startedForTool[0]['params']['data']['cause']);
    }

    public function testInfersGraphNameFromTheLastNamespaceSegmentByDefault(): void
    {
        [$mux, $transformer] = self::install(['emitRootOnRegister' => false]);

        $mux->push(['subagent:xyz'], self::makeEvent('values', ['subagent:xyz']));
        $transformer->finalize();
        $mux->close();

        $started = self::firstLifecycle(self::drain($mux), 'subagent:xyz', 'started');
        $this->assertNotNull($started);
        $this->assertSame('subagent', $started['params']['data']['graph_name']);
    }

    public function testRespectsGetTerminalStatusOverrideWhenProvided(): void
    {
        [$mux, $transformer] = self::install(['getTerminalStatusOverride' => static fn (): string => 'interrupted']);

        $mux->push(['a:0'], self::makeEvent('values', ['a:0']));
        $transformer->finalize();
        $mux->close();

        $interrupted = array_values(array_filter(
            self::lifecycleOf(self::drain($mux)),
            static fn (array $e): bool => $e['params']['data']['event'] === 'interrupted',
        ));
        // Both the child and the root land on interrupted.
        $this->assertGreaterThanOrEqual(2, \count($interrupted));
        $this->assertNotEmpty(array_filter($interrupted, static fn (array $e): bool => $e['params']['namespace'] === []));
    }

    public function testCascadesInterruptedWhenInputRequestedEventsAreSeen(): void
    {
        [$mux, $transformer] = self::install();

        $mux->push([], self::makeEvent('input', [], ['event' => 'requested', 'id' => 'int-1']));
        $transformer->finalize();
        $mux->close();

        $interrupted = array_filter(
            self::lifecycleOf(self::drain($mux)),
            static fn (array $e): bool => $e['params']['data']['event'] === 'interrupted',
        );
        $this->assertGreaterThanOrEqual(1, \count($interrupted));
    }

    public function testFallsThroughToTheParsedSegmentWhenATasksStartHasNoMetadata(): void
    {
        [$mux, $transformer] = self::install(['emitRootOnRegister' => false]);

        $mux->push(['agent:abc'], self::makeEvent('tasks', ['agent:abc'], ['id' => 't1', 'name' => 'tool', 'input' => null, 'triggers' => []]));
        $transformer->finalize();
        $mux->close();

        $started = self::firstLifecycle(self::drain($mux), 'agent:abc', 'started');
        $this->assertNotNull($started);
        $this->assertSame('agent', $started['params']['data']['graph_name']);
        $this->assertArrayNotHasKey('cause', $started['params']['data']);
    }

    public function testTreatsAnExplicitEmptyMetadataDictLikeNoMetadata(): void
    {
        [$mux, $transformer] = self::install(['emitRootOnRegister' => false]);

        $mux->push(['agent:abc'], self::makeEvent('tasks', ['agent:abc'], ['id' => 't1', 'name' => 'tool', 'input' => null, 'triggers' => [], 'metadata' => []]));
        $transformer->finalize();
        $mux->close();

        $started = self::firstLifecycle(self::drain($mux), 'agent:abc', 'started');
        $this->assertNotNull($started);
        $this->assertSame('agent', $started['params']['data']['graph_name']);
        $this->assertArrayNotHasKey('cause', $started['params']['data']);
    }

    public function testNamesASubagentFromLcAgentNameAndRecoversTheToolCallCause(): void
    {
        [$mux, $transformer] = self::install(['emitRootOnRegister' => false]);

        // The supervisor's `tools` push task carries its own lc_agent_name and a
        // `tool_call_with_context` dict as input; the task id seeds the child segment.
        $mux->push([], self::makeEvent('tasks', [], [
            'id' => 'tools_task_1',
            'name' => 'tools',
            'triggers' => [],
            'metadata' => ['lc_agent_name' => 'supervisor'],
            'input' => [
                '__type' => 'tool_call_with_context',
                'tool_call' => ['name' => 'call_weather', 'args' => ['city' => 'Boston'], 'id' => 'call_w', 'type' => 'tool_call'],
                'state' => [],
            ],
        ]));
        // The inner agent's first node task streams under the parent task's namespace segment.
        $mux->push(['tools:tools_task_1'], self::makeEvent('tasks', ['tools:tools_task_1'], [
            'id' => 'inner_model_1',
            'name' => 'model',
            'input' => null,
            'triggers' => [],
            'metadata' => ['lc_agent_name' => 'weather_agent'],
        ]));
        $transformer->finalize();
        $mux->close();

        $started = self::firstLifecycle(self::drain($mux), 'tools:tools_task_1', 'started');
        $this->assertNotNull($started);
        $this->assertSame('weather_agent', $started['params']['data']['graph_name']);
        $this->assertSame(['type' => 'toolCall', 'tool_call_id' => 'call_w'], $started['params']['data']['cause']);
    }

    public function testRecoversTheToolCallCauseFromALegacyListInputShape(): void
    {
        [$mux, $transformer] = self::install(['emitRootOnRegister' => false]);

        $mux->push([], self::makeEvent('tasks', [], [
            'id' => 'tools_task_1',
            'name' => 'tools',
            'triggers' => [],
            'metadata' => ['lc_agent_name' => 'supervisor'],
            'input' => [['name' => 'call_weather', 'args' => ['city' => 'SF'], 'id' => 'call_w']],
        ]));
        $mux->push(['tools:tools_task_1'], self::makeEvent('tasks', ['tools:tools_task_1'], [
            'id' => 'inner_model_1',
            'name' => 'model',
            'input' => null,
            'triggers' => [],
            'metadata' => ['lc_agent_name' => 'weather_agent'],
        ]));
        $transformer->finalize();
        $mux->close();

        $started = self::firstLifecycle(self::drain($mux), 'tools:tools_task_1', 'started');
        $this->assertNotNull($started);
        $this->assertSame('weather_agent', $started['params']['data']['graph_name']);
        $this->assertSame(['type' => 'toolCall', 'tool_call_id' => 'call_w'], $started['params']['data']['cause']);
    }

    public function testSurfacesASameNamedRecursiveSubagentWithItsToolCallCause(): void
    {
        [$mux, $transformer] = self::install(['emitRootOnRegister' => false]);

        $mux->push([], self::makeEvent('tasks', [], [
            'id' => 'tools_task_1',
            'name' => 'tools',
            'triggers' => [],
            'metadata' => ['lc_agent_name' => 'weather_agent'],
            'input' => [
                '__type' => 'tool_call_with_context',
                'tool_call' => ['name' => 'recurse', 'args' => [], 'id' => 'call_x', 'type' => 'tool_call'],
                'state' => [],
            ],
        ]));
        $mux->push(['tools:tools_task_1'], self::makeEvent('tasks', ['tools:tools_task_1'], [
            'id' => 'inner_model_1',
            'name' => 'model',
            'input' => null,
            'triggers' => [],
            'metadata' => ['lc_agent_name' => 'weather_agent'],
        ]));
        $transformer->finalize();
        $mux->close();

        $started = self::firstLifecycle(self::drain($mux), 'tools:tools_task_1', 'started');
        $this->assertNotNull($started);
        $this->assertSame('weather_agent', $started['params']['data']['graph_name']);
        $this->assertSame(['type' => 'toolCall', 'tool_call_id' => 'call_x'], $started['params']['data']['cause']);
    }

    public function testExposesALifecycleLogProjectionIterableByConsumers(): void
    {
        $mux = new Mux();
        $transformer = new LifecycleTransformer(['rootGraphName' => 'g']);
        $projection = $transformer->init();
        $mux->addTransformer($transformer);

        $mux->push(['child:0'], self::makeEvent('values', ['child:0']));
        $transformer->finalize();
        $mux->close();

        // At least: root running, child started, cascade completed for child + root.
        $this->assertGreaterThanOrEqual(3, \count(self::collect($projection['_lifecycleLog']->toAsyncIterable())));
    }

    public function testFilterEntriesScopesTheLogToASubtreeFromAnOffset(): void
    {
        $mux = new Mux();
        $transformer = new LifecycleTransformer(['rootGraphName' => 'g']);
        $projection = $transformer->init();
        $mux->addTransformer($transformer);

        $mux->push(['a:0', 'b:0'], self::makeEvent('values', ['a:0', 'b:0']));
        $mux->push(['z:0'], self::makeEvent('values', ['z:0']));
        $transformer->finalize();

        $inA = self::collect(LifecycleTransformer::filterEntries($projection['_lifecycleLog'], ['a:0']));
        $this->assertSame(
            [['a:0'], ['a:0', 'b:0'], ['a:0'], ['a:0', 'b:0']],
            array_map(static fn (array $e): array => $e['namespace'], $inA),
        );

        $all = self::collect(LifecycleTransformer::filterEntries($projection['_lifecycleLog'], []));
        $skipFirst = self::collect(LifecycleTransformer::filterEntries($projection['_lifecycleLog'], [], 1));
        $this->assertSame('running', $all[0]['event']);
        $this->assertSame(\array_slice($all, 1), $skipFirst);
    }
}
