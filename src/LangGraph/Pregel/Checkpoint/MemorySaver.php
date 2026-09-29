<?php

declare(strict_types=1);

namespace LangGraph\Pregel\Checkpoint;

/**
 * An in-process checkpoint saver.
 *
 * Port of `MemorySaver` from `@langchain/langgraph-checkpoint`.
 *
 * Everything lives in PHP arrays for the life of the process. That makes it
 * ideal for tests and for a single long-lived worker, and useless for surviving
 * a restart — which is the honest boundary of this class.
 *
 * Two ordering rules carry the correctness:
 *
 *  - **Checkpoints are newest-first per thread**, so `getTuple` with no
 *    `checkpoint_id` is a head read rather than a search.
 *  - **Pending writes are keyed by task id**, so a re-run of the same task
 *    *replaces* its earlier writes rather than appending. Without that, a
 *    retried task would double-apply its output and a resumed run would
 *    accumulate duplicates on every attempt.
 */
class MemorySaver extends BaseCheckpointSaver
{
    /**
     * threadId => checkpoints, newest first.
     *
     * @var array<string, list<CheckpointTuple>>
     */
    private array $storage = [];

    /**
     * "threadId|checkpointId" => pending writes, newest last.
     *
     * @var array<string, list<array{0: string, 1: string, 2: mixed}>>
     */
    private array $writes = [];

    public function getTuple(array $config): ?CheckpointTuple
    {
        $threadId = $this->threadId($config);
        if ($threadId === null) {
            return null;
        }

        $checkpointId = $this->configurableOf($config)['checkpoint_id'] ?? null;

        foreach ($this->storage[$threadId] ?? [] as $saved) {
            $savedId = $this->configurableOf($saved->config)['checkpoint_id'] ?? null;
            if ($checkpointId === null || $savedId === $checkpointId) {
                return new CheckpointTuple(
                    config: $saved->config,
                    checkpoint: $saved->checkpoint,
                    metadata: $saved->metadata,
                    parentConfig: $saved->parentConfig,
                    pendingWrites: $this->pendingWritesFor($threadId, (string) $savedId),
                );
            }
        }

        return null;
    }

    public function put(
        array $config,
        Checkpoint $checkpoint,
        array $metadata = [],
        array $newVersions = [],
    ): array {
        $threadId = $this->threadId($config);
        if ($threadId === null) {
            throw new \InvalidArgumentException('thread_id is required to save a checkpoint');
        }

        $checkpointId = $checkpoint->id;
        $incoming = $this->configurableOf($config);
        $namespace = (string) ($incoming['checkpoint_ns'] ?? '');

        $newConfig = ['configurable' => array_merge($incoming, [
            'thread_id' => $threadId,
            'checkpoint_ns' => $namespace,
            'checkpoint_id' => $checkpointId,
        ])];

        if ($namespace !== '') {
            $newConfig['configurable']['checkpoint_map'] = array_merge(
                (array) ($incoming['checkpoint_map'] ?? []),
                [$namespace => $checkpointId],
            );
        }

        $this->storage[$threadId] ??= [];

        // A re-put of the same checkpoint replaces it. Leaving both would make
        // `getTuple` ambiguous about which is the head.
        $this->storage[$threadId] = array_values(array_filter(
            $this->storage[$threadId],
            fn (CheckpointTuple $t): bool => ($this->configurableOf($t->config)['checkpoint_id'] ?? null) !== $checkpointId
        ));

        $parentConfig = null;
        if (isset($incoming['checkpoint_id'])) {
            $parentId = (string) $incoming['checkpoint_id'];
            foreach ($this->storage[$threadId] as $saved) {
                if (($this->configurableOf($saved->config)['checkpoint_id'] ?? null) === $parentId) {
                    $parentConfig = $saved->config;
                    break;
                }
            }
        }

        array_unshift($this->storage[$threadId], new CheckpointTuple(
            config: $newConfig,
            checkpoint: $checkpoint->copy(),
            metadata: $metadata,
            parentConfig: $parentConfig,
        ));

        return $newConfig;
    }

    public function putWrites(array $config, array $writes, string $taskId): array
    {
        $threadId = $this->threadId($config);
        if ($threadId === null) {
            throw new \InvalidArgumentException('thread_id is required to save writes');
        }

        $checkpointId = (string) ($this->configurableOf($config)['checkpoint_id'] ?? '');
        $key = $this->writesKey($threadId, $checkpointId);

        // A re-run of the same task replaces its previous writes wholesale —
        // this is what makes a retried task idempotent.
        $kept = array_values(array_filter(
            $this->writes[$key] ?? [],
            static fn (array $w): bool => $w[0] !== $taskId
        ));

        foreach ($writes as $write) {
            $kept[] = [$taskId, $write[0], $write[1] ?? null];
        }

        $this->writes[$key] = $kept;

        return $config;
    }

    public function list(array $config, \LangGraph\Checkpoint\CheckpointListOptions|int|null $options = null): array
    {
        $limit = \LangGraph\Checkpoint\CheckpointListOptions::of($options)->limit;
        $threadId = $this->threadId($config);
        if ($threadId === null) {
            return [];
        }

        $checkpoints = $this->storage[$threadId] ?? [];
        if ($limit !== null) {
            $checkpoints = array_slice($checkpoints, max(0, $limit));
        }

        return array_map(
            static fn (CheckpointTuple $t): CheckpointTuple => new CheckpointTuple(
                config: $t->config,
                checkpoint: $t->checkpoint,
                metadata: $t->metadata,
                parentConfig: $t->parentConfig,
                pendingWrites: [],
            ),
            $checkpoints,
        );
    }

    /**
     * Every saved checkpoint for a thread, newest first.
     *
     * @return list<CheckpointTuple>
     */
    public function all(string $threadId): array
    {
        return $this->storage[$threadId] ?? [];
    }

    /** Forget a thread entirely. */
    public function deleteThread(string $threadId): void
    {
        unset($this->storage[$threadId]);
        foreach (array_keys($this->writes) as $key) {
            if (str_starts_with($key, $threadId . '|')) {
                unset($this->writes[$key]);
            }
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private function pendingWritesFor(string $threadId, string $checkpointId): array
    {
        return $this->writes[$this->writesKey($threadId, $checkpointId)] ?? [];
    }

    private function writesKey(string $threadId, string $checkpointId): string
    {
        return $threadId . '|' . $checkpointId;
    }

    /**
     * Resolve the thread id from a config.
     *
     * The loop hands the saver the *inner* `configurable` map, so `thread_id` is
     * a direct key here. A whole config is accepted too, because the two shapes
     * are easy to confuse and a silently-empty thread id would file every
     * checkpoint under the same wrong key.
     *
     * @param array<string, mixed> $config
     */
    private function threadId(array $config): ?string
    {
        $threadId = $this->configurableOf($config)['thread_id'] ?? null;

        return is_string($threadId) && $threadId !== '' ? $threadId : null;
    }

    /**
     * The `configurable` map for a config, whether whole or already inner.
     *
     * @param  array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function configurableOf(array $config): array
    {
        if (isset($config['configurable']) && is_array($config['configurable'])) {
            return $config['configurable'];
        }

        return $config;
    }
}
