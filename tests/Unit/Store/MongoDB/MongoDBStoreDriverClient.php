<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use LangGraph\Checkpoint\MongoDB\MongoClientInterface;
use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;

/**
 * Adapts `MongoDB\Client` (ext-mongodb + mongodb/mongodb) to {@see MongoClientInterface}, handing out
 * {@see MongoDBStoreDriverCollection}s. Only constructed when a live server is configured.
 */
final class MongoDBStoreDriverClient implements MongoClientInterface
{
    public readonly \MongoDB\Client $client;

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
        return new MongoDBStoreDriverDatabase($this->client->selectDatabase($name ?? 'langgraph_php_test'));
    }
}
