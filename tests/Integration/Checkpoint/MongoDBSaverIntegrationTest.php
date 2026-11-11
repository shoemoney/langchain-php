<?php

declare(strict_types=1);

namespace LangChain\Tests\Integration\Checkpoint;

use LangChain\Tests\Unit\Checkpoint\CheckpointerSpecCase;
use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\MongoDB\MongoDBSaver;
use LangGraph\Checkpoint\MongoDB\MongoDBDriverClient as ProductionMongoDBDriverClient;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The checkpointer contract, run against {@see MongoDBSaver} on a live MongoDB.
 *
 * Port of `tests/checkpoints.int.test.ts` from `@langchain/langgraph-checkpoint-mongodb`,
 * which runs the shared validation spec against a real server.
 *
 * Env-gated on `LANGGRAPH_MONGO_URI` (for example `mongodb://localhost:27017`) and on
 * ext-mongodb with `mongodb/mongodb`; skipped cleanly when either is missing.
 */
#[CoversClass(MongoDBSaver::class)]
final class MongoDBSaverIntegrationTest extends CheckpointerSpecCase
{
    private string $database = '';
    private ?\MongoDB\Client $nativeClient = null;
    private ?ProductionMongoDBDriverClient $client = null;

    protected function setUp(): void
    {
        $uri = getenv('LANGGRAPH_MONGO_URI');
        if ($uri === false || $uri === '') {
            self::markTestSkipped('Set LANGGRAPH_MONGO_URI to run the MongoDB integration tests.');
        }
        if (!class_exists(\MongoDB\Client::class)) {
            self::markTestSkipped('ext-mongodb and mongodb/mongodb are required for the MongoDB integration tests.');
        }

        $this->database = 'langgraph_php_test_' . bin2hex(random_bytes(12));
        $this->nativeClient = new \MongoDB\Client($uri, [
            'serverSelectionTimeoutMS' => 3000,
            'connectTimeoutMS' => 3000,
        ], ['disableClientPersistence' => true]);
        $this->client = new ProductionMongoDBDriverClient($this->nativeClient);
    }

    protected function tearDown(): void
    {
        if ($this->nativeClient !== null && $this->database !== '') {
            self::assertMatchesRegularExpression('/^langgraph_php_test_[a-f0-9]{24}$/D', $this->database);
            $this->nativeClient->dropDatabase($this->database);
        }
        $this->client = null;
        $this->nativeClient = null;
    }

    protected function makeSaver(): BaseCheckpointSaver
    {
        self::assertNotNull($this->client);
        $saver = new MongoDBSaver($this->client, $this->database);
        self::assertSame([], $saver->setup());

        return $saver;
    }
}
