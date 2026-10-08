<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Postgres\PostgresSaver;

/**
 * Where the Postgres-backed tests get a saver, or learn there is no server.
 *
 * `LANGGRAPH_PG_DSN` names the server (a `postgresql://` URL or a `pgsql:` DSN).
 * When it is unset a local development server is probed; when nothing answers,
 * {@see self::saver()} returns null and the caller skips.
 *
 * Every saver gets its own freshly named schema, so a test never reads or drops a
 * table it did not create.
 */
final class PostgresTestConnection
{
    /** A throwaway database on the development machine's Homebrew server. */
    private const LOCAL_FALLBACK = 'postgresql://localhost/langgraph_php_test?host=/tmp&port=54329';

    /** @var array<string, PostgresSaver> schema => saver */
    private static array $created = [];

    /**
     * A set-up saver in a new schema, or null when no server is reachable.
     */
    public static function saver(): ?PostgresSaver
    {
        $schema = 'lgphp_' . bin2hex(random_bytes(6));
        $explicit = getenv('LANGGRAPH_PG_DSN');
        $candidates = $explicit !== false && $explicit !== '' ? [$explicit] : [self::LOCAL_FALLBACK];

        foreach ($candidates as $dsn) {
            try {
                $saver = PostgresSaver::fromConnString($dsn, ['schema' => $schema]);
                $saver->setup();
            } catch (\PDOException) {
                continue;
            }
            self::$created[$schema] = $saver;

            return $saver;
        }

        return null;
    }

    /** Drop every schema this process created. */
    public static function dropAll(): void
    {
        foreach (self::$created as $schema => $saver) {
            $saver->db()->exec('DROP SCHEMA IF EXISTS "' . str_replace('"', '""', $schema) . '" CASCADE');
        }
        self::$created = [];
    }

    public static function schemaOf(PostgresSaver $saver): string
    {
        foreach (self::$created as $schema => $candidate) {
            if ($candidate === $saver) {
                return (string) $schema;
            }
        }

        throw new \LogicException('That saver was not made by this helper.');
    }
}
