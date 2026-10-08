<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Postgres;

/**
 * The schema migrations, in order.
 *
 * Port of `migrations.ts`. To add a migration, append a string to the list
 * returned by {@see self::getMigrations()}: its position is its version number.
 */
final class Migrations
{
    /**
     * @return list<string>
     */
    public static function getMigrations(string $schema): array
    {
        $tables = Sql::getTablesWithSchema($schema);

        return [
            "CREATE TABLE IF NOT EXISTS {$tables['checkpoint_migrations']} (
    v INTEGER PRIMARY KEY
  );",
            "CREATE TABLE IF NOT EXISTS {$tables['checkpoints']} (
    thread_id TEXT NOT NULL,
    checkpoint_ns TEXT NOT NULL DEFAULT '',
    checkpoint_id TEXT NOT NULL,
    parent_checkpoint_id TEXT,
    type TEXT,
    checkpoint JSONB NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}',
    PRIMARY KEY (thread_id, checkpoint_ns, checkpoint_id)
  );",
            "CREATE TABLE IF NOT EXISTS {$tables['checkpoint_blobs']} (
    thread_id TEXT NOT NULL,
    checkpoint_ns TEXT NOT NULL DEFAULT '',
    channel TEXT NOT NULL,
    version TEXT NOT NULL,
    type TEXT NOT NULL,
    blob BYTEA,
    PRIMARY KEY (thread_id, checkpoint_ns, channel, version)
  );",
            "CREATE TABLE IF NOT EXISTS {$tables['checkpoint_writes']} (
    thread_id TEXT NOT NULL,
    checkpoint_ns TEXT NOT NULL DEFAULT '',
    checkpoint_id TEXT NOT NULL,
    task_id TEXT NOT NULL,
    idx INTEGER NOT NULL,
    channel TEXT NOT NULL,
    type TEXT,
    blob BYTEA NOT NULL,
    PRIMARY KEY (thread_id, checkpoint_ns, checkpoint_id, task_id, idx)
  );",
            "ALTER TABLE {$tables['checkpoint_blobs']} ALTER COLUMN blob DROP not null;",
        ];
    }
}
