<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangGraph\Channels\LastValue;
use LangGraph\Channels\Missing;
use LangGraph\Errors\EmptyChannelError;
use LangGraph\Errors\GraphDrained;
use LangGraph\Errors\GraphInterrupt;
use LangGraph\Errors\GraphRecursionError;
use LangGraph\Errors\GraphValueError;
use LangGraph\Errors\Guard;
use LangGraph\Errors\NodeInterrupt;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\IO;
use LangGraph\Pregel\Send;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The stream projections and the engine's control-flow errors.
 */
#[CoversClass(IO::class)]
#[CoversClass(Command::class)]
#[CoversClass(Send::class)]
#[CoversClass(GraphInterrupt::class)]
#[CoversClass(NodeInterrupt::class)]
#[CoversClass(GraphDrained::class)]
#[CoversClass(GraphRecursionError::class)]
#[CoversClass(GraphValueError::class)]
#[CoversClass(Guard::class)]
final class IOAndErrorsTest extends TestCase
{
    // ---- errors ----------------------------------------------------------

    public function testAnInterruptCarriesItsPayloads(): void
    {
        $e = new GraphInterrupt([
            ['id' => 'task-1', 'value' => 'question?'],
            ['id' => null, 'value' => ['nested' => true]],
        ]);

        $this->assertCount(2, $e->interrupts);
        $this->assertSame('question?', $e->interrupts[0]['value']);
        $this->assertSame(['nested' => true], $e->interrupts[1]['value']);

        // The message is a JSON rendering, so a bare `getMessage()` is still
        // informative without the payload living only in the message.
        $this->assertStringContainsString('question?', $e->getMessage());
    }

    public function testAnInterruptWithNoPayloadsIsEmptyNotNull(): void
    {
        $e = new GraphInterrupt();

        $this->assertSame([], $e->interrupts);
    }

    public function testNodeInterruptIsAGraphInterrupt(): void
    {
        $e = new NodeInterrupt('pause here');

        $this->assertInstanceOf(GraphInterrupt::class, $e);
        $this->assertSame([['id' => null, 'value' => 'pause here']], $e->interrupts);
    }

    public function testGraphDrainedIsABubbleUpWithAReason(): void
    {
        $e = new GraphDrained('sigterm');

        $this->assertSame('sigterm', $e->reason);
        $this->assertStringContainsString('Graph drained: sigterm', $e->getMessage());
        $this->assertSame('shutdown', (new GraphDrained())->reason, 'shutdown is the default');
    }

    public function testGraphRecursionErrorCarriesItsCode(): void
    {
        $e = new GraphRecursionError('Recursion limit of 5 reached');

        $this->assertSame('GRAPH_RECURSION_LIMIT', $e->getLcErrorCode());
    }

    #[DataProvider('guardCases')]
    public function testGuards(bool $expected, mixed $value): void
    {
        $this->assertSame($expected, Guard::isGraphInterrupt($value));
    }

    /**
     * @return iterable<string, array{bool, mixed}>
     */
    public static function guardCases(): iterable
    {
        yield 'a graph interrupt' => [true, new GraphInterrupt()];
        yield 'a node interrupt' => [true, new NodeInterrupt('x')];
        yield 'a drain is not an interrupt' => [false, new GraphDrained()];
        yield 'a value error is not an interrupt' => [false, new GraphValueError('x')];
        yield 'a plain error is not an interrupt' => [false, new \RuntimeException('x')];
        yield 'null' => [false, null];
    }

    public function testBubbleUpGuard(): void
    {
        $this->assertTrue(Guard::isGraphBubbleUp(new GraphInterrupt()));
        $this->assertTrue(Guard::isGraphBubbleUp(new GraphDrained()));
        $this->assertFalse(Guard::isGraphBubbleUp(new GraphValueError('x')));
        $this->assertFalse(Guard::isGraphBubbleUp(new \RuntimeException('x')));
    }

    public function testDrainedGuard(): void
    {
        $this->assertTrue(Guard::isGraphDrained(new GraphDrained()));
        $this->assertFalse(Guard::isGraphDrained(new GraphInterrupt()));
    }

    // ---- Send / Command --------------------------------------------------

    public function testSendRoundTripsThroughItsArrayForm(): void
    {
        $send = new Send('worker', ['n' => 1]);

        $this->assertTrue(Send::isSend($send));
        $this->assertTrue(Send::isSend($send->toArray()));
        $this->assertTrue(Send::isSendInterface($send->toArray()));
        $this->assertFalse(Send::isSend(['node' => 'w', 'args' => 1]), 'no lg_name means not a Send');
    }

    public function testCommandNormalisesGotoToAList(): void
    {
        $one = new Command(goto: 'b');
        $many = new Command(goto: ['b', 'c']);
        $send = new Command(goto: new Send('w', 1));
        $sends = new Command(goto: [new Send('w', 1), new Send('w', 2)]);

        $this->assertSame(['b'], $one->gotoList());
        $this->assertSame(['b', 'c'], $many->gotoList());
        $this->assertCount(1, $send->gotoList());
        $this->assertCount(2, $sends->gotoList());
        $this->assertInstanceOf(Send::class, $sends->gotoList()[0]);
    }

    public function testCommandWithNoGotoHasAnEmptyList(): void
    {
        $this->assertSame([], (new Command())->gotoList());
        $this->assertSame([], (new Command(goto: []))->gotoList());
    }

    public function testCommandUpdateAsTuples(): void
    {
        $this->assertSame(
            [['a', 1], ['b', 2]],
            (new Command(update: ['a' => 1, 'b' => 2]))->updateAsTuples(),
        );

        // An explicit pair list passes through untouched.
        $this->assertSame(
            [['a', 1]],
            (new Command(update: [['a', 1]]))->updateAsTuples(),
        );

        // A bare value goes to the whole-state channel.
        $this->assertSame(
            [['__root__', 'scalar']],
            (new Command(update: 'scalar'))->updateAsTuples(),
        );

        $this->assertSame([], (new Command())->updateAsTuples());
    }

    public function testCommandIsDetectedInBothForms(): void
    {
        $this->assertTrue(Command::isCommand(new Command()));
        $this->assertTrue(Command::isCommand((new Command())->toArray()));
        $this->assertFalse(Command::isCommand(['update' => []]));
        $this->assertFalse(Command::isCommand(null));
    }

    // ---- mapInput --------------------------------------------------------

    public function testMapInputWithASingleChannelWrapsTheValue(): void
    {
        $writes = iterator_to_array(IO::mapInput('__start__', ['items' => [1]]), false);

        $this->assertSame([['__start__', ['items' => [1]]]], $writes);
    }

    public function testMapInputWithSeveralChannelsFiltersByKey(): void
    {
        $writes = iterator_to_array(IO::mapInput(['a', 'b'], ['a' => 1, 'b' => 2, 'c' => 3]), false);

        // `c` is dropped: there is no channel to write it to, and inventing one
        // would silently widen the graph's accepted input.
        $this->assertSame([['a', 1], ['b', 2]], $writes);
    }

    public function testMapInputWithSeveralChannelsRejectsANonObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an object');

        iterator_to_array(IO::mapInput(['a', 'b'], 'scalar'), false);
    }

    public function testMapInputWithNullYieldsNothing(): void
    {
        $this->assertSame([], iterator_to_array(IO::mapInput('x', null), false));
    }

    // ---- mapCommand ------------------------------------------------------

    public function testMapCommandRoutesGotoToABranchChannel(): void
    {
        $writes = iterator_to_array(IO::mapCommand(new Command(goto: 'b')), false);

        $this->assertSame(
            [[Constants::NULL_TASK_ID, 'branch:to:b', '__start__']],
            $writes,
        );
    }

    public function testMapCommandRoutesASendToTheTasksChannel(): void
    {
        $writes = iterator_to_array(IO::mapCommand(new Command(goto: new Send('w', 1))), false);

        $this->assertSame(Constants::TASKS, $writes[0][1]);
        $this->assertInstanceOf(Send::class, $writes[0][2]);
    }

    public function testMapCommandRoutesResumeToTheResumeChannel(): void
    {
        $writes = iterator_to_array(IO::mapCommand(new Command(resume: 'answer')), false);

        $this->assertSame(
            [[Constants::NULL_TASK_ID, Constants::RESUME, 'answer']],
            $writes,
        );
    }

    public function testMapCommandAppendsToAnExistingTaskResume(): void
    {
        // A task can be interrupted twice and resumed twice, so a per-task
        // resume appends to what that task already has rather than replacing it.
        $hash = str_repeat('a', 32);
        $pending = [[$hash, Constants::RESUME, 'first']];

        $writes = iterator_to_array(
            IO::mapCommand(new Command(resume: [$hash => 'second']), $pending),
            false,
        );

        $this->assertSame($hash, $writes[0][0]);
        $this->assertSame(Constants::RESUME, $writes[0][1]);
        $this->assertSame(['first', 'second'], $writes[0][2]);
    }

    public function testMapCommandMapsUpdateToNamedChannels(): void
    {
        $writes = iterator_to_array(IO::mapCommand(new Command(update: ['a' => 1, 'b' => 2])), false);

        $this->assertSame(
            [
                [Constants::NULL_TASK_ID, 'a', 1],
                [Constants::NULL_TASK_ID, 'b', 2],
            ],
            $writes,
        );
    }

    public function testMapCommandRejectsAParentAddress(): void
    {
        $this->expectException(\LangGraph\Errors\InvalidUpdateError::class);
        $this->expectExceptionMessage('There is no parent graph.');

        iterator_to_array(IO::mapCommand(new Command(graph: Command::PARENT)), false);
    }

    // ---- mapOutputValues -------------------------------------------------

    public function testMapOutputValuesYieldsOnlyWhenAnOutputChannelWasWritten(): void
    {
        $channels = ['a' => new LastValue(), 'internal' => new LastValue()];
        $channels['a']->update([1]);
        $channels['internal']->update(['x']);

        // Nothing written to the output channel: no chunk. A `values` event per
        // step regardless would be indistinguishable from progress.
        $none = iterator_to_array(IO::mapOutputValues(['a'], [['internal', 'x']], $channels), false);
        $this->assertSame([], $none);

        $some = iterator_to_array(IO::mapOutputValues(['a'], [['a', 2]], $channels), false);
        $this->assertSame([['a' => 1]], $some);
    }

    public function testMapOutputValuesWithTrueAlwaysYields(): void
    {
        $channels = ['a' => new LastValue()];
        $channels['a']->update([1]);

        $out = iterator_to_array(IO::mapOutputValues(['a'], true, $channels), false);
        $this->assertSame([['a' => 1]], $out);
    }

    public function testMapOutputValuesSkipsEmptyChannels(): void
    {
        $channels = ['a' => new LastValue(), 'b' => new LastValue()];
        $channels['a']->update([1]);

        $out = iterator_to_array(IO::mapOutputValues(['a', 'b'], true, $channels), false);

        // `b` has no value, so it is absent rather than explicitly null.
        $this->assertSame([['a' => 1]], $out);
    }

    // ---- mapOutputUpdates ------------------------------------------------

    public function testMapOutputUpdatesAttributesWritesToTheirNode(): void
    {
        $channels = ['a' => new LastValue()];
        $task = new \LangGraph\Pregel\PregelExecutableTask(id: 't', name: 'worker');

        $out = iterator_to_array(IO::mapOutputUpdates(['a'], [[$task, [['a', 1]]]]), false);

        $this->assertSame([['worker' => ['a' => 1]]], $out);
    }

    public function testMapOutputUpdatesExcludesErrorAndInterruptWrites(): void
    {
        $channels = ['a' => new LastValue()];
        $task = new \LangGraph\Pregel\PregelExecutableTask(id: 't', name: 'worker');

        // A failed or paused task produced no state. Reporting it as an update
        // would be a lie about what the graph did.
        $errored = iterator_to_array(
            IO::mapOutputUpdates(['a'], [[$task, [[Constants::ERROR, 'boom']]]]),
            false,
        );
        $this->assertSame([], $errored);

        $interrupted = iterator_to_array(
            IO::mapOutputUpdates(['a'], [[$task, [[Constants::INTERRUPT, ['id' => null, 'value' => 'q']]]]]),
            false,
        );
        $this->assertSame([], $interrupted);
    }

    public function testMapOutputUpdatesExcludesHiddenTasks(): void
    {
        $channels = ['a' => new LastValue()];
        $config = new \LangChain\Runnables\RunnableConfig();
        $config->tags = [Constants::TAG_HIDDEN];
        $task = new \LangGraph\Pregel\PregelExecutableTask(id: 't', name: 'internal', config: $config);

        $out = iterator_to_array(IO::mapOutputUpdates(['a'], [[$task, [['a', 1]]]]), false);
        $this->assertSame([], $out);
    }

    public function testMapOutputUpdatesSplitsRepeatedWritesToOneChannel(): void
    {
        $channels = ['a' => new LastValue()];
        $task = new \LangGraph\Pregel\PregelExecutableTask(id: 't', name: 'worker');

        // Two writes to the same channel become two entries. Merging them
        // would lose information a caller may need.
        $out = iterator_to_array(
            IO::mapOutputUpdates(['a'], [[$task, [['a', 1], ['a', 2]]]]),
            false,
        );

        $this->assertSame([['worker' => [['a' => 1], ['a' => 2]]]], $out);
    }

    public function testMapOutputUpdatesGroupsRepeatedTasksIntoAList(): void
    {
        $channels = ['a' => new LastValue()];
        $a = new \LangGraph\Pregel\PregelExecutableTask(id: 't1', name: 'worker');
        $b = new \LangGraph\Pregel\PregelExecutableTask(id: 't2', name: 'worker');

        $out = iterator_to_array(
            IO::mapOutputUpdates(['a'], [[$a, [['a', 1]]], [$b, [['a', 2]]]]),
            false,
        );

        $this->assertSame([['worker' => [['a' => 1], ['a' => 2]]]], $out);
    }

    public function testMapOutputUpdatesMarksCachedResults(): void
    {
        $channels = ['a' => new LastValue()];
        $task = new \LangGraph\Pregel\PregelExecutableTask(id: 't', name: 'worker');

        $out = iterator_to_array(IO::mapOutputUpdates(['a'], [[$task, [['a', 1]]]], true), false);

        // A consumer must be able to tell a node that ran from one whose result
        // came out of the cache.
        $this->assertSame([['worker' => ['a' => 1], '__metadata__' => ['cached' => true]]], $out);
    }

    public function testMapOutputUpdatesPrefersReturnWrites(): void
    {
        $channels = ['a' => new LastValue()];
        $task = new \LangGraph\Pregel\PregelExecutableTask(id: 't', name: 'worker');

        $out = iterator_to_array(
            IO::mapOutputUpdates(['a'], [[$task, [[Constants::RETURN, 'direct'], ['a', 1]]]]),
            false,
        );

        $this->assertSame([['worker' => 'direct']], $out);
    }

    // ---- readChannel -----------------------------------------------------

    public function testReadChannelSwallowsTheEmptyErrorByDefault(): void
    {
        $channels = ['a' => new LastValue()];

        $this->assertNull(IO::readChannel($channels, 'a'));

        // Propagating instead is what lets `procInput` tell "empty" from "null".
        $this->expectException(EmptyChannelError::class);
        IO::readChannel($channels, 'a', false);
    }

    public function testReadChannelCanReturnTheExceptionItself(): void
    {
        $channels = ['a' => new LastValue()];

        $result = IO::readChannel($channels, 'a', false, true);

        $this->assertInstanceOf(EmptyChannelError::class, $result);
    }

    public function testReadChannelsSkipsEmptyOnesByDefault(): void
    {
        $channels = ['a' => new LastValue(), 'b' => new LastValue()];
        $channels['a']->update([1]);

        $this->assertSame(['a' => 1], IO::readChannels($channels, ['a', 'b']));
    }

    public function testMissingDistinguishesAbsentFromNull(): void
    {
        // PHP has no `undefined`, so a parameter default and a channel's
        // "no value yet" state would both collapse onto `null` — which is
        // *also* a perfectly valid channel value. `Missing` is what keeps
        // "absent" and "explicitly null" apart.
        $this->assertTrue(Missing::isMissing(new Missing()));
        $this->assertFalse(Missing::isMissing(null));
        $this->assertFalse(Missing::isMissing(0));
        $this->assertFalse(Missing::isMissing(''));
        $this->assertFalse(Missing::isMissing(false));

        // A channel seeded with a factory is available before anything writes
        // to it, which is the distinction the marker exists to preserve.
        $seeded = new LastValue(static fn (): mixed => null);
        $this->assertTrue($seeded->isAvailable());
        $this->assertNull($seeded->get());
    }
}
