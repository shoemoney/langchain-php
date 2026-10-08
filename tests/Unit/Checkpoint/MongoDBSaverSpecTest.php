<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\MongoDB\MongoDBSaver;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The checkpointer contract, run against {@see MongoDBSaver} over {@see FakeMongoCollection}.
 *
 * Not env-gated: the fake is in memory. The integration test runs the same contract
 * against a live server.
 */
#[CoversClass(BaseCheckpointSaver::class)]
#[CoversClass(MongoDBSaver::class)]
#[CoversClass(Checkpoint::class)]
#[CoversClass(CheckpointListOptions::class)]
final class MongoDBSaverSpecTest extends CheckpointerSpecCase
{
    protected function makeSaver(): BaseCheckpointSaver
    {
        return new MongoDBSaver(new MongoDBFakeClient());
    }
}
