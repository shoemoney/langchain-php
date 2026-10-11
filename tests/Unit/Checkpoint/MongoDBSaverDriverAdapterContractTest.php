<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\MongoDB\MongoDBDriverClient;
use LangGraph\Checkpoint\MongoDB\MongoDBDriverCollection;
use LangGraph\Checkpoint\MongoDB\MongoDBDriverDatabase;
use LangGraph\Checkpoint\MongoDB\MongoDBSaver;
use PHPUnit\Framework\Attributes\CoversClass;

/** Runs the shared checkpoint contract through all three production adapter method paths. */
#[CoversClass(MongoDBSaver::class)]
#[CoversClass(MongoDBDriverClient::class)]
#[CoversClass(MongoDBDriverDatabase::class)]
#[CoversClass(MongoDBDriverCollection::class)]
final class MongoDBSaverDriverAdapterContractTest extends CheckpointerSpecCase
{
    protected function makeSaver(): BaseCheckpointSaver
    {
        return new MongoDBSaver(MongoDBDriverAdapterTest::fixture('contract'));
    }

    public function testSetupTtlAndAllIndexFailuresUseNativeAdapterPath(): void
    {
        $adapter = MongoDBDriverAdapterTest::fixture('default');
        $saver = new MongoDBSaver($adapter, 'explicit', 'custom_cp', 'custom_writes', ttl: 45);
        self::assertSame(['explicit'], $adapter->handle->names);
        self::assertSame([], $saver->setup());
        $database = $adapter->handle->databases['explicit'];
        $cp = $database->collections['custom_cp'];
        $writes = $database->collections['custom_writes'];
        self::assertSame(['createIndex', ['thread_id' => 1, 'checkpoint_ns' => 1, 'checkpoint_id' => -1], ['name' => 'thread_ns_checkpoint_idx']], $cp->calls[0]);
        self::assertSame(['createIndex', ['thread_id' => 1, 'checkpoint_ns' => 1, 'checkpoint_id' => 1, 'task_id' => 1, 'idx' => 1], ['name' => 'thread_ns_checkpoint_task_idx']], $writes->calls[0]);
        foreach ([$cp, $writes] as $collection) {
            self::assertSame(['createIndex', ['upserted_at' => 1], ['expireAfterSeconds' => 45]], $collection->calls[1]);
        }
        $first = new \RuntimeException('checkpoint index failure');
        $second = new \RuntimeException('writes index failure');
        $cp->failures['createIndex'] = $first;
        $writes->failures['createIndex'] = $second;
        self::assertSame([$first, $second, $first, $second], $saver->setup());
        self::assertCount(4, $cp->calls);
        self::assertCount(4, $writes->calls);
        unset($cp->failures['createIndex'], $writes->failures['createIndex']);
        self::assertSame([], $saver->setup());
        self::assertSame($database, $adapter->handle->selectDatabase('explicit'));
    }

    public function testCollectionSelectionFailureIsNotMisreportedAsIndexResult(): void
    {
        $adapter = MongoDBDriverAdapterTest::fixture('db');
        $saver = new MongoDBSaver($adapter);
        $error = new \RuntimeException('collection selection');
        $adapter->handle->databases['db']->selectionError = $error;
        try {
            $saver->setup();
            self::fail('Expected selection failure');
        } catch (\RuntimeException $actual) {
            self::assertSame($error, $actual);
        }
    }

    public function testRoundtripOrderingFilteringUpsertsAndWritesThroughNativeApi(): void
    {
        $adapter = MongoDBDriverAdapterTest::fixture('db');
        $saver = new MongoDBSaver($adapter, ttl: 60);
        foreach (['0002', '0009', '0001'] as $id) {
            $saver->put(['configurable' => ['thread_id' => 't', 'checkpoint_ns' => 'ns']], new Checkpoint(v: 4, id: $id, ts: '', channelValues: ['value' => $id]), ['source' => 'loop', 'step' => (int) $id]);
        }
        $config = ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => 'ns', 'checkpoint_id' => '0009']];
        $saver->putWrites($config, [['value', ['a' => 1]]], 'task');
        $saver->putWrites($config, [['value', ['a' => 2]]], 'task');
        $latest = $saver->getTuple(['configurable' => ['thread_id' => 't', 'checkpoint_ns' => 'ns']]);
        self::assertSame('0009', $latest?->checkpoint->id);
        self::assertSame([['task', 'value', ['a' => 1]]], $latest?->pendingWrites);
        $limited = $saver->list(['configurable' => ['thread_id' => 't', 'checkpoint_ns' => 'ns']], new CheckpointListOptions(limit: 2, filter: ['source' => 'loop']));
        self::assertSame(['0009', '0002'], array_map(static fn ($tuple) => $tuple->checkpoint->id, $limited));
        self::assertSame($latest?->pendingWrites, $limited[0]->pendingWrites);
        $before = $saver->list(['configurable' => ['thread_id' => 't', 'checkpoint_ns' => 'ns']], new CheckpointListOptions(before: $config));
        self::assertSame(['0002', '0001'], array_map(static fn ($tuple) => $tuple->checkpoint->id, $before));
        $saver->put($config, new Checkpoint(v: 4, id: '0009', ts: '', channelValues: ['replaced' => true]), []);
        self::assertSame(['replaced' => true], $saver->getTuple($config)?->checkpoint->channelValues);
        $database = $adapter->handle->databases['db'];
        self::assertCount(3, $database->collections['checkpoints']->storage->documents);
        $writes = $database->collections['checkpoint_writes'];
        self::assertCount(1, $writes->storage->documents);
        self::assertSame(0, $writes->calls[0][1]['idx']);
        self::assertSame(['upsert' => true], $writes->calls[0][3]);
        self::assertArrayHasKey('$setOnInsert', $writes->calls[0][2]);
        self::assertInstanceOf(N08NativeDate::class, $writes->calls[0][2]['$setOnInsert']['upserted_at']);
        $saver->putWrites($config, [['__interrupt__', 'one']], 'special');
        $saver->putWrites($config, [['__interrupt__', 'two']], 'special');
        self::assertArrayHasKey('$set', $writes->calls[count($writes->calls) - 1][2]);
        self::assertSame(-3, $writes->calls[count($writes->calls) - 1][1]['idx']);
        self::assertContains(['special', '__interrupt__', 'two'], $saver->getTuple($config)?->pendingWrites);
        $callsBefore = count($writes->calls);
        $saver->putWrites($config, [], 'empty');
        self::assertCount($callsBefore, $writes->calls);
    }

    public function testEmptyMetadataObjectAndBorrowedLifetimeArePreserved(): void
    {
        $adapter = MongoDBDriverAdapterTest::fixture('db');
        $native = $adapter->handle;
        $weak = \WeakReference::create($native);
        $saver = new MongoDBSaver($adapter);
        $saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'c', ts: ''), []);
        $encoded = $native->databases['db']->collections['checkpoints']->calls[0][2]['$set'];
        self::assertInstanceOf(\stdClass::class, $encoded['metadata_search']);
        self::assertSame('{}', $encoded['metadata']->data);
        self::assertStringContainsString('"channel_values":{}', $encoded['checkpoint']->data);
        unset($saver, $adapter);
        gc_collect_cycles();
        self::assertSame($native, $weak->get());
        self::assertInstanceOf(N08NativeDatabase::class, $native->selectDatabase('caller_can_reuse'));
    }

    public function testDeleteThreadIsScopedAcrossBothAdapterCollections(): void
    {
        $adapter = MongoDBDriverAdapterTest::fixture('db');
        $saver = new MongoDBSaver($adapter);
        foreach (['keep', 'delete'] as $thread) {
            $config = $saver->put(['configurable' => ['thread_id' => $thread]], new Checkpoint(v: 4, id: 'c', ts: ''), []);
            $saver->putWrites($config, [['x', 1]], 'task');
        }
        $saver->deleteThread('delete');
        self::assertNull($saver->getTuple(['configurable' => ['thread_id' => 'delete']]));
        self::assertSame([['task', 'x', 1]], $saver->getTuple(['configurable' => ['thread_id' => 'keep']])?->pendingWrites);
        foreach (['checkpoints', 'checkpoint_writes'] as $name) {
            $calls = $adapter->handle->databases['db']->collections[$name]->calls;
            $deletes = array_values(array_filter($calls, static fn ($call) => $call[0] === 'deleteMany'));
            self::assertSame([['deleteMany', ['thread_id' => 'delete']]], $deletes);
        }
    }
    public function testUnacknowledgedDeleteStillDeletesBothThreadCollections(): void
    {
        $adapter = MongoDBDriverAdapterTest::fixture('db');
        $saver = new MongoDBSaver($adapter);
        foreach (['keep', 'delete'] as $thread) {
            $config = $saver->put(['configurable' => ['thread_id' => $thread]], new Checkpoint(v: 4, id: 'c', ts: ''), []);
            $saver->putWrites($config, [['x', 1]], 'task');
        }
        $database = $adapter->handle->databases['db'];
        foreach ($database->collections as $collection) { $collection->unacknowledgedDelete = true; }
        $saver->deleteThread('delete');
        foreach (['checkpoints', 'checkpoint_writes'] as $name) {
            $collection = $database->collections[$name];
            $deletes = array_values(array_filter($collection->calls, static fn ($call) => $call[0] === 'deleteMany'));
            self::assertSame([['deleteMany', ['thread_id' => 'delete']]], $deletes);
            self::assertFalse($collection->lastDeleteResult->isAcknowledged());
            self::assertSame(0, $collection->lastDeleteResult->countReads);
            self::assertSame(['keep'], array_column($collection->storage->documents, 'thread_id'));
        }
        self::assertSame([['task', 'x', 1]], $saver->getTuple(['configurable' => ['thread_id' => 'keep']])?->pendingWrites);
    }

}
