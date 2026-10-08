<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Postgres;

use LangGraph\Checkpoint\CheckpointConstants;

/**
 * Every SQL string the Postgres checkpointer runs.
 *
 * Port of `sql.ts` from `@langchain/langgraph-checkpoint-postgres`.
 *
 * Two deliberate differences from upstream, both forced by the driver rather
 * than chosen:
 *
 *  - Placeholders are `?`, not `$1`. PDO does not parse numbered placeholders,
 *    so `$1` would be sent to the server as a literal and fail.
 *  - Binary columns are read back as base64 inside JSON aggregates instead of
 *    `array_agg(array[...::bytea])`. PDO returns a multi-dimensional bytea
 *    array as one text blob in Postgres array syntax, which is not safely
 *    parseable; a JSON array of base64 strings is.
 */
final class Sql
{
    /**
     * The four tables, each qualified with the quoted schema.
     *
     * The schema is a quoted identifier, so a name with a dash or a space
     * (`my-schema`) is legal. An embedded `"` is doubled, which is the only
     * way to put one inside a quoted identifier.
     *
     * @return array{checkpoints: string, checkpoint_blobs: string, checkpoint_writes: string, checkpoint_migrations: string}
     */
    public static function getTablesWithSchema(string $schema): array
    {
        $quoted = '"' . str_replace('"', '""', $schema) . '"';

        return [
            'checkpoints' => $quoted . '.checkpoints',
            'checkpoint_blobs' => $quoted . '.checkpoint_blobs',
            'checkpoint_migrations' => $quoted . '.checkpoint_migrations',
            'checkpoint_writes' => $quoted . '.checkpoint_writes',
        ];
    }

    /**
     * @return array{
     *     SELECT_SQL: string,
     *     SELECT_PENDING_SENDS_SQL: string,
     *     UPSERT_CHECKPOINT_BLOBS_SQL: string,
     *     UPSERT_CHECKPOINTS_SQL: string,
     *     UPSERT_CHECKPOINT_WRITES_SQL: string,
     *     INSERT_CHECKPOINT_WRITES_SQL: string,
     *     DELETE_CHECKPOINTS_SQL: string,
     *     DELETE_CHECKPOINT_BLOBS_SQL: string,
     *     DELETE_CHECKPOINT_WRITES_SQL: string
     * }
     */
    public static function getSQLStatements(string $schema): array
    {
        $tables = self::getTablesWithSchema($schema);
        $tasks = CheckpointConstants::TASKS;

        return [
            // The trailing space is necessary for combining with WHERE clauses.
            'SELECT_SQL' => "select
    thread_id,
    checkpoint,
    checkpoint_ns,
    checkpoint_id,
    parent_checkpoint_id,
    metadata,
    (
      select json_agg(json_build_array(bl.channel, bl.type, encode(bl.blob, 'base64')))
      from jsonb_each_text(checkpoint -> 'channel_versions')
      inner join {$tables['checkpoint_blobs']} bl
        on bl.thread_id = cp.thread_id
        and bl.checkpoint_ns = cp.checkpoint_ns
        and bl.channel = jsonb_each_text.key
        and bl.version = jsonb_each_text.value
    ) as channel_values,
    (
      select
      json_agg(json_build_array(cw.task_id, cw.channel, cw.type, encode(cw.blob, 'base64')) order by cw.task_id, cw.idx)
      from {$tables['checkpoint_writes']} cw
      where cw.thread_id = cp.thread_id
        and cw.checkpoint_ns = cp.checkpoint_ns
        and cw.checkpoint_id = cp.checkpoint_id
    ) as pending_writes
  from {$tables['checkpoints']} cp ",

            'SELECT_PENDING_SENDS_SQL' => "select
      checkpoint_id,
      json_agg(json_build_array(cw.type, encode(cw.blob, 'base64')) order by cw.task_id, cw.idx) as pending_sends
    from {$tables['checkpoint_writes']} cw
    where cw.thread_id = ?
      and cw.checkpoint_id = any(?::text[])
      and cw.channel = '{$tasks}'
    group by cw.checkpoint_id
  ",

            'UPSERT_CHECKPOINT_BLOBS_SQL' => "INSERT INTO {$tables['checkpoint_blobs']} (thread_id, checkpoint_ns, channel, version, type, blob)
  VALUES (?, ?, ?, ?, ?, ?)
  ON CONFLICT (thread_id, checkpoint_ns, channel, version) DO NOTHING
  ",

            'UPSERT_CHECKPOINTS_SQL' => "INSERT INTO {$tables['checkpoints']} (thread_id, checkpoint_ns, checkpoint_id, parent_checkpoint_id, checkpoint, metadata)
  VALUES (?, ?, ?, ?, ?::jsonb, ?::jsonb)
  ON CONFLICT (thread_id, checkpoint_ns, checkpoint_id)
  DO UPDATE SET
    checkpoint = EXCLUDED.checkpoint,
    metadata = EXCLUDED.metadata;
  ",

            'UPSERT_CHECKPOINT_WRITES_SQL' => "INSERT INTO {$tables['checkpoint_writes']} (thread_id, checkpoint_ns, checkpoint_id, task_id, idx, channel, type, blob)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  ON CONFLICT (thread_id, checkpoint_ns, checkpoint_id, task_id, idx) DO UPDATE SET
    channel = EXCLUDED.channel,
    type = EXCLUDED.type,
    blob = EXCLUDED.blob;
  ",

            'INSERT_CHECKPOINT_WRITES_SQL' => "INSERT INTO {$tables['checkpoint_writes']} (thread_id, checkpoint_ns, checkpoint_id, task_id, idx, channel, type, blob)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  ON CONFLICT (thread_id, checkpoint_ns, checkpoint_id, task_id, idx) DO NOTHING
  ",

            'DELETE_CHECKPOINTS_SQL' => "DELETE FROM {$tables['checkpoints']} WHERE thread_id = ?",
            'DELETE_CHECKPOINT_BLOBS_SQL' => "DELETE FROM {$tables['checkpoint_blobs']} WHERE thread_id = ?",
            'DELETE_CHECKPOINT_WRITES_SQL' => "DELETE FROM {$tables['checkpoint_writes']} WHERE thread_id = ?",
        ];
    }

    /**
     * The existence probe for a schema-qualified table.
     *
     * The schema here is a string VALUE in a `WHERE`, so it takes single
     * quotes, not the double quotes of an identifier.
     */
    public static function tableExistsSQL(string $schema, string $table): string
    {
        $parts = explode('.', $table);
        $tableWithoutSchema = $parts[1] ?? '';
        $schemaLiteral = str_replace("'", "''", $schema);
        $tableLiteral = str_replace("'", "''", $tableWithoutSchema);

        return "SELECT EXISTS (
  SELECT FROM information_schema.tables 
  WHERE  table_schema = '{$schemaLiteral}'
  AND    table_name   = '{$tableLiteral}'
  );";
    }
}
