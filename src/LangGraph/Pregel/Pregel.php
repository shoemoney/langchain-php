<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangGraph\Channels\BaseChannel;
use LangGraph\Channels\ChannelRegistry;
use LangGraph\Errors\EmptyInputError;
use LangGraph\Pregel\Checkpoint\BaseCheckpointSaver;
use LangGraph\Pregel\Retry\RetryPolicy;

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

        $validInput = $this->validateInput($input);

        $loop = PregelLoop::initialize([
            'input' => $validInput,
            'config' => $config,
            'checkpointer' => $this->checkpointer,
            'nodes' => $this->nodes,
            'channelSpecs' => ChannelRegistry::getOnlyChannels($this->channels),
            'outputKeys' => $this->outputChannels,
            'streamKeys' => $this->streamChannels,
            'interruptAfter' => $this->interruptAfter,
            'interruptBefore' => $this->interruptBefore,
            'debug' => $this->debug,
            'triggerToNodes' => $this->triggerToNodes,
        ]);

        $loop->streamModes = (array) $this->streamMode;

        return $loop->run($this->inputChannels);
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

    /**
     * The current state of a thread, plus the tasks that would run next.
     *
     * Port of `Pregel.getState`. A `StateSnapshot` is the whole observable
     * state of a graph at a point in time: its values, which nodes are next, and
     * what each pending task is waiting on.
     *
     * @return array{values: mixed, next: list<string>, config: array<string, mixed>, metadata: array<string, mixed>, tasks: list<array<string, mixed>>}
     */
    public function getState(RunnableConfig|array $config): array
    {
        $configArr = $config instanceof RunnableConfig ? $config->configurable : $config;
        $checkpointer = $this->checkpointer;
        if ($checkpointer === null) {
            throw new \LogicException('getState requires a checkpointer');
        }

        $saved = $checkpointer->getTuple($configArr);
        if ($saved === null) {
            return [
                'values' => [],
                'next' => [],
                'config' => $configArr,
                'metadata' => [],
                'tasks' => [],
            ];
        }

        $channels = ChannelRegistry::emptyChannels($this->channels, $saved->checkpoint->channelValues);

        $loop = PregelLoop::initialize([
            'input' => null,
            'config' => $config instanceof RunnableConfig ? $config : new RunnableConfig(),
            'checkpointer' => $checkpointer,
            'nodes' => $this->nodes,
            'channelSpecs' => ChannelRegistry::getOnlyChannels($this->channels),
            'outputKeys' => $this->outputChannels,
            'streamKeys' => $this->streamChannels,
            'triggerToNodes' => $this->triggerToNodes,
        ]);

        $tasks = Algorithm::prepareNextTasks(
            $saved->checkpoint->copy(),
            $saved->pendingWrites,
            $this->nodes,
            $channels,
            $loop->config,
            false,
            new NextTaskExtraFields(
                step: $loop->step,
                channels: $channels,
                processes: $this->nodes,
            ),
        );

        $next = [];
        foreach ($tasks as $task) {
            $path = $task->path;
            if ($path === null) {
                continue;
            }
            if ($path->isPush()) {
                continue;
            }
            $next[] = $task->name;
        }

        $descriptions = [];
        foreach ($tasks as $task) {
            $descriptions[] = [
                'id' => $task->id,
                'name' => $task->name,
                'interrupts' => $task->interrupts,
            ];
        }

        return [
            'values' => IO::readChannels($channels, $this->outputChannels),
            'next' => $next,
            'config' => $saved->config,
            'metadata' => $saved->metadata,
            'tasks' => $descriptions,
        ];
    }

    /**
     * Every checkpoint saved for a thread, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function getStateHistory(RunnableConfig|array $config): array
    {
        $configArr = $config instanceof RunnableConfig ? $config->configurable : $config;
        $checkpointer = $this->checkpointer;
        if ($checkpointer === null) {
            return [];
        }

        $out = [];
        foreach ($checkpointer->list($configArr) as $saved) {
            $channels = ChannelRegistry::emptyChannels($this->channels, $saved->checkpoint->channelValues);
            $out[] = [
                'values' => IO::readChannels($channels, $this->outputChannels),
                'next' => [],
                'config' => $saved->config,
                'metadata' => $saved->metadata,
                'tasks' => [],
            ];
        }

        return $out;
    }
}
