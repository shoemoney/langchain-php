<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Postgres;

use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointConstants;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\CheckpointTuple;
use LangGraph\Checkpoint\Serde\BaseCheckpointSerializer;
use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;
use LangGraph\Pregel\Checkpoint\CheckpointTuple as PregelCheckpointTuple;

/**
 * A durable checkpoint saver backed by Postgres, over PDO.
 *
 * Port of `PostgresSaver` from `@langchain/langgraph-checkpoint-postgres`.
 *
 * ## Four tables
 *
 * `checkpoints` holds the checkpoint document (without its channel values) and
 * its metadata as JSONB. `checkpoint_blobs` holds each channel's value, keyed by
 * `(thread, namespace, channel, version)`, so a channel that did not change
 * between two supersteps is stored once. `checkpoint_writes` holds task output.
 * `checkpoint_migrations` records which schema versions have run.
 *
 * ## Ordering
 *
 * Checkpoint ids are uuid6 strings, which sort lexicographically in time order.
 * `ORDER BY checkpoint_id DESC` is therefore the newest-first order, and it
 * must stay a STRING comparison: casting the id to anything would reorder it.
 *
 * ## Setup
 *
 * {@see self::setup()} must be called once before first use, exactly as
 * upstream requires; it creates the schema and runs outstanding migrations.
 */
class PostgresSaver extends BaseCheckpointSaver
{
    /** @var array<string, string> */
    private readonly array $sql;

    private readonly string $schema;

    protected bool $isSetup = false;

    /**
     * @param array{schema?: string} $options
     */
    public function __construct(
        private readonly \PDO $db,
        ?BaseCheckpointSerializer $serde = null,
        array $options = [],
    ) {
        parent::__construct($serde);
        $this->schema = $options['schema'] ?? 'public';
        $this->sql = Sql::getSQLStatements($this->schema);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    /**
     * Open a connection from a `postgresql://` URL, a `pgsql:` PDO DSN, or a
     * libpq `key=value` string.
     *
     * @param array{schema?: string} $options
     */
    public static function fromConnString(string $connString, array $options = []): self
    {
        [$dsn, $user, $password] = self::parseConnString($connString);

        return new self(new \PDO($dsn, $user, $password), null, $options);
    }

    /**
     * Turn a connection string into PDO arguments.
     *
     * `?host=/var/run/postgresql` in a URL selects a unix socket, as libpq does.
     *
     * @return array{0: string, 1: ?string, 2: ?string} DSN, user, password
     */
    public static function parseConnString(string $connString): array
    {
        if (str_starts_with($connString, 'pgsql:')) {
            return [$connString, null, null];
        }

        if (!preg_match('#^postgres(?:ql)?://#', $connString)) {
            return ['pgsql:' . implode(';', preg_split('/\s+/', trim($connString)) ?: []), null, null];
        }

        $parts = parse_url($connString);
        if ($parts === false) {
            throw new \InvalidArgumentException('Unparseable Postgres connection string.');
        }
        parse_str($parts['query'] ?? '', $query);

        $pairs = [];
        $host = $query['host'] ?? $parts['host'] ?? null;
        if (is_string($host) && $host !== '') {
            $pairs[] = 'host=' . $host;
        }
        if (isset($parts['port'])) {
            $pairs[] = 'port=' . $parts['port'];
        } elseif (isset($query['port']) && is_string($query['port'])) {
            $pairs[] = 'port=' . $query['port'];
        }
        $database = isset($parts['path']) ? ltrim(rawurldecode($parts['path']), '/') : '';
        if ($database !== '') {
            $pairs[] = 'dbname=' . $database;
        }

        return [
            'pgsql:' . implode(';', $pairs),
            isset($parts['user']) ? rawurldecode($parts['user']) : null,
            isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
        ];
    }

    /** The underlying connection, for callers that need to run their own SQL. */
    public function db(): \PDO
    {
        return $this->db;
    }

    /**
     * Create the schema and run any migrations that have not run yet.
     *
     * It MUST be called directly the first time the checkpointer is used.
     */
    public function setup(): void
    {
        $tables = Sql::getTablesWithSchema($this->schema);
        $this->db->exec('CREATE SCHEMA IF NOT EXISTS "' . str_replace('"', '""', $this->schema) . '"');

        $version = -1;
        $migrations = Migrations::getMigrations($this->schema);

        try {
            $row = $this->db
                ->query("SELECT v FROM {$tables['checkpoint_migrations']} ORDER BY v DESC LIMIT 1")
                ?->fetch();
            if (is_array($row)) {
                $version = (int) $row['v'];
            }
        } catch (\PDOException $e) {
            // 42P01 is Postgres' undefined_table: the migrations table does not exist yet.
            if (($e->errorInfo[0] ?? $e->getCode()) !== '42P01') {
                throw $e;
            }
        }

        for ($v = $version + 1; $v < count($migrations); $v++) {
            $this->db->exec($migrations[$v]);
            $this->db
                ->prepare("INSERT INTO {$tables['checkpoint_migrations']} (v) VALUES (?)")
                ->execute([$v]);
        }

        $this->isSetup = true;
    }

    /**
     * Read the newest checkpoint for a thread/namespace, or the one named.
     *
     * @param array<string, mixed> $config
     */
    public function getTuple(array $config): ?PregelCheckpointTuple
    {
        $configurable = static::configurable($config);
        $threadId = self::stringOrNull($configurable['thread_id'] ?? null);
        if ($threadId === null) {
            return null;
        }

        $namespace = self::checkpointNamespace($configurable);
        $checkpointId = CheckpointId::fromConfig($config);

        if ($checkpointId !== '') {
            $where = 'WHERE thread_id = ? AND checkpoint_ns = ? AND checkpoint_id = ?';
            $args = [$threadId, $namespace, $checkpointId];
        } else {
            $where = 'WHERE thread_id = ? AND checkpoint_ns = ? ORDER BY checkpoint_id DESC LIMIT 1';
            $args = [$threadId, $namespace];
        }

        $statement = $this->db->prepare($this->sql['SELECT_SQL'] . $where);
        $statement->execute($args);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return $this->rowToTuple($row);
    }

    /**
     * A thread's checkpoints, newest first.
     *
     * @param  array<string, mixed>           $config
     * @param  CheckpointListOptions|int|null $options
     * @return list<PregelCheckpointTuple>
     */
    public function list(array $config, CheckpointListOptions|int|null $options = null): array
    {
        $listOptions = CheckpointListOptions::of($options);
        [$where, $args] = $this->searchWhere($config, $listOptions->filter ?? [], $listOptions->beforeCheckpointId());

        $query = $this->sql['SELECT_SQL'] . $where . ' ORDER BY checkpoint_id DESC';
        if ($listOptions->limit !== null) {
            $query .= ' LIMIT ' . max(0, $listOptions->limit);
        }

        $statement = $this->db->prepare($query);
        $statement->execute($args);

        $tuples = [];
        while (($row = $statement->fetch()) !== false) {
            $tuples[] = $this->rowToTuple($row);
        }

        return $tuples;
    }

    /**
     * The WHERE clause for a `list()` call.
     *
     * Port of `_searchWhere`. Returns the clause (with the `WHERE` keyword, or
     * empty) and its positional parameters.
     *
     * @param  array<string, mixed> $config
     * @param  array<string, mixed> $filter
     * @return array{0: string, 1: list<mixed>}
     */
    protected function searchWhere(array $config, array $filter, string $before): array
    {
        $configurable = static::configurable($config);
        $wheres = [];
        $params = [];

        $threadId = self::stringOrNull($configurable['thread_id'] ?? null);
        if ($threadId !== null) {
            $wheres[] = 'thread_id = ?';
            $params[] = $threadId;
        }

        // Strict null check: an empty-string namespace is a real namespace.
        if (array_key_exists('checkpoint_ns', $configurable) && $configurable['checkpoint_ns'] !== null) {
            $wheres[] = 'checkpoint_ns = ?';
            $params[] = (string) $configurable['checkpoint_ns'];
        }

        $checkpointId = self::stringOrNull($configurable['checkpoint_id'] ?? null);
        if ($checkpointId !== null) {
            $wheres[] = 'checkpoint_id = ?';
            $params[] = $checkpointId;
        }

        if ($filter !== []) {
            $wheres[] = 'metadata @> ?::jsonb';
            $params[] = json_encode($filter, JSON_THROW_ON_ERROR);
        }

        if ($before !== '') {
            $wheres[] = 'checkpoint_id < ?';
            $params[] = $before;
        }

        return [$wheres === [] ? '' : 'WHERE ' . implode(' AND ', $wheres), $params];
    }

    /**
     * Persist a new checkpoint.
     *
     * Channel values go to `checkpoint_blobs` (once per version), the rest of
     * the checkpoint to `checkpoints`, in one transaction.
     *
     * @param  array<string, mixed>      $config
     * @param  array<string, mixed>      $metadata
     * @param  array<string, int|string> $newVersions
     * @return array<string, mixed>
     */
    public function put(
        array $config,
        PregelCheckpoint $checkpoint,
        array $metadata = [],
        array $newVersions = [],
    ): array {
        if (!isset($config['configurable']) && !isset($config['thread_id'])) {
            throw new \InvalidArgumentException('Missing "configurable" field in "config" param');
        }

        $configurable = static::configurable($config);
        $threadId = self::stringOrNull($configurable['thread_id'] ?? null);
        if ($threadId === null) {
            throw new \InvalidArgumentException('Missing "thread_id" field in passed "config.configurable".');
        }
        if ($checkpoint->id === '') {
            throw new \InvalidArgumentException('A checkpoint must have an id to be saved.');
        }

        $namespace = self::checkpointNamespace($configurable);
        $parentId = self::stringOrNull($configurable['checkpoint_id'] ?? null);

        // Serialise before opening the transaction so it holds only INSERTs.
        $document = $checkpoint->toArray();
        unset($document['channel_values']);
        $serializedCheckpoint = json_encode($document, JSON_THROW_ON_ERROR);
        $serializedMetadata = $this->dumpMetadata($metadata);
        // A caller that omits `$newVersions` would otherwise lose every channel value silently, because
        // only versioned channels are written to `checkpoint_blobs`. Writing the checkpoint's own
        // versions instead is safe: the blob insert is `ON CONFLICT DO NOTHING`.
        $blobs = $this->dumpBlobs(
            $threadId,
            $namespace,
            $checkpoint->channelValues,
            $newVersions !== [] ? $newVersions : array_intersect_key($checkpoint->channelVersions, $checkpoint->channelValues),
        );

        $this->db->beginTransaction();
        try {
            foreach ($blobs as $blob) {
                $this->run($this->sql['UPSERT_CHECKPOINT_BLOBS_SQL'], $blob, [5]);
            }
            $this->run($this->sql['UPSERT_CHECKPOINTS_SQL'], [
                $threadId,
                $namespace,
                $checkpoint->id,
                $parentId,
                $serializedCheckpoint,
                $serializedMetadata,
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return static::tupleConfig($threadId, $namespace, $checkpoint->id);
    }

    /**
     * Persist one task's writes.
     *
     * When every write is to a special channel the row is replaced, so a resume
     * can overwrite the interrupt it answers; otherwise an existing row wins.
     *
     * @param  array<string, mixed>             $config
     * @param  list<array{0: string, 1: mixed}> $writes
     * @return array<string, mixed>
     */
    public function putWrites(array $config, array $writes, string $taskId): array
    {
        if (!isset($config['configurable']) && !isset($config['thread_id'])) {
            throw new \InvalidArgumentException('Empty configuration supplied.');
        }

        $configurable = static::configurable($config);
        $threadId = self::stringOrNull($configurable['thread_id'] ?? null);
        if ($threadId === null) {
            throw new \InvalidArgumentException('Missing thread_id field in config.configurable.');
        }
        $checkpointId = self::stringOrNull($configurable['checkpoint_id'] ?? null);
        if ($checkpointId === null) {
            throw new \InvalidArgumentException('Missing checkpoint_id field in config.configurable.');
        }
        $namespace = self::checkpointNamespace($configurable);

        $query = CheckpointConstants::allSpecialChannels($writes)
            ? $this->sql['UPSERT_CHECKPOINT_WRITES_SQL']
            : $this->sql['INSERT_CHECKPOINT_WRITES_SQL'];

        $rows = [];
        foreach (array_values($writes) as $position => $write) {
            $channel = (string) $write[0];
            [$type, $serialized] = $this->serde->dumpsTyped($write[1] ?? null);
            $rows[] = [
                $threadId,
                $namespace,
                $checkpointId,
                $taskId,
                static::writeIndex($channel, $position),
                $channel,
                $type,
                $serialized,
            ];
        }

        $this->db->beginTransaction();
        try {
            foreach ($rows as $row) {
                $this->run($query, $row, [7]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $config;
    }

    /** Forget a thread entirely: blobs, checkpoints and writes, in one transaction. */
    public function deleteThread(string $threadId): void
    {
        $this->db->beginTransaction();
        try {
            $this->run($this->sql['DELETE_CHECKPOINT_BLOBS_SQL'], [$threadId]);
            $this->run($this->sql['DELETE_CHECKPOINTS_SQL'], [$threadId]);
            $this->run($this->sql['DELETE_CHECKPOINT_WRITES_SQL'], [$threadId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * The `__pregel_tasks` writes recorded against a checkpoint.
     *
     * @param  array<string, mixed> $config
     * @return list<mixed>
     */
    protected function pendingSendsFor(array $config): array
    {
        $configurable = static::configurable($config);
        $threadId = self::stringOrNull($configurable['thread_id'] ?? null);
        $checkpointId = self::stringOrNull($configurable['checkpoint_id'] ?? null);
        if ($threadId === null || $checkpointId === null) {
            return [];
        }

        $statement = $this->db->prepare($this->sql['SELECT_PENDING_SENDS_SQL']);
        $statement->execute([$threadId, '{"' . addcslashes($checkpointId, '"\\') . '"}']);
        $row = $statement->fetch();
        if ($row === false || $row['pending_sends'] === null) {
            return [];
        }

        $sends = [];
        foreach (self::jsonList($row['pending_sends']) as [$type, $blob]) {
            $sends[] = $this->serde->loadsTyped((string) $type, self::unblob($blob));
        }

        return $sends;
    }

    /**
     * @param  array<string, mixed> $row
     */
    private function rowToTuple(array $row): PregelCheckpointTuple
    {
        $threadId = (string) $row['thread_id'];
        $namespace = (string) ($row['checkpoint_ns'] ?? '');
        $checkpointId = (string) $row['checkpoint_id'];
        $parentId = self::stringOrNull($row['parent_checkpoint_id'] ?? null);

        $document = json_decode((string) $row['checkpoint'], true, 512, JSON_THROW_ON_ERROR);
        $document['channel_values'] = $this->loadBlobs(self::jsonList($row['channel_values']));
        $checkpoint = Checkpoint::fromArray($document);

        $this->migratePendingSends($checkpoint, [
            'thread_id' => $threadId,
            'checkpoint_ns' => $namespace,
            'checkpoint_id' => $checkpointId,
        ], $parentId);

        $pendingWrites = [];
        foreach (self::jsonList($row['pending_writes']) as [$taskId, $channel, $type, $blob]) {
            $pendingWrites[] = [
                (string) $taskId,
                (string) $channel,
                $this->serde->loadsTyped((string) $type, self::unblob($blob)),
            ];
        }

        return new CheckpointTuple(
            config: static::tupleConfig($threadId, $namespace, $checkpointId),
            checkpoint: $checkpoint,
            metadata: (array) $this->serde->loadsTyped('json', (string) $row['metadata']),
            parentConfig: $parentId === null ? null : static::tupleConfig($threadId, $namespace, $parentId),
            pendingWrites: $pendingWrites,
        );
    }

    /**
     * Port of `_loadBlobs`: channels stored as "empty" had no value and are skipped.
     *
     * @param  list<array{0: string, 1: string, 2: ?string}> $blobValues
     * @return array<string, mixed>
     */
    private function loadBlobs(array $blobValues): array
    {
        $values = [];
        foreach ($blobValues as [$channel, $type, $blob]) {
            if ($type === 'empty') {
                continue;
            }
            $values[$channel] = $this->serde->loadsTyped($type, self::unblob($blob));
        }

        return $values;
    }

    /**
     * Port of `_dumpBlobs`: one row per channel version, `empty` for a channel
     * that has a version but no value.
     *
     * @param  array<string, mixed>      $values
     * @param  array<string, int|string> $versions
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: ?string}>
     */
    private function dumpBlobs(string $threadId, string $namespace, array $values, array $versions): array
    {
        $rows = [];
        foreach ($versions as $channel => $version) {
            $channel = (string) $channel;
            if (array_key_exists($channel, $values)) {
                [$type, $blob] = $this->serde->dumpsTyped($values[$channel]);
            } else {
                [$type, $blob] = ['empty', null];
            }
            $rows[] = [$threadId, $namespace, $channel, (string) $version, $type, $blob];
        }

        return $rows;
    }

    /**
     * Port of `_dumpMetadata`. JSONB cannot hold a NUL, so those are removed,
     * and an empty map is written as `{}` rather than `[]`.
     *
     * @param array<string, mixed> $metadata
     */
    private function dumpMetadata(array $metadata): string
    {
        [, $serialized] = $this->serde->dumpsTyped($metadata);
        $serialized = str_replace("\0", '', $serialized);
        $serialized = preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)\\\\u0000/', '$1', $serialized) ?? $serialized;

        return $serialized === '[]' ? '{}' : $serialized;
    }

    /**
     * Run a statement, binding the listed zero-based parameters as binary.
     *
     * @param list<mixed> $params
     * @param list<int>   $lobs
     */
    private function run(string $sql, array $params, array $lobs = []): void
    {
        $statement = $this->db->prepare($sql);
        foreach (array_values($params) as $i => $value) {
            if (in_array($i, $lobs, true)) {
                $statement->bindValue($i + 1, $value, $value === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
            } else {
                $statement->bindValue($i + 1, $value, $value === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            }
        }
        $statement->execute();
    }

    /**
     * @return list<list<mixed>>
     */
    private static function jsonList(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function unblob(mixed $base64): string
    {
        return $base64 === null ? '' : (string) base64_decode((string) $base64, true);
    }
}
