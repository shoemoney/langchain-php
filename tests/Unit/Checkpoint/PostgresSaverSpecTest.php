<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Postgres\PostgresSaver;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The checkpointer contract, run against {@see PostgresSaver}.
 *
 * Env-gated: `LANGGRAPH_PG_DSN` (a `postgresql://` URL or a `pgsql:` DSN) names the
 * server. When it is unset, a local development server is probed (see
 * {@see self::LOCAL_FALLBACK}); when nothing answers, the whole suite is skipped.
 *
 * Every saver gets its own schema, dropped afterwards, so the suite never touches
 * a table it did not create and a failed run leaves nothing behind that the next
 * run can trip over.
 */
#[CoversClass(BaseCheckpointSaver::class)]
#[CoversClass(PostgresSaver::class)]
final class PostgresSaverSpecTest extends CheckpointerSpecCase
{
    /** A throwaway database on the development machine's Homebrew server. */
    private const LOCAL_FALLBACK = 'postgresql://localhost/langgraph_php_test?host=/tmp&port=54329';

    /** @var list<PostgresSaver> */
    private array $savers = [];

    protected function tearDown(): void
    {
        foreach ($this->savers as $saver) {
            $saver->db()->exec('DROP SCHEMA IF EXISTS "' . $this->schemaOf($saver) . '" CASCADE');
        }
        $this->savers = [];
        parent::tearDown();
    }

    protected function makeSaver(): BaseCheckpointSaver
    {
        $schema = 'lgphp_' . bin2hex(random_bytes(6));
        $saver = null;
        foreach (array_filter([getenv('LANGGRAPH_PG_DSN') ?: null, self::LOCAL_FALLBACK]) as $dsn) {
            try {
                $saver = PostgresSaver::fromConnString($dsn, ['schema' => $schema]);
                break;
            } catch (\PDOException) {
                if (getenv('LANGGRAPH_PG_DSN')) {
                    break;
                }
            }
        }
        if ($saver === null) {
            self::markTestSkipped('No Postgres reachable: set LANGGRAPH_PG_DSN to run the checkpointer spec.');
        }

        $saver->setup();
        $this->savers[] = $saver;

        return $saver;
    }

    private function schemaOf(PostgresSaver $saver): string
    {
        $property = new \ReflectionProperty(PostgresSaver::class, 'schema');

        return (string) $property->getValue($saver);
    }
}
