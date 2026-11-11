<?php

declare(strict_types=1);

namespace LangChain\Tests\Integration\Checkpoint;

use LangChain\Tests\Unit\Checkpoint\CheckpointerSpecCase;
use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\MongoDB\MongoDBSaver;
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


    private ?MongoDBDriverClient $client = null;

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
        $this->client = new MongoDBDriverClient($uri);
    }

    protected function tearDown(): void
    {
        $this->client?->dropDatabase($this->database);
    }

    protected function makeSaver(): BaseCheckpointSaver
    {
        self::assertNotNull($this->client);
        $saver = new MongoDBSaver($this->client, $this->database);
        self::assertSame([], $saver->setup());

        return $saver;
    }
}
