<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Channels;

use LangGraph\Channels\AnyValue;
use LangGraph\Channels\BaseChannel;
use LangGraph\Channels\BinaryOperatorAggregate;
use LangGraph\Channels\ChannelRegistry;
use LangGraph\Channels\EphemeralValue;
use LangGraph\Channels\LastValue;
use LangGraph\Channels\LastValueAfterFinish;
use LangGraph\Channels\NamedBarrierValue;
use LangGraph\Channels\Topic;
use LangGraph\Channels\UntrackedValue;
use LangGraph\Errors\EmptyChannelError;
use LangGraph\Errors\InvalidUpdateError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BaseChannel::class)]
#[CoversClass(LastValue::class)]
#[CoversClass(LastValueAfterFinish::class)]
#[CoversClass(AnyValue::class)]
#[CoversClass(EphemeralValue::class)]
#[CoversClass(EphemeralValue::class)]
#[CoversClass(BinaryOperatorAggregate::class)]
#[CoversClass(NamedBarrierValue::class)]
#[CoversClass(Topic::class)]
#[CoversClass(UntrackedValue::class)]
#[CoversClass(ChannelRegistry::class)]
final class ChannelTest extends TestCase
{
    // ---- LastValue -------------------------------------------------------

    public function testLastValueStoresSingleWrite(): void
    {
        $c = new LastValue();
        $this->assertTrue($c->update(['a']));
        $this->assertSame('a', $c->get());
    }

    public function testLastValueRejectsTwoWritesInOneStep(): void
    {
        // This is the mechanism that catches conflicting concurrent graph
        // updates instead of silently letting one branch win.
        $c = new LastValue();
        $this->expectException(InvalidUpdateError::class);
        $this->expectExceptionMessage('LastValue can only receive one value per step.');
        $c->update(['a', 'b']);
    }

    public function testLastValueEmptyUpdateIsANoop(): void
    {
        $c = new LastValue();
        $this->assertFalse($c->update([]));
        $this->assertFalse($c->isAvailable());
    }

    public function testLastValueGetThrowsWhenEmpty(): void
    {
        $c = new LastValue();
        $this->expectException(EmptyChannelError::class);
        $c->get();
    }

    public function testLastValueDistinguishesNullFromNoWrite(): void
    {
        // Writing null is a real update; the single-element list wrapper is what
        // makes that distinguishable from "nothing was written".
        $c = new LastValue();
        $c->update([null]);
        $this->assertTrue($c->isAvailable());
        $this->assertNull($c->get());
    }

    public function testLastValueInitialFactory(): void
    {
        $c = new LastValue(static fn (): int => 42);
        $this->assertSame(42, $c->get());
    }

    public function testLastValueRestoresFromCheckpoint(): void
    {
        $c = (new LastValue())->fromCheckpoint('saved');
        $this->assertSame('saved', $c->get());

        $empty = (new LastValue())->fromCheckpoint(null);
        $this->assertFalse($empty->isAvailable());
    }

    public function testLastValueCheckpointThrowsWhenEmpty(): void
    {
        $c = new LastValue();
        $this->expectException(EmptyChannelError::class);
        $c->checkpoint();
    }

    // ---- LastValueAfterFinish -------------------------------------------

    public function testLastValueAfterFinishIsUnavailableUntilFinished(): void
    {
        $c = new LastValueAfterFinish();
        $c->update(['a']);
        $this->assertFalse($c->isAvailable());

        $this->expectException(EmptyChannelError::class);
        $c->get();
    }

    public function testLastValueAfterFinishBecomesAvailableOnFinish(): void
    {
        $c = new LastValueAfterFinish();
        $c->update(['a']);
        $this->assertTrue($c->finish());
        $this->assertTrue($c->isAvailable());
        $this->assertSame('a', $c->get());
    }

    public function testLastValueAfterFinishConsumeClears(): void
    {
        $c = new LastValueAfterFinish();
        $c->update(['a']);
        $c->finish();
        $this->assertTrue($c->consume());
        $this->assertFalse($c->isAvailable());
    }

    public function testLastValueAfterFinishCheckpointIncludesFinishedFlag(): void
    {
        $c = new LastValueAfterFinish();
        $c->update(['a']);
        $this->assertSame(['a', false], $c->checkpoint());
        $c->finish();
        $this->assertSame(['a', true], $c->checkpoint());
    }

    public function testLastValueAfterFinishRestoresBothHalves(): void
    {
        $c = (new LastValueAfterFinish())->fromCheckpoint(['a', true]);
        $this->assertTrue($c->isAvailable());
        $this->assertSame('a', $c->get());
    }

    // ---- AnyValue --------------------------------------------------------

    public function testAnyValueLastWriteWinsWithoutError(): void
    {
        // Unlike LastValue, AnyValue tolerates concurrent writes on the
        // documented contract that the writers all agree.
        $c = new AnyValue();
        $this->assertTrue($c->update(['a', 'b']));
        $this->assertSame('b', $c->get());
    }

    public function testAnyValueClearsOnEmptyUpdate(): void
    {
        $c = new AnyValue();
        $c->update(['a']);
        $this->assertTrue($c->update([]));
        $this->assertFalse($c->isAvailable());
    }

    public function testAnyValueRestoresFromCheckpoint(): void
    {
        $c = (new AnyValue())->fromCheckpoint('x');
        $this->assertSame('x', $c->get());
    }

    // ---- EphemeralValue --------------------------------------------------

    public function testEphemeralClearsAfterOneStep(): void
    {
        $c = new EphemeralValue();
        $c->update(['a']);
        $this->assertTrue($c->isAvailable());
        // Pregel calls update([]) every step for unwritten channels; that call
        // is the entire mechanism here.
        $this->assertTrue($c->update([]));
        $this->assertFalse($c->isAvailable());
    }

    public function testEphemeralGuardRejectsMultipleWrites(): void
    {
        $c = new EphemeralValue(guard: true);
        $this->expectException(InvalidUpdateError::class);
        $this->expectExceptionMessage('EphemeralValue can only receive one value per step.');
        $c->update(['a', 'b']);
    }

    public function testEphemeralWithoutGuardTakesLastWrite(): void
    {
        $c = new EphemeralValue(guard: false);
        $c->update(['a', 'b']);
        $this->assertSame('b', $c->get());
    }

    public function testEphemeralRestoresFromCheckpoint(): void
    {
        $c = (new EphemeralValue())->fromCheckpoint('e');
        $this->assertSame('e', $c->get());
    }

    // ---- BinaryOperatorAggregate ----------------------------------------

    public function testBinaryOperatorFoldsIncomingValues(): void
    {
        $c = new BinaryOperatorAggregate(static fn (int $a, int $b): int => $a + $b);
        $c->update([1, 2, 3]);
        $this->assertSame(6, $c->get());
    }

    public function testBinaryOperatorSeedsFromFirstValueWithoutOperator(): void
    {
        // The first value seeds the accumulator; it is NOT passed through the
        // operator, because there is nothing to fold it onto yet.
        $c = new BinaryOperatorAggregate(static fn (string $a, string $b): string => $a . $b);
        $c->update(['a', 'b', 'c']);
        $this->assertSame('abc', $c->get());
    }

    public function testBinaryOperatorAccumulatesAcrossSteps(): void
    {
        // The first value SEEDS the accumulator and is not passed through the
        // operator, so a seed of the right shape is required before the second
        // step can fold. That asymmetry is the upstream contract.
        $c = new BinaryOperatorAggregate(
            static fn (array $a, array $b): array => array_merge($a, $b),
            static fn (): array => []
        );
        $c->update([[1]]);
        $c->update([[2]]);
        $this->assertSame([1, 2], $c->get());
    }

    public function testBinaryOperatorWithInitialValueFactory(): void
    {
        $c = new BinaryOperatorAggregate(
            static fn (int $a, int $b): int => $a + $b,
            static fn (): int => 100
        );
        $c->update([1, 2]);
        $this->assertSame(103, $c->get());
    }

    public function testBinaryOperatorEmptyUpdateIsANoop(): void
    {
        $c = new BinaryOperatorAggregate(static fn (int $a, int $b): int => $a + $b);
        $this->assertFalse($c->update([]));
        $this->assertFalse($c->isAvailable());
    }

    public function testBinaryOperatorGetThrowsWhenUnseeded(): void
    {
        $c = new BinaryOperatorAggregate(static fn (int $a, int $b): int => $a + $b);
        $this->expectException(EmptyChannelError::class);
        $c->get();
    }

    public function testBinaryOperatorEqualityComparesOperatorReference(): void
    {
        // Two closures with identical bodies are NOT interchangeable; two
        // references to the same closure are. This mirrors the Python
        // implementation comparing operator references.
        $op = static fn (int $a, int $b): int => $a + $b;
        $a = new BinaryOperatorAggregate($op);
        $b = new BinaryOperatorAggregate($op);
        $this->assertTrue($a->equals($b));

        $c = new BinaryOperatorAggregate(static fn (int $x, int $y): int => $x + $y);
        $this->assertFalse($a->equals($c));
    }

    public function testBinaryOperatorRestoresFromCheckpoint(): void
    {
        $op = static fn (int $a, int $b): int => $a + $b;
        $c = (new BinaryOperatorAggregate($op))->fromCheckpoint(50);
        $c->update([1]);
        $this->assertSame(51, $c->get());
    }

    // ---- NamedBarrierValue ----------------------------------------------

    public function testBarrierBlocksUntilAllNamesSeen(): void
    {
        $c = new NamedBarrierValue(['a', 'b']);
        $c->update(['a']);
        $this->assertFalse($c->isAvailable());

        $c->update(['b']);
        $this->assertTrue($c->isAvailable());
    }

    public function testBarrierIsOrderInsensitive(): void
    {
        $c = new NamedBarrierValue(['a', 'b']);
        $c->update(['b', 'a']);
        $this->assertTrue($c->isAvailable());
    }

    public function testBarrierRejectsUnknownName(): void
    {
        $c = new NamedBarrierValue(['a']);
        $this->expectException(InvalidUpdateError::class);
        $this->expectExceptionMessage('not in names');
        $c->update(['zzz']);
    }

    public function testBarrierGetThrowsWhileWaiting(): void
    {
        $c = new NamedBarrierValue(['a', 'b']);
        $c->update(['a']);
        $this->expectException(EmptyChannelError::class);
        $c->get();
    }

    public function testBarrierRestoresSeenNames(): void
    {
        $c = (new NamedBarrierValue(['a', 'b']))->fromCheckpoint(['a']);
        $this->assertFalse($c->isAvailable());
        $c->update(['b']);
        $this->assertTrue($c->isAvailable());
    }

    // ---- Topic -----------------------------------------------------------

    public function testTopicClearsEachStepByDefault(): void
    {
        $c = new Topic();
        $c->update(['a']);
        $this->assertSame(['a'], $c->get());
        $c->update([]);
        $this->assertFalse($c->isAvailable());
    }

    public function testTopicAccumulatesWhenConfigured(): void
    {
        $c = new Topic(unique: false, accumulate: true);
        $c->update(['a']);
        $c->update(['b']);
        $this->assertSame(['a', 'b'], $c->get());
    }

    public function testTopicFlattensListWrites(): void
    {
        $c = new Topic(accumulate: true);
        $c->update([['a', 'b'], 'c']);
        $this->assertSame(['a', 'b', 'c'], $c->get());
    }

    public function testTopicUniqueDedupesAcrossSteps(): void
    {
        $c = new Topic(unique: true, accumulate: true);
        $c->update(['a']);
        $c->update(['a', 'b']);
        $this->assertSame(['a', 'b'], $c->get());
    }

    public function testTopicUniqueDoesNotCollideIntegerOneWithStringOne(): void
    {
        // Without a type tag in the seen-key, 1 and "1" would be the same value.
        $c = new Topic(unique: true, accumulate: true);
        $c->update([1, '1']);
        $this->assertSame([1, '1'], $c->get());
    }

    public function testTopicCheckpointIsFlatWhenNotUnique(): void
    {
        $c = new Topic(accumulate: true);
        $c->update(['a']);
        $this->assertSame(['a'], $c->checkpoint());
    }

    public function testTopicCheckpointCarriesSeenHistoryWhenUnique(): void
    {
        $c = new Topic(unique: true, accumulate: true);
        $c->update(['a']);
        $c->update(['b']);
        $checkpoint = $c->checkpoint();
        // [seen, values] so dedupe history outliving the buffer survives resume
        $this->assertCount(2, $checkpoint);
        $this->assertSame(['a', 'b'], $checkpoint[0]);
        $this->assertSame(['a', 'b'], $checkpoint[1]);
    }

    public function testTopicRestoreSeesHistoryForUniqueDedupe(): void
    {
        $original = new Topic(unique: true, accumulate: true);
        $original->update(['a', 'b']);

        $restored = $original->fromCheckpoint($original->checkpoint());
        $restored->update(['a', 'c']);
        // 'a' was already in the restored seen-history, so it is NOT delivered
        // a second time; only the genuinely new 'c' is appended.
        $this->assertSame(['a', 'b', 'c'], $restored->get());
        $this->assertSame(1, count(array_keys($restored->get(), 'a', true)));
    }

    // ---- UntrackedValue --------------------------------------------------

    public function testUntrackedValueNeverCheckpoints(): void
    {
        $c = new UntrackedValue();
        $c->update(['a']);
        $this->assertSame('a', $c->get());
        $this->assertNull($c->checkpoint());
    }

    public function testUntrackedValueResetsOnRestore(): void
    {
        $c = new UntrackedValue();
        $c->update(['a']);
        $restored = $c->fromCheckpoint(null);
        $this->assertFalse($restored->isAvailable());
    }

    // ---- registry --------------------------------------------------------

    public function testEmptyChannelsRestoresEachIndependently(): void
    {
        $channels = ['a' => new LastValue(), 'b' => new LastValue()];
        $restored = ChannelRegistry::emptyChannels($channels, ['a' => 1, 'b' => 2]);

        $this->assertSame(1, $restored['a']->get());
        $this->assertSame(2, $restored['b']->get());
        // Distinct objects, not aliases of the prototypes.
        $this->assertNotSame($restored['a'], $restored['b']);
    }

    public function testCheckpointValuesOmitsEmptyChannels(): void
    {
        $a = new LastValue();
        $a->update(['live']);
        $b = new LastValue();

        $values = ChannelRegistry::checkpointValues(['a' => $a, 'b' => $b]);
        $this->assertSame(['a' => 'live'], $values);
    }

    public function testAvailableChannelsListsOnlyReadableOnes(): void
    {
        $a = new LastValue();
        $a->update(['x']);
        $b = new LastValue();

        $this->assertSame(['a'], ChannelRegistry::availableChannels(['a' => $a, 'b' => $b]));
    }

    public function testGetOnlyChannelsDropsNonChannels(): void
    {
        $out = ChannelRegistry::getOnlyChannels(['a' => new LastValue(), 'junk' => 'not a channel']);
        $this->assertArrayHasKey('a', $out);
        $this->assertArrayNotHasKey('junk', $out);
    }

    public function testBaseChannelDefaultsAreNoops(): void
    {
        $c = new class extends BaseChannel {
            public string $lcGraphName = 'Test';
            public function fromCheckpoint(mixed $checkpoint = null): self
            {
                return $this;
            }
            public function update(array $values): bool
            {
                return false;
            }
            public function get(): mixed
            {
                throw new EmptyChannelError();
            }
            public function checkpoint(): mixed
            {
                return null;
            }
        };

        $this->assertFalse($c->consume());
        $this->assertFalse($c->finish());
        $this->assertFalse($c->isAvailable());
        $this->assertTrue(BaseChannel::isChannel($c));
        $this->assertFalse(BaseChannel::isChannel('nope'));
    }
}
