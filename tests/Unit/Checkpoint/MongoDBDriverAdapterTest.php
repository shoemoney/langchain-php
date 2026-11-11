<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\MongoDB\MongoBinary;
use LangGraph\Checkpoint\MongoDB\MongoCollectionInterface;
use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;
use LangGraph\Checkpoint\MongoDB\MongoDBDriverClient;
use LangGraph\Checkpoint\MongoDB\MongoDBDriverCollection;
use LangGraph\Checkpoint\MongoDB\MongoDBDriverDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MongoDBDriverClient::class)]
#[CoversClass(MongoDBDriverDatabase::class)]
#[CoversClass(MongoDBDriverCollection::class)]
final class MongoDBDriverAdapterTest extends TestCase
{
    public static function fixture(?string $defaultDbName = null): N08ClientAdapter
    {
        return new N08ClientAdapter(new N08NativeClient(), $defaultDbName);
    }

    public function testExplicitDatabaseOverridesDefaultAndNullUsesProvidedDefault(): void
    {
        $adapter = self::fixture('default_db');
        $adapter->db('explicit_db')->collection('custom');
        $adapter->db()->collection('other');
        self::assertSame(['explicit_db', 'default_db'], $adapter->handle->names);
        self::assertSame(['custom'], $adapter->handle->databases['explicit_db']->names);
        self::assertSame(['other'], $adapter->handle->databases['default_db']->names);
    }

    public function testMissingDatabaseFailsWithoutNativeSelection(): void
    {
        $adapter = self::fixture();
        try {
            $adapter->db();
            self::fail('Expected explicit database requirement');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('dbName', $e->getMessage());
            self::assertSame([], $adapter->handle->names);
        }
    }

    public function testMetadataAndWrapperDestructionDoNotReconstructOrReleaseBorrower(): void
    {
        $native = new N08NativeClient();
        $weak = \WeakReference::create($native);
        $adapter = new N08ClientAdapter($native, 'db');
        $adapter->appendMetadata(['name' => 'langgraphjs_checkpoint_saver']);
        self::assertSame([], $native->names);
        $database = $adapter->db();
        unset($adapter, $database);
        gc_collect_cycles();
        self::assertSame($native, $weak->get());
        self::assertInstanceOf(N08NativeDatabase::class, $native->selectDatabase('still_usable'));
        self::assertFalse(method_exists(MongoDBDriverClient::class, 'close'));
        self::assertFalse(method_exists(MongoDBDriverClient::class, 'fromConnString'));
    }

    public function testFindOptionsAndDirectFindOneMatchNativeApi(): void
    {
        $adapter = self::fixture('db');
        $collection = $adapter->db()->collection('c');
        $collection->insertMany([['id' => 'b'], ['id' => 'a'], ['id' => 'c']]);
        self::assertSame([['id' => 'c'], ['id' => 'b']], $collection->find([], ['id' => -1], 2));
        self::assertSame(['id' => 'a'], $collection->findOne([], ['id' => 1]));
        self::assertNull($collection->findOne(['id' => 'missing']));
        $native = $adapter->handle->databases['db']->collections['c'];
        self::assertSame('find', $native->calls[1][0]);
        self::assertSame(['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'], 'sort' => ['id' => -1], 'limit' => 2], $native->calls[1][2]);
        self::assertSame('findOne', $native->calls[2][0]);
        self::assertArrayNotHasKey('limit', $native->calls[2][2]);
        $collection->find();
        self::assertSame(['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']], $native->calls[4][2]);
        self::assertCount(3, $collection->find([], [], 0)); // Native limit(0) is unlimited.
    }

    public function testBinaryDatesObjectsAndMutationIsolationAcrossNestedWrites(): void
    {
        $adapter = self::fixture('db');
        $collection = $adapter->db()->collection('c');
        $bytes = "a\0\xffz";
        $date = new \DateTimeImmutable('2024-02-01T12:00:00.123Z');
        $object = (object) ['empty' => new \stdClass(), 'nested' => [new MongoBinary($bytes), $date]];
        $opaque = new class () {};
        $collection->updateOne(['id' => 1], ['$set' => ['document' => $object, 'opaque' => $opaque]], ['upsert' => true]);
        $native = $adapter->handle->databases['db']->collections['c'];
        $encoded = $native->calls[0][2]['$set']['document'];
        self::assertInstanceOf(\stdClass::class, $encoded);
        self::assertInstanceOf(\stdClass::class, $encoded->empty);
        self::assertInstanceOf(N08NativeBinary::class, $encoded->nested[0]);
        self::assertSame($bytes, $encoded->nested[0]->data);
        self::assertInstanceOf(N08NativeDate::class, $encoded->nested[1]);
        self::assertSame($date, $encoded->nested[1]->date);
        self::assertSame($opaque, $native->calls[0][2]['$set']['opaque']);
        self::assertInstanceOf(MongoBinary::class, $object->nested[0]);
        self::assertNotSame($object, $encoded);
        self::assertSame($bytes, $collection->findOne(['id' => 1])['document']['nested'][0]->value());
        $native->nextRead = ['document' => (object) ['binary' => new N08NativeBinary($bytes)]];
        self::assertSame($bytes, $collection->findOne([])['document']['binary']->value());
    }

    public function testAllFakeReadPathsMapEmbeddedDocumentsToArrays(): void
    {
        $adapter = self::fixture('db');
        $collection = $adapter->db()->collection('c');
        $native = $adapter->handle->databases['db']->collections['c'];
        $native->nextRead = ['object' => (object) ['empty' => new \stdClass(),
            'nested' => [(object) ['binary' => new N08NativeBinary("a\0\xff")]]]];
        foreach ([$collection->findOne([]), $collection->find()[0]] as $decoded) {
            self::assertIsArray($decoded['object']);
            self::assertSame([], $decoded['object']['empty']);
            self::assertIsArray($decoded['object']['nested'][0]);
            self::assertSame("a\0\xff", $decoded['object']['nested'][0]['binary']->value());
        }
        self::assertInstanceOf(\stdClass::class, $native->nextRead['object']);
    }

    public function testDeleteResultAndIndexOptionsAreForwarded(): void
    {
        $adapter = self::fixture('db');
        $collection = $adapter->db()->collection('c');
        $collection->insertMany([['id' => 1], ['id' => 2]]);
        self::assertSame(1, $collection->deleteMany(['id' => 1]));
        $native = $adapter->handle->databases['db']->collections['c'];
        $native->unacknowledgedDelete = true;
        self::assertSame(0, $collection->deleteMany(['id' => 2]));
        self::assertFalse($native->lastDeleteResult->isAcknowledged());
        self::assertSame(0, $native->lastDeleteResult->countReads);
        try {
            $native->lastDeleteResult->getDeletedCount();
            self::fail('Real unacknowledged results reject count access');
        } catch (\LogicException $e) {
            self::assertStringContainsString('Unacknowledged', $e->getMessage());
        }
        self::assertSame('ttl', $collection->createIndex(['upserted_at' => 1], ['name' => 'ttl', 'expireAfterSeconds' => 30]));
        self::assertSame(['createIndex', ['upserted_at' => 1], ['name' => 'ttl', 'expireAfterSeconds' => 30]], $native->calls[3]);
    }

    public function testNativeFailuresPropagateUnchanged(): void
    {
        $adapter = self::fixture('db');
        $collection = $adapter->db()->collection('c');
        $native = $adapter->handle->databases['db']->collections['c'];
        foreach (['find', 'findOne', 'updateOne', 'insertMany', 'deleteMany', 'createIndex'] as $method) {
            $error = new \RuntimeException($method . ' failed');
            $native->failures[$method] = $error;
            try {
                match ($method) {
                    'find' => $collection->find(),
                    'findOne' => $collection->findOne([]),
                    'updateOne' => $collection->updateOne([], ['$set' => []]),
                    'insertMany' => $collection->insertMany([[]]),
                    'deleteMany' => $collection->deleteMany([]),
                    'createIndex' => $collection->createIndex(['id' => 1]),
                };
                self::fail('Expected ' . $method . ' failure');
            } catch (\RuntimeException $actual) {
                self::assertSame($error, $actual);
            }
            unset($native->failures[$method]);
        }
    }

    public function testProductionConstructorsRejectNonNativeObjects(): void
    {
        foreach ([MongoDBDriverClient::class, MongoDBDriverDatabase::class, MongoDBDriverCollection::class] as $class) {
            try {
                new $class(new \stdClass());
                self::fail('Expected native constructor type');
            } catch (\TypeError $e) {
                self::assertStringContainsString('MongoDB\\', $e->getMessage());
            }
        }
    }

    public function testNativeConstructorSmokeWithoutServerOperation(): void
    {
        if (!extension_loaded('mongodb') || !class_exists(\MongoDB\Client::class)) {
            self::markTestSkipped('Optional ext-mongodb and mongodb/mongodb unavailable; no native constructor coverage.');
        }
        $client = new \MongoDB\Client('mongodb://127.0.0.1:27017', [], ['disableClientPersistence' => true]);
        $wrapper = new MongoDBDriverClient($client, 'langgraph_no_io');
        self::assertInstanceOf(MongoDBDriverDatabase::class, $wrapper->db());
        self::assertInstanceOf(MongoDBDriverCollection::class, $wrapper->db()->collection('no_io'));
        self::assertInstanceOf(MongoDBDriverDatabase::class, new MongoDBDriverDatabase($client->selectDatabase('direct')));
    }

    public function testNativeBsonCodecWithoutServerOperation(): void
    {
        if (!extension_loaded('mongodb') || !class_exists(\MongoDB\BSON\Document::class)) {
            self::markTestSkipped('Optional ext-mongodb with BSON Document API (>=1.16) unavailable; no actual BSON roundtrip coverage.');
        }
        $native = new N08NativeCollection();
        $adapter = new class ($native) extends MongoDBDriverCollection {
            public function __construct(private readonly N08NativeCollection $handle) {}
            protected function nativeCollection(): object { return $this->handle; }
        };
        $date = new \DateTimeImmutable('2024-02-01T12:00:00.123Z');
        $adapter->insertMany([['binary' => new MongoBinary("a\0\xff"), 'date' => $date, 'object' => (object) ['empty' => new \stdClass(), 'nested' => [new MongoBinary("b\0\xff"), $date]]]]);
        $encoded = $native->calls[0][1][0];
        self::assertInstanceOf(\MongoDB\BSON\Binary::class, $encoded['binary']);
        self::assertSame(\MongoDB\BSON\Binary::TYPE_GENERIC, $encoded['binary']->getType());
        self::assertSame("a\0\xff", $encoded['binary']->getData());
        self::assertSame('123', $encoded['date']->toDateTime()->format('v'));
        $native->bsonRead = \MongoDB\BSON\Document::fromPHP($encoded);
        $decoded = $adapter->findOne([]);
        self::assertSame("a\0\xff", $decoded['binary']->value());
        self::assertSame($date->format('U.u'), $decoded['date']->toDateTime()->format('U.u'));
        self::assertSame([], $decoded['object']['empty']);
        self::assertSame("b\0\xff", $decoded['object']['nested'][0]->value());
        self::assertSame($date->format('U.u'), $decoded['object']['nested'][1]->toDateTime()->format('U.u'));
        $all = $adapter->find();
        self::assertSame([], $all[0]['object']['empty']);
        self::assertSame("b\0\xff", $all[0]['object']['nested'][0]->value());
    }
}

/** Local fake driver handles; no MongoDB vendor classes or native lifecycle APIs are invented. */
final class N08NativeBinary
{
    public function __construct(public readonly string $data) {}
}

final class N08NativeDate
{
    public function __construct(public readonly \DateTimeInterface $date) {}
}

final class N08NativeClient
{
    public array $names = [];
    public array $databases = [];
    public function selectDatabase(string $name): N08NativeDatabase
    {
        $this->names[] = $name;
        return $this->databases[$name] ??= new N08NativeDatabase();
    }
}

final class N08NativeDatabase
{
    public array $names = [];
    public array $collections = [];
    public ?\Throwable $selectionError = null;
    public function selectCollection(string $name): N08NativeCollection
    {
        $this->names[] = $name;
        if ($this->selectionError !== null) { throw $this->selectionError; }
        return $this->collections[$name] ??= new N08NativeCollection();
    }
}

final class N08ClientAdapter extends MongoDBDriverClient
{
    public function __construct(public readonly N08NativeClient $handle, ?string $defaultDbName = null)
    {
        $this->defaultDbName = $defaultDbName;
    }
    protected function nativeClient(): object { return $this->handle; }
    protected function wrapDatabase(object $database): MongoDatabaseInterface { return new N08DatabaseAdapter($database); }
}

final class N08DatabaseAdapter extends MongoDBDriverDatabase
{
    public function __construct(private readonly N08NativeDatabase $handle) {}
    protected function nativeDatabase(): object { return $this->handle; }
    protected function wrapCollection(object $collection): MongoCollectionInterface { return new N08CollectionAdapter($collection); }
}

final class N08CollectionAdapter extends MongoDBDriverCollection
{
    public function __construct(private readonly N08NativeCollection $handle) {}
    protected function nativeCollection(): object { return $this->handle; }
    protected function encodeBinary(MongoBinary $binary): object { return new N08NativeBinary($binary->value()); }
    protected function encodeDate(\DateTimeInterface $date): object { return new N08NativeDate($date); }
    protected function decodeBinary(mixed $value): ?MongoBinary
    {
        return $value instanceof N08NativeBinary ? new MongoBinary($value->data) : parent::decodeBinary($value);
    }
}

final class N08NativeCollection
{
    public readonly FakeMongoCollection $storage;
    public array $calls = [];
    public array $failures = [];
    public bool $unacknowledgedDelete = false;
    public ?object $lastDeleteResult = null;
    public ?array $nextRead = null;
    public ?object $bsonRead = null;
    public function __construct() { $this->storage = new FakeMongoCollection(); }
    public function find(array $filter, array $options): object
    {
        $this->record('find', $filter, $options);
        $limit = ($options['limit'] ?? 0) === 0 ? null : $options['limit'];
        $documents = $this->bsonRead !== null ? [$this->bsonRead->toPHP($options['typeMap'])]
            : ($this->nextRead !== null ? [$this->nextRead]
                : self::wire($this->storage->find(self::stored($filter), $options['sort'] ?? [], $limit)));
        $documents = self::readTypeMap($documents, $options);
        return new class ($documents) {
            public function __construct(private readonly array $documents) {}
            public function toArray(): array { return $this->documents; }
        };
    }
    public function findOne(array $filter, array $options): ?array
    {
        $this->record('findOne', $filter, $options);
        if ($this->bsonRead !== null) { return $this->bsonRead->toPHP($options['typeMap']); }
        $document = $this->nextRead ?? self::wire($this->storage->findOne(self::stored($filter), $options['sort'] ?? []));
        return self::readTypeMap($document, $options);
    }
    public function updateOne(array $filter, array $update, array $options): void
    {
        $this->record('updateOne', $filter, $update, $options);
        $this->storage->updateOne(self::stored($filter), self::stored($update), $options);
    }
    public function insertMany(array $documents): void
    {
        $this->record('insertMany', $documents);
        $this->storage->insertMany(self::stored($documents));
    }
    public function deleteMany(array $filter): object
    {
        $this->record('deleteMany', $filter);
        $count = $this->storage->deleteMany(self::stored($filter));
        return $this->lastDeleteResult = new class ($count, !$this->unacknowledgedDelete) {
            public int $countReads = 0;
            public function __construct(private readonly int $count, private readonly bool $acknowledged) {}
            public function isAcknowledged(): bool { return $this->acknowledged; }
            public function getDeletedCount(): int
            {
                ++$this->countReads;
                if (!$this->acknowledged) { throw new \LogicException('Unacknowledged write result has no deleted count.'); }
                return $this->count;
            }
        };
    }
    public function createIndex(array $keys, array $options): string
    {
        $this->record('createIndex', $keys, $options);
        return $this->storage->createIndex($keys, $options);
    }
    private function record(string $method, mixed ...$arguments): void
    {
        $this->calls[] = [$method, ...$arguments];
        if (isset($this->failures[$method])) { throw $this->failures[$method]; }
    }
    private static function readTypeMap(mixed $value, array $options): mixed
    {
        if (($options['typeMap'] ?? null) !== ['root' => 'array', 'document' => 'array', 'array' => 'array']) {
            throw new \LogicException('Local fixture only implements the requested all-array type map.');
        }
        if ($value instanceof \stdClass) { $value = get_object_vars($value); }
        if (is_array($value)) {
            return array_map(static fn ($field) => self::readTypeMap($field, $options), $value);
        }
        return $value;
    }
    private static function stored(mixed $value): mixed
    {
        if ($value instanceof N08NativeBinary) { return new MongoBinary($value->data); }
        if ($value instanceof N08NativeDate) { return $value->date; }
        if (is_array($value)) { return array_map(self::stored(...), $value); }
        if ($value instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $field) { $copy->{$key} = self::stored($field); }
            return $copy;
        }
        return $value;
    }
    private static function wire(mixed $value): mixed
    {
        if ($value instanceof MongoBinary) { return new N08NativeBinary($value->value()); }
        if (is_array($value)) { return array_map(self::wire(...), $value); }
        return $value;
    }
}
