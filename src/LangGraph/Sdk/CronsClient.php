<?php

declare(strict_types=1);

namespace LangGraph\Sdk;

/**
 * Port of `client/crons/index.ts`.
 *
 * `CronsCreatePayload` keys: `schedule`, `input`, `config`, `context`, `metadata`, `interruptBefore`,
 * `interruptAfter`, `webhook`, `multitaskStrategy`, `checkpointDuring`, `durability`, `enabled`,
 * `timezone`, `streamMode`, `streamSubgraphs`, `streamResumable`, `endTime`, `onRunCompleted`, `signal`.
 * `CronsUpdatePayload` is the same minus the create-only `multitaskStrategy`/`checkpointDuring`.
 *
 * Only the keys a caller passes are sent. A key passed as null IS sent, as JSON null: that is how
 * `update(['endTime' => null])` clears an end time that is already set.
 *
 * @phpstan-import-type Cron from Schema
 * @phpstan-import-type CronCreateResponse from Schema
 */
class CronsClient extends BaseClient
{
    private const CREATE_FIELDS = [
        'schedule' => 'schedule',
        'input' => 'input',
        'config' => 'config',
        'context' => 'context',
        'metadata' => 'metadata',
        'interruptBefore' => 'interrupt_before',
        'interruptAfter' => 'interrupt_after',
        'webhook' => 'webhook',
        'onRunCompleted' => 'on_run_completed',
        'multitaskStrategy' => 'multitask_strategy',
        'checkpointDuring' => 'checkpoint_during',
        'durability' => 'durability',
        'enabled' => 'enabled',
        'timezone' => 'timezone',
        'streamMode' => 'stream_mode',
        'streamSubgraphs' => 'stream_subgraphs',
        'streamResumable' => 'stream_resumable',
        'endTime' => 'end_time',
    ];

    private const UPDATE_FIELDS = [
        'schedule' => 'schedule',
        'timezone' => 'timezone',
        'endTime' => 'end_time',
        'input' => 'input',
        'metadata' => 'metadata',
        'config' => 'config',
        'context' => 'context',
        'webhook' => 'webhook',
        'interruptBefore' => 'interrupt_before',
        'interruptAfter' => 'interrupt_after',
        'onRunCompleted' => 'on_run_completed',
        'enabled' => 'enabled',
        'streamMode' => 'stream_mode',
        'streamSubgraphs' => 'stream_subgraphs',
        'streamResumable' => 'stream_resumable',
        'durability' => 'durability',
    ];

    /**
     * Create a cron job that runs on an existing thread.
     *
     * @param array<string, mixed> $payload CronsCreatePayload
     *
     * @return CronCreateResponse
     */
    public function createForThread(string $threadId, string $assistantId, array $payload = []): array
    {
        $json = self::wireFields($payload, self::CREATE_FIELDS);
        $json['assistant_id'] = $assistantId;

        return $this->fetch("/threads/{$threadId}/runs/crons", [
            'method' => 'POST',
            'json' => $json,
        ] + self::signalOf($payload));
    }

    /**
     * Create a stateless cron job.
     *
     * @param array<string, mixed> $payload CronsCreatePayload
     *
     * @return CronCreateResponse
     */
    public function create(string $assistantId, array $payload = []): array
    {
        $json = self::wireFields($payload, self::CREATE_FIELDS);
        $json['assistant_id'] = $assistantId;

        return $this->fetch('/runs/crons', [
            'method' => 'POST',
            'json' => $json,
        ] + self::signalOf($payload));
    }

    /**
     * Update a cron job by ID.
     *
     * @param array<string, mixed> $payload CronsUpdatePayload
     *
     * @return Cron
     */
    public function update(string $cronId, array $payload = []): array
    {
        return $this->fetch("/runs/crons/{$cronId}", [
            'method' => 'PATCH',
            'json' => self::wireFields($payload, self::UPDATE_FIELDS),
        ] + self::signalOf($payload));
    }

    /**
     * Delete a cron job by ID.
     *
     * @param array{signal?: mixed} $options
     */
    public function delete(string $cronId, array $options = []): void
    {
        $this->fetch("/runs/crons/{$cronId}", ['method' => 'DELETE'] + self::signalOf($options));
    }

    /**
     * Search cron jobs. `metadata` is an exact match per key (Agent Server 0.9.0+).
     *
     * @param array{assistantId?: string, threadId?: string, enabled?: bool, limit?: int, offset?: int, sortBy?: string, sortOrder?: string, select?: list<string>, metadata?: array<string, mixed>, signal?: mixed} $query
     *
     * @return list<Cron>
     */
    public function search(array $query = []): array
    {
        return $this->fetch('/runs/crons/search', [
            'method' => 'POST',
            'json' => self::defined([
                'assistant_id' => $query['assistantId'] ?? null,
                'thread_id' => $query['threadId'] ?? null,
                'enabled' => $query['enabled'] ?? null,
                'limit' => $query['limit'] ?? 10,
                'offset' => $query['offset'] ?? 0,
                'sort_by' => $query['sortBy'] ?? null,
                'sort_order' => $query['sortOrder'] ?? null,
                'select' => $query['select'] ?? null,
                'metadata' => $query['metadata'] ?? null,
            ]),
        ] + self::signalOf($query));
    }

    /**
     * Count cron jobs matching the filters.
     *
     * @param array{assistantId?: string, threadId?: string, metadata?: array<string, mixed>, signal?: mixed} $query
     */
    public function count(array $query = []): int
    {
        return $this->fetch('/runs/crons/count', [
            'method' => 'POST',
            'json' => self::defined([
                'assistant_id' => $query['assistantId'] ?? null,
                'thread_id' => $query['threadId'] ?? null,
                'metadata' => $query['metadata'] ?? null,
            ]),
        ] + self::signalOf($query));
    }
}
