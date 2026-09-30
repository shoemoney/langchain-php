<?php

declare(strict_types=1);

namespace LangChain\Tests\Integration;

use LangChain\Tools\DynamicStructuredTool;
use LangChain\Tools\Schema;
use LangChain\Tools\ToolException;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\SqliteSaver;
use LangChain\Runnables\RunnableConfig;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\TestCase;

/**
 * A real graph, a real SQLite file, and the RAW bytes on disk.
 *
 * The premise of this file: forty-odd iterations of model-driven review
 * produced zero findings of the class that actually cost a release, and both
 * were found only by installing the package into a clean external project and
 * calling the public API. Neither is visible to a unit test:
 *
 *   1. A tool schema built the natural way —
 *      `Schema::object(['a' => Schema::string()])` — validated NOTHING and
 *      emitted `{"a":{"schema":{"type":"string"}}}` to the model. Every unit
 *      test built properties as plain arrays, so the suite described a shape
 *      the library's own factories do not produce.
 *   2. A checkpoint's maps serialise as `{}` on the wire but read back as `[]`.
 *      An in-process map round-trips identically either way, so only a real
 *      store shows it.
 *
 * So this suite asserts on the two things a fake cannot fake: the JSON that
 * actually goes to a provider, and the bytes that actually land in a database.
 */
final class GraphAndCheckpointIntegrationTest extends TestCase
{
    private string $dbFile = '';

    protected function setUp(): void
    {
        $this->dbFile = (string) tempnam(sys_get_temp_dir(), 'lgphp-it-') . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /**
     * The wire format is the contract with the model, so assert the JSON —
     * not that some array round-tripped.
     */
    public function testANestedSchemaDeclaresArgumentTypesToTheModel(): void
    {
        $schema = Schema::object(
            ['query' => Schema::string(), 'limit' => ['type' => 'integer']],
            ['query'],
        );

        $wire = $schema->toJsonSchema();

        self::assertSame(
            [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string'], 'limit' => ['type' => 'integer']],
                'required' => ['query'],
            ],
            $wire,
        );

        // And the string is what a provider would receive on the socket.
        $encoded = json_encode($wire, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"query":{"type":"string"}', $encoded);
        self::assertStringNotContainsString('"schema":', $encoded, 'a nested Schema leaked its property wrapper');
    }

    public function testAToolRejectsInputItsSchemaForbids(): void
    {
        $schema = Schema::object(['query' => Schema::string()], ['query']);
        $tool = new DynamicStructuredTool(
            ['name' => 'search', 'description' => 'search', 'schema' => $schema],
            static fn (array $in): string => 'results for ' . $in['query'],
        );

        self::assertSame('results for php', $tool->invoke(['query' => 'php']));

        foreach ([['query' => 1], ['query' => null], ['query' => []], []] as $bad) {
            try {
                $tool->invoke($bad);
                self::fail('must reject ' . json_encode($bad) . ' — the schema says query is a string');
            } catch (ToolException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * A real file on disk, read back with a raw SELECT rather than through the
     * saver, because the saver is the thing under test.
     */
    public function testCheckpointMapsSerialiseAsObjectsOnTheWire(): void
    {
        $saver = SqliteSaver::fromConnString($this->dbFile);

        $checkpoint = new Checkpoint(
            v: 1,
            id: CheckpointId::uuid6(0),
            ts: '2024-04-19T17:19:07.952Z',
            channelValues: ['messages' => ['hello']],
            channelVersions: ['messages' => 3],
            versionsSeen: ['messages' => ['msg-1' => 1]],
        );

        $saver->put(['thread_id' => 't1'], $checkpoint, ['source' => 'update', 'step' => -1, 'parents' => []]);

        // Read the row as raw text — no decoder, no saver, no round-trip.
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $row = $pdo->query('SELECT checkpoint FROM checkpoints LIMIT 1')->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        $raw = (string) $row['checkpoint'];

        // The JS reader treats these as objects. Emitting [] makes a
        // checkpoint written here unreadable by a JS process and vice versa.
        foreach (['channel_versions', 'versions_seen'] as $key) {
            self::assertMatchesRegularExpression(
                '/"' . $key . '":\{/',
                $raw,
                $key . ' must serialise as a JSON object, not an array',
            );
        }
        self::assertStringNotContainsString('"channel_versions":[]', $raw);
        self::assertStringNotContainsString('"versions_seen":[]', $raw);
    }

    /**
     * The empty-map case, which is the ONLY case the `(object)` cast changes.
     *
     * A non-empty string-keyed PHP array already encodes as a JSON object, so
     * asserting on one proves nothing — the first version of this test did
     * exactly that and a mutation that deleted the cast from `channel_versions`
     * passed straight through it. `[]` encodes as `[]`; only the cast makes it
     * `{}`. This is the shape a first-ever checkpoint has, before any channel
     * has a version.
     */
    public function testAnEmptyCheckpointStillWritesMapsAsObjects(): void
    {
        $saver = SqliteSaver::fromConnString($this->dbFile);

        $checkpoint = new Checkpoint(
            v: 1,
            id: CheckpointId::uuid6(0),
            ts: '2024-04-19T17:19:07.952Z',
            channelValues: [],
            channelVersions: [],
            versionsSeen: [],
        );

        $saver->put(['thread_id' => 'empty'], $checkpoint, ['source' => 'update', 'step' => -1, 'parents' => []]);

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $raw = (string) $pdo->query('SELECT checkpoint FROM checkpoints LIMIT 1')->fetch(\PDO::FETCH_ASSOC)['checkpoint'];

        self::assertMatchesRegularExpression('/"channel_versions":\{/', $raw, 'an empty map must still be {}');
        self::assertMatchesRegularExpression('/"versions_seen":\{/', $raw, 'an empty map must still be {}');
        self::assertStringNotContainsString('"channel_versions":[]', $raw);
        self::assertStringNotContainsString('"versions_seen":[]', $raw);
    }

    public function testAStateGraphRunsAndCheckpointsThroughRealSqlite(): void
    {
        $saver = SqliteSaver::fromConnString($this->dbFile);

        $schema = Annotation::root([
            'messages' => Annotation::withReducer(
                static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
                static fn (): array => [],
            ),
            'n' => Annotation::withReducer(
                static fn ($a, $b) => $b,
                static fn (): int => 0,
            ),
        ]);

        $graph = (new StateGraph($schema))
            ->addNode('double', static fn (array $state): array => [
                'messages' => array_merge(
                    $state['messages'] ?? [],
                    [str_repeat('x', 2 * (int) ($state['n'] ?? 1))],
                ),
            ])
            ->addEdge('__start__', 'double')
            ->compile(['checkpointer' => $saver]);

        $config = RunnableConfig::fromArray([
            'configurable' => ['thread_id' => 'run-1'],
            'recursion_limit' => 5,
        ]);

        $result = $graph->invoke(['n' => 3, 'messages' => []], $config);

        self::assertSame('xxxxxx', $result['messages'][0] ?? null);

        // The run really was persisted: a second, independent handle to the
        // same file sees the checkpoint.
        $reader = SqliteSaver::fromConnString($this->dbFile);
        $tuples = $reader->list(['thread_id' => 'run-1']);
        self::assertNotEmpty($tuples, 'a completed run must leave a checkpoint behind');

        $tuple = $reader->getTuple(['thread_id' => 'run-1']);
        self::assertNotNull($tuple);
    }
}
