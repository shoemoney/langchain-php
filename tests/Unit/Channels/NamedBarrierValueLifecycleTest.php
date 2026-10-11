<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Channels;

use LangGraph\Channels\NamedBarrierValue;
use LangGraph\Errors\EmptyChannelError;
use LangGraph\Errors\InvalidUpdateError;
use LangGraph\Pregel\Algorithm;
use LangGraph\Pregel\Checkpoint\Checkpoint;
use LangGraph\Pregel\PregelExecutableTask;
use LangGraph\Pregel\TaskPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Public lifecycle contracts from pinned named_barrier_value.ts66–76. */
#[CoversClass(NamedBarrierValue::class)]
final class NamedBarrierValueLifecycleTest extends TestCase
{
    #[DataProvider('arrivalOrders')]
    public function testRepeatedCyclesResetOnlyCompletedBarriers(array $order, int $cycles): void
    {
        $channel = new NamedBarrierValue(['a', 'b']);
        for ($cycle = 0; $cycle < $cycles; ++$cycle) {
            self::assertSame([], $channel->checkpoint());
            self::assertFalse($channel->isAvailable());
            $this->assertUnreadable($channel);
            self::assertTrue($channel->update([$order[0]]));
            self::assertFalse($channel->update([$order[0]]), 'a duplicate cannot stand in for another participant');
            self::assertFalse($channel->update([]));
            self::assertFalse($channel->consume());
            self::assertSame([$order[0]], $channel->checkpoint());
            $this->assertUnreadable($channel);
            self::assertTrue($channel->update([$order[1]]));
            self::assertTrue($channel->isAvailable());
            self::assertNull($channel->get());
            self::assertSame($order, $channel->checkpoint());
            self::assertTrue($channel->consume());
            self::assertSame([], $channel->checkpoint());
            self::assertFalse($channel->consume());
        }
    }

    public static function arrivalOrders(): array
    {
        return ['forward two' => [['a', 'b'], 2], 'reverse two' => [['b', 'a'], 2],
            'forward three' => [['a', 'b'], 3], 'reverse three' => [['b', 'a'], 3]];
    }

    public function testPartialEmptyAndCompleteSnapshotsRestoreIndependently(): void
    {
        $original = new NamedBarrierValue(['a', 'b']);
        self::assertSame([], $original->fromCheckpoint($original->checkpoint())->checkpoint());
        $original->update(['b']);
        $partial = $original->checkpoint();
        $restored = $original->fromCheckpoint($partial);
        self::assertSame(['b'], $restored->checkpoint());
        self::assertFalse($restored->consume());
        $this->assertUnreadable($restored);
        $original->update(['a']);
        $complete = $original->checkpoint();
        $ready = $original->fromCheckpoint($complete);
        self::assertTrue($ready->isAvailable());
        self::assertTrue($original->consume());
        self::assertSame(['b'], $partial);
        self::assertSame(['b', 'a'], $complete);
        self::assertSame(['b', 'a'], $ready->checkpoint());
        self::assertTrue($restored->update(['a']));
        self::assertTrue($restored->isAvailable());
        self::assertTrue($ready->consume());
        self::assertSame([], $ready->checkpoint());
    }

    public function testEmptyParticipantSetIsReadyEvenAfterRepeatedConsumption(): void
    {
        $empty = new NamedBarrierValue();
        self::assertSame([], $empty->checkpoint());
        self::assertTrue($empty->isAvailable());
        self::assertNull($empty->get());
        self::assertFalse($empty->update([]));
        self::assertTrue($empty->consume());
        self::assertTrue($empty->consume());
        self::assertTrue($empty->fromCheckpoint([])->isAvailable());
    }

    public function testUnknownParticipantStillRaisesTheExistingError(): void
    {
        $channel = new NamedBarrierValue(['a', 'b']);
        $channel->update(['a']);
        try {
            $channel->update(['unknown']);
            self::fail('unknown participant accepted');
        } catch (InvalidUpdateError) {
            self::assertSame(['a'], $channel->checkpoint());
            self::assertFalse($channel->isAvailable());
        }
    }

    public function testActualAlgorithmAdvancesConsumedVersionAndRecordsPreviousVersion(): void
    {
        $channel = new NamedBarrierValue(['a', 'b']);
        $channel->update(['a', 'b']);
        $checkpoint = new Checkpoint(channelVersions: ['join' => 1]);
        $task = new PregelExecutableTask(id: 'SYNTH_DECIDER', name: 'decider',
            triggers: ['join'], writes: [], path: TaskPath::pull('decider'));
        Algorithm::applyWrites($checkpoint, ['join' => $channel], [$task],
            static fn (?int $version): int => ($version ?? 0) + 1);
        self::assertSame(['decider' => ['join' => 1]], $checkpoint->versionsSeen);
        self::assertSame(2, $checkpoint->channelVersions['join']);
        self::assertFalse($channel->isAvailable());
        self::assertSame([], $channel->checkpoint());
    }

    private function assertUnreadable(NamedBarrierValue $channel): void
    {
        try {
            $channel->get();
            self::fail('incomplete barrier was readable');
        } catch (EmptyChannelError) {
            self::assertFalse($channel->isAvailable());
        }
    }
}
