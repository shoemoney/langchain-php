<?php

declare(strict_types=1);

namespace LangGraph\Sdk;

/**
 * Port of `client/threads/index.ts`, minus the streaming surface (`joinStream`, `stream`), which is
 * WP-23b alongside the runs client and the stream utilities.
 *
 * `ttl` anywhere below is either minutes (an int, sent as `{ttl, strategy: "delete"}`) or an
 * explicit `{ttl: int, strategy?: "delete"}` map.
 *
 * @phpstan-import-type Checkpoint from Schema
 * @phpstan-import-type Thread from Schema
 * @phpstan-import-type ThreadState from Schema
 */
class ThreadsClient extends BaseClient
{
    /**
     * Get a thread by ID. `include` asks the server for extra fields.
     *
     * @param array{include?: list<string>, signal?: mixed} $options
     *
     * @return Thread
     */
    public function get(string $threadId, array $options = []): array
    {
        return $this->fetch("/threads/{$threadId}", [
            'params' => ['include' => $options['include'] ?? null],
        ] + self::signalOf($options));
    }

    /**
     * Create a new thread. `graphId` is stored as `metadata.graph_id`.
     *
     * @param array{metadata?: array<string, mixed>|null, threadId?: string, ifExists?: string, graphId?: string, supersteps?: list<array{updates: list<array{values: mixed, command?: array<string, mixed>, asNode: string}>}>, ttl?: int|array{ttl: int, strategy?: string}, signal?: mixed} $payload
     *
     * @return Thread
     */
    public function create(array $payload = []): array
    {
        $metadata = $payload['metadata'] ?? [];
        if (array_key_exists('graphId', $payload)) {
            $metadata['graph_id'] = $payload['graphId'];
        }

        $json = ['metadata' => $metadata === [] ? new \stdClass() : $metadata];
        if (array_key_exists('threadId', $payload)) {
            $json['thread_id'] = $payload['threadId'];
        }
        if (array_key_exists('ifExists', $payload)) {
            $json['if_exists'] = $payload['ifExists'];
        }
        if (isset($payload['supersteps'])) {
            $json['supersteps'] = array_map(
                static fn (array $step): array => [
                    'updates' => array_map(
                        static fn (array $u): array => self::definedKeys([
                            'values' => $u['values'] ?? null,
                            'command' => $u['command'] ?? null,
                            'as_node' => $u['asNode'] ?? null,
                        ], ['values']),
                        $step['updates'],
                    ),
                ],
                $payload['supersteps'],
            );
        }
        if (isset($payload['ttl'])) {
            $json['ttl'] = self::ttlPayload($payload['ttl']);
        }

        return $this->fetch('/threads', ['method' => 'POST', 'json' => $json] + self::signalOf($payload));
    }

    /**
     * Copy an existing thread.
     *
     * @param array{signal?: mixed} $options
     *
     * @return Thread
     */
    public function copy(string $threadId, array $options = []): array
    {
        return $this->fetch("/threads/{$threadId}/copy", ['method' => 'POST'] + self::signalOf($options));
    }

    /**
     * Update a thread. With `returnMinimal` the server sends `Prefer: return=minimal` and the call
     * returns null.
     *
     * @param array{metadata?: array<string, mixed>|null, ttl?: int|array{ttl: int, strategy?: string}, returnMinimal?: bool, signal?: mixed} $payload
     *
     * @return Thread|null
     */
    public function update(string $threadId, array $payload = []): ?array
    {
        $json = [];
        if (array_key_exists('metadata', $payload)) {
            $json['metadata'] = $payload['metadata'];
        }
        if (isset($payload['ttl'])) {
            $json['ttl'] = self::ttlPayload($payload['ttl']);
        }

        return $this->fetch("/threads/{$threadId}", [
            'method' => 'PATCH',
            'headers' => ($payload['returnMinimal'] ?? false) ? ['Prefer' => 'return=minimal'] : null,
            'json' => $json,
        ] + self::signalOf($payload));
    }

    /**
     * Delete a thread.
     *
     * @param array{signal?: mixed} $options
     */
    public function delete(string $threadId, array $options = []): void
    {
        $this->fetch("/threads/{$threadId}", ['method' => 'DELETE'] + self::signalOf($options));
    }

    /**
     * Prune threads by ID. `delete` removes them entirely; `keep_latest` prunes old checkpoints but
     * keeps each thread and its latest state.
     *
     * @param list<string>                                              $threadIds
     * @param array{strategy?: 'delete'|'keep_latest', signal?: mixed} $options
     *
     * @return array{pruned_count: int}
     */
    public function prune(array $threadIds, array $options = []): array
    {
        return $this->fetch('/threads/prune', [
            'method' => 'POST',
            'json' => ['thread_ids' => $threadIds, 'strategy' => $options['strategy'] ?? 'delete'],
        ] + self::signalOf($options));
    }

    /**
     * List threads.
     *
     * @param array{metadata?: array<string, mixed>, ids?: list<string>, limit?: int, offset?: int, status?: string, sortBy?: string, sortOrder?: string, select?: list<string>, values?: array<string, mixed>, extract?: array<string, string>, signal?: mixed} $query
     *
     * @return list<Thread>
     */
    public function search(array $query = []): array
    {
        return $this->fetch('/threads/search', [
            'method' => 'POST',
            'json' => self::defined([
                'metadata' => $query['metadata'] ?? null,
                'ids' => $query['ids'] ?? null,
                'limit' => $query['limit'] ?? 10,
                'offset' => $query['offset'] ?? 0,
                'status' => $query['status'] ?? null,
                'sort_by' => $query['sortBy'] ?? null,
                'sort_order' => $query['sortOrder'] ?? null,
                'select' => $query['select'] ?? null,
                'values' => $query['values'] ?? null,
                'extract' => $query['extract'] ?? null,
            ]),
        ] + self::signalOf($query));
    }

    /**
     * Count threads matching the filters.
     *
     * @param array{metadata?: array<string, mixed>, values?: mixed, status?: string, signal?: mixed} $query
     */
    public function count(array $query = []): int
    {
        return $this->fetch('/threads/count', [
            'method' => 'POST',
            'json' => self::defined([
                'metadata' => $query['metadata'] ?? null,
                'values' => $query['values'] ?? null,
                'status' => $query['status'] ?? null,
            ]),
        ] + self::signalOf($query));
    }

    /**
     * Get the state of a thread, optionally at a checkpoint.
     *
     * A checkpoint array is POSTed; a checkpoint-id string uses the deprecated path form. With no
     * checkpoint the read is deduplicated (see {@see BaseClient::fetch()}).
     *
     * @param Checkpoint|string|null                        $checkpoint
     * @param array{subgraphs?: bool, signal?: mixed} $options
     *
     * @return ThreadState
     */
    public function getState(string $threadId, array|string|null $checkpoint = null, array $options = []): array
    {
        if ($checkpoint !== null) {
            if (!is_string($checkpoint)) {
                return $this->fetch("/threads/{$threadId}/state/checkpoint", [
                    'method' => 'POST',
                    'json' => self::defined(['checkpoint' => $checkpoint, 'subgraphs' => $options['subgraphs'] ?? null]),
                ] + self::signalOf($options));
            }

            return $this->fetch("/threads/{$threadId}/state/{$checkpoint}", [
                'params' => ['subgraphs' => $options['subgraphs'] ?? null],
            ] + self::signalOf($options));
        }

        return $this->fetch("/threads/{$threadId}/state", [
            'params' => ['subgraphs' => $options['subgraphs'] ?? null],
            'dedupe' => true,
        ] + self::signalOf($options));
    }

    /**
     * Add state to a thread.
     *
     * @param array{values: mixed, checkpoint?: Checkpoint, checkpointId?: string, asNode?: string, signal?: mixed} $options
     *
     * @return array{configurable?: array<string, mixed>}
     */
    public function updateState(string $threadId, array $options): array
    {
        return $this->fetch("/threads/{$threadId}/state", [
            'method' => 'POST',
            'json' => self::wireFields($options, [
                'values' => 'values',
                'checkpoint' => 'checkpoint',
                'checkpointId' => 'checkpoint_id',
                'asNode' => 'as_node',
            ]),
        ] + self::signalOf($options));
    }

    /**
     * Patch the metadata of a thread, addressed by ID or by a config carrying
     * `configurable.thread_id`.
     *
     * @param string|array{configurable?: array<string, mixed>} $threadIdOrConfig
     * @param array<string, mixed>|null                         $metadata
     * @param array{signal?: mixed}                             $options
     */
    public function patchState(string|array $threadIdOrConfig, ?array $metadata, array $options = []): void
    {
        if (is_string($threadIdOrConfig)) {
            $threadId = $threadIdOrConfig;
        } else {
            $threadId = $threadIdOrConfig['configurable']['thread_id'] ?? null;
            if (!is_string($threadId)) {
                throw new \InvalidArgumentException('Thread ID is required when updating state with a config.');
            }
        }

        $this->fetch("/threads/{$threadId}/state", [
            'method' => 'PATCH',
            'json' => ['metadata' => $metadata],
        ] + self::signalOf($options));
    }

    /**
     * Get all past states for a thread. A read despite being a POST, so it is deduplicated.
     *
     * @param array{limit?: int, before?: array<string, mixed>, checkpoint?: array<string, mixed>, metadata?: array<string, mixed>, signal?: mixed} $options
     *
     * @return list<ThreadState>
     */
    public function getHistory(string $threadId, array $options = []): array
    {
        return $this->fetch("/threads/{$threadId}/history", [
            'method' => 'POST',
            'json' => [
                'limit' => $options['limit'] ?? 10,
            ] + self::wireFields($options, ['before' => 'before', 'metadata' => 'metadata', 'checkpoint' => 'checkpoint']),
            'dedupe' => true,
        ] + self::signalOf($options));
    }

    /**
     * @param int|array{ttl: int, strategy?: string} $ttl
     *
     * @return array{ttl: int, strategy?: string}
     */
    private static function ttlPayload(int|array $ttl): array
    {
        return is_int($ttl) ? ['ttl' => $ttl, 'strategy' => 'delete'] : $ttl;
    }

    /**
     * Drop null entries except the keys in `$keep`.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $keep
     *
     * @return array<string, mixed>
     */
    private static function definedKeys(array $values, array $keep): array
    {
        return array_filter($values, static fn (mixed $v, string $k): bool => $v !== null || in_array($k, $keep, true), \ARRAY_FILTER_USE_BOTH);
    }
}
