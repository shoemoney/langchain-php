<?php

declare(strict_types=1);

namespace LangGraph\Sdk;

/**
 * Port of `schema.ts`: the wire types of the LangGraph REST API.
 *
 * TypeScript interfaces have no runtime form and PHP arrays carry the JSON as the server sent it, so
 * the object shapes live here as `@phpstan-type` docblocks and the closed string unions (the only
 * part of `schema.ts` that exists at runtime) as constants. The clients do not validate against
 * these — upstream does not either; the server is the authority.
 *
 * @phpstan-type Config array{tags?: list<string>, recursion_limit?: int, configurable?: array<string, mixed>}
 * @phpstan-type Metadata array<string, mixed>|null
 * @phpstan-type GraphSchema array{graph_id: string, input_schema?: array<string, mixed>|null, output_schema?: array<string, mixed>|null, state_schema?: array<string, mixed>|null, config_schema?: array<string, mixed>|null, context_schema?: array<string, mixed>|null}
 * @phpstan-type Subgraphs array<string, GraphSchema>
 * @phpstan-type AssistantBase array{assistant_id: string, graph_id: string, config: Config, context: mixed, created_at: string, metadata: Metadata, version: int, name: string, description?: string}
 * @phpstan-type Assistant AssistantBase&array{updated_at: string}
 * @phpstan-type AssistantVersion AssistantBase
 * @phpstan-type AssistantsSearchResponse array{assistants: list<Assistant>, next: string|null}
 * @phpstan-type AssistantGraph array{nodes: list<array{id: string|int, name?: string, data?: array<string, mixed>|string, metadata?: mixed}>, edges: list<array{source: string, target: string, data?: string, conditional?: bool}>}
 * @phpstan-type Interrupt array{id?: string, value?: mixed, response_schema?: array<string, mixed>, namespace?: list<string>, when?: string, resumable?: bool, ns?: list<string>}
 * @phpstan-type Checkpoint array{thread_id: string, checkpoint_ns: string, checkpoint_id: string|null, checkpoint_map: array<string, mixed>|null}
 * @phpstan-type ThreadTask array{id: string, name: string, result?: mixed, error: string|null, interrupts: list<Interrupt>, checkpoint: Checkpoint|null, state: array<string, mixed>|null}
 * @phpstan-type ThreadState array{values: mixed, next: list<string>, checkpoint: Checkpoint, metadata: Metadata, created_at: string|null, parent_checkpoint: Checkpoint|null, tasks: list<ThreadTask>}
 * @phpstan-type Thread array{thread_id: string, created_at: string, updated_at: string, state_updated_at: string, metadata: Metadata, status: string, values: mixed, interrupts: array<string, list<Interrupt>>, config?: Config, error?: string|array<string, mixed>|null, extracted?: array<string, mixed>}
 * @phpstan-type Cron array{cron_id: string, assistant_id: string, thread_id: string|null, on_run_completed?: string, end_time: string|null, schedule: string, timezone: string|null, created_at: string, updated_at: string, payload: array<string, mixed>, user_id: string|null, next_run_date: string|null, metadata: array<string, mixed>, enabled: bool}
 * @phpstan-type CronCreateResponse array{cron_id: string, assistant_id: string, thread_id: string|null, user_id: string, payload: array<string, mixed>, schedule: string, next_run_date: string, end_time: string|null, created_at: string, updated_at: string, metadata: Metadata}
 * @phpstan-type Item array{namespace: list<string>, key: string, value: array<string, mixed>, created_at: string, updated_at: string, createdAt: string, updatedAt: string}
 * @phpstan-type SearchItem Item&array{score?: float}
 * @phpstan-type SearchItemsResponse array{items: list<SearchItem>}
 * @phpstan-type ListNamespaceResponse array{namespaces: list<list<string>>}
 */
final class Schema
{
    public const RUN_STATUS = ['pending', 'running', 'error', 'success', 'timeout', 'interrupted'];

    public const THREAD_STATUS = ['idle', 'busy', 'interrupted', 'error'];

    public const MULTITASK_STRATEGY = ['reject', 'interrupt', 'rollback', 'enqueue'];

    public const CANCEL_ACTION = ['interrupt', 'rollback'];

    public const SORT_ORDER = ['asc', 'desc'];

    public const ASSISTANT_SORT_BY = ['assistant_id', 'graph_id', 'name', 'created_at', 'updated_at'];

    public const THREAD_SORT_BY = ['thread_id', 'status', 'created_at', 'updated_at', 'state_updated_at'];

    public const CRON_SORT_BY = ['cron_id', 'assistant_id', 'thread_id', 'created_at', 'updated_at', 'next_run_date'];

    public const ASSISTANT_SELECT_FIELD = [
        'assistant_id', 'graph_id', 'name', 'description', 'config', 'context', 'created_at', 'updated_at', 'metadata', 'version',
    ];

    public const THREAD_SELECT_FIELD = [
        'thread_id', 'created_at', 'updated_at', 'state_updated_at', 'metadata', 'config', 'context', 'status', 'values', 'interrupts',
    ];

    public const RUN_SELECT_FIELD = [
        'run_id', 'thread_id', 'assistant_id', 'created_at', 'updated_at', 'status', 'metadata', 'kwargs', 'multitask_strategy',
    ];

    public const CRON_SELECT_FIELD = [
        'cron_id', 'assistant_id', 'thread_id', 'end_time', 'schedule', 'created_at', 'updated_at', 'user_id', 'payload',
        'next_run_date', 'metadata', 'now', 'timezone', 'enabled', 'on_run_completed',
    ];
}
