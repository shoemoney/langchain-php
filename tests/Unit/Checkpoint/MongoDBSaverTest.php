<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\MongoDB\MongoBinary;
use LangGraph\Checkpoint\MongoDB\MongoCollectionInterface;
use LangGraph\Checkpoint\MongoDB\MongoDBSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of the `MongoDBSaver` describe of `tests/checkpoints.test.ts` from
 * `@langchain/langgraph-checkpoint-mongodb`.
 *
 * Upstream drives the saver with `vi.fn()` mocks of the driver; these use
 * {@see FakeMongoCollection} and assert on its recorded calls instead.
 */
#[CoversClass(MongoDBSaver::class)]
#[CoversClass(MongoBinary::class)]
final class MongoDBSaverTest extends TestCase
{
    private MongoDBFakeClient $client;

    protected function setUp(): void
    {
        $this->client = new MongoDBFakeClient();
    }

    private function saver(mixed ...$args): MongoDBSaver
    {
        return new MongoDBSaver($this->client, ...$args);
    }

    public function testShouldSetClientMetadata(): void
    {
        $this->saver();

        self::assertSame([['name' => 'langgraphjs_checkpoint_saver']], $this->client->metadata);
    }

    public function testSelectsTheNamedDatabaseAndCollections(): void
    {
        $saver = $this->saver(dbName: 'mydb', checkpointCollectionName: 'cps', checkpointWritesCollectionName: 'cws');
        $saver->setup();

        self::assertSame(['mydb'], $this->client->dbNames);
        self::assertSame('cps', $saver->checkpointCollectionName);
        self::assertSame('cws', $saver->checkpointWritesCollectionName);
        self::assertNotNull($this->client->database->collections['cps'] ?? null);
        self::assertNotNull($this->client->database->collections['cws'] ?? null);
    }

    // ---- TTL support ------------------------------------------------------

    public function testShouldEnableTimestampsImplicitlyWhenTtlIsProvided(): void
    {
        $saver = $this->saver(ttl: 3600);

        self::assertSame(['$currentDate' => ['upserted_at' => true]], self::timestampOp($saver));
    }

    public function testShouldNotEnableTimestampsWithoutTtlOrFlag(): void
    {
        self::assertSame([], self::timestampOp($this->saver()));
    }

    public function testShouldReturnCurrentDateOperatorWhenEnableTimestampsIsTrue(): void
    {
        self::assertSame(
            ['$currentDate' => ['upserted_at' => true]],
            self::timestampOp($this->saver(enableTimestamps: true)),
        );
    }

    /** @return array<string, mixed> */
    private static function timestampOp(MongoDBSaver $saver): array
    {
        return (new \ReflectionMethod($saver, 'timestampOp'))->invoke($saver);
    }

    // ---- setup ------------------------------------------------------------

    public function testSetupShouldCreateCompoundIndexesOnBothCollections(): void
    {
        $errors = $this->saver()->setup();

        self::assertSame([], $errors);
        self::assertSame(
            [[
                'method' => 'createIndex',
                'args' => [
                    'keys' => ['thread_id' => 1, 'checkpoint_ns' => 1, 'checkpoint_id' => -1],
                    'options' => ['name' => 'thread_ns_checkpoint_idx'],
                ],
            ]],
            $this->client->checkpoints()->calls,
        );
        self::assertSame(
            [[
                'method' => 'createIndex',
                'args' => [
                    'keys' => ['thread_id' => 1, 'checkpoint_ns' => 1, 'checkpoint_id' => 1, 'task_id' => 1, 'idx' => 1],
                    'options' => ['name' => 'thread_ns_checkpoint_task_idx'],
                ],
            ]],
            $this->client->writes()->calls,
        );
    }

    public function testSetupShouldCreateTtlIndexesInAdditionToCompoundIndexesWhenTtlIsConfigured(): void
    {
        $this->saver(ttl: 3600)->setup();

        // 2 compound indexes + 2 TTL indexes.
        self::assertCount(2, $this->client->checkpoints()->callsTo('createIndex'));
        self::assertCount(2, $this->client->writes()->callsTo('createIndex'));
        foreach ([$this->client->checkpoints(), $this->client->writes()] as $collection) {
            self::assertSame(
                ['keys' => ['upserted_at' => 1], 'options' => ['expireAfterSeconds' => 3600]],
                $collection->callsTo('createIndex')[1]['args'],
            );
        }
    }

    public function testSetupShouldNotCreateTtlIndexesWhenTtlIsNotConfigured(): void
    {
        $this->saver()->setup();

        $all = [...$this->client->checkpoints()->callsTo('createIndex'), ...$this->client->writes()->callsTo('createIndex')];
        self::assertCount(2, $all);
        foreach ($all as $call) {
            self::assertArrayNotHasKey('upserted_at', $call['args']['keys']);
        }
    }

    public function testSetupShouldReturnEmptyArrayOnSuccess(): void
    {
        self::assertSame([], $this->saver(ttl: 3600)->setup());
    }

    public function testSetupShouldReturnErrorsForCallerToHandle(): void
    {
        $failing = new class () implements MongoCollectionInterface {
            public function find(array $filter = [], array $sort = [], ?int $limit = null): array
            {
                return [];
            }

            public function findOne(array $filter, array $sort = []): ?array
            {
                return null;
            }

            public function updateOne(array $filter, array $update, array $options = []): void
            {
            }

            public function insertMany(array $documents): void
            {
            }

            public function deleteMany(array $filter): int
            {
                return 0;
            }

            public function createIndex(array $keys, array $options = []): string
            {
                throw new \RuntimeException('Index creation failed');
            }
        };
        $database = new class ($failing) implements \LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface {
            public function __construct(private readonly MongoCollectionInterface $collection)
            {
            }

            public function collection(string $name): MongoCollectionInterface
            {
                return $this->collection;
            }
        };
        $client = new class ($database) implements \LangGraph\Checkpoint\MongoDB\MongoClientInterface {
            public function __construct(private readonly \LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface $database)
            {
            }

            public function appendMetadata(array $metadata): void
            {
            }

            public function db(?string $name = null): \LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface
            {
                return $this->database;
            }
        };

        $errors = (new MongoDBSaver($client, ttl: 3600))->setup();

        // 2 compound + 2 TTL index creations all fail, and none stops the others.
        self::assertCount(4, $errors);
        self::assertSame('Index creation failed', $errors[0]->getMessage());
    }

    // ---- filter validation ------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function injectionFilters(): array
    {
        return [
            'operator object' => [['source' => ['$regex' => '.*']], 'source'],
            'nested object' => [['metadata' => ['nested' => 'value']], 'metadata'],
        ];
    }

    /**
     * @param array<string, mixed> $filter
     */
    #[DataProvider('injectionFilters')]
    public function testShouldRejectNonPrimitiveFilterValuesToPreventOperatorInjection(array $filter, string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Invalid filter value for key \"{$key}\": filter values must be primitives",
        );

        $this->saver()->list(['configurable' => ['thread_id' => 'test-thread']], new CheckpointListOptions(filter: $filter));
    }

    public function testShouldRejectObjectFilterValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->saver()->list(
            ['configurable' => ['thread_id' => 'test-thread']],
            new CheckpointListOptions(filter: ['source' => new \stdClass()]),
        );
    }

    public function testShouldAllowPrimitiveFilterValues(): void
    {
        $result = $this->saver()->list(
            ['configurable' => ['thread_id' => 'test-thread']],
            new CheckpointListOptions(filter: ['source' => 'input', 'step' => 1, 'active' => true, 'optional' => null]),
        );

        // Nothing stored, so empty — and, crucially, no exception.
        self::assertSame([], $result);
    }

    // ---- metadata filtering -----------------------------------------------

    public function testShouldStoreMetadataSearchAsPlainJsonInPut(): void
    {
        $saver = $this->saver();
        $checkpoint = new Checkpoint(v: 4, id: 'cp-1', ts: '2024-04-19T17:19:07.952Z');
        $metadata = ['source' => 'input', 'step' => 1, 'parents' => []];

        $saver->put(['configurable' => ['thread_id' => 'test-thread']], $checkpoint, $metadata);

        $calls = $this->client->checkpoints()->callsTo('updateOne');
        self::assertCount(1, $calls);
        self::assertSame(
            ['thread_id' => 'test-thread', 'checkpoint_ns' => '', 'checkpoint_id' => 'cp-1'],
            $calls[0]['args']['filter'],
        );
        self::assertSame(['upsert' => true], $calls[0]['args']['options']);

        $set = $calls[0]['args']['update']['$set'];
        self::assertSame($metadata, $set['metadata_search']);
        self::assertInstanceOf(MongoBinary::class, $set['metadata']);
        self::assertNotSame($metadata, $set['metadata']);
    }

    public function testShouldQueryMetadataSearchFieldsInList(): void
    {
        $filter = ['source' => 'input', 'step' => 1, 'active' => true, 'optional' => null];

        $this->saver()->list(
            ['configurable' => ['thread_id' => 'test-thread']],
            new CheckpointListOptions(filter: $filter),
        );

        $calls = $this->client->checkpoints()->callsTo('find');
        self::assertSame(
            [
                'thread_id' => 'test-thread',
                'metadata_search.source' => 'input',
                'metadata_search.step' => 1,
                'metadata_search.active' => true,
                'metadata_search.optional' => null,
            ],
            $calls[0]['args']['filter'],
        );
        self::assertSame(['checkpoint_id' => -1], $calls[0]['args']['sort']);
    }

    // ---- list pendingWrites -----------------------------------------------

    public function testShouldIncludePendingWritesInListResults(): void
    {
        $this->seedCheckpoint('cp-1');
        $this->client->writes()->documents[] = [
            'thread_id' => 'test-thread',
            'checkpoint_ns' => '',
            'checkpoint_id' => 'cp-1',
            'task_id' => 'task-1',
            'idx' => 0,
            'channel' => 'bar',
            'type' => 'json',
            'value' => new MongoBinary('"baz"'),
        ];

        $tuples = $this->saver()->list(['configurable' => ['thread_id' => 'test-thread']]);

        self::assertCount(1, $tuples);
        self::assertSame([['task-1', 'bar', 'baz']], $tuples[0]->pendingWrites);
        $writeFinds = $this->client->writes()->callsTo('find');
        self::assertSame(
            ['thread_id' => 'test-thread', 'checkpoint_ns' => '', 'checkpoint_id' => 'cp-1'],
            $writeFinds[0]['args']['filter'],
        );
    }

    public function testShouldReturnEmptyPendingWritesWhenNoneExist(): void
    {
        $this->seedCheckpoint('cp-1');

        $tuples = $this->saver()->list(['configurable' => ['thread_id' => 'test-thread']]);

        self::assertCount(1, $tuples);
        self::assertSame([], $tuples[0]->pendingWrites);
    }

    private function seedCheckpoint(string $id): void
    {
        $this->client->checkpoints()->documents[] = [
            'thread_id' => 'test-thread',
            'checkpoint_ns' => '',
            'checkpoint_id' => $id,
            'type' => 'json',
            'checkpoint' => new MongoBinary(json_encode([
                'v' => 4,
                'id' => $id,
                'ts' => '2024-04-19T17:19:07.952Z',
                'channel_values' => new \stdClass(),
                'channel_versions' => new \stdClass(),
                'versions_seen' => new \stdClass(),
            ], JSON_THROW_ON_ERROR)),
            'metadata' => new MongoBinary('{"source":"input","step":1,"parents":{}}'),
            'parent_checkpoint_id' => null,
        ];
    }

    // ---- configurable validation ------------------------------------------

    public function testShouldReturnNullWhenThreadIdIsMissingInGetTuple(): void
    {
        self::assertNull($this->saver()->getTuple(['configurable' => []]));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function nonStringGetTupleConfigs(): array
    {
        return [
            'thread_id' => [['thread_id' => ['$gt' => ''], 'checkpoint_ns' => ''], 'thread_id'],
            'checkpoint_ns' => [['thread_id' => 'safe-thread', 'checkpoint_ns' => ['$ne' => null]], 'checkpoint_ns'],
            'checkpoint_id' => [['thread_id' => 'safe-thread', 'checkpoint_ns' => '', 'checkpoint_id' => ['$gt' => '']], 'checkpoint_id'],
        ];
    }

    /**
     * @param array<string, mixed> $configurable
     */
    #[DataProvider('nonStringGetTupleConfigs')]
    public function testShouldRejectObjectValuesInGetTuple(array $configurable, string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid configurable.{$name}: expected a string");

        $this->saver()->getTuple(['configurable' => $configurable]);
    }

    public function testShouldRejectObjectThreadIdInList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configurable.thread_id: expected a string');

        $this->saver()->list(['configurable' => ['thread_id' => ['$gt' => '']]]);
    }

    public function testShouldRejectObjectCheckpointNsInList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configurable.checkpoint_ns: expected a string');

        $this->saver()->list(['configurable' => ['thread_id' => 'safe-thread', 'checkpoint_ns' => ['$ne' => null]]]);
    }

    public function testShouldRejectObjectBeforeCheckpointIdInList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configurable.checkpoint_id: expected a string');

        $this->saver()->list(
            ['configurable' => ['thread_id' => 'safe-thread', 'checkpoint_ns' => '']],
            new CheckpointListOptions(before: ['configurable' => ['checkpoint_id' => ['$lt' => 'zzz']]]),
        );
    }

    public function testShouldRejectNonStringThreadIdInPut(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configurable.thread_id: expected a string');

        $this->saver()->put(
            ['configurable' => ['thread_id' => ['$gt' => '']]],
            new Checkpoint(v: 4, id: 'cp-1', ts: '2024-01-01T00:00:00.000Z'),
            ['source' => 'input', 'step' => 1, 'parents' => []],
        );
    }

    public function testShouldPinSpecialChannelsToFixedNegativeIndicesAndSwitchToSetOnInsertForRegularWrites(): void
    {
        $saver = $this->saver();
        $config = ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c']];

        // A mix of regular writes and a special-channel write in one call. Regular writes must
        // NOT push the special channel onto a positive idx.
        $saver->putWrites($config, [['foo', 'v_foo'], ['bar', 'v_bar'], ['__interrupt__', 'paused']], 'task_A');

        $calls = $this->client->writes()->callsTo('updateOne');
        $indices = array_map(static fn (array $call): int => $call['args']['filter']['idx'], $calls);
        sort($indices);
        // foo (idx 0), bar (idx 1), __interrupt__ (idx -3 via WRITES_IDX_MAP)
        self::assertSame([-3, 0, 1], $indices);

        // A mixed batch goes through $setOnInsert, so a peer task's row at (task_A, idx=0)
        // can't be silently overwritten.
        foreach ($calls as $call) {
            self::assertArrayHasKey('$setOnInsert', $call['args']['update']);
            self::assertArrayNotHasKey('$set', $call['args']['update']);
        }

        // A call where every write is a special channel goes through $set, so e.g. an
        // INTERRUPT -> RESUME transition overwrites.
        $saver->putWrites($config, [['__resume__', 'carry_on']], 'task_A');
        $last = $this->client->writes()->callsTo('updateOne');
        $special = $last[count($last) - 1]['args'];
        self::assertSame(-4, $special['filter']['idx']);
        self::assertArrayHasKey('$set', $special['update']);
        self::assertArrayNotHasKey('$setOnInsert', $special['update']);
    }

    public function testShouldNoOpWithoutTouchingTheCollectionWhenWritesIsEmpty(): void
    {
        // Regression: an empty batch used to reach `bulkWrite([])`, which the driver rejects
        // with "Invalid BulkOperation, Batch cannot be empty" (human-in-the-loop flows).
        $config = ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c']];

        $returned = $this->saver()->putWrites($config, [], 'task_A');

        self::assertSame($config, $returned);
        self::assertSame([], $this->client->writes()->calls);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function nonStringPutWritesConfigs(): array
    {
        return [
            'thread_id' => [['thread_id' => ['$gt' => ''], 'checkpoint_ns' => '', 'checkpoint_id' => 'cp-1'], 'thread_id'],
            'checkpoint_ns' => [['thread_id' => 'safe-thread', 'checkpoint_ns' => ['$ne' => null], 'checkpoint_id' => 'cp-1'], 'checkpoint_ns'],
            'checkpoint_id' => [['thread_id' => 'safe-thread', 'checkpoint_ns' => '', 'checkpoint_id' => ['$gt' => '']], 'checkpoint_id'],
        ];
    }

    /**
     * @param array<string, mixed> $configurable
     */
    #[DataProvider('nonStringPutWritesConfigs')]
    public function testShouldRejectNonStringValuesInPutWrites(array $configurable, string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid configurable.{$name}: expected a string");

        $this->saver()->putWrites(['configurable' => $configurable], [['foo', 'bar']], 'task-1');
    }

    public function testShouldRejectNonStringThreadIdInDeleteThread(): void
    {
        $this->expectException(\TypeError::class);

        $this->saver()->deleteThread(...[[]]);
    }
}
