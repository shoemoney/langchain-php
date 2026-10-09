<?php

declare(strict_types=1);

namespace LangGraph\Stream\Transformers;

use LangGraph\Stream\NativeStreamTransformer;
use LangGraph\Stream\ReplayableIterable;
use LangGraph\Stream\StreamChannel;
use LangGraph\Stream\StreamEmitter;
use LangGraph\Stream\Types;

/**
 * Synthesises `lifecycle` channel events that track the status of the root run and every subgraph it spawns.
 *
 * Port of `createLifecycleTransformer` / `filterLifecycleEntries` from `stream/transformers/lifecycle.ts`.
 * It is registered first so every other transformer and consumer sees a coherent lifecycle stream. Entries
 * are also pushed to a local {@see StreamChannel} so in-process consumers can iterate `run.lifecycle`
 * without filtering the main event stream.
 *
 * A lifecycle entry is `['namespace' => list<string>, 'timestamp' => int, 'event' => status,
 * 'graph_name' => string, 'cause'? => array, 'error'? => string]`; a status is `started`, `running`,
 * `completed`, `interrupted` or `failed`.
 *
 * Options (all optional): `rootGraphName` (default `root`), `initialStatus` (default `running`),
 * `emitRootOnRegister` (default true), `getGraphName` (`callable(list<string>): string`),
 * `getTerminalStatusOverride` (`callable(): ?string`, synchronous here) and `serializeError`
 * (`callable(mixed): string`).
 *
 * Native: the projection is `['_lifecycleLog' => channel, 'lifecycle' => iterable]`.
 */
final class LifecycleTransformer implements NativeStreamTransformer
{
    private const DEFAULT_ROOT_GRAPH_NAME = 'root';

    private readonly StreamChannel $log;

    private readonly string $rootGraphName;

    private readonly string $initialStatus;

    private readonly bool $emitRootOnRegister;

    /** @var \Closure(list<string>): string */
    private readonly \Closure $getGraphName;

    /** @var \Closure(mixed): string */
    private readonly \Closure $serializeError;

    /** @var (\Closure(): ?string)|null */
    private readonly ?\Closure $getTerminalStatusOverride;

    /** @var array<string, array{namespace: list<string>, graphName: string, status: ?string}> */
    private array $namespaces = [];

    /** @var array<string, array<string, mixed>> */
    private array $namespaceCause = [];

    /** @var array<string, ?string> */
    private array $lcByNs = [];

    /** @var array<string, string> task id => triggering tool_call_id */
    private array $pendingToolCalls = [];

    /** @var array<string, true> */
    private array $pendingInterruptIds = [];

    /**
     * Child namespaces whose parent just saw an `updates` event attributed to a node. The completion is
     * deferred until the next inbound event (or `finalize`) so the parent's `updates` lands first.
     *
     * @var list<array{namespace: list<string>, source: array{type: string, parent?: list<string>, node?: string}}>
     */
    private array $pendingCompletions = [];

    private ?StreamEmitter $emitter = null;

    private int $inSelfEmit = 0;

    private bool $finalized = false;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->rootGraphName = $options['rootGraphName'] ?? self::DEFAULT_ROOT_GRAPH_NAME;
        $this->initialStatus = $options['initialStatus'] ?? 'running';
        $this->emitRootOnRegister = $options['emitRootOnRegister'] ?? true;
        $this->getGraphName = isset($options['getGraphName'])
            ? \Closure::fromCallable($options['getGraphName'])
            : static function (array $ns): string {
                if ($ns === []) {
                    return self::DEFAULT_ROOT_GRAPH_NAME;
                }
                $last = $ns[\count($ns) - 1];
                $colon = strpos($last, ':');

                return $colon === false ? $last : substr($last, 0, $colon);
            };
        $this->serializeError = isset($options['serializeError'])
            ? \Closure::fromCallable($options['serializeError'])
            : static function (mixed $err): string {
                if ($err instanceof \Throwable) {
                    return $err->getMessage();
                }

                return \is_string($err) ? $err : (json_encode($err) ?: get_debug_type($err));
            };
        $this->getTerminalStatusOverride = isset($options['getTerminalStatusOverride'])
            ? \Closure::fromCallable($options['getTerminalStatusOverride'])
            : null;
        $this->log = StreamChannel::local();
    }

    /**
     * Only the entries whose namespace lies in the subtree rooted at `$path`, from log index `$startAt`.
     *
     * @param list<string> $path
     * @return \IteratorAggregate<int, array<string, mixed>>
     */
    public static function filterEntries(StreamChannel $log, array $path, int $startAt = 0): \IteratorAggregate
    {
        return new ReplayableIterable(static function () use ($log, $path, $startAt): \Generator {
            foreach ($log->iterate($startAt) as $entry) {
                if (Types::hasPrefix($entry['namespace'], $path)) {
                    yield $entry;
                }
            }
        });
    }

    /**
     * @return array{_lifecycleLog: StreamChannel, lifecycle: \IteratorAggregate<int, array<string, mixed>>}
     */
    public function init(): array
    {
        return ['_lifecycleLog' => $this->log, 'lifecycle' => self::filterEntries($this->log, [], 0)];
    }

    public function onRegister(StreamEmitter $emitter): void
    {
        $this->emitter = $emitter;
        // Seed the root record so cascade logic sees it even when an outer authority owns root emission.
        $this->trackNamespace([]);
        if ($this->emitRootOnRegister) {
            $this->emit([], $this->initialStatus);
        }
    }

    public function process(array $event): bool
    {
        $ns = $event['params']['namespace'];

        // Re-entrant loopback: an event we emitted is being routed back through us by the mux.
        if ($this->inSelfEmit > 0) {
            return true;
        }

        $method = $event['method'] ?? null;
        $data = $event['params']['data'] ?? null;

        $taskCompletion = $method === 'tasks' ? self::extractTaskResultCompletion($data) : null;
        if ($taskCompletion !== null) {
            // Exact task-result attribution beats any ambiguous `updates.node` completion deferred earlier.
            $this->removePendingNodeCompletions($ns, $taskCompletion['name']);
        } elseif ($method === 'tasks') {
            // Task start: record the namespace's subagent identity and any triggering tool call BEFORE
            // ensureStarted synthesises `started`, so a named subagent's graph_name/cause resolve in time.
            $this->recordIdentity($ns, $data);
            $this->recordPendingToolCalls($data);
        }

        // Flush completions deferred by the previous event: wire order is [triggering event] -> [completed].
        $this->flushPendingCompletions();

        // Upstream lifecycle events: stash any `cause`, synthesise our authoritative started/... for the
        // namespace, and suppress the original so we are the single source of truth.
        if ($method === 'lifecycle') {
            $cause = self::extractCause($data);
            if ($cause !== null) {
                $this->namespaceCause[Types::nsKey($ns)] = $cause;
            }
            $this->ensureStarted($ns);

            return false;
        }

        $this->ensureStarted($ns);

        if ($method === 'input' && \is_array($data) && ($data['event'] ?? null) === 'requested') {
            $id = $data['id'] ?? null;
            if (\is_string($id)) {
                $this->pendingInterruptIds[$id] = true;
            }
        }

        if ($taskCompletion !== null) {
            $child = $this->findStartedChildForTask($ns, $taskCompletion);
            if ($child !== null) {
                $this->enqueueCompletion(['namespace' => $child, 'source' => ['type' => 'task']]);
            }
        }

        // Defer child-node completion: `updates` carries the node attribution, the child namespace is
        // `[...ns, "<node>:<uuid>"]`. The oldest still-started match is picked, so repeated updates drain
        // parallel fan-outs in order.
        if ($method === 'updates') {
            $node = $event['params']['node'] ?? null;
            if (\is_string($node) && !str_starts_with($node, '__')) {
                $child = $this->findStartedChildForNode($ns, $node);
                if ($child !== null) {
                    $this->enqueueCompletion(['namespace' => $child, 'source' => ['type' => 'node', 'parent' => $ns, 'node' => $node]]);
                }
            }
        }

        return true;
    }

    public function finalize(): mixed
    {
        if ($this->finalized) {
            return null;
        }
        $this->finalized = true;
        $this->flushPendingCompletions();
        $this->cascadeTerminalStatus($this->resolveTerminalStatus());

        return null;
    }

    public function fail(mixed $error): void
    {
        if ($this->finalized) {
            return;
        }
        $this->finalized = true;
        $message = ($this->serializeError)($error);

        // Cascade `failed` to every still-started namespace; children already terminal are left alone by
        // the dedup guard in emit().
        foreach ($this->namespaces as $rec) {
            if ($rec['namespace'] === [] || $rec['status'] !== 'started') {
                continue;
            }
            $this->emit($rec['namespace'], 'failed');
        }

        $this->emit([], 'failed', ['error' => $message]);
        $this->log->fail($error);
    }

    // ---- internals ---------------------------------------------------------------------------

    /**
     * @param list<string> $ns
     */
    private function resolveGraphName(array $ns): string
    {
        if ($ns === []) {
            return $this->rootGraphName;
        }
        // A nested run carrying an `lc_agent_name` is a named subagent; the agent name wins.
        $lc = $this->lcByNs[Types::nsKey($ns)] ?? null;
        if (\is_string($lc) && $lc !== '') {
            return $lc;
        }

        return ($this->getGraphName)($ns);
    }

    /**
     * Record a namespace's `lc_agent_name` from a task-start payload (first event wins).
     *
     * @param list<string> $ns
     */
    private function recordIdentity(array $ns, mixed $data): void
    {
        $key = Types::nsKey($ns);
        if (\array_key_exists($key, $this->lcByNs)) {
            return;
        }
        $metadata = \is_array($data) && \is_array($data['metadata'] ?? null) ? $data['metadata'] : null;
        $lc = $metadata['lc_agent_name'] ?? null;
        $this->lcByNs[$key] = \is_string($lc) ? $lc : null;
    }

    /**
     * Harvest a task's triggering `tool_call_id` keyed by task id: from a `tool_call_with_context` input
     * (`input.tool_call.id`) or a legacy list of tool-call objects (first with a string `id`).
     */
    private function recordPendingToolCalls(mixed $data): void
    {
        if (!\is_array($data)) {
            return;
        }
        $taskId = $data['id'] ?? null;
        if (!\is_string($taskId)) {
            return;
        }
        $input = $data['input'] ?? null;
        $toolCallId = null;
        if (\is_array($input) && !array_is_list($input) && \is_array($input['tool_call'] ?? null)) {
            $candidate = $input['tool_call']['id'] ?? null;
            if (\is_string($candidate)) {
                $toolCallId = $candidate;
            }
        } elseif (\is_array($input) && array_is_list($input)) {
            foreach ($input as $toolCall) {
                if (\is_array($toolCall) && isset($toolCall['id']) && \is_string($toolCall['id'])) {
                    $toolCallId = $toolCall['id'];
                    break;
                }
            }
        }
        if ($toolCallId !== null) {
            $this->pendingToolCalls[$taskId] = $toolCallId;
        }
    }

    /**
     * A `toolCall` cause for a named subagent namespace: the namespace segment's task id (`node:<task_id>`)
     * joined to a harvested `tool_call_id`. Only namespaces carrying an `lc_agent_name` qualify.
     *
     * @param list<string> $ns
     * @return array<string, mixed>|null
     */
    private function deriveToolCallCause(array $ns): ?array
    {
        if ($ns === []) {
            return null;
        }
        $lc = $this->lcByNs[Types::nsKey($ns)] ?? null;
        if (!\is_string($lc) || $lc === '') {
            return null;
        }
        $segment = $ns[\count($ns) - 1];
        $colon = strpos($segment, ':');
        if ($colon === false) {
            return null;
        }
        $triggerCallId = substr($segment, $colon + 1);
        if ($triggerCallId === '') {
            return null;
        }
        $toolCallId = $this->pendingToolCalls[$triggerCallId] ?? null;
        if (!\is_string($toolCallId) || $toolCallId === '') {
            return null;
        }

        return ['type' => 'toolCall', 'tool_call_id' => $toolCallId];
    }

    /**
     * @param list<string> $ns
     * @return array<string, mixed>|null
     */
    private function resolveStartCause(array $ns): ?array
    {
        return $this->namespaceCause[Types::nsKey($ns)] ?? $this->deriveToolCallCause($ns);
    }

    /**
     * @param list<string> $ns
     * @param array{cause?: array<string, mixed>, error?: string} $extras
     */
    private function emit(array $ns, string $status, array $extras = []): void
    {
        $key = Types::nsKey($ns);
        $current = $this->namespaces[$key] ?? null;
        $graphName = $current['graphName'] ?? $this->resolveGraphName($ns);

        // Dedup: identical status + graph name + no error override => skip.
        if ($current !== null && $current['status'] === $status && $current['graphName'] === $graphName && !isset($extras['error'])) {
            return;
        }

        if ($current === null) {
            $this->namespaces[$key] = ['namespace' => $ns, 'graphName' => $graphName, 'status' => $status];
        } else {
            $this->namespaces[$key]['status'] = $status;
        }

        $data = ['event' => $status, 'graph_name' => $graphName];
        if (isset($extras['cause'])) {
            $data['cause'] = $extras['cause'];
        }
        if (isset($extras['error'])) {
            $data['error'] = $extras['error'];
        }

        $timestamp = Types::now();
        $this->log->push(['namespace' => $ns, 'timestamp' => $timestamp] + $data);

        if ($ns === [] && !$this->emitRootOnRegister) {
            return;
        }
        if ($this->emitter === null) {
            return;
        }

        ++$this->inSelfEmit;
        try {
            $this->emitter->push($ns, Types::protocolEvent('lifecycle', $ns, $data, null, 0, $timestamp));
        } finally {
            --$this->inSelfEmit;
        }
    }

    /**
     * Ensure a record exists for `$ns` without touching its status.
     *
     * @param list<string> $ns
     */
    private function trackNamespace(array $ns): void
    {
        $key = Types::nsKey($ns);
        if (!isset($this->namespaces[$key])) {
            $this->namespaces[$key] = ['namespace' => $ns, 'graphName' => $this->resolveGraphName($ns), 'status' => null];
        }
    }

    private function flushPendingCompletions(): void
    {
        if ($this->pendingCompletions === []) {
            return;
        }
        $toFlush = $this->pendingCompletions;
        $this->pendingCompletions = [];
        foreach ($toFlush as $completion) {
            $rec = $this->namespaces[Types::nsKey($completion['namespace'])] ?? null;
            if ($rec === null || $rec['status'] !== 'started') {
                continue;
            }
            $this->emit($completion['namespace'], 'completed');
        }
    }

    /**
     * @param array{namespace: list<string>, source: array<string, mixed>} $completion
     */
    private function enqueueCompletion(array $completion): void
    {
        $key = Types::nsKey($completion['namespace']);
        $rec = $this->namespaces[$key] ?? null;
        if ($rec === null || $rec['status'] !== 'started') {
            return;
        }
        foreach ($this->pendingCompletions as $pending) {
            if (Types::nsKey($pending['namespace']) === $key) {
                return;
            }
        }
        $this->pendingCompletions[] = $completion;
    }

    /**
     * @param list<string> $parent
     */
    private function removePendingNodeCompletions(array $parent, string $node): void
    {
        $this->pendingCompletions = array_values(array_filter(
            $this->pendingCompletions,
            static fn (array $pending): bool => !(
                $pending['source']['type'] === 'node'
                && ($pending['source']['node'] ?? null) === $node
                && Types::nsKey($pending['source']['parent'] ?? []) === Types::nsKey($parent)
            ),
        ));
    }

    /**
     * Synthesise `started` for each unseen prefix of `$ns`, outermost first, so a consumer never sees a
     * child's `started` before its parent's.
     *
     * @param list<string> $ns
     */
    private function ensureStarted(array $ns): void
    {
        for ($length = 1; $length <= \count($ns); ++$length) {
            $prefix = \array_slice($ns, 0, $length);
            if (isset($this->namespaces[Types::nsKey($prefix)])) {
                continue;
            }
            $this->trackNamespace($prefix);
            $cause = $this->resolveStartCause($prefix);
            $this->emit($prefix, 'started', $cause !== null ? ['cause' => $cause] : []);
        }
    }

    private function defaultTerminalStatus(): string
    {
        return $this->pendingInterruptIds !== [] ? 'interrupted' : 'completed';
    }

    private function resolveTerminalStatus(): string
    {
        if ($this->getTerminalStatusOverride === null) {
            return $this->defaultTerminalStatus();
        }
        try {
            return ($this->getTerminalStatusOverride)() ?? $this->defaultTerminalStatus();
        } catch (\Throwable) {
            return $this->defaultTerminalStatus();
        }
    }

    private function cascadeTerminalStatus(string $status): void
    {
        foreach ($this->namespaces as $rec) {
            if ($rec['namespace'] === [] || $rec['status'] !== 'started') {
                continue;
            }
            $this->emit($rec['namespace'], $status);
        }
        $this->emit([], $status);
        $this->log->close();
    }

    /**
     * @param list<string> $parentNamespace
     * @return list<string>|null
     */
    private function findStartedChildForNode(array $parentNamespace, string $node): ?array
    {
        $prefix = $node . ':';
        foreach ($this->namespaces as $rec) {
            if (\count($rec['namespace']) !== \count($parentNamespace) + 1 || $rec['status'] !== 'started') {
                continue;
            }
            if (!Types::hasPrefix($rec['namespace'], $parentNamespace)) {
                continue;
            }
            $last = $rec['namespace'][\count($rec['namespace']) - 1];
            if ($last === $node || str_starts_with($last, $prefix)) {
                return $rec['namespace'];
            }
        }

        return null;
    }

    /**
     * @param list<string> $parentNamespace
     * @param array{name: string, id: string} $task
     * @return list<string>|null
     */
    private function findStartedChildForTask(array $parentNamespace, array $task): ?array
    {
        $namespace = [...$parentNamespace, $task['name'] . ':' . $task['id']];
        $rec = $this->namespaces[Types::nsKey($namespace)] ?? null;

        return $rec !== null && $rec['status'] === 'started' ? $namespace : null;
    }

    /**
     * A `cause` attached to a `lifecycle.started` payload. Shape validation is loose: any array with a
     * string `type` is accepted so future protocol variants flow through.
     *
     * @return array<string, mixed>|null
     */
    private static function extractCause(mixed $data): ?array
    {
        if (!\is_array($data) || ($data['event'] ?? null) !== 'started') {
            return null;
        }
        $cause = $data['cause'] ?? null;

        return \is_array($cause) && isset($cause['type']) && \is_string($cause['type']) ? $cause : null;
    }

    /**
     * @return array{name: string, id: string}|null
     */
    private static function extractTaskResultCompletion(mixed $data): ?array
    {
        if (!\is_array($data) || !\array_key_exists('result', $data)) {
            return null;
        }
        if (!isset($data['name']) || !\is_string($data['name']) || !isset($data['id']) || !\is_string($data['id'])) {
            return null;
        }
        if (str_starts_with($data['name'], '__')) {
            return null;
        }

        return ['name' => $data['name'], 'id' => $data['id']];
    }
}
