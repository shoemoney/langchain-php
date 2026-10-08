<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\Redis\RedisSaver;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The checkpointer contract, run against {@see RedisSaver} over {@see FakeRedisClient}.
 *
 * Not env-gated: the fake is in memory. `RedisSaverIntegrationTest` runs the same
 * contract against a live server.
 */
#[CoversClass(BaseCheckpointSaver::class)]
#[CoversClass(RedisSaver::class)]
#[CoversClass(Checkpoint::class)]
#[CoversClass(CheckpointListOptions::class)]
final class RedisSaverSpecTest extends CheckpointerSpecCase
{
    protected function makeSaver(): BaseCheckpointSaver
    {
        return new RedisSaver(new FakeRedisClient());
    }
}
