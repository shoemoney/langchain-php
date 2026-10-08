<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\MemorySaver;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The checkpointer contract, run against {@see MemorySaver}.
 */
#[CoversClass(BaseCheckpointSaver::class)]
#[CoversClass(MemorySaver::class)]
#[CoversClass(Checkpoint::class)]
#[CoversClass(CheckpointListOptions::class)]
final class MemorySaverSpecTest extends CheckpointerSpecCase
{
    protected function makeSaver(): BaseCheckpointSaver
    {
        return new MemorySaver();
    }
}
