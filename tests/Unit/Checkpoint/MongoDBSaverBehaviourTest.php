<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\MongoDB\MongoBinary;
use LangGraph\Checkpoint\MongoDB\MongoDBSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The properties a Mongo-shaped saver can get wrong while still passing the shared spec:
 * ordering delegated to the database, the write upsert key, and the stored wire shape.
 */
#[CoversClass(MongoDBSaver::class)]
final class MongoDBSaverBehaviourTest extends TestCase
{
    private MongoDBFakeClient $client;

    private MongoDBSaver $saver;

    protected function setUp(): void
    {
        $this->client = new MongoDBFakeClient();
        $this->saver = new MongoDBSaver($this->client);
    }

    /**
     * Ids are NOT inserted in order here. If the fake (or the saver) skipped the sort,
     * "latest" would be "last inserted" and this would pass for the wrong reason elsewhere.
     */
    public function testLatestCheckpointIsTheHighestIdNotTheLastInserted(): void
    {
        foreach (['0002', '0009', '0001'] as $id) {
            $this->saver->put(
                ['configurable' => ['thread_id' => 't']],
                new Checkpoint(v: 4, id: $id, ts: '2024-01-01T00:00:00.000Z'),
                ['step' => (int) $id],
            );
        }

        $latest = $this->saver->getTuple(['configurable' => ['thread_id' => 't']]);
        self::assertSame('0009', $latest?->checkpoint->id);

        $ids = array_map(
            static fn ($tuple): string => $tuple->checkpoint->id,
            $this->saver->list(['configurable' => ['thread_id' => 't']]),
        );
        self::assertSame(['0009', '0002', '0001'], $ids);
    }

    public function testNumericLookingIdsOrderAsStringsNotNumbers(): void
    {
        foreach (['9', '10'] as $id) {
            $this->saver->put(
                ['configurable' => ['thread_id' => 't']],
                new Checkpoint(v: 4, id: $id, ts: '2024-01-01T00:00:00.000Z'),
                [],
            );
        }

        // String order: "9" > "10". A numeric compare would flip it.
        self::assertSame('9', $this->saver->getTuple(['configurable' => ['thread_id' => 't']])?->checkpoint->id);
    }

    public function testListLimitIsAppliedAfterTheSort(): void
    {
        foreach (['a', 'c', 'b'] as $id) {
            $this->saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: $id, ts: ''), []);
        }

        $tuples = $this->saver->list(['configurable' => ['thread_id' => 't']], 2);

        self::assertSame(['c', 'b'], array_map(static fn ($t): string => $t->checkpoint->id, $tuples));
    }

    public function testBeforeIsExclusive(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: $id, ts: ''), []);
        }

        $tuples = $this->saver->list(
            ['configurable' => ['thread_id' => 't']],
            new CheckpointListOptions(before: ['configurable' => ['checkpoint_id' => 'c']]),
        );

        self::assertSame(['b', 'a'], array_map(static fn ($t): string => $t->checkpoint->id, $tuples));
    }

    public function testListWithZeroLimitYieldsNothing(): void
    {
        $this->saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'a', ts: ''), []);

        self::assertSame([], $this->saver->list(['configurable' => ['thread_id' => 't']], 0));
    }

    public function testPutTwiceWithTheSameKeyReplacesTheDocument(): void
    {
        $config = ['configurable' => ['thread_id' => 't']];
        $this->saver->put($config, new Checkpoint(v: 4, id: 'a', ts: '', channelValues: ['x' => 1]), ['step' => 1]);
        $this->saver->put($config, new Checkpoint(v: 4, id: 'a', ts: '', channelValues: ['x' => 2]), ['step' => 2]);

        self::assertCount(1, $this->client->checkpoints()->documents);
        self::assertSame(2, $this->saver->getTuple($config)?->checkpoint->channelValues['x']);
        self::assertSame(2, $this->saver->getTuple($config)?->metadata['step']);
    }

    // ---- putWrites upsert key ---------------------------------------------

    public function testWritesAreUpsertedOnThreadNsCheckpointTaskAndIdx(): void
    {
        $config = ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => 'ns', 'checkpoint_id' => 'c']];

        $this->saver->putWrites($config, [['a', 1], ['b', 2]], 'task-1');

        $calls = $this->client->writes()->callsTo('updateOne');
        self::assertSame(
            ['thread_id' => 't', 'checkpoint_ns' => 'ns', 'checkpoint_id' => 'c', 'task_id' => 'task-1', 'idx' => 0],
            $calls[0]['args']['filter'],
        );
        self::assertSame(1, $calls[1]['args']['filter']['idx']);
        self::assertSame(['upsert' => true], $calls[0]['args']['options']);
    }

    public function testARegularWriteNeverClobbersAPeerTasksRowAtTheSameKey(): void
    {
        $config = ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c']];

        $this->saver->putWrites($config, [['foo', 'first']], 'task-1');
        $this->saver->putWrites($config, [['foo', 'second']], 'task-1');

        self::assertCount(1, $this->client->writes()->documents);
        // No checkpoint is stored, so read the row directly.
        self::assertSame('"first"', $this->client->writes()->documents[0]['value']->value());
    }

    public function testAnInterruptIsReplacedByAResumeOnlyAtTheirOwnIndices(): void
    {
        $config = ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c']];

        $this->saver->putWrites($config, [['__interrupt__', 'one']], 'task-1');
        $this->saver->putWrites($config, [['__interrupt__', 'two']], 'task-1');
        $this->saver->putWrites($config, [['__resume__', 'go']], 'task-1');

        $docs = $this->client->writes()->documents;
        self::assertCount(2, $docs);
        self::assertSame(-3, $docs[0]['idx']);
        self::assertSame('"two"', $docs[0]['value']->value());
        self::assertSame(-4, $docs[1]['idx']);
    }

    public function testTimestampsAreWrittenOnPutAndOnFirstInsertOfARegularWriteOnly(): void
    {
        $saver = new MongoDBSaver($this->client, ttl: 60);
        $config = ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c']];

        $saver->put($config, new Checkpoint(v: 4, id: 'c2', ts: ''), []);
        self::assertInstanceOf(\DateTimeInterface::class, $this->client->checkpoints()->documents[0]['upserted_at']);

        $saver->putWrites($config, [['foo', 1]], 'task');
        $first = $this->client->writes()->documents[0]['upserted_at'];
        self::assertInstanceOf(\DateTimeInterface::class, $first);

        usleep(2000);
        $saver->putWrites($config, [['foo', 2]], 'task');
        self::assertEquals($first, $this->client->writes()->documents[0]['upserted_at'], 'a no-op insert must not bump the stamp');
    }

    // ---- wire shape -------------------------------------------------------

    public function testStoredDocumentHasTheUpstreamShape(): void
    {
        $this->saver->put(
            ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => 'ns', 'checkpoint_id' => 'parent']],
            new Checkpoint(v: 4, id: 'child', ts: '2024-01-01T00:00:00.000Z', channelValues: ['k' => 'v'], channelVersions: ['k' => 1]),
            ['source' => 'loop', 'step' => 3, 'parents' => []],
        );

        $doc = $this->client->checkpoints()->documents[0];

        self::assertSame(
            ['thread_id', 'checkpoint_ns', 'checkpoint_id', 'parent_checkpoint_id', 'type', 'checkpoint', 'metadata', 'metadata_search'],
            array_keys($doc),
        );
        self::assertSame('parent', $doc['parent_checkpoint_id']);
        self::assertSame('json', $doc['type']);
        self::assertInstanceOf(MongoBinary::class, $doc['checkpoint']);

        $checkpoint = json_decode($doc['checkpoint']->value(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['v', 'id', 'ts', 'channel_values', 'channel_versions', 'versions_seen'],
            array_keys($checkpoint),
        );
        self::assertSame(['k' => 'v'], $checkpoint['channel_values']);
        self::assertSame(['source' => 'loop', 'step' => 3, 'parents' => []], $doc['metadata_search']);
    }

    public function testEmptyMapsSerializeAsObjectsNotLists(): void
    {
        $this->saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'c', ts: ''), []);

        $doc = $this->client->checkpoints()->documents[0];

        $raw = $doc['checkpoint']->value();
        self::assertStringContainsString('"channel_values":{}', $raw);
        self::assertStringContainsString('"channel_versions":{}', $raw);
        self::assertStringContainsString('"versions_seen":{}', $raw);
        self::assertSame('{}', $doc['metadata']->value());
    }

    public function testWriteRowCarriesChannelTypeAndBinaryValue(): void
    {
        $this->saver->putWrites(
            ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c']],
            [['chan', ['a' => 1]]],
            'task',
        );

        $doc = $this->client->writes()->documents[0];

        self::assertSame('chan', $doc['channel']);
        self::assertSame('json', $doc['type']);
        self::assertInstanceOf(MongoBinary::class, $doc['value']);
        self::assertSame('{"a":1}', $doc['value']->value());
    }

    public function testParentConfigIsAbsentWhenThereIsNoParent(): void
    {
        $config = $this->saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'c', ts: ''), []);

        self::assertNull($this->saver->getTuple($config)?->parentConfig);
        self::assertNull($this->client->checkpoints()->documents[0]['parent_checkpoint_id']);
    }

    // ---- deleteThread ------------------------------------------------------

    public function testDeleteThreadRemovesCheckpointsAndWritesOfThatThreadOnly(): void
    {
        foreach (['keep', 'drop'] as $thread) {
            $config = $this->saver->put(['configurable' => ['thread_id' => $thread]], new Checkpoint(v: 4, id: 'c', ts: ''), []);
            $this->saver->putWrites($config, [['x', 1]], 'task');
        }

        $this->saver->deleteThread('drop');

        self::assertCount(1, $this->client->checkpoints()->documents);
        self::assertCount(1, $this->client->writes()->documents);
        self::assertSame('keep', $this->client->checkpoints()->documents[0]['thread_id']);
        self::assertSame('keep', $this->client->writes()->documents[0]['thread_id']);
    }

    public function testASaverSerialisesToAMarkerNotItsClient(): void
    {
        self::assertSame('[' . MongoDBSaver::class . ']', json_decode((string) json_encode($this->saver), true));
    }
}
