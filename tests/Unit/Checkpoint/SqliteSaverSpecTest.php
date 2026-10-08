<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\SqliteSaver;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The checkpointer contract, run against {@see SqliteSaver}.
 */
#[CoversClass(BaseCheckpointSaver::class)]
#[CoversClass(SqliteSaver::class)]
#[CoversClass(Checkpoint::class)]
#[CoversClass(CheckpointListOptions::class)]
final class SqliteSaverSpecTest extends CheckpointerSpecCase
{
    protected function makeSaver(): BaseCheckpointSaver
    {
        return SqliteSaver::fromConnString(':memory:');
    }
}
