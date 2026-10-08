<?php

declare(strict_types=1);

namespace LangChain\Tests\Integration\Checkpoint;

use LangGraph\Checkpoint\MongoDB\MongoClientInterface;
use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;

/**
 * Adapts `MongoDB\Client` (ext-mongodb + mongodb/mongodb) to {@see MongoClientInterface}.
 *
 * Only constructed when a live server is configured; see {@see MongoDBSaverIntegrationTest}.
 */
final class MongoDBDriverClient implements MongoClientInterface
{
    private readonly \MongoDB\Client $client;

    public function __construct(string $uri)
    {
        $this->client = new \MongoDB\Client($uri);
    }

    public function appendMetadata(array $metadata): void
    {
        // The PHP driver takes its handshake metadata at construction; nothing to append.
    }

    public function db(?string $name = null): MongoDatabaseInterface
    {
        return new MongoDBDriverDatabase($this->client->selectDatabase($name ?? 'langgraph_php_test'));
    }

    public function dropDatabase(string $name): void
    {
        $this->client->dropDatabase($name);
    }
}
