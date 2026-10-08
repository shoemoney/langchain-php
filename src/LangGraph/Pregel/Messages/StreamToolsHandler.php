<?php

declare(strict_types=1);

namespace LangGraph\Pregel\Messages;

use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Tracers\Serialized;
use LangGraph\Pregel\Constants;

/**
 * A callback handler that implements `streamMode: 'tools'`.
 *
 * Port of `StreamToolsHandler` from `langgraph-core/src/pregel/stream.ts`.
 *
 * Emits `on_tool_start`, `on_tool_event`, `on_tool_end` and `on_tool_error`
 * events. A tool run is remembered from its start so the later events can be
 * attributed to the same namespace, tool call id and name; once a run ends or
 * fails it is forgotten, so a straggling event for it is dropped rather than
 * emitted without context.
 *
 * `$streamFn` receives upstream's `[namespace, 'tools', event]` triple.
 */
class StreamToolsHandler extends BaseCallbackHandler
{
    public string $name = 'StreamToolsHandler';

    /** @var callable(array{0: list<string>, 1: string, 2: array<string, mixed>}): void */
    public $streamFn;

    /**
     * @var array<string, array{ns: list<string>, toolCallId: ?string, toolName: string, input: mixed}>
     */
    public array $runs = [];

    /**
     * @param callable(array{0: list<string>, 1: string, 2: array<string, mixed>}): void $streamFn
     */
    public function __construct(callable $streamFn)
    {
        parent::__construct();
        $this->streamFn = $streamFn;
        // Tool lifecycle callbacks must run before the tool's invoke returns or fails.
        $this->awaitHandlers = true;
    }

    /**
     * `$metadata` is nullable, which the base signature is not: upstream tells
     * "no metadata at all" (a tool run outside any graph; not streamed) from an
     * empty object (a run inside one with no namespace; streamed under `[]`).
     *
     * @param array<string, mixed>|string $input
     * @param list<string>                $tags
     * @param array<string, mixed>|null   $metadata
     */
    public function handleToolStart(
        Serialized $tool,
        array|string $input,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        ?array $metadata = null,
        ?string $runName = null,
        ?string $toolCallId = null,
    ): void {
        if ($metadata === null || in_array(Constants::TAG_HIDDEN, $tags, true)) {
            return;
        }

        $namespace = $metadata['langgraph_checkpoint_ns'] ?? null;
        $ns = is_string($namespace) ? explode('|', $namespace) : [];

        $this->runs[$runId] = [
            'ns' => $ns,
            'toolCallId' => $toolCallId,
            'toolName' => $runName ?? 'unknown',
            'input' => $input,
        ];

        ($this->streamFn)([$ns, 'tools', [
            'event' => 'on_tool_start',
            'toolCallId' => $toolCallId,
            'name' => $this->runs[$runId]['toolName'],
            'input' => $input,
        ]]);
    }

    /**
     * @param list<string> $tags
     */
    public function handleToolEvent(
        mixed $chunk,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
        $info = $this->runs[$runId] ?? null;
        if ($info === null) {
            return;
        }

        ($this->streamFn)([$info['ns'], 'tools', [
            'event' => 'on_tool_event',
            'toolCallId' => $info['toolCallId'],
            'name' => $info['toolName'],
            'data' => $chunk,
        ]]);
    }

    /**
     * @param list<string> $tags
     */
    public function handleToolEnd(
        mixed $output,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
        $info = $this->runs[$runId] ?? null;
        unset($this->runs[$runId]);
        if ($info === null) {
            return;
        }

        ($this->streamFn)([$info['ns'], 'tools', [
            'event' => 'on_tool_end',
            'toolCallId' => $info['toolCallId'],
            'name' => $info['toolName'],
            'output' => $output,
        ]]);
    }

    /**
     * @param list<string> $tags
     */
    public function handleToolError(
        \Throwable $error,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
        $info = $this->runs[$runId] ?? null;
        unset($this->runs[$runId]);
        if ($info === null) {
            return;
        }

        ($this->streamFn)([$info['ns'], 'tools', [
            'event' => 'on_tool_error',
            'toolCallId' => $info['toolCallId'],
            'name' => $info['toolName'],
            'error' => $error,
        ]]);
    }
}
