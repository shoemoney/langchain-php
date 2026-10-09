<?php

declare(strict_types=1);

namespace LangGraph\Agents\Transformers;

use LangChain\Messages\ToolMessage;
use LangGraph\Stream\AbstractStreamTransformer;
use LangGraph\Stream\Deferred;
use LangGraph\Stream\NativeStreamTransformer;
use LangGraph\Stream\StreamChannel;

/**
 * Correlates `tools` channel events into per-call {@see ToolCallStream} objects.
 *
 * Port of `createToolCallTransformer` from `langchain/src/agents/transformers/tool-call.ts`; the factory is
 * {@see self::factory()} and the instance it makes is the transformer. Its projection is `toolCalls`.
 */
final class ToolCallTransformer extends AbstractStreamTransformer implements NativeStreamTransformer
{
    private StreamChannel $toolCallsLog;

    /** @var array<string, array{output: Deferred, status: Deferred, error: Deferred}> */
    private array $pendingCalls = [];

    /**
     * @param list<string> $path the namespace of the agent's own graph
     */
    public function __construct(private readonly array $path = [])
    {
        $this->toolCallsLog = StreamChannel::local();
    }

    /**
     * Upstream's `createToolCallTransformer(path)`: a factory for the transformer.
     *
     * @param list<string> $path
     * @return \Closure(): self
     */
    public static function factory(array $path = []): \Closure
    {
        return static fn (): self => new self($path);
    }

    public function init(): array
    {
        return ['toolCalls' => $this->toolCallsLog];
    }

    public function process(array $event): bool
    {
        $namespace = $event['params']['namespace'];

        // Only events at the same depth as the agent's graph.
        if (!$this->isOwnEvent($namespace)) {
            return true;
        }

        $data = $event['params']['data'];

        if ($event['method'] === 'messages' && \is_array($data)) {
            if (($data['event'] ?? null) === 'content-block-finish') {
                $contentBlock = $data['contentBlock'] ?? $data['content_block'] ?? null;
                if (\is_array($contentBlock) && ($contentBlock['type'] ?? null) === 'tool_call') {
                    $this->createToolCallEntry(
                        (string) ($contentBlock['id'] ?? ''),
                        (string) ($contentBlock['name'] ?? ''),
                        $contentBlock['args'] ?? $contentBlock['input'] ?? null,
                    );
                }
            }
        }

        if ($event['method'] === 'tools' && \is_array($data)) {
            $toolCallId = $data['tool_call_id'] ?? null;
            $toolCallId = \is_string($toolCallId) ? $toolCallId : '';

            if (($data['event'] ?? null) === 'tool-started') {
                $this->createToolCallEntry($toolCallId, (string) ($data['tool_name'] ?? 'unknown'), $data['input'] ?? null);
            }

            $pending = $toolCallId !== '' ? ($this->pendingCalls[$toolCallId] ?? null) : null;

            if ($pending !== null) {
                if (($data['event'] ?? null) === 'tool-finished') {
                    $pending['output']->resolve(self::normalizeToolOutput($data['output'] ?? null));
                    $pending['status']->resolve(Types::STATUS_FINISHED);
                    $pending['error']->resolve(null);
                    unset($this->pendingCalls[$toolCallId]);
                } elseif (($data['event'] ?? null) === 'tool-error') {
                    $message = (string) ($data['message'] ?? 'unknown error');
                    // An interrupt raised inside a tool (HITL middleware or a raw `interrupt()`) surfaces as a
                    // `tool-error` whose message is the serialized interrupt. It is control flow, not a failure:
                    // keep the call pending (it re-runs on resume) and never reject `output`.
                    if (self::isToolInterrupt($message)) {
                        return true;
                    }
                    $pending['output']->reject(new \RuntimeException($message));
                    $pending['status']->resolve(Types::STATUS_ERROR);
                    $pending['error']->resolve($message);
                    unset($this->pendingCalls[$toolCallId]);
                }
            }
        }

        return true;
    }

    public function finalize(): mixed
    {
        foreach ($this->pendingCalls as $pending) {
            $pending['status']->resolve(Types::STATUS_FINISHED);
            $pending['error']->resolve(null);
            $pending['output']->resolve(null);
        }
        $this->pendingCalls = [];
        $this->toolCallsLog->close();

        return null;
    }

    public function fail(mixed $error): void
    {
        foreach ($this->pendingCalls as $pending) {
            $pending['status']->resolve(Types::STATUS_ERROR);
            $pending['error']->resolve($error instanceof \Throwable ? $error->getMessage() : (string) (\is_scalar($error) ? $error : get_debug_type($error)));
            $pending['output']->reject($error);
        }
        $this->pendingCalls = [];
        $this->toolCallsLog->fail($error);
    }

    /**
     * Whether `$namespace` belongs to the agent's own graph: it starts with the path and is at most one level
     * deeper (the agent's internal nodes like `tools`, `model_request`). Events from subagent subgraphs, two or
     * more levels deeper, are excluded.
     *
     * @param list<string> $namespace
     */
    private function isOwnEvent(array $namespace): bool
    {
        $depth = \count($this->path);
        if (\count($namespace) < $depth || \count($namespace) > $depth + 1) {
            return false;
        }

        return Types::hasPrefix($namespace, $this->path);
    }

    private function createToolCallEntry(string $callId, string $name, mixed $rawInput): void
    {
        if (isset($this->pendingCalls[$callId])) {
            return;
        }
        $input = \is_string($rawInput) ? json_decode($rawInput, true, 512, \JSON_THROW_ON_ERROR) : $rawInput;

        $output = new Deferred();
        $status = new Deferred();
        $error = new Deferred();
        $this->pendingCalls[$callId] = ['output' => $output, 'status' => $status, 'error' => $error];

        $this->toolCallsLog->push(new ToolCallStream($name, $callId, $input, $output, $status, $error));
    }

    /**
     * Detects a `tool-error` payload that is a graph interrupt rather than a genuine tool failure.
     *
     * A tool that calls `interrupt()` throws a `GraphInterrupt` whose message is the JSON-serialized `Interrupt[]`.
     * Each entry is `{ id, value }`: a stable string `id` plus the interrupt `value`. BOTH must be present on every
     * entry: a bare `value` is not a reliable discriminator, since a genuine tool error can also be a JSON array
     * of `{ value }` records (a validator emitting `[{"value":"bad input","message":"invalid"}]`).
     */
    private static function isToolInterrupt(string $message): bool
    {
        try {
            $parsed = json_decode($message, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!\is_array($parsed) || $parsed === [] || !array_is_list($parsed)) {
            return false;
        }
        foreach ($parsed as $entry) {
            if (!\is_array($entry) || !\is_string($entry['id'] ?? null) || !\array_key_exists('value', $entry)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A ToolMessage output (live, or serialized as `{lc, type: "constructor", id: [..., "ToolMessage"], kwargs}`
     * after crossing a protocol boundary) becomes its content.
     */
    private static function normalizeToolOutput(mixed $output): mixed
    {
        if ($output instanceof ToolMessage) {
            return $output->content;
        }
        if (\is_array($output) && ($output['type'] ?? null) === 'constructor' && \is_array($output['id'] ?? null)
            && $output['id'] !== [] && $output['id'][array_key_last($output['id'])] === 'ToolMessage'
        ) {
            return $output['kwargs']['content'] ?? null;
        }

        return $output;
    }
}
