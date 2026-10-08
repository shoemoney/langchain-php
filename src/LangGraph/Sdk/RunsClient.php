<?php

declare(strict_types=1);

namespace LangGraph\Sdk;

/**
 * Port of `client/runs/index.ts`.
 *
 * Payload arrays use the camelCase names of the JS option objects (`streamMode`, `interruptBefore`,
 * `checkpointId`, ...); a key that is ABSENT is omitted from the request, one present with null is sent
 * as null (see {@see BaseClient::wireFields()}).
 *
 * ## Streaming
 *
 * {@see self::stream()} and {@see self::joinStream()} return a `\Generator` of parts
 * `{id, event, data}`, pulled lazily as the transport delivers. `signal` is a `callable(): bool`
 * (see {@see Utils\Signals}), polled between reads. `streamIdleReconnect` is an int ms window, `'auto'`
 * (default) or `0`; the transport and clock behaviour is described on {@see StreamsWithRetry}.
 *
 * @phpstan-import-type Run from Schema
 */
class RunsClient extends BaseClient
{
    use StreamsWithRetry;

    /** payload key => wire key, shared by stream/create/wait where upstream spells them the same. */
    private const COMMON = [
        'input' => 'input',
        'command' => 'command',
        'config' => 'config',
        'context' => 'context',
        'metadata' => 'metadata',
        'interruptBefore' => 'interrupt_before',
        'interruptAfter' => 'interrupt_after',
        'checkpoint' => 'checkpoint',
        'checkpointId' => 'checkpoint_id',
        'webhook' => 'webhook',
        'multitaskStrategy' => 'multitask_strategy',
        'onCompletion' => 'on_completion',
        'afterSeconds' => 'after_seconds',
        'ifNotExists' => 'if_not_exists',
        'checkpointDuring' => 'checkpoint_during',
        'durability' => 'durability',
    ];

    private const STREAMING = [
        'streamMode' => 'stream_mode',
        'streamSubgraphs' => 'stream_subgraphs',
        'streamResumable' => 'stream_resumable',
        'feedbackKeys' => 'feedback_keys',
    ];

    /**
     * Create a run and stream the results. A null `$threadId` runs statelessly.
     *
     * Payload: the create fields plus `onDisconnect`, `signal`, `streamIdleReconnect`,
     * `onRunCreated` `callable(array{run_id: string, thread_id: string|null}): void`.
     *
     * @param array<string, mixed> $payload
     *
     * @return \Generator<int, array{id: string|null, event: string, data: mixed}>
     */
    public function stream(?string $threadId, string $assistantId, array $payload = []): \Generator
    {
        $json = self::wireFields($payload, self::COMMON + self::STREAMING + ['onDisconnect' => 'on_disconnect'])
            + ['assistant_id' => $assistantId];

        return $this->streamWithRetry([
            'endpoint' => $threadId === null ? '/runs/stream' : "/threads/{$threadId}/runs/stream",
            'method' => 'POST',
            'json' => self::objectify($json),
            'signal' => $payload['signal'] ?? null,
            'idleReconnect' => $payload['streamIdleReconnect'] ?? null,
            'onInitialResponse' => function ($response) use ($payload): void {
                $runMetadata = self::getRunMetadataFromResponse($response);
                if ($runMetadata !== null && isset($payload['onRunCreated'])) {
                    ($payload['onRunCreated'])($runMetadata);
                }
            },
        ]);
    }

    /**
     * Create a run.
     *
     * Payload also takes `_langsmithTracer` `{projectName, exampleId}`, `signal`, `onRunCreated`.
     *
     * @param array<string, mixed> $payload
     *
     * @return Run
     */
    public function create(?string $threadId, string $assistantId, array $payload = []): array
    {
        $json = self::wireFields($payload, self::COMMON + self::STREAMING)
            + ['assistant_id' => $assistantId]
            + self::tracerFields($payload);

        $endpoint = $threadId === null ? '/runs' : "/threads/{$threadId}/runs";
        [$run, $response] = $this->fetch($endpoint, [
            'method' => 'POST',
            'json' => self::objectify($json),
            'withResponse' => true,
        ] + self::signalOf($payload));

        $runMetadata = self::getRunMetadataFromResponse($response);
        if ($runMetadata !== null && isset($payload['onRunCreated'])) {
            ($payload['onRunCreated'])($runMetadata);
        }

        return $run;
    }

    /**
     * Create a batch of stateless background runs.
     *
     * Faithful to upstream, each payload is sent with its keys as given (camelCase stays camelCase)
     * plus `assistant_id`; only null entries are dropped.
     *
     * @param list<array<string, mixed>> $payloads each with an `assistantId`
     * @param array{signal?: mixed}      $options
     *
     * @return list<Run>
     */
    public function createBatch(array $payloads, array $options = []): array
    {
        $filtered = array_map(
            static fn (array $payload): array => self::defined($payload + ['assistant_id' => $payload['assistantId'] ?? null]),
            $payloads,
        );

        return $this->fetch('/runs/batch', ['method' => 'POST', 'json' => $filtered] + self::signalOf($options));
    }

    /**
     * Create a run and wait for it to complete.
     *
     * With `raiseError` (default true) a `__error__` entry in the result is thrown.
     *
     * @param array<string, mixed> $payload
     *
     * @return mixed The last values chunk of the thread.
     */
    public function wait(?string $threadId, string $assistantId, array $payload = []): mixed
    {
        $json = self::wireFields($payload, self::COMMON + ['onDisconnect' => 'on_disconnect'])
            + ['assistant_id' => $assistantId]
            + self::tracerFields($payload);

        $endpoint = $threadId === null ? '/runs/wait' : "/threads/{$threadId}/runs/wait";
        [$run, $response] = $this->fetch($endpoint, [
            'method' => 'POST',
            'json' => self::objectify($json),
            'timeoutMs' => null,
            'withResponse' => true,
        ] + self::signalOf($payload));

        $runMetadata = self::getRunMetadataFromResponse($response);
        if ($runMetadata !== null && isset($payload['onRunCreated'])) {
            ($payload['onRunCreated'])($runMetadata);
        }

        $error = is_array($run) ? ($run['__error__'] ?? null) : null;
        if (($payload['raiseError'] ?? true) && is_array($error) && array_key_exists('error', $error) && array_key_exists('message', $error)) {
            throw new \RuntimeException("{$error['error']}: {$error['message']}");
        }

        return $run;
    }

    /**
     * List all runs for a thread.
     *
     * @param array{limit?: int, offset?: int, status?: string, select?: list<string>, signal?: mixed} $options
     *
     * @return list<Run>
     */
    public function list(string $threadId, array $options = []): array
    {
        return $this->fetch("/threads/{$threadId}/runs", [
            'params' => [
                'limit' => $options['limit'] ?? 10,
                'offset' => $options['offset'] ?? 0,
                'status' => $options['status'] ?? null,
                'select' => $options['select'] ?? null,
            ],
        ] + self::signalOf($options));
    }

    /**
     * Get a run by ID.
     *
     * @param array{signal?: mixed} $options
     *
     * @return Run
     */
    public function get(string $threadId, string $runId, array $options = []): array
    {
        return $this->fetch("/threads/{$threadId}/runs/{$runId}", self::signalOf($options));
    }

    /**
     * Cancel a run. `$action` is `interrupt` (default) or `rollback`.
     *
     * @param array{signal?: mixed} $options
     */
    public function cancel(string $threadId, string $runId, bool $wait = false, string $action = 'interrupt', array $options = []): void
    {
        $this->fetch("/threads/{$threadId}/runs/{$runId}/cancel", [
            'method' => 'POST',
            'params' => ['wait' => $wait ? '1' : '0', 'action' => $action],
        ] + self::signalOf($options));
    }

    /**
     * Cancel one or more runs.
     *
     * @param array{threadId?: string, runIds?: list<string>, status?: 'pending'|'running'|'all', action?: string, signal?: mixed} $options
     */
    public function cancelMany(array $options): void
    {
        $this->fetch('/runs/cancel', [
            'method' => 'POST',
            'json' => self::defined([
                'thread_id' => $options['threadId'] ?? null,
                'run_ids' => $options['runIds'] ?? null,
                'status' => $options['status'] ?? null,
            ]) ?: new \stdClass(),
            'params' => ['action' => $options['action'] ?? null],
        ] + self::signalOf($options));
    }

    /**
     * Block until a run is done.
     *
     * @param array{cancelOnDisconnect?: bool, signal?: mixed} $options
     */
    public function join(string $threadId, string $runId, array $options = []): mixed
    {
        return $this->fetch("/threads/{$threadId}/runs/{$runId}/join", [
            'timeoutMs' => null,
            'params' => ['cancel_on_disconnect' => ($options['cancelOnDisconnect'] ?? false) ? '1' : '0'],
        ] + self::signalOf($options));
    }

    /**
     * Stream output from a run in real time, until the run is done.
     *
     * `$options` may also be a bare signal closure, as upstream accepts a bare `AbortSignal`.
     * `lastEventId` resumes after that event. `streamIdleReconnect`: int ms, `'auto'` (default) or 0.
     *
     * @param array{signal?: mixed, cancelOnDisconnect?: bool, lastEventId?: string, streamMode?: string|list<string>, streamIdleReconnect?: int|string}|\Closure|null $options
     *
     * @return \Generator<int, array{id: string|null, event: string, data: mixed}>
     */
    public function joinStream(?string $threadId, string $runId, array|\Closure|null $options = null): \Generator
    {
        $opts = $options instanceof \Closure ? ['signal' => $options] : ($options ?? []);
        $lastEventId = $opts['lastEventId'] ?? '';

        return $this->streamWithRetry([
            'endpoint' => $threadId !== null ? "/threads/{$threadId}/runs/{$runId}/stream" : "/runs/{$runId}/stream",
            'method' => 'GET',
            'signal' => $opts['signal'] ?? null,
            'idleReconnect' => $opts['streamIdleReconnect'] ?? null,
            'headers' => $lastEventId !== '' ? ['Last-Event-ID' => $lastEventId] : null,
            'params' => [
                'cancel_on_disconnect' => ($opts['cancelOnDisconnect'] ?? false) ? '1' : '0',
                'stream_mode' => $opts['streamMode'] ?? null,
            ],
        ]);
    }

    /**
     * Delete a run.
     *
     * @param array{signal?: mixed} $options
     */
    public function delete(string $threadId, string $runId, array $options = []): void
    {
        $this->fetch("/threads/{$threadId}/runs/{$runId}", ['method' => 'DELETE'] + self::signalOf($options));
    }

    /**
     * `config`, `context` and `metadata` are JSON objects: an empty PHP array would encode as `[]`.
     *
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private static function objectify(array $json): array
    {
        foreach (['config', 'context', 'metadata'] as $key) {
            if (array_key_exists($key, $json) && $json[$key] === []) {
                $json[$key] = new \stdClass();
            }
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{langsmith_tracer?: array{project_name: mixed, example_id: mixed}}
     */
    private static function tracerFields(array $payload): array
    {
        $tracer = $payload['_langsmithTracer'] ?? null;

        return $tracer ? ['langsmith_tracer' => ['project_name' => $tracer['projectName'] ?? null, 'example_id' => $tracer['exampleId'] ?? null]] : [];
    }
}
