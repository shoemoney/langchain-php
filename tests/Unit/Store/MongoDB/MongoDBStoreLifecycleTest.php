<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;
use LangGraph\Store\MongoDB\MongoDBIndexConfig;
use LangGraph\Store\MongoDB\MongoDBOwnedStoreClient;
use LangGraph\Store\MongoDB\MongoDBStore;
use LangGraph\Store\MongoDB\MongoDBTtlConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** PHP-authored lifecycle tests: upstream has source factory/stop methods, no matching unit cases. */
#[CoversClass(MongoDBStore::class)]
#[CoversClass(MongoDBOwnedStoreClient::class)]
final class MongoDBStoreLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        // Loading this test fixture's class also declares the local native-shape handles.
        self::assertTrue(class_exists(MongoDBStoreDriverAdapterTest::class));
        N09FactoryStore::reset();
        N09StoreNativeClient::$destructThrows = 0;
        N09StoreNativeClient::$destructCalls = 0;
    }

    protected function tearDown(): void
    {
        N09FactoryStore::$lease?->release();
        N09FactoryStore::reset();
    }

    public function testConstructionIsLazyAndFirstNativeOperationDuringStartIsIndexCreation(): void
    {
        $creations = 0;
        $lease = new N09StoreOwnedLease(static function () use (&$creations): object {
            ++$creations;

            return new N09StoreNativeClient();
        }, []);
        $store = new MongoDBStore($lease, 'db');
        self::assertSame(0, $creations);
        $store->start();
        self::assertSame(1, $creations);
        $store->start();
        self::assertSame(1, $creations);
        $lease->release();
    }

    public function testFactoryReturnsBaseStoreAndCreatesUniqueTtlAndVectorIndexesOnSelectedNames(): void
    {
        $native = new N09StoreNativeClient();
        N09FactoryStore::$factory = static fn (): object => $native;
        $store = N09FactoryStore::fromConnString('mongodb://fake-only', 'chosen_db', 'chosen_collection', ttl: new MongoDBTtlConfig(60), embeddings: new HashEmbeddings(), indexConfig: new MongoDBIndexConfig(name: 'vector', dims: 10), uriOptions: ['appname' => 'local-test']);
        self::assertSame(MongoDBStore::class, get_class($store));
        self::assertCount(1, N09FactoryStore::$factoryCalls);
        self::assertSame(['mongodb://fake-only', ['appname' => 'local-test'], ['disableClientPersistence' => true, 'driver' => ['name' => 'langgraphjs_store']]], N09FactoryStore::$factoryCalls[0]);
        $collection = $native->databases['chosen_db']->collections['chosen_collection'];
        self::assertSame(['createIndex', ['namespaceStr' => 1, 'key' => 1], ['unique' => true]], $collection->calls[0]);
        self::assertSame(['createIndex', ['expiresAt' => 1], ['expireAfterSeconds' => 0]], $collection->calls[1]);
        self::assertSame('createSearchIndex', $collection->calls[2][0]);
        self::assertSame(['name' => 'vector', 'type' => 'vectorSearch'], $collection->calls[2][2]);
        self::assertSame(10, $collection->calls[2][1]['fields'][0]['numDimensions']);
        self::assertSame(['chosen_db'], array_keys($native->databases));
        self::assertSame(['chosen_collection'], array_keys($native->databases['chosen_db']->collections));
        $store->stop();
    }

    public function testCallerDriverKeysHaveExplicitPhpPrecedenceAndLateMetadataDoesNotMutateNativeOptions(): void
    {
        $store = N09FactoryStore::fromConnString('mongodb://fake-only', 'db', driverOptions: ['driver' => ['name' => 'caller', 'version' => '9', 'platform' => 'local']]);
        self::assertSame(['name' => 'caller', 'version' => '9', 'platform' => 'local'], N09FactoryStore::$factoryCalls[0][2]['driver']);
        N09FactoryStore::$lease->appendMetadata(['name' => 'late', 'extra' => 'ignored']);
        $store->put(['ns'], 'key', ['value' => 1]);
        self::assertCount(1, N09FactoryStore::$factoryCalls);
        self::assertSame(['name' => 'caller', 'version' => '9', 'platform' => 'local'], N09FactoryStore::$factoryCalls[0][2]['driver']);
        $store->stop();
    }

    public static function invalidDriverOptions(): iterable
    {
        foreach ([false, null, 0, 1, 'true', []] as $index => $value) {
            yield 'persistence ' . $index => [['disableClientPersistence' => $value]];
        }
        foreach ([null, false, 'driver', new \stdClass()] as $index => $value) {
            yield 'driver ' . $index => [['driver' => $value]];
        }
    }

    #[DataProvider('invalidDriverOptions')]
    public function testInvalidOptionsFailBeforeLeaseAcquisition(array $options): void
    {
        try {
            N09FactoryStore::fromConnString('mongodb://fake-only', 'db', driverOptions: $options);
            self::fail('Invalid ownership/driver option must be rejected');
        } catch (\InvalidArgumentException) {
            self::assertNull(N09FactoryStore::$lease);
            self::assertSame([], N09FactoryStore::$factoryCalls);
            self::assertSame(0, N09FactoryStore::$acquisitions);
        }
    }

    public function testFactoryFailureReleasesExactlyOnceAndRethrowsTheOriginalThrowable(): void
    {
        $error = new \RuntimeException('fake native constructor failure');
        N09FactoryStore::$factory = static fn (): object => throw $error;
        self::assertSame($error, self::caught(static fn () => N09FactoryStore::fromConnString('mongodb://fake-only', 'db')));
        self::assertSame(1, N09FactoryStore::$lease->releaseCalls);
        self::assertCount(1, N09FactoryStore::$factoryCalls);
        $this->assertReleased();
    }

    public function testIndexStartFailureReleasesExactlyOnceAndRethrowsTheOriginalThrowable(): void
    {
        $error = new \RuntimeException('fake index failure');
        N09FactoryStore::$factory = static function () use ($error): object {
            $native = new N09StoreNativeClient();
            $native->selectDatabase('db')->selectCollection('store')->failures['createIndex'] = $error;

            return $native;
        };
        self::assertSame($error, self::caught(static fn () => N09FactoryStore::fromConnString('mongodb://fake-only', 'db')));
        self::assertSame(1, N09FactoryStore::$lease->releaseCalls);
        self::assertCount(1, N09FactoryStore::$factoryCalls);
        $this->assertReleased();
    }

    public function testPostAcquisitionConstructorFailureIsAlsoCoveredByCleanup(): void
    {
        $error = new \RuntimeException('fake metadata failure in store constructor');
        N09FactoryStore::$metadataError = $error;
        self::assertSame($error, self::caught(static fn () => N09FactoryStore::fromConnString('mongodb://fake-only', 'db')));
        self::assertSame(1, N09FactoryStore::$lease->releaseCalls);
        self::assertSame([], N09FactoryStore::$factoryCalls);
        $this->assertReleased();
    }

    public function testEmptyDatabaseNameFailsAfterAcquisitionWithOneReleaseAndNoNativeCreation(): void
    {
        $error = self::caught(static fn () => N09FactoryStore::fromConnString('mongodb://fake-only', ''));
        self::assertInstanceOf(\InvalidArgumentException::class, $error);
        self::assertSame('MongoDBStore::fromConnString() needs a dbName.', $error->getMessage());
        self::assertSame(1, N09FactoryStore::$lease->releaseCalls);
        self::assertSame([], N09FactoryStore::$factoryCalls);
        $this->assertReleased();
    }

    public function testThrowingNativeDestructorCannotMaskOriginalStartFailureOrLeaveLeaseUsable(): void
    {
        $error = new \RuntimeException('original index error');
        $cleanup = new \RuntimeException('fake native destructor failure');
        N09FactoryStore::$factory = static function () use ($error, $cleanup): object {
            $native = new N09StoreNativeClient();
            $native->destructError = $cleanup;
            $native->selectDatabase('db')->selectCollection('store')->failures['createIndex'] = $error;

            return $native;
        };
        $caught = self::caught(static fn () => N09FactoryStore::fromConnString('mongodb://fake-only', 'db'));
        self::assertSame($error, $caught);
        self::assertNull($caught->getPrevious());
        self::assertSame(1, N09StoreNativeClient::$destructThrows);
        self::assertSame(1, N09FactoryStore::$lease->releaseCalls);
        self::assertCount(1, N09FactoryStore::$factoryCalls);
        $this->assertReleased();
    }

    public function testStopTwiceIsIdempotentAndPostStopOperationsNeverCreateAnotherNativeClient(): void
    {
        $store = N09FactoryStore::fromConnString('mongodb://fake-only', 'db');
        $store->stop();
        $store->stop();
        self::assertSame(1, N09StoreNativeClient::$destructCalls);
        self::assertCount(1, N09FactoryStore::$factoryCalls);
        self::assertInstanceOf(\LogicException::class, self::caught(static fn () => $store->get(['ns'], 'key')));
        self::assertCount(1, N09FactoryStore::$factoryCalls);
        N09FactoryStore::$lease->appendMetadata(['name' => 'after-release']);
        $this->assertReleased();
    }

    public function testNativeBecomesUnreachableFromPhpWhileStoreRemainsAliveNotSocketClosure(): void
    {
        $weak = null;
        N09FactoryStore::$factory = static function () use (&$weak): object {
            $native = new N09StoreNativeClient();
            $weak = \WeakReference::create($native);

            return $native;
        };
        $store = N09FactoryStore::fromConnString('mongodb://fake-only', 'db');
        self::assertNotNull($weak->get());
        $store->stop();
        gc_collect_cycles();
        self::assertNull($weak->get());
        self::assertInstanceOf(MongoDBStore::class, $store);
        // Only PHP reachability proved: no native Manager/socket was opened or checked here.
    }

    public function testBorrowedStopPreservesIdentityAndDoesNotChangeOptionsOrMetadata(): void
    {
        $client = MongoDBStoreDriverAdapterTest::fixture();
        $native = $client->handle;
        $weak = \WeakReference::create($native);
        $store = new MongoDBStore($client, 'db');
        $store->start();
        $store->stop();
        $store->stop();
        $store->put(['ns'], 'key', ['value' => 1]);
        self::assertSame(['value' => 1], $store->get(['ns'], 'key')->value);
        self::assertSame($native, $client->handle);
        self::assertSame($native, $weak->get());
        self::assertSame([], N09FactoryStore::$factoryCalls);
    }

    private function assertReleased(): void
    {
        self::assertInstanceOf(\LogicException::class, self::caught(static fn () => N09FactoryStore::$lease->db('db')->collection('store')));
    }

    private static function caught(callable $operation): ?\Throwable
    {
        try {
            $operation();
        } catch (\Throwable $error) {
            return $error;
        }

        return null;
    }
}

/** All counters and injected failures are test-only. Production release() executes unchanged. */
final class N09StoreOwnedLease extends MongoDBOwnedStoreClient
{
    public int $releaseCalls = 0;
    public ?\Throwable $metadataError = null;

    protected function wrapDatabase(object $database): MongoDatabaseInterface
    {
        return new N09StoreDatabaseAdapter($database);
    }

    public function appendMetadata(array $metadata): void
    {
        if ($this->metadataError !== null) {
            throw $this->metadataError;
        }
        parent::appendMetadata($metadata);
    }

    public function release(): void
    {
        ++$this->releaseCalls;
        parent::release();
    }
}

/** Factory seam only; fromConnString constructs the production base MongoDBStore, not this class. */
final class N09FactoryStore extends MongoDBStore
{
    public static ?N09StoreOwnedLease $lease = null;
    public static array $factoryCalls = [];
    public static ?\Closure $factory = null;
    public static ?\Throwable $metadataError = null;
    public static int $acquisitions = 0;

    protected static function ownedClient(string $connString, array $uriOptions, array $driverOptions): MongoDBOwnedStoreClient
    {
        ++self::$acquisitions;
        self::$lease = new N09StoreOwnedLease(static function (array $options) use ($connString, $uriOptions): object {
            self::$factoryCalls[] = [$connString, $uriOptions, $options];

            return (self::$factory)($options);
        }, $driverOptions);
        self::$lease->metadataError = self::$metadataError;

        return self::$lease;
    }

    public static function reset(): void
    {
        self::$lease = null;
        self::$factoryCalls = [];
        self::$metadataError = null;
        self::$acquisitions = 0;
        self::$factory = static fn (): object => new N09StoreNativeClient();
    }
}
