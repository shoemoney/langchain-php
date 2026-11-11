<?php

declare(strict_types=1);

namespace LangGraph\Store\MongoDB;

use LangGraph\Checkpoint\MongoDB\MongoCollectionInterface;
use LangGraph\Checkpoint\MongoDB\MongoDBDriverDatabase;

/** Wraps a borrowed native database with store-specific collection adapters. */
class MongoDBDriverStoreDatabase extends MongoDBDriverDatabase
{
    protected function wrapCollection(object $collection): MongoCollectionInterface
    {
        return new MongoDBDriverStoreCollection($collection);
    }
}
