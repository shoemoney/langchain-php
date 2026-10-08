<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableSequence;
use LangGraph\Channels\BaseChannel;
use LangGraph\Cache\BaseCache;
use LangGraph\Channels\ChannelRegistry;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Errors\EmptyChannelError;
use LangGraph\Errors\EmptyInputError;
use LangGraph\Errors\GraphValueError;
use LangGraph\Errors\InvalidUpdateError;
use LangGraph\Pregel\Checkpoint\BaseCheckpointSaver;
use LangGraph\Pregel\Checkpoint\CheckpointFunctions;
use LangGraph\Pregel\Checkpoint\CheckpointTuple;
use LangGraph\Pregel\Messages\StreamMessagesHandler;
use LangGraph\Pregel\Messages\StreamProtocolMessagesHandler;
use LangGraph\Pregel\Messages\StreamToolsHandler;
use LangGraph\Pregel\Messages\TracedNode;
use LangGraph\Pregel\Retry\RetryPolicy;
use LangGraph\Pregel\Utils\Config;
use LangGraph\Store\BaseStore;

/**
 * The durable-execution engine: a graph of nodes and channels, run to
 * completion or to an interrupt.
 *
 * Port of the `Pregel` class from `langgraph-core/src/pregel/index.ts`.
 *
 * Pregel is a small surface over a large engine. What a caller supplies is:
 *
 *  - `nodes` — a map of name to {@see PregelNode}. Nodes never call each other.
 *  - `channels` — the named state slots nodes read and write.
 *  - `inputChannels` / `outputChannels` — which slots the graph accepts and
 *    returns. Naming them separately is what lets a graph take one shape in and
 *    return another.
 *
 * What it does with that is the superstep loop: prepare the tasks a step needs,
 * run them behind a barrier, apply their writes to the channels, repeat. The
 * barrier is why the whole thing is deterministic — no node ever observes a
 * sibling's half-finished output.
 *
 * ## invoke vs stream
 *
 * Both are the same generator. {@see self::stream()} hands the caller the
 * `[mode, payload]` chunks as they are produced; {@see self::invoke()} drains
 * it and returns the final state. There is no separate implementation, so the
 * two can never disagree about what the graph did.
 */
class Pregel extends Runnable
{
    /**
     * The queue channel every graph has.
     *
     * Port of the `TASKS` channel registration in `Pregel`'s constructor. It is
     * a non-accumulating {@see \LangGraph\Channels\Topic} — a queue of packets
     * awaiting scheduling, not accumulated state. Non-accumulating is the
     * point: a packet is consumed by the step that schedules it, so it cannot
     * re-fire on a later step.
     *
     * Registering it here rather than in each builder is what makes `Send`
     * available in *every* graph, including a hand-assembled `Pregel` that
     * declares no channels of its own.
     */
    public const TASKS_CHANNEL = Constants::TASKS;

    /**
     * The durability modes {@see self::resolveDurability()} accepts. See there for how `sync`
     * and `async` collapse in PHP.
     *
     * @var list<string>
     */
    public const DURABILITY_MODES = ['sync', 'async', 'exit'];

    /**
     * @param array<string, PregelNode>        $nodes
     * @param array<string, BaseChannel>       $channels
     * @param string|list<string>              $inputChannels
     * @param string|list<string>              $outputChannels
     * @param list<string>                     $streamChannels
     * @param array<string, list<string>>      $triggerToNodes
     */
    public function __construct(
        public readonly array $nodes = [],
        public array $channels = [],
        public readonly string|array $inputChannels = [],
        public readonly string|array $outputChannels = [],
        public readonly array $streamChannels = [],
        public readonly ?BaseCheckpointSaver $checkpointer = null,
        public array $streamMode = ['updates'],
        public ?RetryPolicy $retryPolicy = null,
        public bool $debug = false,
        public array $triggerToNodes = [],
        public ?string $name = null,
        public array $interruptBefore = [],
        public array $interruptAfter = [],
        public ?int $stepTimeout = null,
        public ?string $description = null,
        public bool $checkpointerDisabled = false,
        public ?BaseStore $store = null,
        public ?BaseCache $cache = null,
    ) {
        if (isset($channels[self::TASKS_CHANNEL])
            && $channels[self::TASKS_CHANNEL]->lcGraphName !== 'Topic') {
            throw new \InvalidArgumentException(
                'Channel ' . self::TASKS_CHANNEL . ' is reserved and cannot be used in the graph.'
            );
        }

        $this->channels[self::TASKS_CHANNEL] = new \LangGraph\Channels\Topic(accumulate: false);
    }

    public function getName(): string
    {
        return $this->name ?? 'Pregel';
    }

    /**
     * Run the graph to completion and return its final state.
     *
     * Port of `Pregel.invoke`.
     *
     * Drains the same generator {@see self::stream()} returns and reads the
     * loop's output from it. An interrupt is *not* an error: a graph that pauses
     * returns the state it paused in, and the caller resumes it by invoking
     * again with a `Command` carrying a resume value.
     *
     * @param mixed $input State, a {@see Command}, or null to resume.
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $generator = $this->stream($input, $config);
        foreach ($generator as $ignored) {
            // Drain. The final value comes from the generator's return.
        }

        return $generator->getReturn();
    }

    /**
     * Run the graph, yielding `[mode, payload]` chunks as it goes.
     *
     * Port of `Pregel._streamIterator`.
     *
     * The `mode` is what kind of event this is: `values` for whole-state
     * snapshots (one per superstep that changed state) and `updates` for
     * per-node deltas. Both are emitted, and a caller picks by asking for the
     * mode they want — the loop does not decide, it produces and the consumer
     * filters.
     *
     * @return \Generator<int, array{0: string, 1: mixed}, mixed, mixed>
     */
    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        $config = $this->ensureConfig($config);

        if ($config->recursionLimit < 1) {
            throw new \InvalidArgumentException('Passed "recursionLimit" must be at least 1.');
        }

        if ($this->checkpointer !== null && $config->configurable === []) {
            throw new \InvalidArgumentException(
                'Checkpointer requires one or more of the following "configurable" keys: '
                . '"thread_id", "checkpoint_ns", "checkpoint_id"'
            );
        }

        $durability = $this->resolveDurability($config);

        // The run-scoped drain switch. Precedence: an explicit `control` option, then one a
        // parent run propagated through the task config, then a fresh one, so a node can always
        // reach `$config->options['control']`.
        if (!(($config->options['control'] ?? null) instanceof RunControl)) {
            $config = $config->with(['options' => array_merge($config->options, ['control' => new RunControl()])]);
        }

        // Per-call key overrides, checked against the channels (upstream `_defaults`).
        $inputKeys = $config->options['inputKeys'] ?? $this->inputChannels;
        $outputKeys = $config->options['outputKeys'] ?? $this->outputChannels;
        if (isset($config->options['inputKeys'])) {
            Validate::validateKeys($inputKeys, $this->channels);
        }
        if (isset($config->options['outputKeys'])) {
            Validate::validateKeys($outputKeys, $this->channels);
        }

        // A subgraph compiled with `checkpointer: true` keeps ONE persistent checkpoint lineage
        // across the parent's runs: its namespace loses the `:taskId` suffix the parent would
        // otherwise give every invocation, so each call resumes the previous call's state.
        if ($this->persistsAcrossInvocations()) {
            $config = self::patchConfigurable($config, [
                Constants::CONFIG_KEY_CHECKPOINT_NS => implode(
                    Constants::CHECKPOINT_NAMESPACE_SEPARATOR,
                    array_map(
                        static fn (string $part): string => explode(Constants::CHECKPOINT_NAMESPACE_END, $part)[0],
                        explode(Constants::CHECKPOINT_NAMESPACE_SEPARATOR, (string) ($config->configurable[Constants::CONFIG_KEY_CHECKPOINT_NS] ?? '')),
                    ),
                ),
            ]);
        }

        $validInput = $this->validateInput($input);

        $modes = $this->resolveStreamModes();

        // `messages` and `tools` are not produced by the loop: they are callbacks
        // fired from inside nodes. Their handlers push into the loop once it
        // exists, which is why the loop is captured by reference.
        $loop = null;
        $handlers = $this->streamHandlers($modes, $loop, $config);
        if ($handlers !== []) {
            $config = $config->with(['callbacks' => array_merge($config->callbacks, $handlers)]);
        }

        $loop = PregelLoop::initialize([
            'input' => $validInput,
            'config' => $config,
            'checkpointer' => $this->checkpointer,
            'checkpointerDisabled' => $this->checkpointerDisabled,
            'nodes' => $this->nodes,
            'channelSpecs' => ChannelRegistry::getOnlyChannels($this->channels),
            'outputKeys' => $outputKeys,
            'streamKeys' => $this->streamChannels,
            'interruptAfter' => $config->options['interruptAfter'] ?? $this->interruptAfter,
            'interruptBefore' => $config->options['interruptBefore'] ?? $this->interruptBefore,
            'debug' => $this->debug,
            'triggerToNodes' => $this->triggerToNodes,
            'store' => $config->configurable[Constants::CONFIG_KEY_STORE] ?? $config->options['store'] ?? $this->store,
            'cache' => $config->configurable[Constants::CONFIG_KEY_CACHE] ?? $config->options['cache'] ?? $this->cache,
            'durability' => $durability,
        ]);

        $loop->streamModes = $modes;
        $loop->traceNodes = $handlers !== [];

        return $loop->run($inputKeys);
    }

    /**
     * Whether this graph is a subgraph that asked for `checkpointer: true`.
     *
     * Upstream keeps `true` on the graph. This port's `StateGraph::compile()` (outside this work
     * package) turns `true` into a plain base-class {@see Checkpoint\MemorySaver} before the graph
     * exists, so the choice is recovered from that: a nested graph holding exactly that class
     * got its saver from `compile(['checkpointer' => true])`; a saver a caller supplied is a
     * subclass (`LangGraph\Checkpoint\MemorySaver`, `SqliteSaver`...). A root graph is never
     * affected: it has no parent namespace to strip.
     */
    private function persistsAcrossInvocations(): bool
    {
        return $this->checkpointer !== null
            && $this->checkpointer::class === Checkpoint\MemorySaver::class
            && !$this->checkpointerDisabled;
    }

    /**
     * When checkpoints are written: `sync`, `async` (the default) or `exit`.
     *
     * Port of the durability handling in `_defaults`. The option is read, in order, from
     * `options['durability']`, the deprecated `options['checkpointDuring']` (`false` is `exit`,
     * `true` is `async`) and the `__pregel_durability` configurable key a parent run may have
     * set. Naming both of the first two is an error, as upstream.
     *
     * PHP collapse: `sync` and `async` differ upstream only in whether the loop AWAITS the saver
     * before preparing the next superstep. Every saver call here is synchronous, so both write a
     * checkpoint per superstep and behave identically. `exit` is real: no checkpoint or task
     * write reaches the saver until the run exits, then one final checkpoint and the pending
     * writes are saved. The cost of `exit` is the usual one: a process killed mid-run loses all
     * progress since the last save.
     *
     * @throws \InvalidArgumentException on an unknown mode, or both options given.
     */
    private function resolveDurability(RunnableConfig $config): string
    {
        $durability = $config->options['durability'] ?? null;
        $checkpointDuring = $config->options['checkpointDuring'] ?? null;

        if ($durability !== null && $checkpointDuring !== null) {
            throw new \InvalidArgumentException('Cannot use both `durability` and `checkpointDuring` at the same time.');
        }

        $resolved = $durability
            ?? ($checkpointDuring === null ? null : ($checkpointDuring === false ? 'exit' : 'async'))
            ?? $config->configurable[Constants::CONFIG_KEY_DURABILITY]
            ?? 'async';

        if (!in_array($resolved, self::DURABILITY_MODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown durability "%s"; expected one of: %s.',
                is_scalar($resolved) ? (string) $resolved : get_debug_type($resolved),
                implode(', ', self::DURABILITY_MODES),
            ));
        }

        return $resolved;
    }

    /**
     * The stream modes the loop itself produces.
     *
     * Upstream's `StreamMode` union (`pregel/types.ts`) declares eight — `values`,
     * `updates`, `debug`, `messages`, `checkpoints`, `tasks`, `custom`, `tools`. The loop
     * emits three; `messages` and `tools` come from callback handlers
     * ({@see self::HANDLER_STREAM_MODES}); `checkpoints`, `tasks` and `custom` need payloads
     * this port does not build. That is ported-subsystem scope, not a patch, and it is named
     * here so the gap is a declared constant rather than something a caller discovers by
     * receiving an empty stream.
     *
     * @var list<string>
     */
    public const SUPPORTED_STREAM_MODES = ['updates', 'values', 'debug'];

    /**
     * Modes produced by a callback handler rather than by the loop.
     *
     * `messages` (token and node-output messages, {@see StreamMessagesHandler})
     * and `tools` (tool lifecycle events, {@see StreamToolsHandler}). Kept apart
     * from {@see self::SUPPORTED_STREAM_MODES} because that constant is the set
     * the LOOP emits and is pinned as such; {@see self::resolveStreamModes()}
     * accepts the union.
     *
     * @var list<string>
     */
    public const HANDLER_STREAM_MODES = ['messages', 'tools'];

    /**
     * Validate the configured modes and normalise to a list.
     *
     * Without this, asking for a mode the port cannot emit is a SILENT NO-OP:
     * `PregelLoop::emit()` drops any chunk whose mode is not subscribed, so
     * `streamMode: ['debug']` yields an empty stream, no exception and no clue. A
     * caller cannot tell that from a graph which legitimately produced nothing, and
     * the two demand opposite responses. Refusing at the boundary is the same rule
     * the port already applies to an unserialisable channel value: refuse rather
     * than lose the caller's work silently.
     *
     * @return list<string>
     */
    private function resolveStreamModes(): array
    {
        $modes = array_values(array_map(strval(...), (array) $this->streamMode));

        $supported = array_merge(self::SUPPORTED_STREAM_MODES, self::HANDLER_STREAM_MODES);
        $unsupported = array_values(array_diff($modes, $supported));
        if ($unsupported !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported stream mode(s): %s. This port emits %s; the remaining upstream modes '
                . '(%s) are not implemented. Requesting one would otherwise produce an empty stream '
                . 'with no error.',
                implode(', ', $unsupported),
                implode(', ', $supported),
                implode(', ', array_diff([
                    'values', 'updates', 'debug', 'messages', 'checkpoints', 'tasks', 'custom', 'tools',
                ], $supported)),
            ));
        }

        return $modes;
    }

    /**
     * The callback handlers that produce the `messages` and `tools` modes.
     *
     * Port of the "set up messages stream mode" and "set up tools stream mode"
     * blocks of `_streamIterator`. Each handler gets a sink that narrows
     * upstream's `[namespace, mode, payload]` chunk to this port's
     * `[mode, payload]` envelope: the namespace is dropped because `stream()`
     * does not surface subgraph chunks, and the payload keeps its shape, so a
     * `messages` chunk is `['messages', [message, metadata]]`.
     *
     * `$config->options['version'] === 'v3'` selects the protocol-event handler,
     * as `options.version` does upstream.
     *
     * @param list<string> $modes
     * @return list<object>
     */
    private function streamHandlers(array $modes, ?PregelLoop &$loop, RunnableConfig $config): array
    {
        $handlers = [];

        if (in_array('messages', $modes, true)) {
            $sink = static function (array $chunk) use (&$loop): void {
                $loop?->emit([$chunk[2]], 'messages');
            };
            $handlers[] = ($config->options['version'] ?? null) === 'v3'
                ? new StreamProtocolMessagesHandler($sink)
                : new StreamMessagesHandler($sink);
        }

        if (in_array('tools', $modes, true)) {
            $handlers[] = new StreamToolsHandler(static function (array $chunk) use (&$loop): void {
                $loop?->emit([$chunk[2]], 'tools');
            });
        }

        return $handlers;
    }

    /**
     * Merge a caller config over the graph's defaults.
     *
     * Port of `Pregel._defaults`. The graph's own recursion limit is the
     * fallback; a caller-supplied one always wins, because a caller who set it
     * knew something about their graph the builder did not.
     */
    private function ensureConfig(?RunnableConfig $config): RunnableConfig
    {
        $base = new RunnableConfig(
            recursionLimit: $this->retryPolicy !== null ? 25 : Constants::RECURSION_LIMIT_DEFAULT,
        );

        if ($config === null) {
            return $base;
        }

        return $config;
    }

    /**
     * Reject input the graph cannot accept.
     *
     * Port of `_validateInput`. A `Command` is always allowed through — it is
     * how a caller resumes. A plain value is only allowed if the graph declares
     * at least one input channel, because a graph with none has nothing to seed
     * and would silently do nothing.
     */
    private function validateInput(mixed $input): mixed
    {
        if ($input instanceof Command) {
            return $input;
        }

        $inputKeys = is_array($this->inputChannels) ? $this->inputChannels : [$this->inputChannels];
        if ($inputKeys === []) {
            if ($input === null) {
                throw new EmptyInputError('Received null input without a default');
            }

            return $input;
        }

        if ($input === null) {
            // A null input means "resume"; the loop handles it.
            return null;
        }

        return $input;
    }

    // ---- state management: getState / getStateHistory / updateState / getSubgraphs ----------

    /** Upstream's `SCHEDULED` pending-write channel (`constants.ts`). */
    private const SCHEDULED = '__scheduled__';

    /**
     * Accept the config spellings a caller may hold.
     *
     * A {@see RunnableConfig}; a whole config array (`['configurable' => [...]]`); or a bare
     * `configurable` map (`['thread_id' => ...]`), which is what this port's savers and earlier
     * callers pass.
     *
     * @param RunnableConfig|array<string, mixed> $config
     */
    private static function asConfig(RunnableConfig|array $config): RunnableConfig
    {
        if ($config instanceof RunnableConfig) {
            return $config;
        }
        if (isset($config['configurable']) && is_array($config['configurable'])) {
            return RunnableConfig::fromArray($config) ?? new RunnableConfig();
        }

        return new RunnableConfig(configurable: $config);
    }

    /**
     * A copy of `$config` with `$patch` laid over its `configurable`; a null value removes the key.
     *
     * Port of `patchConfigurable`, where an `undefined` value is how a key is cleared.
     *
     * @param array<string, mixed> $patch
     */
    private static function patchConfigurable(RunnableConfig $config, array $patch): RunnableConfig
    {
        $copy = clone $config;
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($copy->configurable[$key]);
            } else {
                $copy->configurable[$key] = $value;
            }
        }

        return $copy;
    }

    /**
     * Add the subgraph lineage to a checkpoint config.
     *
     * Port of `patchCheckpointMap`: when the checkpoint's metadata records `parents`, the config
     * gains a `checkpoint_map` of those parents plus this namespace's own checkpoint, which is
     * how a subgraph resumed later finds the right parent checkpoint.
     *
     * @param array<string, mixed>      $config   A saver's `['configurable' => [...]]`.
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>
     */
    private static function patchCheckpointMap(array $config, ?array $metadata): array
    {
        $configurable = isset($config['configurable']) && is_array($config['configurable']) ? $config['configurable'] : $config;
        $parents = (array) ($metadata['parents'] ?? []);
        if ($parents !== []) {
            $parents[(string) ($configurable['checkpoint_ns'] ?? '')] = $configurable['checkpoint_id'] ?? null;
            $configurable[Constants::CONFIG_KEY_CHECKPOINT_MAP] = $parents;
        }

        return ['configurable' => $configurable];
    }

    /**
     * Find a compiled graph inside a node's runnable.
     *
     * Port of `findSubgraphPregel` (`pregel/utils/subgraph.ts`): the candidate itself, or any
     * step of a `RunnableSequence` (searched breadth first, so a graph wrapped in a sequence of
     * sequences is still found). A traced wrapper is looked through.
     */
    public static function findSubgraphPregel(?RunnableInterface $candidate): ?self
    {
        if ($candidate === null) {
            return null;
        }

        $candidates = [$candidate];
        foreach ($candidates as $current) {
            if ($current instanceof TracedNode) {
                $current = $current->inner;
            }
            if ($current instanceof self) {
                return $current;
            }
            if ($current instanceof RunnableSequence) {
                foreach ($current->steps as $step) {
                    $candidates[] = $step;
                }
            }
        }

        return null;
    }

    /**
     * The graphs nested inside this graph's nodes.
     *
     * Port of `getSubgraphs`. Yields `[name, graph]`. With a `$namespace`, only the node whose
     * name prefixes it is considered, and the walk stops at an exact match; with `$recurse`,
     * the children of each match are walked too and named `parent|child`.
     *
     * @return \Generator<int, array{0: string, 1: self}>
     */
    public function getSubgraphs(?string $namespace = null, bool $recurse = false): \Generator
    {
        foreach ($this->nodes as $name => $node) {
            $name = (string) $name;
            if ($namespace !== null && !str_starts_with($namespace, $name)) {
                continue;
            }

            $candidates = $node->subgraphs !== [] ? $node->subgraphs : [$node->bound];
            foreach ($candidates as $candidate) {
                $graph = self::findSubgraphPregel($candidate);
                if ($graph === null) {
                    continue;
                }

                if ($name === $namespace) {
                    yield [$name, $graph];

                    return;
                }

                if ($namespace === null) {
                    yield [$name, $graph];
                }

                if ($recurse) {
                    $newNamespace = $namespace === null ? null : substr($namespace, strlen($name) + 1);
                    foreach ($graph->getSubgraphs($newNamespace, $recurse) as [$subgraphName, $subgraph]) {
                        yield [$name . Constants::CHECKPOINT_NAMESPACE_SEPARATOR . $subgraphName, $subgraph];
                    }
                }
            }
        }
    }

    /**
     * The saver for a call: the one a parent graph published on the config, else this graph's.
     *
     * @throws GraphValueError when there is none.
     */
    private function requireCheckpointer(RunnableConfig $config): BaseCheckpointSaver
    {
        $checkpointer = $config->configurable[Constants::CONFIG_KEY_CHECKPOINTER] ?? $this->checkpointer;
        if (!$checkpointer instanceof BaseCheckpointSaver) {
            throw new GraphValueError('No checkpointer set', ['lc_error_code' => 'MISSING_CHECKPOINTER']);
        }

        return $checkpointer;
    }

    /**
     * The compiled subgraph a nested `checkpoint_ns` addresses, when this call should delegate.
     *
     * Delegation happens for a namespaced config that is not already inside a task (no
     * `__pregel_read`) and carries no saver of its own. The namespace is recast first, because
     * a stored namespace carries task ids and loop counters the static node path does not.
     *
     * @return array{0: string, 1: self}|null
     */
    private function subgraphFor(RunnableConfig $config, bool $requireNotInTask): ?array
    {
        $namespace = (string) ($config->configurable['checkpoint_ns'] ?? '');
        if ($namespace === '' || isset($config->configurable[Constants::CONFIG_KEY_CHECKPOINTER])) {
            return null;
        }
        if ($requireNotInTask && isset($config->configurable[Constants::CONFIG_KEY_READ])) {
            return null;
        }

        $recast = Config::recastCheckpointNamespace($namespace);
        foreach ($this->getSubgraphs($recast, true) as [$name, $subgraph]) {
            if ($name === $recast) {
                return [$recast, $subgraph];
            }
        }

        // No static subgraph for this namespace (for example a subgraph created dynamically
        // inside a tool call): fall through and read the saver with the full namespace, so the
        // persisted state is still reachable.
        return null;
    }

    /**
     * The current state of a thread, plus the tasks that would run next.
     *
     * Port of `Pregel.getState`. A `StateSnapshot` is the whole observable state of a graph at a
     * point in time: its values, which nodes are next, and what each pending task is waiting on.
     *
     * A config whose `checkpoint_ns` addresses a subgraph is answered by that subgraph. Unless
     * the config names a specific `checkpoint_id`, the writes of tasks that already finished in
     * the current superstep are folded into `values`, and those tasks are left out of `next`.
     *
     * `$options['subgraphs']` is accepted and forwarded to a delegated call. Upstream also uses
     * it to attach each pending subgraph task's own `StateSnapshot` as `tasks[].state`; that
     * field has no home on the port's `PregelTaskDescription`, so it is not populated here.
     * Read a subgraph's state by calling `getState()` with its `checkpoint_ns` instead.
     *
     * @param RunnableConfig|array<string, mixed> $config
     * @param array{subgraphs?: bool}             $options
     * @throws GraphValueError when there is no checkpointer.
     */
    public function getState(RunnableConfig|array $config, array $options = []): StateSnapshot
    {
        $config = self::asConfig($config);
        $checkpointer = $this->requireCheckpointer($config);

        $delegate = $this->subgraphFor($config, true);
        if ($delegate !== null && !isset($config->configurable[Constants::CONFIG_KEY_READ])) {
            return $delegate[1]->getState(
                self::patchConfigurable($config, [Constants::CONFIG_KEY_CHECKPOINTER => $checkpointer]),
                $options,
            );
        }

        $saved = $checkpointer->getTuple($config->configurable);

        return $this->prepareStateSnapshot(
            $config,
            $saved,
            applyPendingWrites: !isset($config->configurable['checkpoint_id']),
        );
    }

    /**
     * Build a snapshot from a saved checkpoint tuple.
     *
     * Port of `_prepareStateSnapshot`, shared by {@see self::getState()} and
     * {@see self::getStateHistory()}.
     */
    private function prepareStateSnapshot(RunnableConfig $config, ?CheckpointTuple $saved, bool $applyPendingWrites = false): StateSnapshot
    {
        if ($saved === null) {
            // A thread that has never been written is a saver saying "no such thread", not a
            // corrupt record: upstream answers with an empty snapshot, and `config` still echoes
            // what was asked for (the `configurable` map, as this port has always returned it) so
            // the caller can act without re-deriving the thread id.
            return new StateSnapshot(
                values: [],
                next: [],
                config: $config->configurable,
                metadata: [],
                createdAt: null,
                parentConfig: null,
                tasks: [],
            );
        }

        $checkpoint = $saved->checkpoint->copy();
        $channels = ChannelRegistry::emptyChannels($this->channels, $checkpoint->channelValues);
        $savedConfig = new RunnableConfig(configurable: $saved->config['configurable'] ?? $saved->config);

        // Null-task writes (a `Command(update:)` given before a resume) are part of the state.
        $nullWrites = [];
        foreach ($saved->pendingWrites as [$taskId, $channel, $value]) {
            if ($taskId === Constants::NULL_TASK_ID) {
                $nullWrites[] = [(string) $channel, $value];
            }
        }
        if ($nullWrites !== []) {
            Algorithm::applyWrites($checkpoint, $channels, [new PregelInputWrites($nullWrites)], null, $this->triggerToNodes);
        }

        $nextTasks = Algorithm::prepareNextTasks(
            $checkpoint,
            $saved->pendingWrites,
            $this->nodes,
            $channels,
            $savedConfig,
            false,
            new NextTaskExtraFields(
                step: ((int) ($saved->metadata['step'] ?? -1)) + 1,
                channels: $channels,
                processes: $this->nodes,
            ),
            includeCompleted: true,
        );

        if ($applyPendingWrites && $saved->pendingWrites !== []) {
            foreach ($saved->pendingWrites as [$taskId, $channel, $value]) {
                // Control signals are not state. `RESUME` joins the list upstream spells out
                // (`ERROR`, `INTERRUPT`, `SCHEDULED`): it records an answer to a pause, and a
                // task carrying only that is still waiting to run.
                if (in_array($channel, [Constants::ERROR, Constants::INTERRUPT, self::SCHEDULED, Constants::RESUME], true)) {
                    continue;
                }
                if (isset($nextTasks[$taskId])) {
                    $nextTasks[$taskId]->writes[] = [(string) $channel, $value];
                }
            }

            $withWrites = array_values(array_filter(
                $nextTasks,
                static fn (PregelExecutableTask $task): bool => $task->writes !== [],
            ));
            if ($withWrites !== []) {
                Algorithm::applyWrites($checkpoint, $channels, $withWrites, null, $this->triggerToNodes);
            }
        }

        $metadata = $saved->metadata;
        $threadId = $savedConfig->configurable['thread_id'] ?? null;
        if ($metadata !== [] && $threadId !== null) {
            $metadata['thread_id'] = $threadId;
        }

        $next = [];
        $descriptions = [];
        foreach ($nextTasks as $task) {
            if ($task->writes === []) {
                $next[] = $task->name;
            }
            $descriptions[] = $task->toDescription();
        }

        return new StateSnapshot(
            values: IO::readChannels($channels, $this->outputChannels),
            next: $next,
            config: self::patchCheckpointMap($saved->config, $saved->metadata),
            metadata: $metadata,
            createdAt: $checkpoint->ts !== '' ? $checkpoint->ts : null,
            parentConfig: $saved->parentConfig,
            tasks: $descriptions,
        );
    }

    /**
     * The checkpoints saved for a thread, newest first.
     *
     * Port of `getStateHistory`. Useful for debugging, for time travel (feed an entry's `config`
     * back into `invoke(null, ...)`), and for analysing how a graph behaved.
     *
     * Each entry is the array shape of {@see StateSnapshot::toArray()} (`values`, `next`,
     * `config`, `metadata`, `tasks`) plus `createdAt` and `parentConfig`. It is an array rather
     * than a `StateSnapshot` because callers of this method already index it that way; build a
     * snapshot with `getState()` when you need the object.
     *
     * `$options` filters before the list is built, exactly as the saver's `list()` does: `filter`
     * keeps checkpoints whose metadata matches every pair, `before` (a config naming a
     * checkpoint) keeps only older ones, and `limit` caps how many come back. Pass a
     * {@see CheckpointListOptions}, or the same three keys as an array.
     *
     * A config whose `checkpoint_ns` addresses a subgraph is answered by that subgraph.
     *
     * @param RunnableConfig|array<string, mixed>                                                       $config
     * @param CheckpointListOptions|array{filter?: array<string, mixed>, before?: RunnableConfig|array<string, mixed>, limit?: int}|int|null $options
     * @return list<array<string, mixed>>
     * @throws GraphValueError when there is no checkpointer.
     */
    public function getStateHistory(RunnableConfig|array $config, CheckpointListOptions|array|int|null $options = null): array
    {
        $config = self::asConfig($config);
        $checkpointer = $this->requireCheckpointer($config);

        $delegate = $this->subgraphFor($config, false);
        if ($delegate !== null) {
            return $delegate[1]->getStateHistory(
                self::patchConfigurable($config, [Constants::CONFIG_KEY_CHECKPOINTER => $checkpointer]),
                $options,
            );
        }

        if (is_array($options)) {
            $before = $options['before'] ?? null;
            $options = new CheckpointListOptions(
                before: $before instanceof RunnableConfig ? $before->configurable : $before,
                limit: $options['limit'] ?? null,
                filter: $options['filter'] ?? null,
            );
        }

        // Pin the namespace: without it a saver lists every namespace of the thread, so a root
        // graph's history would be interleaved with its subgraphs' checkpoints.
        $merged = self::patchConfigurable($config, [
            'checkpoint_ns' => (string) ($config->configurable['checkpoint_ns'] ?? ''),
        ]);

        $history = [];
        foreach ($checkpointer->list($merged->configurable, $options) as $tuple) {
            $snapshot = $this->prepareStateSnapshot(
                new RunnableConfig(configurable: $tuple->config['configurable'] ?? $tuple->config),
                $tuple,
            );
            $history[] = $snapshot->toArray() + [
                'createdAt' => $snapshot->createdAt,
                'parentConfig' => $snapshot->parentConfig,
            ];
        }

        return $history;
    }

    /**
     * Update a thread's state as if a node had produced `$values`.
     *
     * Port of `updateState`; see {@see self::bulkUpdateState()} for the mechanics.
     *
     * Used for human-in-the-loop edits at a breakpoint, to fork a thread from an old checkpoint
     * (pass that checkpoint's config), and to inject outside input. `$asNode` names the node
     * the update is attributed to; it decides which node's outgoing edges fire next, so it may
     * be omitted only when it is unambiguous (a single node, a fresh thread, or exactly one
     * node that ran last).
     *
     * @param RunnableConfig|array<string, mixed> $config
     * @return RunnableConfig The config of the checkpoint the update wrote.
     * @throws GraphValueError    when there is no checkpointer.
     * @throws InvalidUpdateError when the update cannot be attributed to a node.
     */
    public function updateState(RunnableConfig|array $config, mixed $values, ?string $asNode = null): RunnableConfig
    {
        return $this->bulkUpdateState($config, [['updates' => [['values' => $values, 'asNode' => $asNode]]]]);
    }

    /**
     * Apply a sequence of state updates, one checkpoint per superstep.
     *
     * Port of `bulkUpdateState`. Each superstep is a list of updates applied TOGETHER as one
     * step (so several tasks of one superstep can be recreated); the supersteps are applied in
     * order, each starting from the checkpoint the previous one wrote.
     *
     * An update runs the writers of its `asNode` node over `values`, exactly as the engine would
     * after that node ran: the state channels are written, the node's outgoing edges fire, and
     * channel versions are bumped, so the next `invoke(null, $config)` schedules the successors
     * of `asNode`. That is what makes a fork share no history with its origin.
     *
     * Special `asNode` values:
     *  - `Constants::END` with `values: null` clears all pending tasks;
     *  - `Constants::COPY` forks the checkpoint without changing state (with `values` a list of
     *    `[values, asNode]` pairs it also applies those updates to the fork);
     *  - `Constants::INPUT` writes `values` through the graph's input channels, as a run's input
     *    would be;
     *  - omitting both `values` and `asNode` writes an empty checkpoint.
     *
     * @param RunnableConfig|array<string, mixed>                                              $startConfig
     * @param list<array{updates: list<array{values?: mixed, asNode?: string|null}>}>          $supersteps
     * @return RunnableConfig The config of the last checkpoint written.
     * @throws GraphValueError    when there is no checkpointer.
     * @throws InvalidUpdateError when an update cannot be attributed to a node or must stand alone.
     * @throws \InvalidArgumentException when no superstep, or an empty one, is given.
     */
    public function bulkUpdateState(RunnableConfig|array $startConfig, array $supersteps): RunnableConfig
    {
        $startConfig = self::asConfig($startConfig);
        $checkpointer = $this->requireCheckpointer($startConfig);

        if ($supersteps === []) {
            throw new \InvalidArgumentException('No supersteps provided');
        }
        foreach ($supersteps as $superstep) {
            if (($superstep['updates'] ?? []) === []) {
                throw new \InvalidArgumentException('No updates provided');
            }
        }

        // Delegate to the subgraph a nested namespace addresses.
        $namespace = (string) ($startConfig->configurable['checkpoint_ns'] ?? '');
        if ($namespace !== '' && !isset($startConfig->configurable[Constants::CONFIG_KEY_CHECKPOINTER])) {
            $recast = Config::recastCheckpointNamespace($namespace);
            foreach ($this->getSubgraphs($recast, true) as [, $subgraph]) {
                return $subgraph->bulkUpdateState(
                    self::patchConfigurable($startConfig, [Constants::CONFIG_KEY_CHECKPOINTER => $checkpointer]),
                    $supersteps,
                );
            }
            throw new \RuntimeException("Subgraph \"{$recast}\" not found");
        }

        $currentConfig = $startConfig;
        foreach ($supersteps as $superstep) {
            $currentConfig = $this->updateSuperStep($currentConfig, array_values($superstep['updates']), $checkpointer);
        }

        return $currentConfig;
    }

    /**
     * One superstep of {@see self::bulkUpdateState()}: read the head, apply, write one checkpoint.
     *
     * Port of the `updateSuperStep` closure.
     *
     * @param list<array{values?: mixed, asNode?: string|null, taskId?: string|null}> $updates
     */
    private function updateSuperStep(RunnableConfig $config, array $updates, BaseCheckpointSaver $checkpointer): RunnableConfig
    {
        $saved = $checkpointer->getTuple($config->configurable);
        $checkpoint = $saved !== null ? $saved->checkpoint->copy() : CheckpointFunctions::emptyCheckpoint();
        $previousVersions = $saved?->checkpoint->channelVersions ?? [];
        $step = (int) ($saved?->metadata['step'] ?? -1);
        $parents = (object) ($saved?->metadata['parents'] ?? []);
        $getNextVersion = static fn (int|string|null $v): int|string => $checkpointer->getNextVersion(is_int($v) ? $v : null);

        // Merge the caller's config over the previous checkpoint's.
        $checkpointConfig = self::patchConfigurable($config, [
            'checkpoint_ns' => (string) ($config->configurable['checkpoint_ns'] ?? ''),
        ]);
        $checkpointMetadata = $config->metadata;
        if ($saved !== null && ($saved->config['configurable'] ?? []) !== []) {
            $checkpointConfig = self::patchConfigurable($config, $saved->config['configurable']);
            $checkpointMetadata = array_merge($saved->metadata, $checkpointMetadata);
        }

        $values = $updates[0]['values'] ?? null;
        $asNode = $updates[0]['asNode'] ?? null;

        // Nothing to write but a new checkpoint.
        if ($values === null && $asNode === null) {
            if (count($updates) > 1) {
                throw new InvalidUpdateError('Cannot create empty checkpoint with multiple updates');
            }

            $nextConfig = $checkpointer->put(
                $checkpointConfig->configurable,
                self::createCheckpoint($checkpoint, null, $step),
                ['source' => 'update', 'step' => $step + 1, 'parents' => $parents],
                [],
            );

            return self::wrap(self::patchCheckpointMap($nextConfig, $saved?->metadata));
        }

        $channels = ChannelRegistry::emptyChannels($this->channels, $checkpoint->channelValues);

        if ($values === null && $asNode === Constants::END) {
            if (count($updates) > 1) {
                throw new InvalidUpdateError('Cannot apply multiple updates when clearing state');
            }

            if ($saved !== null) {
                $savedConfig = new RunnableConfig(configurable: $saved->config['configurable'] ?? $saved->config);
                $nextTasks = $this->tasksOf($checkpoint, $saved, $channels, $savedConfig, $step + 1);

                $nullWrites = self::nullWritesOf($saved);
                if ($nullWrites !== []) {
                    Algorithm::applyWrites($checkpoint, $channels, [new PregelInputWrites($nullWrites)], $getNextVersion, $this->triggerToNodes);
                }
                self::attachFinishedWrites($nextTasks, $saved);

                // Applying every current task's writes consumes the channels that triggered
                // them, so nothing is left to run.
                Algorithm::applyWrites($checkpoint, $channels, array_values($nextTasks), $getNextVersion, $this->triggerToNodes);
            }

            $nextConfig = $checkpointer->put(
                $checkpointConfig->configurable,
                self::createCheckpoint($checkpoint, $channels, $step),
                array_merge($checkpointMetadata, ['source' => 'update', 'step' => $step + 1, 'parents' => $parents]),
                CheckpointFunctions::getNewChannelVersions($previousVersions, $checkpoint->channelVersions),
            );

            return self::wrap(self::patchCheckpointMap($nextConfig, $saved?->metadata));
        }

        if ($asNode === Constants::COPY) {
            if (count($updates) > 1) {
                throw new InvalidUpdateError('Cannot copy checkpoint with multiple updates');
            }
            if ($saved === null) {
                throw new InvalidUpdateError('Cannot copy a non-existent checkpoint');
            }

            $nextCheckpoint = self::createCheckpoint($checkpoint, null, $step);
            $forkBase = $saved->parentConfig
                ?? self::patchConfigurable(new RunnableConfig(configurable: $saved->config['configurable'] ?? $saved->config), ['checkpoint_id' => null])->configurable;
            $nextConfig = $checkpointer->put(
                $forkBase,
                $nextCheckpoint,
                ['source' => 'fork', 'step' => $step + 1, 'parents' => (object) ($saved->metadata['parents'] ?? [])],
                [],
            );

            // Clone a checkpoint and update its state in one go, reusing the task ids the forked
            // checkpoint would have, so a pending write recorded against them still matches.
            if (self::isCopyWithUpdates($values)) {
                $forkConfig = new RunnableConfig(configurable: $nextConfig['configurable'] ?? $nextConfig);
                $forkTasks = Algorithm::prepareNextTasks(
                    $nextCheckpoint,
                    $saved->pendingWrites,
                    $this->nodes,
                    $channels,
                    $forkConfig,
                    false,
                    new NextTaskExtraFields(step: $step + 2, channels: $channels, processes: $this->nodes),
                    includeCompleted: true,
                );

                $tasksByName = [];
                foreach ($forkTasks as $task) {
                    $tasksByName[$task->name][] = $task->id;
                }

                $perNode = [];
                $flat = [];
                foreach ($values as [$updateValues, $updateNode]) {
                    $index = $perNode[$updateNode] = ($perNode[$updateNode] ?? -1) + 1;
                    $flat[$updateNode][] = [
                        'values' => $updateValues,
                        'asNode' => $updateNode,
                        'taskId' => $tasksByName[$updateNode][$index] ?? null,
                    ];
                }

                return $this->updateSuperStep(
                    self::wrap(self::patchCheckpointMap($nextConfig, $saved->metadata)),
                    array_merge(...array_values($flat)),
                    $checkpointer,
                );
            }

            return self::wrap(self::patchCheckpointMap($nextConfig, $saved->metadata));
        }

        if ($asNode === Constants::INPUT) {
            if (count($updates) > 1) {
                throw new InvalidUpdateError('Cannot apply multiple updates when updating as input');
            }

            $inputWrites = iterator_to_array(IO::mapInput($this->inputChannels, $values), false);
            if ($inputWrites === []) {
                throw new InvalidUpdateError('Received no input writes for ' . json_encode($this->inputChannels, JSON_PRETTY_PRINT));
            }

            Algorithm::applyWrites($checkpoint, $channels, [new PregelInputWrites($inputWrites)], $getNextVersion, $this->triggerToNodes);

            $nextStep = isset($saved?->metadata['step']) ? (int) $saved->metadata['step'] + 1 : -1;
            $nextConfig = $checkpointer->put(
                $checkpointConfig->configurable,
                self::createCheckpoint($checkpoint, $channels, $nextStep),
                ['source' => 'input', 'step' => $nextStep, 'parents' => $parents],
                CheckpointFunctions::getNewChannelVersions($previousVersions, $checkpoint->channelVersions),
            );

            $checkpointer->putWrites(
                $nextConfig,
                $inputWrites,
                CheckpointFunctions::uuid5(Constants::INPUT, $checkpoint->id),
            );

            return self::wrap(self::patchCheckpointMap($nextConfig, $saved?->metadata));
        }

        // Fold in the writes of tasks that already finished this superstep, unless the caller
        // addressed a specific checkpoint.
        if (!isset($config->configurable['checkpoint_id']) && $saved !== null && $saved->pendingWrites !== []) {
            $savedConfig = new RunnableConfig(configurable: $saved->config['configurable'] ?? $saved->config);
            $pendingTasks = $this->tasksOf($checkpoint, $saved, $channels, $savedConfig, $step + 1);

            $nullWrites = self::nullWritesOf($saved);
            if ($nullWrites !== []) {
                Algorithm::applyWrites($saved->checkpoint->copy(), $channels, [new PregelInputWrites($nullWrites)], null, $this->triggerToNodes);
            }
            self::attachFinishedWrites($pendingTasks, $saved);

            $withWrites = array_values(array_filter(
                $pendingTasks,
                static fn (PregelExecutableTask $task): bool => $task->writes !== [],
            ));
            if ($withWrites !== []) {
                Algorithm::applyWrites($checkpoint, $channels, $withWrites, null, $this->triggerToNodes);
            }
        }

        $validUpdates = $this->attributeUpdates($updates, $checkpoint);

        $tasks = [];
        foreach ($validUpdates as ['values' => $updateValues, 'asNode' => $updateNode, 'taskId' => $taskId]) {
            if (!isset($this->nodes[$updateNode])) {
                throw new InvalidUpdateError("Node \"{$updateNode}\" does not exist");
            }

            // Running the node's own writers over `values` is what makes this "as the node":
            // they write the state channels and trigger the node's outgoing edges.
            $writers = $this->nodes[$updateNode]->getWriters();
            if ($writers === []) {
                throw new InvalidUpdateError("No writers found for node \"{$updateNode}\"");
            }

            $tasks[] = new PregelExecutableTask(
                id: $taskId ?? CheckpointFunctions::uuid5(Constants::INTERRUPT, $checkpoint->id),
                name: $updateNode,
                input: $updateValues,
                writes: [],
                triggers: [Constants::INTERRUPT],
                proc: count($writers) > 1 ? new RunnableSequence($writers) : $writers[0],
                writers: [],
            );
        }

        foreach ($tasks as $task) {
            $taskConfig = $config->with(['run_name' => $config->runName ?? ($this->getName() . 'UpdateState')]);
            $taskConfig->configurable = array_merge($config->configurable, [
                Constants::CONFIG_KEY_SEND => static function (array $items) use ($task): void {
                    foreach ($items as $item) {
                        $task->writes[] = $item;
                    }
                },
                Constants::CONFIG_KEY_READ => static fn (string|array $select, bool $fresh = false): mixed => Algorithm::localRead(
                    $checkpoint,
                    $channels,
                    $task,
                    $select,
                    $fresh,
                ),
            ]);

            $task->proc?->invoke($task->input, $taskConfig);
        }

        foreach ($tasks as $task) {
            // Channel writes belong to the checkpoint being updated; `PUSH` writes (a `Send`)
            // belong to the one about to be written, below.
            $channelWrites = array_values(array_filter($task->writes, static fn (array $w): bool => $w[0] !== Constants::PUSH));
            if ($saved !== null && $channelWrites !== []) {
                $checkpointer->putWrites($checkpointConfig->configurable, $channelWrites, $task->id);
            }
        }

        Algorithm::applyWrites($checkpoint, $channels, $tasks, $getNextVersion, $this->triggerToNodes);

        $nextConfig = $checkpointer->put(
            $checkpointConfig->configurable,
            self::createCheckpoint($checkpoint, $channels, $step + 1),
            ['source' => 'update', 'step' => $step + 1, 'parents' => $parents],
            CheckpointFunctions::getNewChannelVersions($previousVersions, $checkpoint->channelVersions),
        );

        foreach ($tasks as $task) {
            $pushWrites = array_values(array_filter($task->writes, static fn (array $w): bool => $w[0] === Constants::PUSH));
            if ($pushWrites !== []) {
                $checkpointer->putWrites($nextConfig, $pushWrites, $task->id);
            }
        }

        return self::wrap(self::patchCheckpointMap($nextConfig, $saved?->metadata));
    }

    /**
     * Decide which node each update is attributed to.
     *
     * A single update may omit `asNode` when it is unambiguous: the graph has one node; or the
     * thread is fresh and the input channel names a node; or exactly one node was the last to
     * see a newer channel version than any other. Several updates must each name their node.
     *
     * @param list<array{values?: mixed, asNode?: string|null, taskId?: string|null}> $updates
     * @return list<array{values: mixed, asNode: string, taskId: string|null}>
     */
    private function attributeUpdates(array $updates, Checkpoint\Checkpoint $checkpoint): array
    {
        if (count($updates) !== 1) {
            $valid = [];
            foreach ($updates as $update) {
                $node = $update['asNode'] ?? null;
                if ($node === null) {
                    throw new InvalidUpdateError('"asNode" is required when applying multiple updates');
                }
                $valid[] = ['values' => $update['values'] ?? null, 'asNode' => $node, 'taskId' => $update['taskId'] ?? null];
            }

            return $valid;
        }

        $values = $updates[0]['values'] ?? null;
        $asNode = $updates[0]['asNode'] ?? null;
        $taskId = $updates[0]['taskId'] ?? null;

        $nonNullVersion = null;
        foreach ($checkpoint->versionsSeen as $seenVersions) {
            foreach ($seenVersions as $version) {
                if ($version) {
                    $nonNullVersion = $version;
                    break 2;
                }
            }
        }

        if ($asNode === null && count($this->nodes) === 1) {
            $asNode = (string) array_key_first($this->nodes);
        } elseif ($asNode === null && $nonNullVersion === null) {
            if (is_string($this->inputChannels) && isset($this->nodes[$this->inputChannels])) {
                $asNode = $this->inputChannels;
            }
        } elseif ($asNode === null) {
            $lastSeenByNode = [];
            foreach ($checkpoint->versionsSeen as $node => $seenVersions) {
                // `__interrupt__` is not a node: it records what a resume had already seen, and
                // counting it would make every resumed thread look ambiguous.
                if ((string) $node === Constants::INTERRUPT) {
                    continue;
                }
                foreach ($seenVersions as $version) {
                    $lastSeenByNode[] = [$version, (string) $node];
                }
            }
            usort($lastSeenByNode, static fn (array $a, array $b): int => CheckpointFunctions::compareChannelVersions($a[0], $b[0]));

            // Two nodes at the same newest version make the attribution ambiguous.
            $count = count($lastSeenByNode);
            if ($count === 1) {
                $asNode = $lastSeenByNode[0][1];
            } elseif ($count > 1 && $lastSeenByNode[$count - 1][0] !== $lastSeenByNode[$count - 2][0]) {
                $asNode = $lastSeenByNode[$count - 1][1];
            }
        }

        if ($asNode === null) {
            throw new InvalidUpdateError('Ambiguous update, specify "asNode"');
        }

        return [['values' => $values, 'asNode' => $asNode, 'taskId' => $taskId]];
    }

    /**
     * The tasks a saved checkpoint has pending, finished ones included, keyed by task id.
     *
     * @param array<string, BaseChannel> $channels
     * @return array<string, PregelExecutableTask>
     */
    private function tasksOf(Checkpoint\Checkpoint $checkpoint, CheckpointTuple $saved, array $channels, RunnableConfig $savedConfig, int $step): array
    {
        return Algorithm::prepareNextTasks(
            $checkpoint,
            $saved->pendingWrites,
            $this->nodes,
            $channels,
            $savedConfig,
            false,
            new NextTaskExtraFields(step: $step, channels: $channels, processes: $this->nodes, checkpointer: $this->checkpointer),
            includeCompleted: true,
        );
    }

    /**
     * The `[channel, value]` writes recorded against no task (a `Command(update:)`).
     *
     * @return list<array{0: string, 1: mixed}>
     */
    private static function nullWritesOf(CheckpointTuple $saved): array
    {
        $out = [];
        foreach ($saved->pendingWrites as [$taskId, $channel, $value]) {
            if ($taskId === Constants::NULL_TASK_ID) {
                $out[] = [(string) $channel, $value];
            }
        }

        return $out;
    }

    /**
     * Give each pending task the writes it already produced, skipping control signals.
     *
     * @param array<string, PregelExecutableTask> $tasks
     */
    private static function attachFinishedWrites(array $tasks, CheckpointTuple $saved): void
    {
        foreach ($saved->pendingWrites as [$taskId, $channel, $value]) {
            if (in_array($channel, [Constants::ERROR, Constants::INTERRUPT, self::SCHEDULED], true)) {
                continue;
            }
            if (isset($tasks[$taskId])) {
                $tasks[$taskId]->writes[] = [(string) $channel, $value];
            }
        }
    }

    /** Whether `$values` is a non-empty list of `[values, asNode]` pairs (a copy-with-updates). */
    private static function isCopyWithUpdates(mixed $values): bool
    {
        if (!is_array($values) || $values === [] || !array_is_list($values)) {
            return false;
        }
        foreach ($values as $pair) {
            if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2 || !is_string($pair[1])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A new checkpoint derived from `$checkpoint`.
     *
     * Port of `createCheckpoint`: a fresh time-ordered id and timestamp over copies of the version
     * maps; the channel values are re-read from `$channels` when given, else carried over.
     *
     * @param array<string, BaseChannel>|null $channels
     */
    private static function createCheckpoint(Checkpoint\Checkpoint $checkpoint, ?array $channels, int $step): Checkpoint\Checkpoint
    {
        if ($channels === null) {
            $values = $checkpoint->channelValues;
        } else {
            $values = [];
            foreach (ChannelRegistry::getOnlyChannels($channels) as $name => $channel) {
                try {
                    $value = $channel->checkpoint();
                    if ($value !== null) {
                        $values[$name] = $value;
                    }
                } catch (EmptyChannelError) {
                    // An empty channel is omitted rather than stored as null.
                }
            }
        }

        $copy = $checkpoint->copy();

        return new Checkpoint\Checkpoint(
            v: 4,
            id: CheckpointFunctions::uuid6($step),
            ts: gmdate('Y-m-d\TH:i:s.v\Z'),
            channelValues: $values,
            channelVersions: $copy->channelVersions,
            versionsSeen: $copy->versionsSeen,
        );
    }

    /**
     * @param array<string, mixed> $config A saver's `['configurable' => [...]]`.
     */
    private static function wrap(array $config): RunnableConfig
    {
        return new RunnableConfig(configurable: $config['configurable'] ?? $config);
    }

    /**
     * Check the graph's wiring, throwing {@see GraphValidationError} on the first fault.
     *
     * Port of `Pregel.validate`. Not run automatically: upstream validates on construction, but
     * this port's builders assemble the graph through constructor arguments they do not own, so
     * validation is an explicit call. Returns `$this` for chaining.
     */
    public function validate(): static
    {
        Validate::validateGraph(
            $this->nodes,
            $this->channels,
            $this->inputChannels,
            $this->outputChannels,
            $this->streamChannels === [] ? null : $this->streamChannels,
            $this->interruptAfter === ['*'] ? '*' : $this->interruptAfter,
            $this->interruptBefore === ['*'] ? '*' : $this->interruptBefore,
        );

        return $this;
    }
}
