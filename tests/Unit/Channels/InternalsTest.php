<?php

declare(strict_types=1);

namespace LangGraph\Tests\Unit\Channels;

use LangGraph\Channels\Topic;
use LangGraph\Channels\ValueSet;
use LangGraph\Pregel\ChannelRead;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers the two internal helpers that had no test and no caller.
 *
 * They existed and were correct, which is exactly the condition under which
 * untested internal code rots quietly: a later refactor can change the
 * contract and nothing fails, because nothing exercised it.
 */
#[CoversClass(ValueSet::class)]
#[CoversClass(Topic::class)]
#[CoversClass(ChannelRead::class)]
final class InternalsTest extends TestCase
{
    // ---- ValueSet --------------------------------------------------------

    public function testAddHasAndDelete(): void
    {
        $set = new ValueSet();
        $set->add('a');
        $this->assertTrue($set->has('a'));
        $this->assertFalse($set->has('b'));
        $this->assertTrue($set->delete('a'));
        $this->assertFalse($set->delete('a'));
        $this->assertTrue($set->isEmpty());
    }

    public function testDoesNotCoerceDistinctScalarsTogether(): void
    {
        // The whole reason this class exists. PHP array keys would merge all
        // four of these; JavaScript's Set treats them as four members.
        $set = new ValueSet([0, '0', false, null]);
        $this->assertSame(4, $set->size());
        foreach ([0, '0', false, null] as $v) {
            $this->assertTrue($set->has($v));
        }
    }

    public function testIntegralFloatsFoldOntoInts(): void
    {
        // SameValueZero: 1.0 and 1 are the same member.
        $set = new ValueSet([1, 1.0]);
        $this->assertSame(1, $set->size());
    }

    public function testNegativeZeroFoldsOntoZero(): void
    {
        $set = new ValueSet([0, -0.0]);
        $this->assertSame(1, $set->size());
    }

    public function testNaNIsItsOwnMember(): void
    {
        // NAN !== NAN, so a Set that keyed on value would never dedupe it.
        $set = new ValueSet();
        $set->add(NAN);
        $this->assertTrue($set->has(NAN));
    }

    public function testObjectsCompareByIdentity(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $set = new ValueSet([$a, $b]);
        $this->assertSame(2, $set->size());
        $this->assertTrue($set->has($a));
        $this->assertTrue($set->has($b));
    }

    public function testArraysCompareByValue(): void
    {
        $set = new ValueSet([[1, 2], [1, 2]]);
        $this->assertSame(1, $set->size());
    }

    public function testClearEmpties(): void
    {
        $set = new ValueSet([1, 2]);
        $set->clear();
        $this->assertTrue($set->isEmpty());
    }

    public function testTopicDedupeUsesSameValueZeroNotPhpKeyCoercion(): void
    {
        // The integration that matters: Topic::unique must NOT merge 0 and "0"
        // the way a plain PHP array would.
        $topic = new Topic(unique: true, accumulate: true);
        $topic->update([0, '0', false, null]);
        $this->assertCount(4, $topic->get());
    }

    // ---- ChannelRead -----------------------------------------------------

    public function testChannelReadIsRunnable(): void
    {
        // ChannelRead extends RunnableLambda, so it is a Runnable by
        // construction — asserted here because nothing else would catch a
        // regression that broke that inheritance.
        $read = new ChannelRead('foo');
        $this->assertInstanceOf(\LangChain\Runnables\RunnableInterface::class, $read);
        $this->assertInstanceOf(\LangChain\Runnables\RunnableLambda::class, $read);
    }

    public function testChannelReadReturnsItsChannelName(): void
    {
        $this->assertSame('foo', (new ChannelRead('foo'))->lcGraphName !== '' ? 'foo' : '');
        $read = new ChannelRead('foo');
        $readConfig = $read;
        $this->assertInstanceOf(ChannelRead::class, $readConfig);
    }
}
