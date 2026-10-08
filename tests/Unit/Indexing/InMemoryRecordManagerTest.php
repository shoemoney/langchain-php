<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Indexing;

use LangChain\Indexing\InMemoryRecordManager;
use LangChain\Indexing\RecordManager;
use LangChain\Indexing\RecordManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Own tests: upstream ships only the record-manager interface, no implementation.
 */
#[CoversClass(InMemoryRecordManager::class)]
#[CoversClass(RecordManager::class)]
final class InMemoryRecordManagerTest extends TestCase
{
    public function testExistsAnswersPerKeyInOrder(): void
    {
        $clock = new TestClock();
        $manager = $clock->manager();
        $manager->createSchema();
        $manager->update(['a', 'c']);

        $this->assertSame([true, false, true], $manager->exists(['a', 'b', 'c']));
    }

    public function testListKeysFiltersByBeforeAfterGroupAndLimit(): void
    {
        $clock = new TestClock();
        $manager = $clock->manager();
        $manager->update(['a', 'b'], ['groupIds' => ['g1', 'g2']]);   // t=1000
        $clock->advance();
        $manager->update(['c'], ['groupIds' => ['g1']]);              // t=1010

        $this->assertSame(['a', 'b', 'c'], $manager->listKeys());
        $this->assertSame(['a', 'b'], $manager->listKeys(['before' => 1010.0]));
        $this->assertSame(['c'], $manager->listKeys(['after' => 1000.0]));
        $this->assertSame(['a', 'c'], $manager->listKeys(['groupIds' => ['g1']]));
        $this->assertSame(['a'], $manager->listKeys(['limit' => 1, 'groupIds' => ['g1']]));
        $this->assertSame([], $manager->listKeys(['before' => 1000.0]), 'before is exclusive');
    }

    public function testUpdatingAnExistingKeyRefreshesItsTimestampAndGroup(): void
    {
        $clock = new TestClock();
        $manager = $clock->manager();
        $manager->update(['a'], ['groupIds' => ['old']]);
        $clock->advance();
        $manager->update(['a'], ['groupIds' => ['new']]);

        $this->assertSame([], $manager->listKeys(['before' => 1010.0]));
        $this->assertSame(['a'], $manager->listKeys(['groupIds' => ['new']]));
        $this->assertSame([], $manager->listKeys(['groupIds' => ['old']]));
    }

    public function testDeleteKeysRemovesOnlyThoseKeysAndToleratesUnknownOnes(): void
    {
        $manager = (new TestClock())->manager();
        $manager->update(['a', 'b']);

        $manager->deleteKeys(['a', 'nope']);

        $this->assertSame(['b'], $manager->listKeys());
    }

    public function testGroupIdsMustMatchKeysOneForOne(): void
    {
        $manager = (new TestClock())->manager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Number of keys does not match number of group ids');

        $manager->update(['a', 'b'], ['groupIds' => ['only-one']]);
    }

    public function testTimeAtLeastInTheFutureIsATimeSyncError(): void
    {
        $manager = (new TestClock())->manager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Time sync issue');

        $manager->update(['a'], ['timeAtLeast' => 5000.0]);
    }

    public function testTimeAtLeastInThePastOrPresentIsAccepted(): void
    {
        $manager = (new TestClock())->manager();

        $manager->update(['a'], ['timeAtLeast' => 1000.0]);

        $this->assertSame(['a'], $manager->listKeys());
    }

    public function testDefaultClockIsWallTime(): void
    {
        $manager = new InMemoryRecordManager();

        $before = microtime(true);
        $time = $manager->getTime();

        $this->assertGreaterThanOrEqual($before, $time);
        $this->assertLessThanOrEqual(microtime(true), $time);
    }

    public function testSerializationIdAndNamespaceConstant(): void
    {
        $this->assertSame(['langchain', 'recordmanagers', 'InMemoryRecordManager'], InMemoryRecordManager::lcId());
        $this->assertSame('10f90ea3-90a4-4962-bf75-83a0f3c1c62a', RecordManagerInterface::UUIDV5_NAMESPACE);
    }
}
