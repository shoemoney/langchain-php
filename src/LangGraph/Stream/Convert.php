<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * Protocol event conversion: maps raw `[namespace, mode, payload]` stream chunks to protocol events.
 *
 * Port of `stream/convert.ts` (`convertToProtocolEvent`, `isCheckpointEnvelope`,
 * `STREAM_EVENTS_V3_MODES`). Events are arrays, see {@see Types}.
 */
final class Convert
{
    /**
     * Every mode the protocol maps to a channel. `debug` is excluded (a re-wrap of `checkpoints` + `tasks`),
     * and so is `checkpoints`: its protocol channel carries a lightweight envelope emitted as its own chunk,
     * not Pregel's full-state `checkpoints` payload.
     *
     * @var list<string>
     */
    public const STREAM_EVENTS_V3_MODES = ['values', 'updates', 'messages', 'tools', 'custom', 'tasks'];

    private function __construct()
    {
    }

    /**
     * True when `$payload` is a lightweight checkpoint envelope (not a full-state Pregel `checkpoints`
     * debug payload).
     */
    public static function isCheckpointEnvelope(mixed $payload): bool
    {
        if (!\is_array($payload)) {
            return false;
        }

        return isset($payload['id']) && \is_string($payload['id'])
            && (\array_key_exists('source', $payload) || (isset($payload['step']) && \is_int($payload['step'])))
            && !\array_key_exists('values', $payload)
            && !\array_key_exists('config', $payload);
    }

    /**
     * Convert one chunk into zero or more protocol events.
     *
     * @param list<string> $namespace
     * @return list<array<string, mixed>>
     */
    public static function toProtocolEvent(array $namespace, string $mode, mixed $payload, int $seq): array
    {
        $timestamp = Types::now();
        $make = static function (string $method, mixed $data, ?string $node = null) use ($namespace, $seq, $timestamp): array {
            return Types::protocolEvent($method, $namespace, $data, $node, $seq, $timestamp);
        };

        switch ($mode) {
            case 'messages':
                ['data' => $data, 'node' => $node] = self::unwrapMessagesPayload($payload);

                return [$make('messages', $data, $node !== null && $node !== '' ? $node : null)];

            case 'tools':
                return [$make('tools', self::convertToolsPayload($payload))];

            case 'checkpoints':
                return self::isCheckpointEnvelope($payload) ? [$make('checkpoints', $payload)] : [];

            case 'values':
                return [$make('values', $payload)];

            case 'updates':
                $data = self::convertUpdatesPayload($payload);

                // The completed node is also surfaced at the top level of `params` so transformers
                // (notably the lifecycle one) can attribute a finished task to its child namespace.
                return [$make('updates', $data, isset($data['node']) && \is_string($data['node']) ? $data['node'] : null)];

            case 'custom':
                $data = \is_array($payload) && !array_is_list($payload) && \array_key_exists('name', $payload)
                    ? $payload
                    : ['payload' => $payload];

                return [$make('custom', $data)];

            case 'tasks':
                return [$make('tasks', $payload)];

            default:
                return [];
        }
    }

    /**
     * @return array{data: mixed, node?: string|null}
     */
    private static function unwrapMessagesPayload(mixed $payload): array
    {
        if (!\is_array($payload) || !array_is_list($payload) || \count($payload) !== 2) {
            return ['data' => $payload, 'node' => null];
        }

        [$data, $metadata] = $payload;
        if (!\is_array($metadata)) {
            return ['data' => $payload, 'node' => null];
        }

        $node = isset($metadata['langgraph_node']) && \is_string($metadata['langgraph_node']) ? $metadata['langgraph_node'] : null;
        $runId = isset($metadata['run_id']) && \is_string($metadata['run_id']) ? $metadata['run_id'] : null;
        if ($runId !== null && \is_array($data)) {
            $data['run_id'] = $runId;
        }

        return ['data' => $data, 'node' => $node];
    }

    /**
     * Normalise a raw tools-mode payload into a protocol `tools` event body.
     *
     * @return array<string, mixed>
     */
    private static function convertToolsPayload(mixed $payload): array
    {
        if (!\is_array($payload)) {
            return ['event' => 'tool-error', 'tool_call_id' => '', 'message' => 'Unexpected tools payload shape'];
        }

        $toolCallId = self::stringify($payload['toolCallId'] ?? '');
        $event = $payload['event'] ?? null;

        switch ($event) {
            case 'on_tool_start':
                return [
                    'event' => 'tool-started',
                    'tool_call_id' => $toolCallId,
                    'tool_name' => self::stringify($payload['name'] ?? 'unknown'),
                    'input' => $payload['input'] ?? null,
                ];

            case 'on_tool_event':
                $data = $payload['data'] ?? '';

                return [
                    'event' => 'tool-output-delta',
                    'tool_call_id' => $toolCallId,
                    'delta' => \is_string($data) ? $data : (json_encode($data) ?: ''),
                ];

            case 'on_tool_end':
                return ['event' => 'tool-finished', 'tool_call_id' => $toolCallId, 'output' => $payload['output'] ?? null];

            case 'on_tool_error':
                $error = $payload['error'] ?? null;
                if ($error instanceof \Throwable) {
                    $message = $error->getMessage();
                } elseif (\is_array($error) && isset($error['message']) && \is_string($error['message'])) {
                    $message = $error['message'];
                } else {
                    $message = self::stringify($error ?? 'unknown error');
                }

                return ['event' => 'tool-error', 'tool_call_id' => $toolCallId, 'message' => $message];

            default:
                return [
                    'event' => 'tool-error',
                    'tool_call_id' => '',
                    'message' => 'Unknown tool event: ' . self::stringify($event),
                ];
        }
    }

    /**
     * Reshape the first `{node: delta}` entry of an updates payload into `{node, values}`.
     *
     * @return array<string, mixed>
     */
    private static function convertUpdatesPayload(mixed $payload): array
    {
        if (!\is_array($payload) || $payload === []) {
            return ['values' => []];
        }

        $node = array_key_first($payload);
        $values = $payload[$node];

        return [
            'node' => (string) $node,
            'values' => \is_array($values) ? $values : ['value' => $values],
        ];
    }

    private static function stringify(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return json_encode($value) ?: get_debug_type($value);
    }
}
