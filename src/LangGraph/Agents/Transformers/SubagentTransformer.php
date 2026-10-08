<?php

declare(strict_types=1);

namespace LangGraph\Agents\Transformers;

/**
 * Surfaces nested named agents as {@see SubagentRunStream}s on `subagents`.
 *
 * Port of `createSubagentTransformer` from `langchain/src/agents/transformers/subagent.ts`; the factory is
 * {@see self::factory()} and the instance it makes is the transformer.
 *
 * It watches `tasks` events to record each namespace's `lc_agent_name` (set by an agent's `name`) and the
 * triggering tool call, then, for any nested run one level below `$scope` that carries an `lc_agent_name`,
 * emits a handle. Each handle is backed by its own per-subagent transformers scoped to the subagent's
 * namespace (a {@see ToolCallTransformer}, a nested SubagentTransformer and, when one is supplied, a messages
 * transformer). Every event in the subtree is fed straight into them; the subagent's final `output` is resolved
 * from its last `values` snapshot when its `lifecycle` completes.
 *
 * Langgraph's `createMessagesTransformer` has no PHP counterpart, so a messages transformer is supplied by the
 * caller as `$messagesFactory` (a callable taking the subagent's namespace and returning a
 * {@see NativeStreamTransformerInterface} whose projection is `messages`). Without one, a handle's `messages`
 * is an empty channel.
 */
final class SubagentTransformer implements NativeStreamTransformerInterface
{
    private StreamChannel $subagentsLog;

    /** @var array<string, string|null> `lc_agent_name` observed per namespace (first task event wins) */
    private array $lcByNs = [];

    /** @var array<string, string> triggering task id => originating LLM `tool_call_id` */
    private array $pendingToolCalls = [];

    /**
     * Namespace key => the `tool_call_id` of the most recent tool to start executing there. A tool that invokes a
     * subagent emits its `tool-started` at the tools-node namespace where the subagent then roots.
     *
     * @var array<string, string>
     */
    private array $activeToolCallByNs = [];

    /** @var array<string, SubagentHandle> */
    private array $handles = [];

    /**
     * @param list<string>                                                              $scope           namespace prefix this transformer is scoped to: the root agent uses `[]`, nested handles their own namespace
     * @param (callable(list<string>): NativeStreamTransformerInterface)|null            $messagesFactory builds the per-subagent messages transformer
     */
    public function __construct(
        private readonly array $scope = [],
        private readonly mixed $messagesFactory = null,
    ) {
        $this->subagentsLog = new StreamChannel();
    }

    /**
     * Upstream's `createSubagentTransformer(scope)`: a factory for the transformer.
     *
     * @param list<string> $scope
     * @return \Closure(): self
     */
    public static function factory(array $scope = [], ?callable $messagesFactory = null): \Closure
    {
        return static fn (): self => new self($scope, $messagesFactory);
    }

    public function init(): array
    {
        return ['subagents' => $this->subagentsLog];
    }

    public function process(array $event): bool
    {
        $namespace = $event['params']['namespace'];
        $data = $event['params']['data'];
        $isTaskResult = $event['method'] === 'tasks' && \is_array($data) && \array_key_exists('result', $data);

        // Track the tool currently executing at each namespace. A subagent's dispatching tool starts at the same
        // namespace the subagent roots under, so this records the cause before the subagent is discovered.
        if ($event['method'] === 'tools'
            && \is_array($data)
            && ($data['event'] ?? null) === 'tool-started'
            && \is_string($data['tool_call_id'] ?? null)
            && $data['tool_call_id'] !== ''
        ) {
            $this->activeToolCallByNs[Types::namespaceKey($namespace)] = $data['tool_call_id'];
        }

        // A task start: record identity / tool call, then discover a subagent boundary *before* fanning out so the
        // new handle receives its own subtree events (which Pregel emits after the parent-namespace task).
        if ($event['method'] === 'tasks' && !$isTaskResult) {
            $this->recordIdentity($namespace, $data);
            $this->recordPendingToolCalls($data);
            $this->maybeStartSubagent($namespace);
        }

        // Fan the event out to every active subagent whose subtree contains it. The per-subagent transformers
        // self-filter by namespace depth.
        foreach ($this->handles as $handle) {
            if ($handle->done) {
                continue;
            }
            if (!Types::hasPrefix($namespace, $handle->path)) {
                continue;
            }

            $handle->messages?->process($event);
            $handle->toolCall->process($event);
            $handle->nested->process($event);

            // Track the subagent's own (root-level) state and resolve its `output` from the last snapshot when its
            // lifecycle completes.
            if (Types::namespaceKey($namespace) === $handle->key) {
                if ($event['method'] === 'values' && \is_array($data)) {
                    $handle->latestValues = $data;
                } elseif ($event['method'] === 'lifecycle' && \is_array($data)) {
                    $status = $data['event'] ?? null;
                    if ($status === 'completed' || $status === 'interrupted') {
                        $this->finishHandle($handle, null);
                    } elseif ($status === 'failed') {
                        $this->finishHandle($handle, new \RuntimeException('Subagent ' . $handle->name . ' failed'));
                    }
                }
            }
        }

        return true;
    }

    public function finalize(): void
    {
        foreach ($this->handles as $handle) {
            $this->finishHandle($handle, null);
        }
        $this->subagentsLog->close();
    }

    public function fail(mixed $error): void
    {
        foreach ($this->handles as $handle) {
            $this->finishHandle($handle, $error instanceof \Throwable ? $error : new \RuntimeException((string) (\is_scalar($error) ? $error : get_debug_type($error))));
        }
        $this->subagentsLog->fail($error);
    }

    /**
     * @param list<string> $namespace
     */
    private function recordIdentity(array $namespace, mixed $data): void
    {
        $key = Types::namespaceKey($namespace);
        if (\array_key_exists($key, $this->lcByNs)) {
            return;
        }
        $metadata = \is_array($data) && \is_array($data['metadata'] ?? null) ? $data['metadata'] : null;
        $lc = $metadata['lc_agent_name'] ?? null;
        $this->lcByNs[$key] = \is_string($lc) ? $lc : null;
    }

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
        if (\is_array($input) && \is_array($input['tool_call'] ?? null)) {
            $candidate = $input['tool_call']['id'] ?? null;
            if (\is_string($candidate)) {
                $toolCallId = $candidate;
            }
        } elseif (\is_array($input) && array_is_list($input)) {
            foreach ($input as $toolCall) {
                if (\is_array($toolCall) && \is_string($toolCall['id'] ?? null)) {
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
     * Derive the `toolCall` cause for a named-subagent namespace.
     *
     * Primary signal: the tool whose `tool-started` event fired at the subagent's own namespace. Fallback: the
     * namespace segment's task id (`node:<task_id>`) joined to a tool call harvested from a task input.
     *
     * @param list<string> $namespace
     * @return array{type: 'toolCall', tool_call_id: string}|null
     */
    private function deriveCause(array $namespace): ?array
    {
        $active = $this->activeToolCallByNs[Types::namespaceKey($namespace)] ?? null;
        if (\is_string($active) && $active !== '') {
            return Types::toolCallCause($active);
        }
        $segment = $namespace[array_key_last($namespace)];
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

        return Types::toolCallCause($toolCallId);
    }

    /**
     * @param list<string> $namespace
     */
    private function maybeStartSubagent(array $namespace): void
    {
        $depth = \count($this->scope);
        if (\count($namespace) !== $depth + 1 || !Types::hasPrefix($namespace, $this->scope)) {
            return;
        }
        $key = Types::namespaceKey($namespace);
        if (isset($this->handles[$key])) {
            return;
        }
        $lc = $this->lcByNs[$key] ?? null;
        // Only surface nested runs carrying an `lc_agent_name`; plain subgraphs are excluded so `subagents` stays agent-only.
        if (!\is_string($lc) || $lc === '') {
            return;
        }

        $messages = $this->messagesFactory !== null ? ($this->messagesFactory)($namespace) : null;
        $messagesChannel = $messages?->init()['messages'] ?? new StreamChannel();
        $toolCall = new ToolCallTransformer($namespace);
        $nested = new self($namespace, $this->messagesFactory);
        $output = new Deferred();

        $this->handles[$key] = new SubagentHandle($key, $namespace, $lc, $messages, $messagesChannel, $toolCall, $nested, $output);

        $this->subagentsLog->push(new SubagentRunStream(
            name: $lc,
            cause: $this->deriveCause($namespace),
            output: $output,
            messages: $messagesChannel,
            toolCalls: $toolCall->init()['toolCalls'],
            subagents: $nested->init()['subagents'],
        ));
    }

    private function finishHandle(SubagentHandle $handle, ?\Throwable $error): void
    {
        if ($handle->done) {
            return;
        }
        $handle->done = true;
        if ($error === null) {
            $handle->output->resolve($handle->latestValues);
        } else {
            $handle->output->reject($error);
        }
        if ($handle->messages !== null) {
            $handle->messages->finalize();
        } else {
            $handle->messagesChannel->close();
        }
        $handle->toolCall->finalize();
        $handle->nested->finalize();
    }
}
