<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use LangGraph\Checkpoint\MongoDB\MongoCollectionInterface;
use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;
use LangGraph\Checkpoint\MongoDB\MongoDBDriverClient;
use LangGraph\Checkpoint\MongoDB\MongoDBDriverCollection;
use LangGraph\Store\MongoDB\MongoDBDriverStoreClient;
use LangGraph\Store\MongoDB\MongoDBDriverStoreCollection;
use LangGraph\Store\MongoDB\MongoDBDriverStoreDatabase;
use LangGraph\Store\MongoDB\MongoDBIndexConfig;
use LangGraph\Store\MongoDB\MongoDBStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/** Store contract through production adapter methods and local native-shape fakes, not a server. */
#[CoversClass(MongoDBStore::class)]
#[CoversClass(MongoDBDriverStoreClient::class)]
#[CoversClass(MongoDBDriverStoreDatabase::class)]
#[CoversClass(MongoDBDriverStoreCollection::class)]
#[CoversClass(MongoDBDriverCollection::class)]
final class MongoDBStoreDriverAdapterTest extends MongoDBStoreContractCase
{
    private N09StoreBorrowedClient $client;

    protected function setUp(): void
    {
        $this->client = self::fixture();
    }

    public static function fixture(bool $decodeDates = true): N09StoreBorrowedClient
    {
        return new N09StoreBorrowedClient(new N09StoreNativeClient(), $decodeDates);
    }

    protected function makeStore(string $collection, ?HashEmbeddings $embeddings = null, ?MongoDBIndexConfig $indexConfig = null): MongoDBStore
    {
        $store = new MongoDBStore($this->client, 'contract', $collection, embeddings: $embeddings, indexConfig: $indexConfig);
        $store->start();

        return $store;
    }

    protected function rawDocument(string $collection, array $namespace, string $key): ?array
    {
        return $this->client->handle->selectDatabase('contract')->selectCollection($collection)->storage->findOne(['namespace' => $namespace, 'key' => $key]);
    }

    protected function searchIndex(string $collection, string $name): ?array
    {
        foreach ($this->client->handle->selectDatabase('contract')->selectCollection($collection)->storage->searchIndexes as $definition) {
            if ($definition['name'] === $name) {
                return $definition['definition'];
            }
        }

        return null;
    }

    public function testBulkUsesPositionalNativeArgumentsAndBooleanUpsertsWithoutReadingResult(): void
    {
        $native = new N09StoreNativeCollection();
        $adapter = new N09StoreCollectionAdapter($native);
        $date = new \DateTimeImmutable('2020-02-03T04:05:06.789Z');
        $adapter->bulkWrite([
            ['updateOne' => ['filter' => ['key' => 'a'], 'update' => ['$set' => ['date' => $date]], 'upsert' => true]],
            ['deleteOne' => ['filter' => ['key' => 'b']]],
            ['updateOne' => ['filter' => ['key' => 'c'], 'update' => ['$set' => ['n' => 1]]]],
            ['updateOne' => ['filter' => ['key' => 'd'], 'update' => ['$set' => ['n' => 2]], 'upsert' => 'true']],
        ]);
        $operations = $native->calls[0][1];
        self::assertSame(['key' => 'a'], $operations[0]['updateOne'][0]);
        self::assertInstanceOf(N09StoreNativeDate::class, $operations[0]['updateOne'][1]['$set']['date']);
        self::assertSame($date, $operations[0]['updateOne'][1]['$set']['date']->date);
        self::assertSame(['upsert' => true], $operations[0]['updateOne'][2]);
        self::assertSame([['key' => 'b']], $operations[1]['deleteOne']);
        self::assertSame(['upsert' => false], $operations[2]['updateOne'][2]);
        self::assertSame(['upsert' => false], $operations[3]['updateOne'][2]);
        self::assertSame([], $native->calls[0][2]);
    }

    public static function invalidBulkOperations(): iterable
    {
        yield 'unknown' => [[['insertOne' => ['document' => []]]]];
        yield 'multiple keys' => [[['deleteOne' => ['filter' => []], 'updateOne' => ['filter' => [], 'update' => []]]]];
        yield 'empty' => [[[]]];
        yield 'valid then invalid' => [[['deleteOne' => ['filter' => []]], ['unknown' => []]]];
    }

    #[DataProvider('invalidBulkOperations')]
    public function testUnsupportedBulkSpecsAreRejectedBeforeAnyNativeCall(array $operations): void
    {
        $native = new N09StoreNativeCollection();
        try {
            (new N09StoreCollectionAdapter($native))->bulkWrite($operations);
            self::fail('Invalid keyed bulk operation must be rejected');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $native->calls);
        }
    }

    public function testSearchIndexSeparatesDefinitionAndNativeOptionsAndPreservesExceptionIdentity(): void
    {
        $native = new N09StoreNativeCollection();
        $adapter = new N09StoreCollectionAdapter($native);
        $adapter->createSearchIndex(['name' => 'vector', 'type' => 'vectorSearch', 'definition' => ['fields' => []], 'ignored' => 'not a native option']);
        self::assertSame(['createSearchIndex', ['fields' => []], ['name' => 'vector', 'type' => 'vectorSearch']], $native->calls[0]);
        $error = new \RuntimeException('index already exists');
        $native->failures['createSearchIndex'] = $error;
        try {
            $adapter->createSearchIndex(['definition' => []]);
            self::fail('Native error must propagate');
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
    }

    public function testSetupStillSwallowsAlreadyExistsOnlyThroughTheNativeAdapter(): void
    {
        $store = new MongoDBStore($this->client, 'db', indexConfig: new MongoDBIndexConfig(name: 'v', dims: 10), embeddings: new HashEmbeddings());
        $native = $this->client->handle->selectDatabase('db')->selectCollection('store');
        $native->failures['createSearchIndex'] = new \RuntimeException('Index ALREADY EXISTS');
        $store->start();
        self::assertSame('createSearchIndex', $native->calls[1][0]);
        $error = new \RuntimeException('search index permission denied');
        $native->failures['createSearchIndex'] = $error;
        try {
            $store->start();
            self::fail('Unrelated native errors must propagate');
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
    }

    public function testFindOneAndUpdateUsesIntegerEnumsForcedArraysAndNestedDateDecoding(): void
    {
        $native = new N09StoreNativeCollection();
        $adapter = new N09StoreCollectionAdapter($native);
        $date = new \DateTimeImmutable('2020-02-03T04:05:06.789Z');
        $adapter->bulkWrite([['updateOne' => ['filter' => ['key' => 'a'], 'update' => ['$set' => ['nested' => ['date' => $date]]], 'upsert' => true]]]);
        $after = $adapter->findOneAndUpdate(['key' => 'a'], ['$set' => ['n' => 1]], ['returnDocument' => 'after', 'typeMap' => ['root' => 'object']]);
        self::assertSame(1, $after['n']);
        self::assertInstanceOf(\DateTimeImmutable::class, $after['nested']['date']);
        self::assertSame($date->format('U.u'), $after['nested']['date']->format('U.u'));
        self::assertSame(['returnDocument' => 2, 'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']], end($native->calls)[3]);
        $before = $adapter->findOneAndUpdate(['key' => 'a'], ['$set' => ['n' => 2]]);
        self::assertSame(1, $before['n']);
        self::assertSame(1, end($native->calls)[3]['returnDocument']);
        self::assertNull($adapter->findOneAndUpdate(['key' => 'missing'], ['$set' => ['n' => 3]]));
        $calls = count($native->calls);
        try {
            $adapter->findOneAndUpdate([], [], ['returnDocument' => 2]);
            self::fail('The seam expects before/after strings');
        } catch (\InvalidArgumentException) {
            self::assertCount($calls, $native->calls);
        }
    }

    public function testFindFindOneAndAggregateDecodeDatesButBaseSaverCollectionDoesNot(): void
    {
        $native = new N09StoreNativeCollection();
        $adapter = new N09StoreCollectionAdapter($native);
        $date = new \DateTimeImmutable('2020-02-03T04:05:06.789Z');
        $native->storage->documents = [['nested' => ['date' => $date]], []];
        self::assertInstanceOf(\DateTimeImmutable::class, $adapter->findOne([])['nested']['date']);
        self::assertSame([], $adapter->find()[1]);
        self::assertInstanceOf(\DateTimeImmutable::class, $adapter->aggregate([])[0]['nested']['date']);
        $base = new class ($native) extends MongoDBDriverCollection {
            public function __construct(private readonly N09StoreNativeCollection $native)
            {
            }
            protected function nativeCollection(): object
            {
                return $this->native;
            }
        };
        self::assertInstanceOf(N09StoreNativeDate::class, $base->findOne([])['nested']['date']);
    }

    public function testDateHookPreventsTheStoresSilentEpochZeroRegression(): void
    {
        $store = new MongoDBStore($this->client, 'db');
        $store->put(['dates'], 'item', ['value' => 1]);
        self::assertGreaterThan(1_000_000_000, $store->get(['dates'], 'item')->createdAt->getTimestamp());
        $noDecode = new MongoDBStore(self::fixture(false), 'db');
        $noDecode->put(['dates'], 'item', ['value' => 1]);
        self::assertSame(0, $noDecode->get(['dates'], 'item')->createdAt->getTimestamp());
    }

    public function testPlainCheckpointClientCannotServeTheStoreCollectionSeam(): void
    {
        $native = $this->client->handle;
        $client = new class ($native) extends MongoDBDriverClient {
            public function __construct(private readonly N09StoreNativeClient $native)
            {
            }
            protected function nativeClient(): object
            {
                return $this->native;
            }
            protected function wrapDatabase(object $database): MongoDatabaseInterface
            {
                return new class ($database) implements MongoDatabaseInterface {
                    public function __construct(private readonly N09StoreNativeDatabase $native)
                    {
                    }
                    public function collection(string $name): MongoCollectionInterface
                    {
                        return new class ($this->native->selectCollection($name)) extends MongoDBDriverCollection {
                            public function __construct(private readonly N09StoreNativeCollection $native)
                            {
                            }
                            protected function nativeCollection(): object
                            {
                                return $this->native;
                            }
                        };
                    }
                };
            }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('MongoDBStore needs a');
        (new MongoDBStore($client, 'db'))->start();
    }

    public function testNativeConstructorsAreStrictAndSmokeIsExplicitlySkippedWithoutOptionalDependencies(): void
    {
        if (!extension_loaded('mongodb') || !class_exists(\MongoDB\Client::class)) {
            self::markTestSkipped('Native constructor smoke requires ext-mongodb and optional mongodb/mongodb; no install or service call.');
        }
        $native = new \MongoDB\Client('mongodb://127.0.0.1:1', [], ['disableClientPersistence' => true]);
        $client = new MongoDBDriverStoreClient($native, 'db');
        self::assertInstanceOf(MongoDBDriverStoreDatabase::class, $client->db());
        self::assertInstanceOf(MongoDBDriverStoreCollection::class, $client->db()->collection('store'));
    }

    public function testActualBsonDateRoundTripIsExplicitlySkippedWithoutTheExtension(): void
    {
        if (!extension_loaded('mongodb') || !class_exists(\MongoDB\BSON\Document::class)) {
            self::markTestSkipped('BSON date smoke requires ext-mongodb Document::fromPHP/toPHP (>=1.16), not native-shape fake evidence.');
        }
        $date = new \DateTimeImmutable('2020-02-03T04:05:06.789Z');
        $native = new N09StoreNativeCollection();
        $native->bsonRead = \MongoDB\BSON\Document::fromPHP(['date' => new \MongoDB\BSON\UTCDateTime($date), 'nested' => ['date' => new \MongoDB\BSON\UTCDateTime($date)]]);
        $adapter = new class ($native) extends MongoDBDriverStoreCollection {
            public function __construct(private readonly N09StoreNativeCollection $native)
            {
            }
            protected function nativeCollection(): object
            {
                return $this->native;
            }
        };
        self::assertSame($date->format('U.u'), $adapter->findOne([])['date']->format('U.u'));
        self::assertInstanceOf(\DateTimeImmutable::class, $adapter->findOne([])['nested']['date']);
    }
}

/** Local native-shape test handles; no MongoDB vendor classes or aliases are invented. */
final class N09StoreNativeDate { public function __construct(public readonly \DateTimeInterface $date) {} }
final class N09StoreNativeClient
{
    public array $names = []; public array $databases = []; public ?\Throwable $destructError = null;
    public function selectDatabase(string $name): N09StoreNativeDatabase
    {
        $this->names[] = $name;
        return $this->databases[$name] ??= new N09StoreNativeDatabase();
    }
    public static int $destructThrows = 0; public static int $destructCalls = 0;
    public function __destruct() { self::$destructCalls++; if ($this->destructError !== null) { $e = $this->destructError; $this->destructError = null; self::$destructThrows++; throw $e; } }
}
final class N09StoreNativeDatabase
{
    public array $collections = [];
    public function selectCollection(string $name): N09StoreNativeCollection { return $this->collections[$name] ??= new N09StoreNativeCollection(); }
}
final class N09StoreNativeCollection
{
    public mixed $bsonRead = null; public FakeMongoStoreCollection $storage; public array $calls = []; public array $failures = [];
    private const ARRAY_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];
    public function __construct() { $this->storage = new FakeMongoStoreCollection(); }
    public function find(array|object $filter = [], array $options = []): object
    {
        $this->record('find', $filter, $options);
        $docs = self::wire($this->storage->find(self::stored($filter), $options['sort'] ?? [], $options['limit'] ?? null));
        return self::cursor(self::typeMap($docs, $options));
    }
    public function findOne(array|object $filter = [], array $options = []): array|object|null
    {
        $this->record('findOne', $filter, $options);
        if ($this->bsonRead !== null) { self::typeMap(null, $options); return $this->bsonRead->toPHP($options['typeMap']); }
        return self::typeMap(self::wire($this->storage->findOne(self::stored($filter), $options['sort'] ?? [])), $options);
    }
    public function updateOne(array|object $filter, array|object $update, array $options = []): object
    {
        $this->record('updateOne', $filter, $update, $options);
        $this->storage->updateOne(self::stored($filter), self::stored($update), $options);
        return new \stdClass();
    }
    public function createIndex(array|object $key, array $options = []): string
    {
        $this->record('createIndex', $key, $options);
        return $this->storage->createIndex($key, $options);
    }
    public function aggregate(array $pipeline, array $options = []): object
    {
        $this->record('aggregate', $pipeline, $options);
        return self::cursor(self::typeMap(self::wire($this->storage->aggregate(self::stored($pipeline))), $options));
    }
    public function bulkWrite(array $operations, array $options = []): object
    {
        $this->record('bulkWrite', $operations, $options);
        $keyed = [];
        foreach ($operations as $op) {
            $type = array_key_first($op); $args = $op[$type];
            if ($type === 'updateOne') {
                if (!is_bool($args[2]['upsert'] ?? false)) { throw new \InvalidArgumentException('upsert must be bool'); }
                $keyed[] = ['updateOne' => ['filter' => self::stored($args[0]), 'update' => self::stored($args[1]), 'upsert' => $args[2]['upsert'] ?? false]];
            } elseif ($type === 'deleteOne') {
                $keyed[] = ['deleteOne' => ['filter' => self::stored($args[0])]];
            } else { throw new \InvalidArgumentException("fake: unsupported {$type}"); }
        }
        $this->storage->bulkWrite($keyed);
        return new \stdClass();
    }
    public function createSearchIndex(array|object $definition, array $options = []): string
    {
        $this->record('createSearchIndex', $definition, $options);
        return $this->storage->createSearchIndex(['definition' => $definition] + $options);
    }
    public function findOneAndUpdate(array|object $filter, array|object $update, array $options = []): array|object|null
    {
        $this->record('findOneAndUpdate', $filter, $update, $options);
        if (!in_array($options['returnDocument'] ?? 1, [1, 2], true)) { throw new \InvalidArgumentException('returnDocument int'); }
        $doc = $this->storage->findOneAndUpdate(self::stored($filter), self::stored($update), ['returnDocument' => ($options['returnDocument'] ?? 1) === 2 ? 'after' : 'before']);
        return self::typeMap(self::wire($doc), $options);
    }
    private function record(string $m, mixed ...$args): void
    {
        $this->calls[] = [$m, ...$args];
        if (isset($this->failures[$m])) { throw $this->failures[$m]; }
    }
    private static function cursor(array $docs): object
    {
        return new class ($docs) { public function __construct(private array $d) {} public function toArray(): array { return $this->d; } };
    }
    private static function typeMap(mixed $v, array $options): mixed
    {
        if (($options['typeMap'] ?? null) !== self::ARRAY_MAP) { throw new \LogicException('fake: all-array typeMap required'); }
        return $v;
    }
    private static function stored(mixed $v): mixed
    {
        if ($v instanceof N09StoreNativeDate) { return $v->date; }
        return is_array($v) ? array_map(self::stored(...), $v) : $v;
    }
    private static function wire(mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) { return new N09StoreNativeDate($v); }
        return is_array($v) ? array_map(self::wire(...), $v) : $v;
    }
}

/* ---------- adapter subclasses, N08 pattern: constructors bypass the strict native types ---------- */
class N09StoreCollectionAdapter extends MongoDBDriverStoreCollection
{
    public function __construct(public readonly N09StoreNativeCollection $handle, private readonly bool $decodeDates = true) {}
    protected function nativeCollection(): object { return $this->handle; }
    protected function encodeDate(\DateTimeInterface $date): object { return new N09StoreNativeDate($date); }
    protected function decodeDate(mixed $value): ?\DateTimeInterface
    {
        return $this->decodeDates && $value instanceof N09StoreNativeDate ? \DateTimeImmutable::createFromInterface($value->date) : null;
    }
}
class N09StoreDatabaseAdapter extends MongoDBDriverStoreDatabase
{
    public function __construct(private readonly N09StoreNativeDatabase $handle, private readonly bool $decodeDates = true) {}
    protected function nativeDatabase(): object { return $this->handle; }
    protected function wrapCollection(object $collection): MongoCollectionInterface { return new N09StoreCollectionAdapter($collection, $this->decodeDates); }
}
final class N09StoreBorrowedClient extends MongoDBDriverStoreClient
{
    public function __construct(public readonly N09StoreNativeClient $handle, bool $decodeDates = true) { $this->defaultDbName = null; $this->decodeDates = $decodeDates; }
    private bool $decodeDates;
    protected function nativeClient(): object { return $this->handle; }
    protected function wrapDatabase(object $database): MongoDatabaseInterface { return new N09StoreDatabaseAdapter($database, $this->decodeDates); }
}
