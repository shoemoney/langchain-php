<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Channels\BaseChannel;
use LangGraph\Channels\ChannelRegistry;
use LangGraph\Cache\BaseCache;
use LangGraph\Errors\EmptyInputError;
use LangGraph\Errors\GraphInterrupt;
use LangGraph\Errors\GraphRecursionError;
use LangGraph\Errors\Guard;
use LangGraph\Pregel\Checkpoint\BaseCheckpointSaver;
use LangGraph\Pregel\Checkpoint\Checkpoint;
use LangGraph\Pregel\Checkpoint\CheckpointFunctions;
use LangGraph\Pregel\Checkpoint\CheckpointTuple;
use LangGraph\Store\AsyncBatchedStore;
use LangGraph\Store\BaseStore;

/**
 * The superstep loop, expressed as a PHP generator.
 *
 * Port of `PregelLoop` from `langgraph-core/src/pregel/loop.ts`.
 *
 * ## Why a generator
 *
 * In TypeScript the loop is an `AsyncGenerator`, and `invoke()` awaits it to
 * completion while `stream()` yields it outward. PHP has one primitive that
 * covers both: `\Generator`. So this class *is* the loop — {@see self::run()}
 * performs supersteps and yields `[mode, payload]` chunks as it goes. A caller
 * who wants the final value drains the generator; a caller who wants progress
 * reads the yields. There is no second implementation to keep in sync, and no
 * event loop, because the generator *is* the control flow.
 *
 * ## The barrier
 *
 * Each superstep is a barrier: every task in step N must finish before any
 * write is applied, and no task in step N+1 starts until those writes are
 * applied and checkpointed. That is what makes a graph deterministic — nodes
 * in the same step cannot see each other's partial output.
 *
 * ## Checkpoint-before-emit
 *
 * The order inside a superstep matters and is not free to choose: writes are
 * applied, the checkpoint is written, and *only then* are `values` emitted. A
 * consumer that receives a `values` chunk and then crashes must be able to
 * resume from a checkpoint that already contains that state. Emitting first
 * would hand out state that nothing can resume from.
 *
 * @phpstan-type PendingWriteList list<array{0: string, 1: string, 2: mixed}>
 */
class PregelLoop
{
    /** Chunks buffered by `_emit`, drained by the generator. */
    private array $streamBuffer = [];

    /** The final output, readable via `Generator::getReturn()`. */
    public mixed $output = null;

    public function __construct(
        public mixed $input,
        public RunnableConfig $config,
        public ?BaseCheckpointSaver $checkpointer,
        public Checkpoint $checkpoint,
        public array $checkpointMetadata,
        public array $checkpointPreviousVersions,
        public array $checkpointPendingWrites,
        public RunnableConfig $checkpointConfig,
        public array $channels,
        public int $step,
        public int $stop,
        public string|array $outputKeys = [],
        public string|array $streamKeys = [],
        public array $nodes = [],
        public array $checkpointNamespace = [],
        public bool $skipDoneTasks = true,
        public bool $isNested = false,
        public bool $resumeAtHead = false,
        public array $interruptAfter = [],
        public array $interruptBefore = [],
        public bool $debug = false,
        public array $triggerToNodes = [],
        public array $status = [],
        public array $tasks = [],
        public array $toInterrupt = [],
        public ?array $updatedChannels = null,
        public ?AsyncBatchedStore $store = null,
        public ?BaseCache $cache = null,
    ) {
    }

    /**
     * Build a loop, loading any saved checkpoint.
     *
     * Port of `PregelLoop.initialize`.
     *
     * Two decisions here are subtle and both are about resuming correctly:
     *
     *  - `skipDoneTasks` is false only when the caller named an explicit
     *    `checkpoint_id`. Without one, the loop replays from the head and must
     *    re-run everything; with one, it is re-entering a specific step and must
     *    re-attach the writes that step already produced.
     *  - The step counter is `metadata.step + 1`. A fresh checkpoint is stored
     *    at step `-2` ("input"), so the first real superstep is `0`.
     *
     * @param  array<string, mixed>              $params Keys: `channelSpecs`
     *         (array<string, BaseChannel>), `outputKeys` (string|list<string>),
     *         `nodes` (array<string, PregelNode>), `interruptAfter`
     *         (list<string>|string). These are ARRAY KEYS, not parameters —
     *         the signature takes one `$params` array, so naming them as
     *         `@param` told a reader to pass four arguments.
     */
    public static function initialize(array $params): self
    {
        $config = $params['config'];

        $skipDoneTasks = $config->configurable === [] || !array_key_exists('checkpoint_id', $config->configurable);

        $scratchpad = $config->configurable[Constants::CONFIG_KEY_SCRATCHPAD] ?? null;
        if ($scratchpad instanceof PregelScratchpad && $scratchpad->subgraphCounter > 0) {
            $config = clone $config;
            $config->configurable[Constants::CONFIG_KEY_CHECKPOINT_NS] = implode(
                Constants::CHECKPOINT_NAMESPACE_SEPARATOR,
                [
                    (string) ($config->configurable[Constants::CONFIG_KEY_CHECKPOINT_NS] ?? ''),
                    (string) $scratchpad->subgraphCounter,
                ]
            );
            $scratchpad->subgraphCounter += 1;
        }

        $isNested = array_key_exists(Constants::CONFIG_KEY_READ, $config->configurable);
        if (!$isNested
            && isset($config->configurable['checkpoint_ns'])
            && $config->configurable['checkpoint_ns'] !== '') {
            // A top-level graph cannot start inside a subgraph's namespace.
            $config = clone $config;
            $config->configurable['checkpoint_ns'] = '';
            unset($config->configurable['checkpoint_id']);
        }

        $checkpointConfig = $config;
        if (!isset($config->configurable['checkpoint_id'])) {
            $ns = (string) ($config->configurable['checkpoint_ns'] ?? '');
            $map = $config->configurable[Constants::CONFIG_KEY_CHECKPOINT_MAP] ?? null;
            if (is_array($map) && isset($map[$ns]) && $map[$ns] !== '') {
                $checkpointConfig = clone $config;
                $checkpointConfig->configurable['checkpoint_id'] = $map[$ns];
            }
        }

        $checkpointNamespace = self::namespaceFromNs($config->configurable['checkpoint_ns'] ?? null);

        // A subgraph is compiled without a checkpointer of its own and persists through its
        // parent's: the parent publishes it on the task config, and it takes priority over the
        // graph's own (upstream `_defaults`). Without this a subgraph never saved a checkpoint,
        // so a resume found nothing, minted a fresh checkpoint id, derived different task ids
        // and re-fired the same interrupt forever.
        // `checkpointer: false` opts out entirely and is checked first (upstream `_defaults`).
        $checkpointer = ($params['checkpointerDisabled'] ?? false)
            ? null
            : ($config->configurable[Constants::CONFIG_KEY_CHECKPOINTER]
                ?? $params['checkpointer']
                ?? null);
        $saved = null;
        if ($checkpointer !== null) {
            $saved = $checkpointer->getTuple($checkpointConfig->configurable);
        }

        $hasPersistedParent = $saved !== null;

        if ($saved === null) {
            $saved = new CheckpointTuple(
                config: [],
                checkpoint: CheckpointFunctions::emptyCheckpoint(),
                metadata: ['source' => 'input', 'step' => -2, 'parents' => new \stdClass()],
                pendingWrites: [],
            );
        }

        $prevCheckpointConfig = $saved->parentConfig;
        $checkpoint = $saved->checkpoint->copy();
        $checkpointMetadata = $saved->metadata;
        $checkpointPendingWrites = $saved->pendingWrites;

        // Time-travelling into a subgraph: drop resume values written during
        // the original run, so the interrupt re-fires instead of silently
        // consuming a stale answer.
        $currentNs = $config->configurable['checkpoint_ns'] ?? null;
        $map = $config->configurable[Constants::CONFIG_KEY_CHECKPOINT_MAP] ?? null;
        $isDirectSubgraphTimeTravel = is_string($currentNs)
            && $currentNs !== ''
            && is_array($map)
            && array_key_exists($currentNs, $map);

        if ($isDirectSubgraphTimeTravel && $checkpointPendingWrites !== []) {
            $checkpointPendingWrites = array_values(array_filter(
                $checkpointPendingWrites,
                static fn (array $w): bool => $w[1] !== Constants::RESUME
            ));
        }

        $channels = ChannelRegistry::emptyChannels($params['channelSpecs'], $checkpoint->channelValues);

        $step = (int) ($checkpointMetadata['step'] ?? 0) + 1;
        $stop = $step + $config->recursionLimit + 1;
        $checkpointPreviousVersions = $checkpoint->channelVersions;

        // The store reaches every task through the config, so a subgraph run as a node inherits it
        // (upstream `config.store ?? this.store`). It is added to the loop's own copy of the config
        // only: the checkpointer config above and the caller's config never carry it.
        $store = ($params['store'] ?? null) instanceof BaseStore ? new AsyncBatchedStore($params['store']) : null;
        if ($store !== null) {
            $store->start();
            $config = clone $config;
            $config->configurable[Constants::CONFIG_KEY_STORE] = $store;
        }

        $loop = new self(
            input: $params['input'] ?? null,
            config: $config,
            checkpointer: $checkpointer,
            checkpoint: $checkpoint,
            checkpointMetadata: $checkpointMetadata,
            checkpointPreviousVersions: $checkpointPreviousVersions,
            checkpointPendingWrites: $checkpointPendingWrites,
            checkpointConfig: $checkpointConfig,
            channels: $channels,
            step: $step,
            stop: $stop,
            outputKeys: $params['outputKeys'] ?? [],
            streamKeys: $params['streamKeys'] ?? [],
            nodes: $params['nodes'] ?? [],
            checkpointNamespace: $checkpointNamespace,
            skipDoneTasks: $skipDoneTasks,
            isNested: $isNested,
            interruptAfter: $params['interruptAfter'] ?? [],
            interruptBefore: $params['interruptBefore'] ?? [],
            debug: (bool) ($params['debug'] ?? false),
            triggerToNodes: $params['triggerToNodes'] ?? [],
            status: ['status' => 'pending'],
            store: $store,
            cache: $params['cache'] ?? null,
        );
        $loop->hasPersistedParent = $hasPersistedParent;

        return $loop;
    }

    /** Whether a real checkpoint was loaded at initialization. */
    public bool $hasPersistedParent = false;

    /**
     * Whether this run is resuming rather than starting fresh.
     *
     * Port of the `isResuming` getter. Three independent signals count, because
     * any one of them means the caller believes there is prior state:
     * an explicit resuming flag, a null/`Command(resume:)` input, or the run id
     * matching the checkpoint's. The `hasChannelVersions` guard is what stops a
     * brand-new thread from being mistaken for a resume — with no versions
     * recorded there is nothing to resume *from*.
     */
    public function isResuming(): bool
    {
        $hasChannelVersions = $this->checkpoint->channelVersions !== [];

        $configResuming = $this->config->configurable[Constants::CONFIG_KEY_RESUMING] ?? null;

        $inputIsNullOrUndefined = $this->input === null;
        $inputIsCommandResuming = $this->input instanceof Command && $this->input->resume !== null;
        $inputIsResuming = $this->input instanceof PregelInputResuming;

        $runIdMatchesPrevious = !$this->isNested
            && ($this->config->metadata['run_id'] ?? null) !== null
            && ($this->checkpointMetadata['run_id'] ?? null) !== null
            && $this->config->metadata['run_id'] === $this->checkpointMetadata['run_id'];

        return $hasChannelVersions && (
            $configResuming === true
            || $inputIsNullOrUndefined
            || $inputIsCommandResuming
            || $inputIsResuming
            || $runIdMatchesPrevious
        );
    }

    /** Whether this loop is replaying a past step rather than advancing. */
    public function isReplaying(): bool
    {
        return !$this->skipDoneTasks;
    }

    /**
     * Drive the graph, yielding `[mode, payload]` chunks.
     *
     * This is the port of `Pregel._runLoop`. Each iteration is one superstep:
     * {@see self::tick()} decides what runs and applies the previous step's
     * writes, the runner executes the resulting tasks, and the loop goes again.
     *
     * The return value is the final output, reachable with
     * `Generator::getReturn()`.
     *
     * @param  string|list<string> $inputKeys
     * @return \Generator<int, array{0: string, 1: mixed}, mixed, mixed>
     */
    public function run($inputKeys = []): \Generator
    {
        $runner = new PregelRunner($this);

        try {
            while ($this->tick($inputKeys)) {
                yield from $this->drain();

                foreach ($this->matchCachedWrites() as $task) {
                    $this->outputWrites($task->id, $task->writes, true);
                }
                yield from $this->drain();

                $runner->tick();
                yield from $this->drain();
            }

            if (($this->status['status'] ?? null) === 'out_of_steps') {
                throw new GraphRecursionError(
                    'Recursion limit of ' . $this->config->recursionLimit . ' reached '
                    . 'without hitting a stop condition. You can increase the '
                    . 'limit by setting the "recursionLimit" config key.'
                );
            }
        } catch (\Throwable $e) {
            $suppress = $this->finishAndHandleError($e);
            if (!$suppress) {
                yield from $this->drain();
                throw $e;
            }
        }

        $this->finishAndHandleError();
        yield from $this->drain();

        return $this->output;
    }

    /**
     * Execute one superstep.
     *
     * Port of `PregelLoop.tick`. Returns true while the graph should keep
     * running. This is where a superstep is *decided*, as opposed to executed:
     * it applies the previous step's writes, prepares the next step's tasks,
     * decides whether to interrupt, and checkpoints.
     *
     * The branches, in order:
     *
     *  1. **Not started** — run {@see self::first()} to apply input.
     *  2. **A pending interrupt** — halt, waiting for a resume.
     *  3. **All tasks have writes** — the superstep is complete; apply them,
     *     checkpoint, and prepare what runs next.
     *  4. **Otherwise** — return false; the runner still has work to do.
     *
     * @param string|list<string> $inputKeys
     */
    public function tick($inputKeys = []): bool
    {
        // Port of `![INPUT_DONE, INPUT_RESUMING].includes(this.input)`. A
        // `null` input is *not* "already started": it is the marker for "carry
        // on from the checkpoint", and it still needs `first()` to decide
        // whether that means resuming or is a caller error.
        $inputAlreadyApplied = $this->input instanceof PregelInputDone
            || $this->input instanceof PregelInputResuming;

        if (!$inputAlreadyApplied) {
            $this->first($inputKeys);
        } elseif ($this->toInterrupt !== []) {
            $this->status['status'] = 'interrupt_before';
            throw new GraphInterrupt();
        } elseif ($this->allTasksHaveWrites()) {
            $finishTaskList = array_values($this->tasks);

            $this->updatedChannels = Algorithm::applyWrites(
                $this->checkpoint,
                $this->channels,
                $finishTaskList,
                $this->nextVersion(...),
                $this->triggerToNodes,
            );

            $writes = [];
            foreach ($finishTaskList as $task) {
                foreach ($task->writes as $write) {
                    $writes[] = $write;
                }
            }

            foreach (IO::mapOutputValues($this->outputKeys, $writes, $this->channels) as $valuesOutput) {
                $this->emitValues([$valuesOutput]);
            }

            // Clear pending writes, then checkpoint — the checkpoint records the
            // superstep as complete, so it must be written after the writes it
            // supersedes are no longer pending.
            $this->checkpointPendingWrites = [];
            $this->putCheckpoint('loop');

            if (Algorithm::shouldInterrupt($this->checkpoint, $this->interruptAfter, $finishTaskList)) {
                $this->status['status'] = 'interrupt_after';
                throw new GraphInterrupt();
            }

            unset($this->config->configurable[Constants::CONFIG_KEY_RESUMING]);
        } else {
            return false;
        }

        if ($this->step > $this->stop) {
            $this->status['status'] = 'out_of_steps';

            return false;
        }

        $nextTasks = Algorithm::prepareNextTasks(
            $this->checkpoint,
            $this->checkpointPendingWrites,
            $this->nodes,
            $this->channels,
            $this->config,
            true,
            new NextTaskExtraFields(
                step: $this->step,
                channels: $this->channels,
                processes: $this->nodes,
                checkpointer: $this->checkpointer,
                triggerToNodes: $this->triggerToNodes,
                updatedChannels: $this->updatedChannels,
            ),
        );
        $this->tasks = $nextTasks;
        $taskList = array_values($this->tasks);

        if ($taskList === []) {
            $this->status['status'] = 'done';

            return false;
        }

        // A previous loop may have left writes for these tasks. Re-attaching
        // them is what makes a resume skip work already done rather than redo it.
        if ($this->skipDoneTasks && $this->checkpointPendingWrites !== []) {
            foreach ($this->checkpointPendingWrites as [$tid, $k, $v]) {
                if (in_array($k, [
                    Constants::ERROR,
                    Constants::ERROR_SOURCE_NODE,
                    Constants::INTERRUPT,
                    Constants::RESUME,
                ], true)) {
                    continue;
                }
                foreach ($this->tasks as $task) {
                    if ($task->id === $tid) {
                        $task->writes[] = [$k, $v];
                        break;
                    }
                }
            }

            foreach (array_values($this->tasks) as $task) {
                if ($task->writes !== []) {
                    $this->outputWrites($task->id, $task->writes, true);
                }
            }
        }

        // Every task already has its writes: the graph is done, so re-tick
        // rather than dispatching nothing.
        if ($this->allTasksHaveWrites()) {
            return $this->tick($inputKeys);
        }

        if (Algorithm::shouldInterrupt($this->checkpoint, $this->interruptBefore, $taskList)) {
            $this->status['status'] = 'interrupt_before';
            throw new GraphInterrupt();
        }

        return true;
    }

    /**
     * Apply the run's input and decide whether it is a resume.
     *
     * Port of `_first`. Runs exactly once per loop, before the first superstep.
     *
     * The interesting part is the branch between "fresh input" and "resuming".
     * Fresh input is written to the input channels and gets its own `input`
     * checkpoint. Resuming must *not* — it continues an existing checkpoint, and
     * writing a new one would reset the thread's history and the step counter
     * with it. When both apply (a `Command` carrying both an update and a
     * resume), resume wins, which is why the check is ordered that way.
     *
     * @param string|list<string> $inputKeys
     */
    public function first($inputKeys = []): void
    {
        $configurable = $this->config->configurable;

        // A resume value handed down by a parent subgraph.
        $scratchpad = $configurable[Constants::CONFIG_KEY_SCRATCHPAD] ?? null;
        if ($scratchpad instanceof PregelScratchpad && $scratchpad->nullResume !== null) {
            $this->putWrites(Constants::NULL_TASK_ID, [[Constants::RESUME, $scratchpad->nullResume]]);
        }

        if ($this->input instanceof Command) {
            $hasResume = $this->input->resume !== null;

            if ($hasResume
                && is_array($this->input->resume)
                && $this->input->resume !== []
                && self::allKeysAreHashes($this->input->resume)) {
                $this->config->configurable[Constants::CONFIG_KEY_RESUME_MAP] = $this->input->resume;
            }

            if ($hasResume && $this->checkpointer === null) {
                throw new \LogicException('Cannot use Command(resume=...) without checkpointer');
            }

            $writesByTask = [];
            foreach (IO::mapCommand($this->input, $this->checkpointPendingWrites) as [$tid, $key, $value]) {
                $writesByTask[$tid][] = [$key, $value];
            }

            if ($writesByTask === []) {
                throw new EmptyInputError('Received empty Command input');
            }

            foreach ($writesByTask as $tid => $ws) {
                $this->putWrites($tid, $ws);
            }
        }

        // Writes belonging to the graph rather than to any task.
        $nullWrites = [];
        foreach ($this->checkpointPendingWrites as $write) {
            if ($write[0] === Constants::NULL_TASK_ID) {
                $nullWrites[] = [$write[1], $write[2] ?? null];
            }
        }

        if ($nullWrites !== []) {
            Algorithm::applyWrites(
                $this->checkpoint,
                $this->channels,
                [new PregelInputWrites($nullWrites)],
                $this->nextVersion(...),
                $this->triggerToNodes,
            );
        }

        $inputIsCommand = $this->input instanceof Command;
        $isCommandUpdateOrGoto = $inputIsCommand && $nullWrites !== [];

        $isTimeTraveling = $this->isReplaying()
            && (
                ($this->isNested
                    && isset($configurable[Constants::CONFIG_KEY_CHECKPOINT_NS])
                    && $configurable[Constants::CONFIG_KEY_CHECKPOINT_NS] !== ''
                    && isset($configurable[Constants::CONFIG_KEY_CHECKPOINT_MAP])
                    && array_key_exists($configurable[Constants::CONFIG_KEY_CHECKPOINT_NS], $configurable[Constants::CONFIG_KEY_CHECKPOINT_MAP]))
                || !(
                    ($inputIsCommand && $this->input->resume !== null)
                    || ($configurable[Constants::CONFIG_KEY_RESUMING] ?? null) === true
                    || $this->resumeAtHead
                )
            );

        if ($isTimeTraveling) {
            $this->checkpointPendingWrites = array_values(array_filter(
                $this->checkpointPendingWrites,
                static fn (array $w): bool => $w[1] !== Constants::RESUME
            ));
        }

        $cachedIsResuming = $this->isResuming();

        if ($cachedIsResuming || $isCommandUpdateOrGoto) {
            // Snapshot the versions seen at the interrupt point, so
            // `shouldInterrupt` can tell "nothing changed" from "a node should
            // run again" on a later resume.
            $interruptSeen = $this->checkpoint->versionsSeen[Constants::INTERRUPT] ?? [];
            foreach ($this->channels as $channelName => $channel) {
                if (array_key_exists($channelName, $this->checkpoint->channelVersions)) {
                    $interruptSeen[$channelName] = $this->checkpoint->channelVersions[$channelName];
                }
            }
            $this->checkpoint->versionsSeen[Constants::INTERRUPT] = $interruptSeen;

            if ($isTimeTraveling
                && $this->checkpointMetadata['source'] !== 'update'
                && $this->checkpointMetadata['source'] !== 'fork') {
                $this->checkpointPendingWrites = array_values(array_filter(
                    $this->checkpointPendingWrites,
                    static fn (array $w): bool => $w[1] !== Constants::INTERRUPT
                ));
                $this->putCheckpoint('fork');
            }

            foreach (IO::mapOutputValues($this->outputKeys, true, $this->channels) as $valuesOutput) {
                $this->emitValues([$valuesOutput]);
            }

            if ($cachedIsResuming) {
                $this->input = PregelInputResuming::instance();
            } elseif ($isCommandUpdateOrGoto) {
                // Persist before emitting, so a `values` chunk always points at
                // a checkpoint that contains the state it reported.
                $this->putCheckpoint('input');
                $this->input = PregelInputDone::instance();
            }
        } else {
            $inputWrites = [];
            foreach (IO::mapInput($inputKeys, $this->input) as $write) {
                $inputWrites[] = $write;
            }

            if ($inputWrites !== []) {
                // Input writes are applied through a synthetic task so they go
                // through exactly the same fold as everything else, ordering
                // included. Reusing the fold is what keeps the ordering
                // guarantee total rather than "except for the input".
                $discardTasks = Algorithm::prepareNextTasks(
                    $this->checkpoint,
                    $this->checkpointPendingWrites,
                    $this->nodes,
                    $this->channels,
                    $this->config,
                    true,
                    new NextTaskExtraFields(
                        step: $this->step,
                        channels: $this->channels,
                        processes: $this->nodes,
                    ),
                );

                $this->updatedChannels = Algorithm::applyWrites(
                    $this->checkpoint,
                    $this->channels,
                    array_merge(array_values($discardTasks), [new PregelInputWrites($inputWrites)]),
                    $this->nextVersion(...),
                    $this->triggerToNodes,
                );

                $this->putCheckpoint('input');
                $this->input = PregelInputDone::instance();
            } elseif (!array_key_exists(Constants::CONFIG_KEY_RESUMING, $this->config->configurable)) {
                throw new EmptyInputError(
                    'Received no input writes for ' . json_encode($inputKeys)
                );
            } else {
                $this->input = PregelInputDone::instance();
            }
        }

        if (!$this->isNested) {
            $this->config->configurable[Constants::CONFIG_KEY_RESUMING] = $this->isResuming();
        }
    }

    /**
     * Record a task's writes so the next step can see them.
     *
     * Port of `putWrites`. Called as each task finishes.
     *
     * Three details are load-bearing:
     *
     *  - **Writes to the same task replace, not append.** A retried task would
     *    otherwise accumulate two copies of its output.
     *  - **Writes are persisted *before* the checkpoint that will read them.**
     *    This ordering is what makes a crash mid-superstep resumable: the
     *    surviving outputs are already on disk.
     *  - **Untracked channel values are stripped.** A node may hold a large
     *    value in memory for its own use; persisting it would bloat every
     *    checkpoint for no benefit on resume.
     *
     * @param list<array{0: string, 1: mixed}> $writes
     */
    public function putWrites(string $taskId, array $writes): void
    {
        if ($writes === []) {
            return;
        }

        $writesCopy = $writes;

        // Collapse repeated writes to the same reserved channel: only the last
        // one carries information (the error, the interrupt, the resume value).
        $allReserved = true;
        foreach ($writesCopy as $write) {
            if (!array_key_exists($write[0], PregelRunner::WRITES_IDX_MAP)) {
                $allReserved = false;
                break;
            }
        }
        if ($allReserved) {
            $deduped = [];
            foreach ($writesCopy as $write) {
                $deduped[$write[0]] = $write;
            }
            $writesCopy = array_values($deduped);
        }

        $writesToSave = $writesCopy;
        if ($this->hasUntrackedChannels()) {
            $writesToSave = [];
            foreach ($writesCopy as $write) {
                $channel = $this->channels[$write[0]] ?? null;
                if ($channel instanceof BaseChannel && $channel->lcGraphName === 'UntrackedValue') {
                    continue;
                }
                if ($write[0] === Constants::TASKS) {
                    $send = Send::fromMixed($write[1] ?? null);
                    if ($send !== null) {
                        $writesToSave[] = [
                            Constants::TASKS,
                            self::sanitizeUntrackedValuesInSend($send, $this->channels),
                        ];
                        continue;
                    }
                }
                $writesToSave[] = $write;
            }
        }

        $this->checkpointPendingWrites = array_values(array_filter(
            $this->checkpointPendingWrites,
            static fn (array $w): bool => $w[0] !== $taskId
        ));

        foreach ($writesToSave as $write) {
            $this->checkpointPendingWrites[] = [$taskId, $write[0], $write[1] ?? null];
        }

        if ($this->checkpointer !== null) {
            $config = clone $this->checkpointConfig;
            $config->configurable['thread_id'] ??= $this->config->configurable['thread_id'] ?? null;
            $config->configurable[Constants::CONFIG_KEY_CHECKPOINT_NS] = (string) ($this->config->configurable['checkpoint_ns'] ?? '');
            $config->configurable['checkpoint_id'] = $this->checkpoint->id;
            $this->checkpointer->putWrites($config->configurable, $writesToSave, $taskId);
        }

        if ($this->tasks !== []) {
            $this->outputWrites($taskId, $writesCopy);
        }

        $this->cacheTaskWrites($taskId, $writesCopy);
    }

    /**
     * Remember a finished task's writes under its cache key.
     *
     * Port of the tail of `putWrites`. Only a task that declared a cache policy
     * has a key, and only a successful one is cached: a task that errored or
     * interrupted must run again.
     *
     * @param list<array{0: string, 1: mixed}> $writes
     */
    private function cacheTaskWrites(string $taskId, array $writes): void
    {
        if ($this->cache === null || $this->tasks === []) {
            return;
        }

        $task = $this->tasks[$taskId] ?? null;
        if ($task === null || $task->cacheKey === null) {
            return;
        }

        if ($writes[0][0] === Constants::ERROR || $writes[0][0] === Constants::INTERRUPT) {
            return;
        }

        $this->cache->set([[
            'key' => [$task->cacheKey['ns'], $task->cacheKey['key']],
            'value' => $task->writes,
            'ttl' => $task->cacheKey['ttl'],
        ]]);
    }

    /**
     * Emit a finished task's writes to the stream.
     *
     * Port of `_outputWrites`.
     *
     * The interrupt branch has a subtle rule: a task path ending in `true` means
     * this task was invoked by another task rather than scheduled by the engine,
     * and its interrupts belong to the *parent*, which will report them. Emitting
     * here too would double-report. The trailing flag on the path is the only
     * thing that distinguishes the two cases, which is why it exists.
     *
     * @param list<array{0: string, 1: mixed}> $writes
     */
    public function outputWrites(string $taskId, array $writes, bool $cached = false): void
    {
        $task = $this->tasks[$taskId] ?? null;
        if ($task === null) {
            return;
        }

        if (in_array(Constants::TAG_HIDDEN, $task->config?->tags ?? [], true)) {
            return;
        }

        if ($writes !== []) {
            $firstChannel = $writes[0][0] ?? null;

            if ($firstChannel === Constants::INTERRUPT) {
                $path = $task->path;
                if ($path !== null && $path->isPush() && $path->isCallPath()) {
                    return;
                }

                $interruptWrites = [];
                foreach ($writes as $write) {
                    if ($write[0] === Constants::INTERRUPT) {
                        $interruptWrites[] = $write[1] ?? null;
                    }
                }

                $this->emit([[Constants::INTERRUPT => $interruptWrites]], 'updates');
                $this->emit([[Constants::INTERRUPT => $interruptWrites]], 'values');
            } elseif ($firstChannel !== Constants::ERROR) {
                foreach (IO::mapOutputUpdates($this->outputKeys, [[$task, $writes]], $cached) as $update) {
                    $this->emit([$update], 'updates');
                }
            }
        }
    }

    /**
     * Whether every prepared task has already produced its writes.
     *
     * This is the superstep barrier. `true` means the step is complete and its
     * writes can be applied; `false` means the runner still has work.
     */
    private function allTasksHaveWrites(): bool
    {
        foreach ($this->tasks as $task) {
            if ($task->writes === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Re-attach previously-recorded writes to freshly prepared tasks.
     *
     * @param array<string, PregelExecutableTask> $tasks
     */
    public function matchWrites(array $tasks): void
    {
        foreach ($this->checkpointPendingWrites as [$tid, $k, $v]) {
            if (in_array($k, [Constants::ERROR, Constants::INTERRUPT, Constants::RESUME], true)) {
                continue;
            }
            foreach ($tasks as $task) {
                if ($task->id === $tid) {
                    $task->writes[] = [$k, $v];
                    break;
                }
            }
        }

        foreach ($tasks as $task) {
            if ($task->writes !== []) {
                $this->outputWrites($task->id, $task->writes, true);
            }
        }
    }

    /**
     * Fill cacheable tasks from the node cache.
     *
     * Port of `_matchCachedWrites`. A task is looked up only when it has a cache
     * key and no writes yet; a hit copies the cached writes onto the task, which
     * then never runs.
     *
     * @return list<PregelExecutableTask> Tasks whose writes came from the cache.
     */
    public function matchCachedWrites(): array
    {
        if ($this->cache === null) {
            return [];
        }

        $keys = [];
        $keyMap = [];
        foreach ($this->tasks as $task) {
            if ($task->cacheKey !== null && $task->writes === []) {
                $fullKey = [$task->cacheKey['ns'], $task->cacheKey['key']];
                $keys[] = $fullKey;
                $keyMap[self::serializeCacheKey($fullKey[0], $fullKey[1])] = $task;
            }
        }

        if ($keys === []) {
            return [];
        }

        $matched = [];
        foreach ($this->cache->get($keys) as $hit) {
            $task = $keyMap[self::serializeCacheKey($hit['key'][0], $hit['key'][1])] ?? null;
            if ($task !== null) {
                array_push($task->writes, ...$hit['value']);
                $matched[] = $task;
            }
        }

        return $matched;
    }

    /**
     * Finalise the run, converting an interrupt into a clean return.
     *
     * Port of `finishAndHandleError`. Returns true when the error was an
     * interrupt that the loop is *supposed* to absorb — the run is suspended,
     * not failed, and the caller should see the state as it stands.
     */
    public function finishAndHandleError(?\Throwable $error = null): bool
    {
        // The exit checkpoint: one final save so the thread's head records the state
        // the run actually ended in.
        //
        // `exiting` is upstream's `this.checkpointMetadata === inputMetadata` — an
        // IDENTITY test on the metadata OBJECT, true only while the loop still holds
        // the very object it was handed, i.e. before any superstep has replaced it.
        // PHP arrays have no identity, so it has to be reconstructed, and it was
        // reconstructed as `true` ALWAYS.
        //
        // That is a false positive on every normal run, and putCheckpoint responds by
        // REUSING the current checkpoint id instead of minting one — so the final save
        // OVERWROTE the last real superstep instead of appending to it. Measured, same
        // graph in both runtimes:
        //
        //     upstream  metadata steps  -1 (input), 0, 1, 2
        //     this port                 -1 (input), 0, 1, 3
        //
        // Step 2 was destroyed and replaced by a step 3 that never ran a superstep. The
        // warning directly above `putCheckpoint()` describes exactly this — "a false
        // positive would overwrite successive supersteps onto a single row and silently
        // destroy the thread's history" — and it happened anyway.
        //
        // The faithful reconstruction is `source === 'input'`: that is the metadata the
        // loop was handed and has not yet replaced.
        // Upstream gates this whole block on `this.durability === "exit"`, and
        // `durability` DEFAULTS TO "async" (pregel/index.ts:1922-1927):
        //
        //     const defaultDurability = config.durability ?? checkpointDuringDurability
        //       ?? config?.configurable?.[CONFIG_KEY_DURABILITY] ?? "async";
        //
        // So on a default run upstream does NOT write a final checkpoint here at all -
        // the last per-superstep checkpoint already records the state the run ended in.
        // The port wrote one unconditionally, which added a row rather than replacing
        // one.
        //
        // With BOTH the identity check and the durability gate missing, the port did
        // two wrong things at once: it saved when upstream would not, and it reused
        // the checkpoint id when it did. Measured, same graph in all three:
        //
        //     upstream                  -1 (input), 0, 1, 2      4 checkpoints
        //     port, before this fix      -1 (input), 0, 1, 3      4 checkpoints (step 2 DESTROYED)
        //     port, identity check only  -1 (input), 0, 1, 2, 3   5 checkpoints (spurious step 3)
        //     port, both fixed           -1 (input), 0, 1, 2      4 checkpoints, same as upstream
        //
        // The intermediate state is recorded because it is the more instructive one:
        // fixing the id reuse alone converts silent history loss into a visible extra
        // row, which is what made the missing durability gate visible at all.
        //
        // The interrupt path still saves, because upstream's condition also admits a
        // nested graph with an error or interrupt, and `$error !== null` is that case.
        if (
            $this->checkpointer !== null
            && $error !== null
            && !$this->isNested
        ) {
            $this->putCheckpoint($this->checkpointMetadata['source'] ?? 'loop', exiting: true);
        }

        $suppress = $this->suppressInterrupt($error);

        if ($suppress || $error === null) {
            $this->output = IO::readChannels($this->channels, $this->outputKeys);
        }

        if ($suppress) {
            // One last `values` event with the pending writes applied, so the
            // caller sees the state the interrupt happened in.
            if ($this->tasks !== [] && $this->checkpointPendingWrites !== []) {
                $anyWrites = false;
                foreach ($this->tasks as $task) {
                    if ($task->writes !== []) {
                        $anyWrites = true;
                        break;
                    }
                }
                if ($anyWrites) {
                    $this->updatedChannels = Algorithm::applyWrites(
                        $this->checkpoint,
                        $this->channels,
                        array_values($this->tasks),
                        $this->nextVersion(...),
                        $this->triggerToNodes,
                    );

                    $writes = [];
                    foreach ($this->tasks as $task) {
                        foreach ($task->writes as $write) {
                            $writes[] = $write;
                        }
                    }
                    foreach (IO::mapOutputValues($this->outputKeys, $writes, $this->channels) as $valuesOutput) {
                        $this->emitValues([$valuesOutput]);
                    }
                }
            }

            // An interrupt with no payloads still has to be visible, or a
            // caller resuming with `null` would not know a pause occurred.
            if (Guard::isGraphInterrupt($error) && $error->interrupts === []) {
                $this->emit([
                    [Constants::INTERRUPT => []],
                ], 'updates');
                $this->emit([
                    [Constants::INTERRUPT => []],
                ], 'values');
            }
        }

        return $suppress;
    }

    /**
     * Whether an interrupt should be absorbed rather than propagated.
     *
     * A nested loop must *not* absorb: the parent owns the checkpoint and must
     * be told, or the subgraph's pause would vanish.
     */
    private function suppressInterrupt(?\Throwable $e): bool
    {
        if (!Guard::isGraphInterrupt($e) || $this->isNested) {
            return false;
        }

        return true;
    }

    /**
     * Write a new checkpoint for the current superstep.
     *
     * Port of `_putCheckpoint`.
     *
     * Two things are ordered deliberately:
     *
     *  - The checkpoint is written *after* the superstep's writes have been
     *    applied and *after* pending writes are cleared. A checkpoint is a
     *    promise that everything in it is complete, so it may never be ahead of
     *    the state.
     *  - Channel values are snapshotted from the live channels here, not held.
     *    A channel's value at step N is whatever it is when the checkpoint is
     *    taken, which is the only definition that survives a resume.
     */
    public function putCheckpoint(string $source = 'loop', bool $exiting = false): void
    {
        // `$exiting` is PHP's stand-in for the TS original's
        // `this.checkpointMetadata === inputMetadata`, an *identity* check that
        // is true only when `finishAndHandleError` hands back the very same
        // metadata object to re-save on the way out. PHP arrays have no
        // identity, so the one caller that means it says so explicitly. Getting
        // this wrong is not cosmetic: an "exiting" save keeps the existing
        // checkpoint id instead of minting one, so a false positive would
        // overwrite successive supersteps onto a single row and silently
        // destroy the thread's history.
        $exiting = $exiting;
        $doCheckpoint = $this->checkpointer !== null;

        $values = [];
        foreach (ChannelRegistry::getOnlyChannels($this->channels) as $name => $channel) {
            try {
                $value = $channel->checkpoint();
                if ($value !== null) {
                    $values[$name] = $value;
                }
            } catch (\LangGraph\Errors\EmptyChannelError) {
                // An empty channel is omitted rather than stored as null.
                continue;
            }
        }

        $channelVersions = $this->checkpoint->channelVersions;
        $newVersions = CheckpointFunctions::getNewChannelVersions(
            $this->checkpointPreviousVersions,
            $channelVersions
        );
        $this->checkpointPreviousVersions = $channelVersions;

        $newCheckpoint = new Checkpoint(
            v: 4,
            id: $exiting ? $this->checkpoint->id : CheckpointFunctions::uuid6($this->step),
            ts: gmdate('Y-m-d\TH:i:s.v\Z'),
            channelValues: $values,
            channelVersions: $channelVersions,
            versionsSeen: $this->checkpoint->versionsSeen,
        );
        $this->checkpoint = $newCheckpoint;

        if ($doCheckpoint) {
            $config = clone $this->checkpointConfig;
            $config->configurable['thread_id'] ??= $this->config->configurable['thread_id'] ?? null;
            $config->configurable[Constants::CONFIG_KEY_CHECKPOINT_NS] = (string) ($this->config->configurable['checkpoint_ns'] ?? '');

            if (($this->checkpointConfig->configurable['checkpoint_id'] ?? null) !== null) {
                $this->prevCheckpointConfig = $this->checkpointConfig;
            } else {
                $this->prevCheckpointConfig = null;
            }

            // An exiting save re-puts the checkpoint the loop already holds, so its metadata
            // stays as it was (upstream only rewrites `step` and `parents` when `!exiting`).
            // Bumping `step` here would make the NEXT run resume at `step + 1`, derive different
            // task ids, and orphan every pending write (RESUME and INTERRUPT included) that was
            // stored under the ids of the run that was interrupted.
            if (!$exiting) {
                $this->checkpointMetadata = array_merge($this->checkpointMetadata, [
                    'source' => $source,
                    'step' => $this->step,
                    // The checkpoint map is the subgraph lineage: every namespace
                    // this run has touched, mapped to the checkpoint it was at.
                    // A subgraph resuming later reads its parent's id from here.
                    'parents' => (object) ($this->config->configurable[Constants::CONFIG_KEY_CHECKPOINT_MAP] ?? []),
                ]);
            }

            $this->checkpointer->put(
                $config->configurable,
                $newCheckpoint->copy(),
                $this->checkpointMetadata,
                $newVersions,
            );

            $this->checkpointConfig = $config;
            $this->checkpointConfig->configurable['checkpoint_id'] = $newCheckpoint->id;

            // `debug` only. Emitted AFTER the write so the event names a checkpoint
            // that exists. Its payload is the same seven fields `getState()` returns —
            // upstream's `checkpoint` debug payload IS a StateSnapshot — so it is read
            // off the same values rather than reassembled, and the two cannot drift.
            $this->emitDebug('checkpoint', [
                'config' => $this->checkpointConfig->configurable,
                'values' => IO::readChannels($this->channels, $this->outputKeys),
                'metadata' => $this->checkpointMetadata,
                'next' => $this->nextTaskNames(),
                'parentConfig' => $this->prevCheckpointConfig?->configurable,
                'tasks' => array_map(
                    static fn (PregelExecutableTask $t): array => $t->toDescription()->toArray(),
                    array_values($this->tasks),
                ),
            ], $this->step);
        }

        if (!$exiting) {
            $this->step += 1;
        }
    }

    /**
     * The names of tasks that would run next, in the order they sit in `$tasks`.
     *
     * Shared with the `next` field of a `checkpoint` debug payload so a debug
     * consumer and `getState()` cannot report different pending nodes.
     *
     * @return list<string>
     */
    public function nextTaskNames(): array
    {
        $out = [];
        foreach ($this->tasks as $task) {
            $path = $task->path;
            // A `Send`-driven task has no path, and a push path is not a node the
            // caller can name — both are excluded, matching what `getState()` does.
            if ($path === null || $path->isPush()) {
                continue;
            }
            $out[] = $task->name;
        }

        return $out;
    }

    /** The config of the checkpoint preceding the current one. */
    public ?RunnableConfig $prevCheckpointConfig = null;

    /**
     * Buffer a stream chunk, if anyone is listening for that mode.
     *
     * @param list<mixed>            $payload
     * @param list<string>|null      $modes
     */
    public function emit(array $payload, string $mode = 'updates', ?array $modes = null): void
    {
        $modes ??= $this->streamModes;
        if (!in_array($mode, $modes, true)) {
            return;
        }

        foreach ($payload as $item) {
            $this->streamBuffer[] = [$mode, $item];
        }
    }

    /**
     * Emit a `debug` event.
     *
     * Port of LangGraph JS's `debug` stream mode. The shape is fixed and was read
     * off a real run rather than off the source:
     * `tests/Fixtures/langgraph/debug-stream-events.json` records
     * `@langchain/langgraph` 1.4.18 streaming a two-node graph with
     * `streamMode: 'debug'`, producing eight events of three types — `checkpoint`,
     * `task` and `task_result` — each wrapped as `{step, type, timestamp, payload}`.
     *
     * `debug` is the mode that shows TASK BOUNDARIES, which `values` and `updates`
     * both hide: `updates` says what a node wrote, `values` says the state after,
     * and only `debug` says a task started, what it was handed, and what it
     * returned. A caller reconstructing why a graph took the path it took needs
     * exactly that.
     *
     * Gated through {@see self::emit()} like every other mode, so a graph that did
     * not ask for `debug` pays nothing and receives nothing.
     *
     * @param array<string, mixed> $payload
     */
    public function emitDebug(string $type, array $payload, ?int $step = null): void
    {
        $this->emit([
            [
                'step' => $step ?? $this->step,
                'type' => $type,
                'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
                'payload' => $payload,
            ],
        ], 'debug');
    }

    /**
     * The public description of a task, as a `debug` event payload.
     *
     * The key set is upstream's `task` payload — `id`, `name`, `path`, `interrupts`
     * — plus the `metadata` a debug consumer reads to see triggers and step. Built
     * from the executable task rather than re-derived, so the event and
     * `getState()` cannot describe the same task differently.
     *
     * @return array<string, mixed>
     */
    public function debugTaskPayload(PregelExecutableTask $task, bool $withInput): array
    {
        $payload = [
            'id' => $task->id,
            'name' => $task->name,
            // `triggers` is TOP-LEVEL upstream, not only inside `metadata`. Reading
            // the recorded run rather than the source is what caught this: the
            // payload keys are `id, input, interrupts, metadata, name, triggers` —
            // five at the top level with `triggers` beside `name`, and a nested
            // `langgraph_triggers` inside `metadata` as well. Nesting it and calling
            // that done would have satisfied a reader looking at `metadata` and left
            // a consumer reading `triggers` — the documented place — with nothing.
            'triggers' => $task->triggers,
            'interrupts' => $task->interrupts,
            'metadata' => [
                'langgraph_step' => $this->step,
                'langgraph_node' => $task->name,
                'langgraph_triggers' => $task->triggers,
                'langgraph_path' => $task->path?->toArray(),
            ],
        ];

        if ($withInput) {
            $payload['input'] = $task->input;
        }

        return $payload;
    }

    /**
     * Emit a `values` chunk.
     *
     * Values are emitted without a namespace, because a caller reading the
     * graph's state should not have to know it is nested.
     *
     * GATED, like every other mode. This used to push straight into
     * `$streamBuffer`, so a graph configured `streamMode: ['updates']` received
     * `values` chunks and nothing else — the subscription was silently inverted,
     * and the caller could not tell it from a graph that produced no updates.
     * Upstream gates uniformly at the consumer (`streamMode.includes(mode)`,
     * `pregel/index.ts:2511`) with no unconditional channel, so the two are now
     * equivalent.
     *
     * @param list<mixed> $payload
     */
    public function emitValues(array $payload): void
    {
        $this->emit($payload, 'values');
    }

    /** Which stream modes this loop is producing. */
    public array $streamModes = ['updates'];


    /**
     * Hand every buffered chunk to the caller.
     *
     * The keys are EXPLICIT and MONOTONIC, and that is load-bearing. `run()` calls
     * this from five places via `yield from`, and a sub-generator's auto-keys restart
     * at 0 on every call — so each drain re-yielded key `0` and the outer generator
     * emitted `0, 0, 0`. `foreach` ignores keys and saw every chunk, but
     * `iterator_to_array($stream)` — the DEFAULT, `preserve_keys: true` — collapses
     * them, keeping only the last. Measured on a graph streamed with
     * `['updates','values']`: `foreach` yielded 3 chunks, `iterator_to_array()`
     * returned 1.
     *
     * So a caller doing the obvious thing silently lost every chunk but the last,
     * with no error. This counter is instance state precisely so it survives across
     * drains, which is what makes the outer sequence a proper list again.
     *
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    private function drain(): \Generator
    {
        while ($this->streamBuffer !== []) {
            yield $this->yieldedChunks++ => array_shift($this->streamBuffer);
        }
    }

    /** Monotonic across every {@see self::drain()} call for this run. */
    private int $yieldedChunks = 0;

    /**
     * Schedule a task mid-superstep, from a node calling another node.
     *
     * Port of `acceptPush`. A node that returns a `Send` (or writes one) does not
     * wait for the target to run: the target is prepared and scheduled for
     * later in the *same* superstep, which is how recursive fan-out works
     * without the node itself blocking on the result.
     */
    public function acceptPush(PregelExecutableTask $task, int $writeIdx, mixed $call = null): ?PregelExecutableTask
    {
        if ($this->interruptAfter !== []
            && Algorithm::shouldInterrupt($this->checkpoint, $this->interruptAfter, [$task])) {
            $this->toInterrupt[] = $task;

            return null;
        }

        $taskPath = new TaskPath(array_merge(
            [Constants::PUSH, $task->path?->toArray() ?? []],
            [$writeIdx, $task->id],
        ));

        $pushed = Algorithm::prepareSingleTask(
            $taskPath,
            $this->checkpoint,
            $this->checkpointPendingWrites,
            $this->nodes,
            $this->channels,
            $task->config ?? new RunnableConfig(),
            true,
            new NextTaskExtraFields(
                step: $this->step,
                channels: $this->channels,
                processes: $this->nodes,
                checkpointer: $this->checkpointer,
            ),
        );

        if ($pushed === null) {
            return null;
        }

        if ($this->interruptBefore !== []
            && Algorithm::shouldInterrupt($this->checkpoint, $this->interruptBefore, [$pushed])) {
            $this->toInterrupt[] = $pushed;

            return null;
        }

        $this->tasks[$pushed->id] = $pushed;
        if ($this->skipDoneTasks) {
            $this->matchWrites([$pushed->id => $pushed]);
        }

        return $pushed;
    }

    /** The next channel version, honouring a checkpointer's own scheme. */
    private function nextVersion(int|string|null $current): int|string
    {
        if ($this->checkpointer !== null) {
            return $this->checkpointer->getNextVersion(is_int($current) ? $current : null);
        }

        return Algorithm::increment($current);
    }

    private function hasUntrackedChannels(): bool
    {
        foreach ($this->channels as $channel) {
            if ($channel instanceof BaseChannel && $channel->lcGraphName === 'UntrackedValue') {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip UntrackedValue channels from a `Send`'s args.
     *
     * Port of `sanitizeUntrackedValuesInSend`. A `Send` is checkpointed, and an
     * untracked value is by definition one that must not be — so it has to come
     * off the packet before the packet is written, or the exclusion is defeated
     * by a nested one.
     */
    private static function sanitizeUntrackedValuesInSend(Send $packet, array $channels): Send
    {
        if (!is_array($packet->args)) {
            return $packet;
        }

        $sanitized = [];
        foreach ($packet->args as $key => $value) {
            $channel = $channels[$key] ?? null;
            if ($channel instanceof BaseChannel && $channel->lcGraphName === 'UntrackedValue') {
                continue;
            }
            $sanitized[$key] = $value;
        }

        return new Send($packet->node, $sanitized, $packet->timeout);
    }

    /**
     * @param list<string> $ns
     * @return list<string>
     */
    public static function namespaceFromNs(?string $ns): array
    {
        if ($ns === null || $ns === '') {
            return [];
        }

        return explode(Constants::CHECKPOINT_NAMESPACE_SEPARATOR, $ns);
    }

    private static function serializeCacheKey(array $ns, string $key): string
    {
        return 'ns:' . implode(',', $ns) . '|key:' . $key;
    }

    private static function allKeysAreHashes(array $map): bool
    {
        foreach (array_keys($map) as $key) {
            if (!is_string($key) || !preg_match('/^[0-9a-f]{32}$/', $key)) {
                return false;
            }
        }

        return true;
    }
}
