<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Errors\GraphInterrupt;
use LangGraph\Errors\GraphValueError;
use LangGraph\Pregel\Algorithm;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\InterruptOptions;
use LangGraph\Pregel\PendingWritesIndex;
use LangGraph\Pregel\PregelScratchpad;
use LangGraph\Utils\Hash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/**
 * `interrupt()` against a hand-built task config, so each branch of the function is
 * reached directly instead of through a whole graph.
 */
#[CoversClass(PendingWritesIndex::class)]
#[CoversClass(PregelScratchpad::class)]
#[CoversClass(InterruptOptions::class)]
final class InterruptScratchpadTest extends TestCase
{
    /**
     * @param list<array<int, mixed>> $sent  Receives every batch handed to `__pregel_send`.
     */
    private static function taskConfig(PregelScratchpad $scratchpad, array &$sent, string $ns = 'node:abc'): RunnableConfig
    {
        return new RunnableConfig(configurable: [
            Constants::CONFIG_KEY_CHECKPOINTER => new \stdClass(),
            Constants::CONFIG_KEY_SCRATCHPAD => $scratchpad,
            Constants::CONFIG_KEY_CHECKPOINT_NS => $ns,
            Constants::CONFIG_KEY_SEND => static function (array $writes) use (&$sent): void {
                $sent[] = $writes;
            },
        ]);
    }

    private static function within(RunnableConfig $config, callable $fn): mixed
    {
        return PregelScratchpad::withConfig($config, $fn);
    }

    public function testWithNoResumeValueItThrowsAnInterruptCarryingTheValueAndAnId(): void
    {
        $sent = [];
        $config = self::taskConfig(new PregelScratchpad(), $sent, 'node:abc');

        try {
            self::within($config, static fn () => interrupt('q'));
            self::fail('expected a GraphInterrupt');
        } catch (GraphInterrupt $e) {
            self::assertSame([['id' => Hash::xxh3('node:abc'), 'value' => 'q']], $e->interrupts);
        }

        self::assertSame([], $sent, 'nothing is persisted for an unanswered question');
    }

    public function testAReplayedResumeValueIsReturnedByPositionAndPersistedThroughThatPosition(): void
    {
        $sent = [];
        $scratchpad = new PregelScratchpad(resume: ['A', 'B']);
        $config = self::taskConfig($scratchpad, $sent);

        self::assertSame('A', self::within($config, static fn () => interrupt('one')));
        self::assertSame('B', self::within($config, static fn () => interrupt('two')));

        self::assertSame(
            [[[Constants::RESUME, ['A']]], [[Constants::RESUME, ['A', 'B']]]],
            $sent,
            'each replay persists only the values up to the interrupt being consumed',
        );
    }

    public function testTheGraphWideResumeAnswersTheNextUnansweredInterruptAndIsRecorded(): void
    {
        $sent = [];
        $scratchpad = new PregelScratchpad(resume: ['A'], nullResume: 'B');
        $config = self::taskConfig($scratchpad, $sent);

        self::assertSame('A', self::within($config, static fn () => interrupt('one')));
        self::assertSame('B', self::within($config, static fn () => interrupt('two')));

        self::assertSame(['A', 'B'], $scratchpad->resume);
        self::assertNull($scratchpad->nullResume, 'it is consumed');
        self::assertSame([[Constants::RESUME, ['A', 'B']]], $sent[1]);

        $this->expectException(GraphInterrupt::class);
        self::within($config, static fn () => interrupt('three'));
    }

    public function testAGraphWideResumeDoesNotSkipAnUnansweredPosition(): void
    {
        $sent = [];
        // interruptCounter is already past index 0 but only zero values were recorded: a state that
        // cannot be reached through the engine, and must not silently hand B to the wrong question.
        $scratchpad = new PregelScratchpad(resume: [], nullResume: 'B');
        $scratchpad->interruptCounter = 0;
        $config = self::taskConfig($scratchpad, $sent);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Resume length mismatch: 0 !== 1');

        self::within($config, static fn () => interrupt('q'));
    }

    public function testAResumeValueThatIsLegitimatelyFalsyIsStillReplayed(): void
    {
        $sent = [];
        $scratchpad = new PregelScratchpad(resume: [0, '', false]);
        $config = self::taskConfig($scratchpad, $sent);

        self::assertSame(0, self::within($config, static fn () => interrupt('a')));
        self::assertSame('', self::within($config, static fn () => interrupt('b')));
        self::assertFalse(self::within($config, static fn () => interrupt('c')));
    }

    public function testWithoutACheckpointerItRaisesAValueErrorWithAnErrorCode(): void
    {
        $sent = [];
        $config = self::taskConfig(new PregelScratchpad(), $sent);
        unset($config->configurable[Constants::CONFIG_KEY_CHECKPOINTER]);

        try {
            self::within($config, static fn () => interrupt('q'));
            self::fail('expected a GraphValueError');
        } catch (GraphValueError $e) {
            self::assertSame('No checkpointer set', $e->getMessage());
            self::assertSame('MISSING_CHECKPOINTER', $e->getLcErrorCode());
        }
    }

    public function testOutsideAGraphItRaisesAValueError(): void
    {
        $this->expectException(GraphValueError::class);

        interrupt('nowhere');
    }

    public function testTheResponseSchemaIsAttachedFromEitherOptionsForm(): void
    {
        $schema = ['type' => 'string'];

        foreach ([new InterruptOptions($schema), ['responseSchema' => $schema]] as $options) {
            $sent = [];
            $config = self::taskConfig(new PregelScratchpad(), $sent);

            try {
                self::within($config, static fn () => interrupt('q', $options));
            } catch (GraphInterrupt $e) {
                self::assertSame($schema, $e->interrupts[0]['response_schema']);
            }
        }
    }

    public function testTheInterruptCounterAdvancesOnEveryCall(): void
    {
        $sent = [];
        $scratchpad = new PregelScratchpad(resume: ['A']);
        $config = self::taskConfig($scratchpad, $sent);

        self::within($config, static fn () => interrupt('one'));
        self::assertSame(0, $scratchpad->interruptCounter);

        try {
            self::within($config, static fn () => interrupt('two'));
        } catch (GraphInterrupt) {
        }
        self::assertSame(1, $scratchpad->interruptCounter);
    }

    // ---- the pending-writes plumbing around it ---------------------------------

    public function testBuildScratchpadFlattensTheRecordedResumeListsOneLevel(): void
    {
        $index = PendingWritesIndex::build([
            ['t1', Constants::RESUME, ['A']],
            ['t1', Constants::RESUME, ['A', 'B']],
            ['t1', Constants::RESUME, 'C'],
        ]);

        $scratchpad = Algorithm::buildScratchpad('t1', null, null, 'hash', $index);

        self::assertSame(['A', 'A', 'B', 'C'], $scratchpad->resume);
    }

    public function testBuildScratchpadKeepsAMappedListResumeValueIntact(): void
    {
        $index = PendingWritesIndex::build([]);

        $scratchpad = Algorithm::buildScratchpad('t1', null, ['hash' => ['a', 'list']], 'hash', $index);

        self::assertSame([['a', 'list']], $scratchpad->resume, 'the map value answers one interrupt, whatever its shape');
    }

    public function testBuildScratchpadIgnoresAMapEntryForADifferentNamespace(): void
    {
        $scratchpad = Algorithm::buildScratchpad('t1', null, ['other' => 'x'], 'hash', PendingWritesIndex::build([]));

        self::assertSame([], $scratchpad->resume);
    }

    public function testBuildScratchpadTakesTheFirstGraphWideResume(): void
    {
        $index = PendingWritesIndex::build([
            [Constants::NULL_TASK_ID, Constants::RESUME, 'first'],
            [Constants::NULL_TASK_ID, Constants::RESUME, 'second'],
        ]);

        self::assertSame('first', Algorithm::buildScratchpad('t1', null, null, 'h', $index)->nullResume);
    }

    public function testAControlSignalWriteDoesNotMakeATaskCompleted(): void
    {
        $index = PendingWritesIndex::build([
            ['paused', Constants::INTERRUPT, ['id' => 'x', 'value' => 'q']],
            ['paused', Constants::RESUME, ['A']],
            ['failed', Constants::ERROR, 'boom'],
            ['failed', Constants::ERROR_SOURCE_NODE, 'n'],
            ['done', 'answer', 1],
            ['quiet', Constants::NO_WRITES, null],
        ]);

        self::assertFalse($index->hasCompletedWrite('paused'));
        self::assertFalse($index->hasCompletedWrite('failed'));
        self::assertTrue($index->hasCompletedWrite('done'));
        self::assertTrue($index->hasCompletedWrite('quiet'), 'a task that ran and wrote nothing did finish');
        self::assertFalse($index->hasCompletedWrite('unknown'));
    }

    public function testHasSuccessfulWriteKeepsItsUpstreamMeaning(): void
    {
        $index = PendingWritesIndex::build([['paused', Constants::INTERRUPT, 'q'], ['failed', Constants::ERROR, 'x']]);

        self::assertTrue($index->hasSuccessfulWrite('paused'), 'upstream counts everything but ERROR');
        self::assertFalse($index->hasSuccessfulWrite('failed'));
    }

    public function testInterruptsForReadsOnlyThatTasksInterruptWrites(): void
    {
        $writes = [
            ['a', Constants::INTERRUPT, ['id' => '1', 'value' => 'qa']],
            ['b', Constants::INTERRUPT, ['id' => '2', 'value' => 'qb']],
            ['a', Constants::RESUME, ['x']],
            ['a', Constants::INTERRUPT, ['id' => '3', 'value' => 'qa2']],
        ];

        self::assertSame(
            [['id' => '1', 'value' => 'qa'], ['id' => '3', 'value' => 'qa2']],
            Algorithm::interruptsFor($writes, 'a'),
        );
        self::assertSame([], Algorithm::interruptsFor($writes, 'none'));
        self::assertSame([], Algorithm::interruptsFor(null, 'a'));
    }
}
