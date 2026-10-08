<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Pregel\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Postgres\PostgresSaver;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The checkpointer contract, run against {@see PostgresSaver}.
 *
 * Env-gated on `LANGGRAPH_PG_DSN`; see {@see PostgresTestConnection} for the
 * fallback and for why each saver lives in its own schema. With no server
 * reachable every test is skipped.
 */
#[CoversClass(BaseCheckpointSaver::class)]
#[CoversClass(PostgresSaver::class)]
final class PostgresSaverSpecTest extends CheckpointerSpecCase
{
    protected function tearDown(): void
    {
        PostgresTestConnection::dropAll();
        parent::tearDown();
    }

    protected function makeSaver(): BaseCheckpointSaver
    {
        $saver = PostgresTestConnection::saver();
        if ($saver === null) {
            self::markTestSkipped('No Postgres reachable: set LANGGRAPH_PG_DSN to run the checkpointer spec.');
        }

        return $saver;
    }
}
