<?php

declare(strict_types=1);

namespace LangGraph\Sdk;

/**
 * Port of `client/assistants/index.ts`.
 *
 * Every method takes an optional `signal` (carried on the request, see {@see BaseClient}). Return
 * shapes are the wire arrays documented in {@see Schema}.
 *
 * @phpstan-import-type Assistant from Schema
 * @phpstan-import-type AssistantGraph from Schema
 * @phpstan-import-type AssistantVersion from Schema
 * @phpstan-import-type AssistantsSearchResponse from Schema
 * @phpstan-import-type GraphSchema from Schema
 * @phpstan-import-type Subgraphs from Schema
 */
class AssistantsClient extends BaseClient
{
    /**
     * Get an assistant by ID.
     *
     * @param array{signal?: mixed} $options
     *
     * @return Assistant
     */
    public function get(string $assistantId, array $options = []): array
    {
        return $this->fetch("/assistants/{$assistantId}", self::signalOf($options));
    }

    /**
     * Get the JSON representation of the graph assigned to a runnable.
     *
     * `xray` includes subgraphs; an integer limits the depth.
     *
     * @param array{xray?: bool|int, signal?: mixed} $options
     *
     * @return AssistantGraph
     */
    public function getGraph(string $assistantId, array $options = []): array
    {
        return $this->fetch("/assistants/{$assistantId}/graph", [
            'params' => ['xray' => $options['xray'] ?? null],
        ] + self::signalOf($options));
    }

    /**
     * Get the state and config schema of the graph assigned to a runnable.
     *
     * @param array{signal?: mixed} $options
     *
     * @return GraphSchema
     */
    public function getSchemas(string $assistantId, array $options = []): array
    {
        return $this->fetch("/assistants/{$assistantId}/schemas", self::signalOf($options));
    }

    /**
     * Get the subgraphs of an assistant, optionally under a namespace and/or recursively.
     *
     * @param array{namespace?: string, recurse?: bool, signal?: mixed} $options
     *
     * @return Subgraphs
     */
    public function getSubgraphs(string $assistantId, array $options = []): array
    {
        $namespace = $options['namespace'] ?? null;
        $path = $namespace !== null && $namespace !== ''
            ? "/assistants/{$assistantId}/subgraphs/{$namespace}"
            : "/assistants/{$assistantId}/subgraphs";

        return $this->fetch($path, [
            'params' => ['recurse' => $options['recurse'] ?? null],
        ] + self::signalOf($options));
    }

    /**
     * Create a new assistant.
     *
     * @param array{graphId: string, config?: array<string, mixed>, context?: mixed, metadata?: array<string, mixed>|null, assistantId?: string, ifExists?: string, name?: string, description?: string, signal?: mixed} $payload
     *
     * @return Assistant
     */
    public function create(array $payload): array
    {
        return $this->fetch('/assistants', [
            'method' => 'POST',
            'json' => self::wireFields($payload, [
                'graphId' => 'graph_id',
                'config' => 'config',
                'context' => 'context',
                'metadata' => 'metadata',
                'assistantId' => 'assistant_id',
                'ifExists' => 'if_exists',
                'name' => 'name',
                'description' => 'description',
            ]),
        ] + self::signalOf($payload));
    }

    /**
     * Update an assistant.
     *
     * @param array{graphId?: string, config?: array<string, mixed>, context?: mixed, metadata?: array<string, mixed>|null, name?: string, description?: string, signal?: mixed} $payload
     *
     * @return Assistant
     */
    public function update(string $assistantId, array $payload = []): array
    {
        return $this->fetch("/assistants/{$assistantId}", [
            'method' => 'PATCH',
            'json' => self::wireFields($payload, [
                'graphId' => 'graph_id',
                'config' => 'config',
                'context' => 'context',
                'metadata' => 'metadata',
                'name' => 'name',
                'description' => 'description',
            ]),
        ] + self::signalOf($payload));
    }

    /**
     * Delete an assistant. `deleteThreads` also deletes every thread whose
     * `metadata.assistant_id` is this assistant; it defaults to false.
     *
     * @param array{deleteThreads?: bool, signal?: mixed} $options
     */
    public function delete(string $assistantId, array $options = []): void
    {
        $deleteThreads = ($options['deleteThreads'] ?? false) ? 'true' : 'false';

        $this->fetch("/assistants/{$assistantId}?delete_threads={$deleteThreads}", [
            'method' => 'DELETE',
        ] + self::signalOf($options));
    }

    /**
     * List assistants.
     *
     * Returns the list, or with `includePagination` a map of the assistants and the
     * `X-Pagination-Next` cursor (null when the header is absent).
     *
     * @param array{graphId?: string, name?: string, metadata?: array<string, mixed>, limit?: int, offset?: int, sortBy?: string, sortOrder?: string, select?: list<string>, includePagination?: bool, signal?: mixed} $query
     *
     * @return list<Assistant>|AssistantsSearchResponse
     */
    public function search(array $query = []): array
    {
        $json = self::defined([
            'graph_id' => $query['graphId'] ?? null,
            'name' => $query['name'] ?? null,
            'metadata' => $query['metadata'] ?? null,
            'limit' => $query['limit'] ?? 10,
            'offset' => $query['offset'] ?? 0,
            'sort_by' => $query['sortBy'] ?? null,
            'sort_order' => $query['sortOrder'] ?? null,
            'select' => $query['select'] ?? null,
        ]);

        [$assistants, $response] = $this->fetch('/assistants/search', [
            'method' => 'POST',
            'json' => $json,
            'withResponse' => true,
        ] + self::signalOf($query));

        if ($query['includePagination'] ?? false) {
            return ['assistants' => $assistants, 'next' => $response->header('X-Pagination-Next')];
        }

        return $assistants;
    }

    /**
     * Count assistants matching the filters (exact match per metadata key/value).
     *
     * @param array{metadata?: array<string, mixed>, graphId?: string, name?: string, signal?: mixed} $query
     */
    public function count(array $query = []): int
    {
        return $this->fetch('/assistants/count', [
            'method' => 'POST',
            'json' => self::defined([
                'metadata' => $query['metadata'] ?? null,
                'graph_id' => $query['graphId'] ?? null,
                'name' => $query['name'] ?? null,
            ]),
        ] + self::signalOf($query));
    }

    /**
     * List all versions of an assistant.
     *
     * @param array{metadata?: array<string, mixed>, limit?: int, offset?: int, signal?: mixed} $payload
     *
     * @return list<AssistantVersion>
     */
    public function getVersions(string $assistantId, array $payload = []): array
    {
        return $this->fetch("/assistants/{$assistantId}/versions", [
            'method' => 'POST',
            'json' => self::defined([
                'metadata' => $payload['metadata'] ?? null,
                'limit' => $payload['limit'] ?? 10,
                'offset' => $payload['offset'] ?? 0,
            ]),
        ] + self::signalOf($payload));
    }

    /**
     * Change the version of an assistant.
     *
     * @param array{signal?: mixed} $options
     *
     * @return Assistant
     */
    public function setLatest(string $assistantId, int $version, array $options = []): array
    {
        return $this->fetch("/assistants/{$assistantId}/latest", [
            'method' => 'POST',
            'json' => ['version' => $version],
        ] + self::signalOf($options));
    }
}
