<?php

declare(strict_types=1);

namespace LangGraph\Store\Postgres;

/**
 * Schema-qualified table names for the Postgres store.
 *
 * Port of `getStoreTablesWithSchema` from `store/sql.ts`.
 */
final class Sql
{
    private function __construct()
    {
    }

    /**
     * @return array{store: string, store_vectors: string, store_migrations: string}
     */
    public static function getStoreTablesWithSchema(string $schema): array
    {
        $quoted = self::quoteIdentifier($schema);

        return [
            'store' => $quoted . '.store',
            'store_vectors' => $quoted . '.store_vectors',
            'store_migrations' => $quoted . '.store_migrations',
        ];
    }

    /** Quote an identifier for use in SQL, doubling embedded quotes. */
    public static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
