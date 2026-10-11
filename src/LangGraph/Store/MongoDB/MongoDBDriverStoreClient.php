<?php

declare(strict_types=1);

namespace LangGraph\Store\MongoDB;

use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;
use LangGraph\Checkpoint\MongoDB\MongoDBDriverClient;

/**
 * Adapts an optional borrowed mongodb/mongodb client for the store.
 * Its strict native constructor, explicit default database and no-op appendMetadata are inherited.
 * The caller retains client identity, configuration and lifecycle ownership.
 */
class MongoDBDriverStoreClient extends MongoDBDriverClient
{
    protected function wrapDatabase(object $database): MongoDatabaseInterface
    {
        return new MongoDBDriverStoreDatabase($database);
    }
}
