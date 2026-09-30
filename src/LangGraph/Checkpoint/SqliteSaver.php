<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint;

use LangGraph\Checkpoint\Serde\BaseCheckpointSerializer;
use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;
use LangGraph\Pregel\Checkpoint\CheckpointTuple as PregelCheckpointTuple;
use LangGraph\Pregel\Checkpoint\MemorySaver as PregelMemorySaver;

/**
 * A durable checkpoint saver backed by SQLite.
 *
 * Port of `SqliteSaver` from `@langchain/langgraph-checkpoint-sqlite`, against
 * PDO rather than `better-sqlite3`.
 *
 * ## The schema is two tables
 *
 * `checkpoints` holds one row per superstep: the serialised checkpoint, its
 * metadata, and the id of the checkpoint it replaced. `writes` holds one row per
 * task output, keyed by `(thread, namespace, checkpoint, task, index)`.
 *
 * The split is what makes a crash survivable. A `writes` row can exist for a
 * `checkpoints` row that was never written, and that is not corruption — it is
 * exactly the state a run dies in, and reading it back is how the already-finished
 * tasks are discovered and skipped on resume.
 *
 * ## Conflict resolution
 *
 * A write is keyed by task *and* index, so two writes collide only when they came
 * from the same task at the same position. Regular writes never overwrite: a
 * retried task's earlier attempt stays, and the engine prefers it. The four
 * special channels (error, scheduled, interrupt, resume) are pinned to negative
 * indices and always overwrite, which is what allows a resume to replace the
 * interrupt it is answering.
 */
class SqliteSaver extends PregelMemorySaver
{
    protected bool $isSetup = false;

    /** `getTuple` with a `checkpoint_id`. */
    private ?\PDOStatement $withCheckpoint = null;

    /** `getTuple` without one: the newest row for the thread. */
    private ?\PDOStatement $withoutCheckpoint = null;

    public function __construct(
        private readonly \PDO $db,
        ?BaseCheckpointSerializer $serde = null,
    ) {
        parent::__construct($serde);
    }

    /**
     * Open (or create) a database file.
     *
     * Port of `fromConnString`. `:memory:` gives a private database that lives
     * exactly as long as this object — the right default for a test.
     */
    public static function fromConnString(string $connString): self
    {
        // A caller who writes a full DSN — `sqlite:/path/to.db`, which is what
        // PDO's own documentation shows and what anyone copying a PDO
        // connection string will pass — used to get the prefix a second time
        // and `PDOException: unable to open database file`, with the doubled
        // string as the only clue. Verified against PDO directly: a doubled
        // prefix fails to open.
        $dsn = str_starts_with($connString, 'sqlite:') ? $connString : 'sqlite:' . $connString;

        return new self(new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]));
    }

    /** The underlying connection, for callers that need to run their own SQL. */
    public function db(): \PDO
    {
        return $this->db;
    }

    /**
     * Read the newest checkpoint for a thread/namespace, or null.
     *
     * @param array<string, mixed> $config
     */
    public function getTuple(array $config): ?PregelCheckpointTuple
    {
        $this->setup();

        $configurable = static::configurable($config);
        $threadId = self::stringOrNull($configurable['thread_id'] ?? null);
        if ($threadId === null) {
            return null;
        }

        $namespace = self::checkpointNamespace($configurable);
        $checkpointId = CheckpointId::fromConfig($config);

        // `setup()` always populates both, so neither branch can be null here.
        $statement = $checkpointId === ''
            ? ($this->withoutCheckpoint ?? throw new \LogicException('The saver was not set up.'))
            : ($this->withCheckpoint ?? throw new \LogicException('The saver was not set up.'));
        $statement->execute($checkpointId === ''
            ? [$threadId, $namespace]
            : [$threadId, $namespace, $checkpointId]);

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
        $this->setup();

        $listOptions = CheckpointListOptions::of($options);
        $configurable = static::configurable($config);

        $where = [];
        $args = [];

        $threadId = self::stringOrNull($configurable['thread_id'] ?? null);
        if ($threadId !== null) {
            $where[] = 'thread_id = ?';
            $args[] = $threadId;
        }

        if (array_key_exists('checkpoint_ns', $configurable)) {
            $where[] = 'checkpoint_ns = ?';
            $args[] = (string) $configurable['checkpoint_ns'];
        }

        $before = $listOptions->beforeCheckpointId();
        if ($before !== '') {
            $where[] = 'checkpoint_id < ?';
            $args[] = $before;
        }

        $limit = $listOptions->limit;
        $filter = $listOptions->filter ?? [];

        // The metadata filter is pushed into SQL so that `limit` still bounds
        // the *filtered* set. `json_quote(json_extract(...))` yields the value in
        // its JSON form, which is what the comparison argument is encoded as —
        // that is what makes `step = -1` and `parents = {"": id}` compare equal
        // rather than comparing a number to the string "1".
        foreach ($filter as $key => $value) {
            $where[] = 'json_quote(json_extract(CAST(metadata AS TEXT), ?)) = ?';
            $args[] = '$.' . $key;
            $args[] = json_encode($value, JSON_THROW_ON_ERROR);
        }

        $sql = $this->selectSql() . ($where === [] ? '' : 'WHERE ' . implode(' AND ', $where) . "\n")
            . 'ORDER BY checkpoint_id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);
        }

        $statement = $this->db->prepare($sql);
        $statement->execute($args);

        $tuples = [];
        while (($row = $statement->fetch()) !== false) {
            $tuples[] = $this->rowToTuple($row);
        }

        return $tuples;
    }

    /**
     * Persist a new checkpoint.
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
        $this->setup();

        if (!isset($config['configurable']) && !isset($config['thread_id'])) {
            throw new \InvalidArgumentException('Empty configuration supplied.');
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

        [$type, $serializedCheckpoint] = $this->serde->dumpsTyped($this->wireCheckpoint($checkpoint));
        [$metadataType, $serializedMetadata] = $this->serde->dumpsTyped($metadata);
        if ($type !== $metadataType) {
            throw new \InvalidArgumentException(
                'Failed to serialized checkpoint and metadata to the same type.'
            );
        }

        $statement = $this->db->prepare(
            'INSERT OR REPLACE INTO checkpoints '
            . '(thread_id, checkpoint_ns, checkpoint_id, parent_checkpoint_id, type, checkpoint, metadata) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $threadId,
            $namespace,
            $checkpoint->id,
            $parentId,
            $type,
            $serializedCheckpoint,
            $serializedMetadata,
        ]);

        return static::tupleConfig($threadId, $namespace, $checkpoint->id);
    }

    /**
     * Persist one task's writes.
     *
     * @param  array<string, mixed>            $config
     * @param  list<array{0: string, 1: mixed}> $writes
     * @return array<string, mixed>
     */
    public function putWrites(array $config, array $writes, string $taskId): array
    {
        $this->setup();

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

        // When every write is to a special channel we replace, so a resume can
        // overwrite the interrupt it is answering. Otherwise we ignore, so one
        // task's retry cannot clobber another task's write at the same index.
        $replace = CheckpointConstants::allSpecialChannels($writes);
        $statement = $this->db->prepare(
            'INSERT ' . ($replace ? 'OR REPLACE ' : 'OR IGNORE ') . 'INTO writes '
            . '(thread_id, checkpoint_ns, checkpoint_id, task_id, idx, channel, type, value) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $this->db->beginTransaction();
        try {
            foreach (array_values($writes) as $position => $write) {
                $channel = (string) $write[0];
                [$type, $serialized] = $this->serde->dumpsTyped($write[1] ?? null);
                $statement->execute([
                    $threadId,
                    $namespace,
                    $checkpointId,
                    $taskId,
                    static::writeIndex($channel, $position),
                    $channel,
                    $type,
                    $serialized,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $config;
    }

    /**
     * Forget a thread entirely.
     *
     * Both tables, in one transaction: a thread whose checkpoints are gone but
     * whose writes remain is a thread that resurrects on the next read.
     */
    public function deleteThread(string $threadId): void
    {
        $this->setup();

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM checkpoints WHERE thread_id = ?')->execute([$threadId]);
            $this->db->prepare('DELETE FROM writes WHERE thread_id = ?')->execute([$threadId]);
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

        $statement = $this->db->prepare(
            'SELECT type, value FROM writes WHERE thread_id = ? AND checkpoint_ns = ? AND checkpoint_id = ? '
            . 'AND channel = ? ORDER BY task_id, idx'
        );
        $statement->execute([
            $threadId,
            self::checkpointNamespace($configurable),
            $checkpointId,
            CheckpointConstants::TASKS,
        ]);

        $sends = [];
        while (($row = $statement->fetch()) !== false) {
            $sends[] = $this->serde->loadsTyped((string) $row['type'], (string) $row['value']);
        }

        return $sends;
    }

    /** Create the schema once per connection. */
    protected function setup(): void
    {
        if ($this->isSetup) {
            return;
        }

        // WAL lets a reader and the writer that is mid-`putWrites` coexist,
        // which is the whole point of writing a task's output before the
        // superstep commits.
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS checkpoints (
                thread_id TEXT NOT NULL,
                checkpoint_ns TEXT NOT NULL DEFAULT \'\',
                checkpoint_id TEXT NOT NULL,
                parent_checkpoint_id TEXT,
                type TEXT,
                checkpoint BLOB,
                metadata BLOB,
                PRIMARY KEY (thread_id, checkpoint_ns, checkpoint_id)
            )'
        );
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS writes (
                thread_id TEXT NOT NULL,
                checkpoint_ns TEXT NOT NULL DEFAULT \'\',
                checkpoint_id TEXT NOT NULL,
                task_id TEXT NOT NULL,
                idx INTEGER NOT NULL,
                channel TEXT NOT NULL,
                type TEXT,
                value BLOB,
                PRIMARY KEY (thread_id, checkpoint_ns, checkpoint_id, task_id, idx)
            )'
        );

        $withCheckpoint = $this->db->prepare(
            $this->selectSql() . 'WHERE thread_id = ? AND checkpoint_ns = ? AND checkpoint_id = ?'
        );
        $withoutCheckpoint = $this->db->prepare(
            $this->selectSql() . 'WHERE thread_id = ? AND checkpoint_ns = ? ORDER BY checkpoint_id DESC LIMIT 1'
        );

        $this->withCheckpoint = $withCheckpoint;
        $this->withoutCheckpoint = $withoutCheckpoint;
        $this->isSetup = true;
    }

    /**
     * The row projection every read shares.
     *
     * Pending writes are collected in a single correlated subquery rather than
     * joined in, so a checkpoint with no writes yields `[]` instead of dropping
     * the row. They are ordered by `(task_id, idx)` — the order live execution
     * applies them in — so a resumed run reconstructs the same state a live run
     * would have produced.
     */
    private function selectSql(): string
    {
        return "SELECT
    thread_id,
    checkpoint_ns,
    checkpoint_id,
    parent_checkpoint_id,
    type,
    checkpoint,
    metadata,
    (
      SELECT json_group_array(
        json_object(
          'task_id', pw.task_id,
          'channel', pw.channel,
          'type', pw.type,
          'value', CAST(pw.value AS TEXT)
        )
      )
      FROM writes as pw
      WHERE pw.thread_id = checkpoints.thread_id
        AND pw.checkpoint_ns = checkpoints.checkpoint_ns
        AND pw.checkpoint_id = checkpoints.checkpoint_id
      ORDER BY pw.task_id, pw.idx
    ) as pending_writes
FROM checkpoints\n";
    }

    /**
     * Turn a selected row into a tuple.
     *
     * @param  array<string, mixed> $row
     * @return PregelCheckpointTuple
     */
    private function rowToTuple(array $row): PregelCheckpointTuple
    {
        $threadId = (string) $row['thread_id'];
        $namespace = (string) ($row['checkpoint_ns'] ?? '');
        $checkpointId = (string) $row['checkpoint_id'];
        $parentId = self::stringOrNull($row['parent_checkpoint_id'] ?? null);
        $type = (string) ($row['type'] ?? 'json');

        $checkpoint = Checkpoint::fromArray(
            (array) $this->serde->loadsTyped($type, (string) $row['checkpoint'])
        );
        $this->migratePendingSends($checkpoint, [
            'thread_id' => $threadId,
            'checkpoint_ns' => $namespace,
            'checkpoint_id' => $checkpointId,
        ], $parentId);

        $pendingWrites = [];
        foreach (json_decode((string) $row['pending_writes'], true, 512, JSON_THROW_ON_ERROR) as $write) {
            $pendingWrites[] = [
                (string) $write['task_id'],
                (string) $write['channel'],
                $this->serde->loadsTyped((string) ($write['type'] ?? 'json'), (string) ($write['value'] ?? '')),
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
     * The wire record for a checkpoint, whichever checkpoint class was handed in.
     *
     * @return array<string, mixed>
     */
    private function wireCheckpoint(PregelCheckpoint $checkpoint): array
    {
        return $checkpoint instanceof Checkpoint
            ? $checkpoint->toArray()
            : [
                'v' => $checkpoint->v,
                'id' => $checkpoint->id,
                'ts' => $checkpoint->ts,
                'channel_values' => $checkpoint->channelValues,
                'channel_versions' => $checkpoint->channelVersions,
                'versions_seen' => $checkpoint->versionsSeen,
            ];
    }
}
