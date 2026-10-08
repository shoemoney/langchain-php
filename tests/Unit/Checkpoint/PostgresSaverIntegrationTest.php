<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointConstants;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\Postgres\Migrations;
use LangGraph\Checkpoint\Postgres\PostgresSaver;
use LangGraph\Checkpoint\Postgres\Sql;
use LangGraph\Pregel\Constants;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tests/checkpoints.int.test.ts` from `@langchain/langgraph-checkpoint-postgres`,
 * plus a real graph run through the saver.
 *
 * Env-gated on `LANGGRAPH_PG_DSN` (see {@see PostgresTestConnection}); skipped
 * when no server is reachable.
 */
#[CoversClass(PostgresSaver::class)]
#[CoversClass(Sql::class)]
#[CoversClass(Migrations::class)]
final class PostgresSaverIntegrationTest extends TestCase
{
    private PostgresSaver $saver;

    protected function setUp(): void
    {
        $saver = PostgresTestConnection::saver();
        if ($saver === null) {
            self::markTestSkipped('No Postgres reachable: set LANGGRAPH_PG_DSN to run the Postgres integration tests.');
        }
        $this->saver = $saver;
    }

    protected function tearDown(): void
    {
        PostgresTestConnection::dropAll();
    }

    public function testShouldProperlyInitializeAndSetupTheDatabase(): void
    {
        $schema = PostgresTestConnection::schemaOf($this->saver);
        $db = $this->saver->db();

        $found = $db->prepare('SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?');
        $found->execute([$schema]);
        self::assertCount(1, $found->fetchAll());

        $tables = $db->prepare(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = ? "
            . "AND table_name IN ('checkpoints', 'checkpoint_blobs', 'checkpoint_writes', 'checkpoint_migrations')"
        );
        $tables->execute([$schema]);
        self::assertCount(4, $tables->fetchAll());

        $columns = static function (\PDO $db, string $schema, string $table): array {
            $statement = $db->prepare(
                'SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns '
                . 'WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position'
            );
            $statement->execute([$schema, $table]);

            return array_map(
                static fn (array $c): array => [$c['column_name'], $c['data_type'], $c['is_nullable'], $c['column_default']],
                $statement->fetchAll(),
            );
        };

        self::assertSame([
            ['thread_id', 'text', 'NO', null],
            ['checkpoint_ns', 'text', 'NO', "''::text"],
            ['checkpoint_id', 'text', 'NO', null],
            ['parent_checkpoint_id', 'text', 'YES', null],
            ['type', 'text', 'YES', null],
            ['checkpoint', 'jsonb', 'NO', null],
            ['metadata', 'jsonb', 'NO', "'{}'::jsonb"],
        ], $columns($db, $schema, 'checkpoints'));

        self::assertSame([
            ['thread_id', 'text', 'NO', null],
            ['checkpoint_ns', 'text', 'NO', "''::text"],
            ['channel', 'text', 'NO', null],
            ['version', 'text', 'NO', null],
            ['type', 'text', 'NO', null],
            ['blob', 'bytea', 'YES', null],
        ], $columns($db, $schema, 'checkpoint_blobs'));

        self::assertSame([
            ['thread_id', 'text', 'NO', null],
            ['checkpoint_ns', 'text', 'NO', "''::text"],
            ['checkpoint_id', 'text', 'NO', null],
            ['task_id', 'text', 'NO', null],
            ['idx', 'integer', 'NO', null],
            ['channel', 'text', 'NO', null],
            ['type', 'text', 'YES', null],
            ['blob', 'bytea', 'NO', null],
        ], $columns($db, $schema, 'checkpoint_writes'));

        $count = $db->query('SELECT COUNT(*) AS count FROM "' . $schema . '".checkpoint_migrations')->fetch();
        self::assertSame(count(Migrations::getMigrations($schema)), (int) $count['count']);
    }

    public function testSetupIsIdempotent(): void
    {
        $this->saver->setup();
        $this->saver->setup();

        $schema = PostgresTestConnection::schemaOf($this->saver);
        $count = $this->saver->db()->query('SELECT COUNT(*) AS c FROM "' . $schema . '".checkpoint_migrations')->fetch();
        self::assertSame(count(Migrations::getMigrations($schema)), (int) $count['c']);
    }

    public function testShouldSaveAndRetrieveCheckpointsCorrectly(): void
    {
        self::assertNull($this->saver->getTuple(['configurable' => ['thread_id' => '1']]));

        $checkpoint1 = new Checkpoint(
            v: 1,
            id: CheckpointId::uuid6(0),
            ts: '2024-04-19T17:19:07.952Z',
            channelValues: ['someKey1' => 'someValue1'],
            channelVersions: ['someKey1' => 1, 'someKey2' => 1],
            versionsSeen: ['someKey3' => ['someKey1' => 1]],
        );
        $checkpoint2 = new Checkpoint(
            v: 1,
            id: CheckpointId::uuid6(1),
            ts: '2024-04-20T17:19:07.952Z',
            channelValues: ['someKey1' => 'someValue2'],
            channelVersions: ['someKey1' => 2],
            versionsSeen: ['someKey3' => ['someKey1' => 2]],
        );

        $config = $this->saver->put(
            ['configurable' => ['thread_id' => '1']],
            $checkpoint1,
            ['source' => 'update', 'step' => -1, 'parents' => []],
            $checkpoint1->channelVersions,
        );
        self::assertSame(
            ['configurable' => ['thread_id' => '1', 'checkpoint_ns' => '', 'checkpoint_id' => $checkpoint1->id]],
            $config,
        );

        $this->saver->putWrites(
            ['configurable' => ['checkpoint_id' => $checkpoint1->id, 'checkpoint_ns' => '', 'thread_id' => '1']],
            [['bar', 'baz']],
            'foo',
        );

        $first = $this->saver->getTuple(['configurable' => ['thread_id' => '1']]);
        self::assertNotNull($first);
        self::assertSame($checkpoint1->id, $first->config['configurable']['checkpoint_id']);
        self::assertEquals($checkpoint1->toArray(), $first->checkpoint->toArray());
        self::assertEquals(['source' => 'update', 'step' => -1, 'parents' => []], $first->metadata);
        self::assertNull($first->parentConfig);
        self::assertSame([['foo', 'bar', 'baz']], $first->pendingWrites);

        $this->saver->put(
            ['configurable' => ['thread_id' => '1', 'checkpoint_id' => '2024-04-18T17:19:07.952Z']],
            $checkpoint2,
            ['source' => 'update', 'step' => -1, 'parents' => []],
            $checkpoint2->channelVersions,
        );

        $second = $this->saver->getTuple(['configurable' => ['thread_id' => '1']]);
        self::assertNotNull($second);
        self::assertSame($checkpoint2->id, $second->checkpoint->id);
        self::assertSame(
            ['configurable' => ['thread_id' => '1', 'checkpoint_ns' => '', 'checkpoint_id' => '2024-04-18T17:19:07.952Z']],
            $second->parentConfig,
        );

        $tuples = $this->saver->list(['configurable' => ['thread_id' => '1']]);
        self::assertCount(2, $tuples);
        self::assertSame('2024-04-20T17:19:07.952Z', $tuples[0]->checkpoint->ts);
        self::assertSame('2024-04-19T17:19:07.952Z', $tuples[1]->checkpoint->ts);
    }

    public function testShouldDeleteThread(): void
    {
        $thread1 = ['configurable' => ['thread_id' => '1', 'checkpoint_ns' => '']];
        $thread2 = ['configurable' => ['thread_id' => '2', 'checkpoint_ns' => '']];
        $meta = ['source' => 'update', 'step' => -1, 'parents' => []];

        $this->saver->put($thread1, Checkpoint::empty(), $meta, []);
        $this->saver->put($thread2, Checkpoint::empty(), $meta, []);
        self::assertNotNull($this->saver->getTuple($thread1));

        $this->saver->deleteThread('1');

        self::assertNull($this->saver->getTuple($thread1));
        self::assertNotNull($this->saver->getTuple($thread2));
    }

    public function testPendingSendsMigration(): void
    {
        $config = ['configurable' => ['thread_id' => 'thread-1', 'checkpoint_ns' => '']];

        $checkpoint0 = new Checkpoint(v: 1, id: CheckpointId::uuid6(0), ts: '2024-04-19T17:19:07.952Z');
        $config = $this->saver->put($config, $checkpoint0, ['source' => 'loop', 'parents' => [], 'step' => 0], []);

        $this->saver->putWrites($config, [[CheckpointConstants::TASKS, 'send-1'], [CheckpointConstants::TASKS, 'send-2']], 'task-1');
        $this->saver->putWrites($config, [[CheckpointConstants::TASKS, 'send-3']], 'task-2');

        // The sends belong to the NEXT checkpoint, not the one they were written against.
        $tuple0 = $this->saver->getTuple($config);
        self::assertNotNull($tuple0);
        self::assertSame([], $tuple0->checkpoint->channelValues);
        self::assertSame([], $tuple0->checkpoint->channelVersions);

        $checkpoint1 = new Checkpoint(v: 1, id: CheckpointId::uuid6(1), ts: '2024-04-20T17:19:07.952Z');
        $config = $this->saver->put($config, $checkpoint1, ['source' => 'loop', 'parents' => [], 'step' => 1], []);

        $tuple1 = $this->saver->getTuple($config);
        self::assertNotNull($tuple1);
        self::assertSame([CheckpointConstants::TASKS => ['send-1', 'send-2', 'send-3']], $tuple1->checkpoint->channelValues);
        self::assertArrayHasKey(CheckpointConstants::TASKS, $tuple1->checkpoint->channelVersions);

        $tuples = $this->saver->list(['configurable' => ['thread_id' => 'thread-1']]);
        self::assertCount(2, $tuples);
        self::assertSame([CheckpointConstants::TASKS => ['send-1', 'send-2', 'send-3']], $tuples[0]->checkpoint->channelValues);
        self::assertArrayHasKey(CheckpointConstants::TASKS, $tuples[0]->checkpoint->channelVersions);
    }

    public function testShouldJsonEncodeWhenReferencedFromARunnableConfigurable(): void
    {
        $json = json_encode(['configurable' => ['__pregel_checkpointer' => $this->saver]], JSON_THROW_ON_ERROR);

        self::assertStringContainsString('[LangGraph\\\\Checkpoint\\\\Postgres\\\\PostgresSaver]', $json);
    }

    public function testShouldDefaultMissingVersionsSeenWhenLoadingACheckpoint(): void
    {
        $schema = PostgresTestConnection::schemaOf($this->saver);
        $checkpointId = CheckpointId::uuid6(3);

        $this->saver->db()->prepare(
            'INSERT INTO "' . $schema . '".checkpoints (thread_id, checkpoint_ns, checkpoint_id, parent_checkpoint_id, checkpoint, metadata) '
            . 'VALUES (?, ?, ?, ?, ?::jsonb, ?::jsonb)'
        )->execute([
            'missing-versions-seen',
            '',
            $checkpointId,
            null,
            json_encode(['v' => 4, 'id' => $checkpointId, 'ts' => gmdate('c'), 'channel_versions' => ['messages' => 1]]),
            json_encode(['source' => 'input', 'step' => 0, 'parents' => new \stdClass()]),
        ]);

        $tuple = $this->saver->getTuple(['configurable' => ['thread_id' => 'missing-versions-seen']]);

        self::assertNotNull($tuple);
        self::assertSame([], $tuple->checkpoint->versionsSeen);
        self::assertSame(['messages' => 1], $tuple->checkpoint->channelVersions);
    }

    public function testNewestFirstOrderingIsLexicographicOnTheCheckpointId(): void
    {
        $ids = [CheckpointId::uuid6(0), CheckpointId::uuid6(1), CheckpointId::uuid6(2)];
        foreach ([$ids[1], $ids[2], $ids[0]] as $id) {
            $this->saver->put(
                ['configurable' => ['thread_id' => 'order']],
                new Checkpoint(v: 4, id: $id, ts: gmdate('c')),
                [],
                [],
            );
        }

        $listed = array_map(
            static fn ($t): string => $t->checkpoint->id,
            $this->saver->list(['configurable' => ['thread_id' => 'order']]),
        );
        $expected = $ids;
        rsort($expected, SORT_STRING);

        self::assertSame($expected, $listed);
        self::assertSame($expected[0], $this->saver->getTuple(['configurable' => ['thread_id' => 'order']])?->checkpoint->id);
    }

    public function testAGraphRunIsPersistedAndResumableThroughPostgres(): void
    {
        $runs = [];
        $builder = new StateGraph(['value' => 'int', 'log' => 'list']);
        $builder->addNode('double', static function (array $state) use (&$runs): array {
            $runs[] = 'double';

            return ['value' => $state['value'] * 2];
        });
        $builder->addNode('increment', static function (array $state) use (&$runs): array {
            $runs[] = 'increment';

            return ['value' => $state['value'] + 1, 'log' => ['incremented']];
        });
        $builder->addEdge(Constants::START, 'double');
        $builder->addEdge('double', 'increment');
        $builder->addEdge('increment', Constants::END);
        $graph = $builder->compile(['checkpointer' => $this->saver]);

        $config = new RunnableConfig();
        $config->configurable = ['thread_id' => 'pg-graph-1'];

        $result = $graph->invoke(['value' => 5], $config);
        self::assertSame(11, $result['value']);
        self::assertSame(['double', 'increment'], $runs);

        self::assertGreaterThanOrEqual(2, count($this->saver->list(['thread_id' => 'pg-graph-1'])));
        $snapshot = $graph->getState(['configurable' => ['thread_id' => 'pg-graph-1']]);
        self::assertSame(11, $snapshot->values['value']);
        self::assertSame([], $snapshot->next);

        // The same thread continues from the saved state instead of starting over.
        $runs = [];
        $resumed = $graph->invoke(['value' => 100], $config);
        self::assertSame(201, $resumed['value']);
        self::assertSame(['double', 'increment'], $runs);
    }

    public function testAnInterruptedGraphResumesFromThePostgresCheckpoint(): void
    {
        $runs = [];
        $builder = new StateGraph(['value' => 'int']);
        $builder->addNode('a', static function (array $s) use (&$runs): array {
            $runs[] = 'a';

            return ['value' => $s['value'] + 1];
        });
        $builder->addNode('b', static function (array $s) use (&$runs): array {
            $runs[] = 'b';

            return ['value' => $s['value'] + 10];
        });
        $builder->addEdge(Constants::START, 'a');
        $builder->addEdge('a', 'b');
        $builder->addEdge('b', Constants::END);
        $graph = $builder->compile(['checkpointer' => $this->saver, 'interruptBefore' => ['b']]);

        $config = new RunnableConfig();
        $config->configurable = ['thread_id' => 'pg-interrupt-1'];
        $graph->invoke(['value' => 0], $config);

        self::assertSame(['a'], $runs);
        $paused = $graph->getState(['configurable' => ['thread_id' => 'pg-interrupt-1']]);
        self::assertSame(1, $paused->values['value']);
        self::assertSame(['b'], $paused->next);

        $result = $graph->invoke(null, $config);
        self::assertSame(11, $result['value']);
        self::assertSame(['a', 'b'], $runs);
    }
}
