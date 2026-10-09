<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Postgres;

use LangGraph\Store\Postgres\Modules\IndexConfig;
use LangGraph\Store\Postgres\PostgresStore;

/**
 * Where the Postgres store tests get a store, or learn there is no server.
 *
 * `LANGGRAPH_PG_DSN` names the server (a `postgresql://` URL or a `pgsql:` DSN).
 * When it is unset a local development server is probed; when nothing answers,
 * {@see self::store()} returns null and the caller skips.
 *
 * Every store gets its own freshly named schema, so a test never reads or drops
 * a table it did not create, and {@see self::dropAll()} removes them.
 */
final class PostgresStoreTestConnection
{
    /** A throwaway database on the development machine's Homebrew server. */
    private const LOCAL_FALLBACK = 'postgresql://localhost/langgraph_php_test?host=/tmp&port=54329';

    /** SQLSTATEs for "the pgvector extension cannot be created here". */
    private const EXTENSION_UNAVAILABLE = ['0A000', '42501', '58P01'];

    /** @var array<string, PostgresStore> schema => store */
    private static array $created = [];

    /** The connection string in use. */
    public static function dsn(): string
    {
        $explicit = getenv('LANGGRAPH_PG_DSN');

        return $explicit !== false && $explicit !== '' ? $explicit : self::LOCAL_FALLBACK;
    }

    /**
     * A set-up store in a new schema, or null when no server is reachable.
     *
     * @param array{ttl?: \LangGraph\Store\Postgres\Modules\TtlConfig, textSearchLanguage?: string} $options
     */
    public static function store(array $options = []): ?PostgresStore
    {
        $schema = 'lgphp_' . bin2hex(random_bytes(6));

        try {
            $store = PostgresStore::fromConnString(self::dsn(), $options + ['schema' => $schema]);
            $store->setup();
        } catch (\PDOException) {
            return null;
        }
        self::$created[$schema] = $store;

        return $store;
    }

    /**
     * A set-up store with a vector index, or null when there is no server or
     * pgvector cannot be enabled on it.
     *
     * @param array{ttl?: \LangGraph\Store\Postgres\Modules\TtlConfig, textSearchLanguage?: string} $options
     */
    public static function vectorStore(IndexConfig $index, array $options = []): ?PostgresStore
    {
        $schema = 'lgphp_' . bin2hex(random_bytes(6));

        try {
            $store = PostgresStore::fromConnString(self::dsn(), $options + ['schema' => $schema, 'index' => $index]);
        } catch (\PDOException) {
            return null;
        }
        self::$created[$schema] = $store;

        try {
            $store->setup();
        } catch (\PDOException $e) {
            if (in_array((string) $e->getCode(), self::EXTENSION_UNAVAILABLE, true)) {
                return null;
            }
            throw $e;
        }

        return $store;
    }

    /** Drop every schema this process created. */
    public static function dropAll(): void
    {
        foreach (self::$created as $schema => $store) {
            $store->db()->exec('DROP SCHEMA IF EXISTS "' . str_replace('"', '""', (string) $schema) . '" CASCADE');
        }
        self::$created = [];
    }

    public static function schemaOf(PostgresStore $store): string
    {
        foreach (self::$created as $schema => $candidate) {
            if ($candidate === $store) {
                return (string) $schema;
            }
        }

        throw new \LogicException('That store was not made by this helper.');
    }
}
