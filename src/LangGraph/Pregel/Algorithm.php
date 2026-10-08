<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Channels\BaseChannel;
use LangGraph\Channels\ChannelRegistry;
use LangGraph\Channels\Missing;
use LangGraph\Errors\EmptyChannelError;
use LangGraph\Errors\InvalidUpdateError;
use LangGraph\Pregel\Checkpoint\Checkpoint;
use LangGraph\Pregel\Checkpoint\CheckpointFunctions;

/**
 * The scheduling algorithm: which tasks run next, and how their writes land.
 *
 * Port of `langgraph-core/src/pregel/algo.ts`. This is the correctness core of
 * Pregel, ported function-for-function; the docblocks below explain *why* the
 * code is shaped the way it is, because the shape is not obvious.
 *
 * Three ideas carry the whole file:
 *
 *  1. **A superstep ends by folding writes into channels, in a fixed order.**
 *     {@see self::applyWrites()} is that fold. Order is not incidental: tasks
 *     finish in whatever order the runtime schedules them, so `_applyWrites`
 *     sorts by task *path* before touching any channel. Two runs of the same
 *     graph must produce the same state, and without that sort they would not.
 *  2. **Which nodes run next is arithmetic, not iteration.** A node is
 *     scheduled when some channel it *triggers* on has advanced past the
 *     version it last saw. {@see self::prepareNextTasks()} evaluates exactly
 *     that, and derives a deterministic task id from it so a resumed run
 *     recognises its own in-flight work.
 *  3. **Reserved channels are not channels.** `ERROR`, `INTERRUPT`, `TASKS`
 *     and friends carry control meaning. Writes to them are dropped, folded
 *     into a scalar, or routed to the scheduler — never applied to a
 *     `BaseChannel`. Getting this wrong produces a graph that looks like it ran
 *     but whose state is fiction.
 */
final class Algorithm
{
    private function __construct()
    {
    }

    /**
     * The next version for a channel counter.
     *
     * Port of `increment`. `null` means "never written", which is version 1.
     */
    public static function increment(int|string|null $current): int|string
    {
        return $current === null ? 1 : (is_int($current) ? $current + 1 : $current . '1');
    }

    /**
     * Whether any updated channel triggers a node.
     *
     * Port of `triggersNextStep`. Used to decide whether this is the *last*
     * superstep, and therefore whether channels should be told the run is
     * finishing (which is what lets `LastValueAfterFinish` publish its value).
     *
     * @param list<string>                        $updatedChannels
     * @param array<string, list<string>>|null    $triggerToNodes
     */
    public static function triggersNextStep(array $updatedChannels, ?array $triggerToNodes): bool
    {
        if ($triggerToNodes === null) {
            return false;
        }

        foreach ($updatedChannels as $channel) {
            if (isset($triggerToNodes[$channel]) && $triggerToNodes[$channel] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the run should stop after this step.
     *
     * Port of `shouldInterrupt`. Two independent questions, and *both* must be
     * yes: has any channel advanced (so there is genuinely new state a node
     * would react to), and is any of the tasks an interrupt target. Requiring
     * both is what stops `interruptBefore` firing on a step where nothing
     * actually changed — which would make it a no-op pause that burns a resume
     * round trip.
     *
     * @param list<string>          $interruptNodes Node names, or `['*']` for all.
     * @param list<WritesProtocol>  $tasks
     */
    public static function shouldInterrupt(Checkpoint $checkpoint, array $interruptNodes, array $tasks): bool
    {
        $nullVersion = CheckpointFunctions::getNullChannelVersion($checkpoint->channelVersions);
        $seen = $checkpoint->versionsSeen[Constants::INTERRUPT] ?? [];

        $anyChannelUpdated = false;

        $startVersion = $checkpoint->channelVersions[Constants::START] ?? $nullVersion;
        $seenStart = $seen[Constants::START] ?? $nullVersion;

        if ($startVersion !== null && $seenStart !== null
            && CheckpointFunctions::compareChannelVersions($startVersion, $seenStart) > 0) {
            $anyChannelUpdated = true;
        } else {
            foreach ($checkpoint->channelVersions as $channel => $version) {
                $seenVersion = $seen[$channel] ?? $nullVersion;
                if ($seenVersion === null) {
                    continue;
                }
                if (CheckpointFunctions::compareChannelVersions($version, $seenVersion) > 0) {
                    $anyChannelUpdated = true;
                    break;
                }
            }
        }

        $interruptAll = $interruptNodes === ['*'];
        $anyTriggeredNodeInInterruptNodes = false;

        foreach ($tasks as $task) {
            if ($interruptAll) {
                $tags = $task instanceof PregelExecutableTask ? ($task->config?->tags ?? []) : [];
                if (!in_array(Constants::TAG_HIDDEN, $tags, true)) {
                    $anyTriggeredNodeInInterruptNodes = true;
                    break;
                }
            } elseif (in_array($task->writesName(), $interruptNodes, true)) {
                $anyTriggeredNodeInInterruptNodes = true;
                break;
            }
        }

        return $anyChannelUpdated && $anyTriggeredNodeInInterruptNodes;
    }

    /**
     * Read state as this task sees it, optionally with its own writes applied.
     *
     * Port of `_localRead`. With `$fresh`, the task's in-flight writes are
     * folded over a *throwaway* copy of the channels first, so a conditional
     * edge can branch on what the node is about to write rather than on what
     * was committed last step. The real channels are never touched.
     *
     * @param string|list<string>    $select
     * @param array<string, BaseChannel> $channels
     * @param array<string, mixed>   $config
     */
    public static function localRead(
        Checkpoint $checkpoint,
        array $channels,
        WritesProtocol $task,
        string|array $select,
        bool $fresh = false,
        ?RunnableConfig $config = null,
    ): mixed {
        $updated = [];
        $taskWrites = $task->writesList();

        if (!is_array($select)) {
            foreach ($taskWrites as $write) {
                if ($write[0] === $select) {
                    $updated = [$select];
                    break;
                }
            }
        } else {
            foreach ($select as $channel) {
                foreach ($taskWrites as $write) {
                    if ($write[0] === $channel) {
                        $updated[] = $channel;
                        break;
                    }
                }
            }
        }

        if (!$fresh || $updated === []) {
            return self::readChannels($channels, $select);
        }

        $localChannels = [];
        foreach ($channels as $name => $channel) {
            if (in_array($name, $updated, true)) {
                $localChannels[$name] = $channel;
            }
        }

        $newChannels = ChannelRegistry::emptyChannels(
            $localChannels,
            self::channelValuesFor($channels, $localChannels)
        );

        self::applyWrites(
            $checkpoint->copy(),
            $newChannels,
            [$task],
            static fn (int|string|null $v): int|string => Algorithm::increment($v),
            null
        );

        return self::readChannels(array_merge($channels, $newChannels), $select);
    }

    /**
     * Validate and record writes a task produced mid-superstep.
     *
     * Port of `_localWrite`. A `Send` on the `TASKS` or `PUSH` channel is how a
     * node schedules another node, so it is validated here rather than trusted:
     * a `Send` naming a node that does not exist would otherwise be accepted and
     * silently dropped later, leaving the user with a graph that mysteriously
     * does less than they wrote.
     *
     * @param array<string, PregelNode>  $processes
     * @param list<array{0: string, 1: mixed}> $writes
     */
    public static function localWrite(
        callable $commit,
        array $processes,
        array $writes,
    ): void {
        foreach ($writes as $write) {
            $channel = $write[0];
            $value = $write[1] ?? null;

            if (($channel === Constants::PUSH || $channel === Constants::TASKS) && $value !== null) {
                if (!Send::isSend($value)) {
                    throw new InvalidUpdateError(
                        'Invalid packet type, expected SendProtocol, got ' . json_encode($value)
                    );
                }
                $send = Send::fromMixed($value);
                if ($send === null || !isset($processes[$send->node])) {
                    throw new InvalidUpdateError(
                        'Invalid node name "' . ($send?->node ?? '') . '" in Send packet'
                    );
                }
            }
        }

        $commit($writes);
    }

    /**
     * Fold a superstep's task writes into the channels.
     *
     * Port of `_applyWrites`. Returns the set of channels that actually changed,
     * which is what the next step's scheduling is derived from.
     *
     * The order of operations below is the algorithm, and each step exists for a
     * reason:
     *
     *  1. **Sort tasks by path.** Tasks complete in runtime order; applying
     *     writes in that order would make state depend on scheduling. Sorting
     *     by the first three path segments makes the fold deterministic. Later
     *     segments (write index, parent task id) are excluded so a task's own
     *     multiple writes keep their relative order.
     *  2. **Record `versions_seen`.** For every trigger a task consumed, record
     *     the version it saw. This is what stops the same node being scheduled
     *     twice for one channel update.
     *  3. **Consume and bump triggered channels.** A channel that was
     *     *read* rather than written is consumed and re-versioned, so it is not
     *     re-offered to the same node forever.
     *  4. **Group writes by channel, then apply.** Grouping first is what lets
     *     a reducer channel receive all of a step's updates as one batch.
     *  5. **Notify untouched channels of the new step.** `update([])` on every
     *     channel is how `EphemeralValue` clears itself and how a
     *     counter channel notices a step happened.
     *  6. **Finish channels if this is the last step.** Only when nothing
     *     triggers anything further, so a value published on the final step
     *     still reaches the output.
     *
     * @param list<WritesProtocol>                 $tasks
     * @param (callable(int|string|null): (int|string))|null $getNextVersion
     * @param array<string, list<string>>|null     $triggerToNodes
     * @return list<string> The channels whose value changed.
     */
    public static function applyWrites(
        Checkpoint $checkpoint,
        array $channels,
        array $tasks,
        ?callable $getNextVersion = null,
        ?array $triggerToNodes = null,
    ): array {
        // 1. Sort by the first three path segments for a deterministic fold.
        $pathCache = [];
        foreach ($tasks as $i => $task) {
            $pathCache[$i] = $task->path()?->orderingKey() ?? [];
        }

        $order = array_keys($tasks);
        usort($order, static function (int $a, int $b) use ($pathCache): int {
            $aPath = $pathCache[$a];
            $bPath = $pathCache[$b];
            $min = min(count($aPath), count($bPath));

            for ($i = 0; $i < $min; $i++) {
                $cmp = self::comparePathSegments($aPath[$i], $bPath[$i]);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            // A shorter path sorts first: a PULL task precedes a PUSH task.
            return count($aPath) <=> count($bPath);
        });

        $sortedTasks = [];
        foreach ($order as $i) {
            $sortedTasks[] = $tasks[$i];
        }

        $onlyChannels = ChannelRegistry::getOnlyChannels($channels);

        // 2. Record versions_seen; collect the channels to consume.
        $bumpStep = false;
        $channelsToConsume = [];
        foreach ($sortedTasks as $task) {
            $triggers = $task->triggers();
            if ($triggers !== []) {
                $bumpStep = true;
            }

            $name = $task->writesName();
            $checkpoint->versionsSeen[$name] ??= [];

            foreach ($triggers as $channel) {
                if (array_key_exists($channel, $checkpoint->channelVersions)) {
                    $checkpoint->versionsSeen[$name][$channel] = $checkpoint->channelVersions[$channel];
                }
                if (!Constants::isReserved($channel)) {
                    $channelsToConsume[$channel] = true;
                }
            }
        }

        // 3. Consume and re-version channels that were read but not written.
        $maxVersion = CheckpointFunctions::maxChannelMapVersion($checkpoint->channelVersions);

        $usedNewVersion = false;
        foreach (array_keys($channelsToConsume) as $channel) {
            if (isset($onlyChannels[$channel]) && $onlyChannels[$channel]->consume()) {
                if ($getNextVersion !== null) {
                    $checkpoint->channelVersions[$channel] = $getNextVersion($maxVersion);
                    $usedNewVersion = true;
                }
            }
        }

        // 4. Group writes by channel so each reducer sees one batch.
        $pendingWritesByChannel = [];
        foreach ($sortedTasks as $task) {
            foreach ($task->writesList() as $write) {
                $channel = $write[0];
                if (self::isIgnoredChannel($channel)) {
                    continue;
                }
                if (!isset($onlyChannels[$channel])) {
                    continue;
                }
                $pendingWritesByChannel[$channel][] = $write[1] ?? null;
            }
        }

        if ($maxVersion !== null && $getNextVersion !== null) {
            $maxVersion = $usedNewVersion ? $getNextVersion($maxVersion) : $maxVersion;
        }

        $updatedChannels = [];
        foreach ($pendingWritesByChannel as $channel => $values) {
            if (!isset($onlyChannels[$channel])) {
                continue;
            }
            $chan = $onlyChannels[$channel];

            try {
                $updated = $chan->update($values);
            } catch (InvalidUpdateError $e) {
                throw new InvalidUpdateError(
                    'Invalid update for channel "' . $channel . '" with values '
                    . json_encode($values) . ': ' . $e->getMessage(),
                    $e->fields
                );
            }

            if ($updated && $getNextVersion !== null) {
                $checkpoint->channelVersions[$channel] = $getNextVersion($maxVersion);

                // An unavailable channel cannot wake a task, so it is not
                // reported as updated — that would schedule a node whose
                // trigger has no value.
                if ($chan->isAvailable()) {
                    $updatedChannels[$channel] = true;
                }
            }
        }

        // 5. Notify every other channel that a new step began.
        if ($bumpStep) {
            foreach ($onlyChannels as $name => $chan) {
                if (!isset($updatedChannels[$name])) {
                    if (!$chan->isAvailable()) {
                        continue;
                    }
                    $updated = $chan->update([]);
                    if ($updated && $getNextVersion !== null) {
                        $checkpoint->channelVersions[$name] = $getNextVersion($maxVersion);
                        if ($chan->isAvailable()) {
                            $updatedChannels[$name] = true;
                        }
                    }
                }
            }
        }

        // 6. If nothing will trigger another step, tell channels we're done.
        if ($bumpStep && !self::triggersNextStep(array_keys($updatedChannels), $triggerToNodes)) {
            foreach ($onlyChannels as $name => $chan) {
                if ($chan->finish() && $getNextVersion !== null) {
                    $checkpoint->channelVersions[$name] = $getNextVersion($maxVersion);
                    if ($chan->isAvailable()) {
                        $updatedChannels[$name] = true;
                    }
                }
            }
        }

        return array_keys($updatedChannels);
    }

    /**
     * Reserved channels that never become channel state.
     *
     * Port of the `IGNORE` set. Each name here is a *channel-shaped* slot whose
     * value means something other than state: `NO_WRITES` marks a task that
     * returned nothing, `PUSH`/`RESUME`/`INTERRUPT` are control signals, and
     * `ERROR` records a failure. Writing any of them into a real channel would
     * corrupt the graph's state with engine bookkeeping.
     */
    public static function isIgnoredChannel(string $channel): bool
    {
        return in_array($channel, [
            Constants::NO_WRITES,
            Constants::PUSH,
            Constants::RESUME,
            Constants::INTERRUPT,
            Constants::RETURN,
            Constants::ERROR,
            Constants::ERROR_SOURCE_NODE,
        ], true);
    }

    /**
     * The nodes worth considering for the next step.
     *
     * Port of `candidateNodes`. When the caller knows which channels changed and
     * what they trigger, only those nodes are candidates — that turns "iterate
     * every node in the graph" into "iterate the handful a write woke up", which
     * is the difference between a graph scaling with its state and one scaling
     * with its node count.
     *
     * @param array<string, PregelNode> $processes
     * @return list<string>
     */
    public static function candidateNodes(Checkpoint $checkpoint, array $processes, NextTaskExtraFields $extra): array
    {
        if ($extra->updatedChannels !== null && $extra->triggerToNodes !== null) {
            $triggered = [];
            foreach ($extra->updatedChannels as $channel) {
                foreach ($extra->triggerToNodes[$channel] ?? [] as $id) {
                    $triggered[$id] = true;
                }
            }

            $names = array_map(strval(...), array_keys($triggered));
            sort($names, SORT_STRING);

            return $names;
        }

        // An empty checkpoint has no versions, so there is nothing to compare
        // a trigger against and no node can be scheduled.
        $isEmptyChannelVersions = true;
        foreach ($checkpoint->channelVersions as $version) {
            if ($version !== null) {
                $isEmptyChannelVersions = false;
                break;
            }
        }
        if ($isEmptyChannelVersions) {
            return [];
        }

        return array_map(strval(...), array_keys($processes));
    }

    /**
     * Build every task for the next superstep.
     *
     * Port of `_prepareNextTasks`. The returned set is the *union* of two kinds
     * of work, and that union is Pregel's whole scheduling model:
     *
     *  - **PUSH** tasks — one per packet in the `TASKS` channel, which is how
     *    `Send` and dynamic fan-out are expressed;
     *  - **PULL** tasks — one per node with an advanced trigger, which is how
     *    an ordinary edge is expressed.
     *
     * PUSH tasks come first and in packet order; PULL tasks follow in sorted
     * node order. Task ids are keyed by path, so a task appears once even if
     * both routes reach it.
     *
     * @param array<string, PregelNode>   $processes
     * @param array<string, BaseChannel>  $channels
     * @param list<array{0: string, 1: string, 2: mixed}>|null $pendingWrites
     * @return array<string, PregelExecutableTask>
     */
    public static function prepareNextTasks(
        Checkpoint $checkpoint,
        ?array $pendingWrites,
        array $processes,
        array $channels,
        RunnableConfig $config,
        bool $forExecution,
        NextTaskExtraFields $extra,
    ): array {
        $tasks = [];
        $indexedExtra = $extra;

        // PUSH tasks: one per queued packet.
        $tasksChannel = $channels[Constants::TASKS] ?? null;
        if ($tasksChannel instanceof BaseChannel && $tasksChannel->isAvailable()) {
            $packets = $tasksChannel->get();
            $len = is_array($packets) ? count($packets) : 0;
            for ($i = 0; $i < $len; $i++) {
                $task = self::prepareSingleTask(
                    TaskPath::push($i),
                    $checkpoint,
                    $pendingWrites,
                    $processes,
                    $channels,
                    $config,
                    $forExecution,
                    $indexedExtra,
                );
                if ($task !== null) {
                    $tasks[$task->id] = $task;
                }
            }
        }

        // PULL tasks: one per node with an advanced trigger.
        foreach (self::candidateNodes($checkpoint, $processes, $indexedExtra) as $name) {
            $task = self::prepareSingleTask(
                TaskPath::pull($name),
                $checkpoint,
                $pendingWrites,
                $processes,
                $channels,
                $config,
                $forExecution,
                $indexedExtra,
            );
            if ($task !== null) {
                $tasks[$task->id] = $task;
            }
        }

        return $tasks;
    }

    /**
     * Build one task, if its route is live this step.
     *
     * Port of `_prepareSingleTask`. Returns null whenever the route is not
     * currently live, which is the normal case: most nodes are not triggered on
     * any given step. The three routes, in the order they are checked:
     *
     *  - **PUSH via packet** — a `Send` queued in `TASKS`.
     *  - **PULL** — a node whose trigger channel advanced past what it has seen.
     *
     * The task id is a UUID derived from the checkpoint id, the step, the node
     * name, the route, and the trigger. That determinism is load-bearing: it is
     * how a resumed run recognises a task it already ran, finds its recorded
     * writes, and skips re-executing it.
     *
     * @param array<string, PregelNode>   $processes
     * @param array<string, BaseChannel>  $channels
     * @param list<array{0: string, 1: string, 2: mixed}>|null $pendingWrites
     */
    public static function prepareSingleTask(
        TaskPath $taskPath,
        Checkpoint $checkpoint,
        ?array $pendingWrites,
        array $processes,
        array $channels,
        RunnableConfig $config,
        bool $forExecution,
        NextTaskExtraFields $extra,
    ): ?PregelExecutableTask {
        $step = $extra->step;
        $configurable = $config->configurable;
        $parentNamespace = (string) ($configurable['checkpoint_ns'] ?? '');

        if ($taskPath->isPush()) {
            $index = (int) $taskPath->segments[1];

            $tasksChannel = $channels[Constants::TASKS] ?? null;
            if (!$tasksChannel instanceof BaseChannel || !$tasksChannel->isAvailable()) {
                return null;
            }

            $sends = $tasksChannel->get();
            if (!is_array($sends) || $index < 0 || $index >= count($sends)) {
                return null;
            }

            $raw = $sends[$index];
            $packet = Send::fromMixed($raw);
            if ($packet === null) {
                // Upstream warns and continues (algo.ts:860-861) with this exact
                // message. `trigger_error(..., E_USER_WARNING)` is not that here:
                // phpunit.xml sets `failOnWarning="true"`, so the notice became a
                // TEST FAILURE and a recoverable condition could not be tested.
                // Recorded instead — see LangChain\Utils\Notice.
                \LangChain\Utils\Notice::record(
                    'Ignoring invalid packet ' . json_encode($raw) . ' in pending sends.'
                );

                return null;
            }
            if (!isset($processes[$packet->node])) {
                // As above — upstream warns and continues (algo.ts:866-867).
                \LangChain\Utils\Notice::record(
                    'Ignoring unknown node name ' . $packet->node . ' in pending sends.'
                );

                return null;
            }

            $proc = $processes[$packet->node];
            $triggers = [Constants::PUSH];
            $checkpointNamespace = self::childNamespace($parentNamespace, $packet->node);
            $taskId = CheckpointFunctions::uuid5(
                (string) json_encode([
                    $checkpointNamespace,
                    (string) $step,
                    $packet->node,
                    Constants::PUSH,
                    (string) $index,
                ]),
                $checkpoint->id
            );
            $taskCheckpointNamespace = $checkpointNamespace . Constants::CHECKPOINT_NAMESPACE_END . $taskId;

            $metadata = array_merge([
                'langgraph_step' => $step,
                'langgraph_node' => $packet->node,
                'langgraph_triggers' => $triggers,
                'langgraph_path' => $taskPath->toArray(),
                'langgraph_checkpoint_ns' => $taskCheckpointNamespace,
                'checkpoint_ns' => $taskCheckpointNamespace,
            ], $proc->metadata);

            if (!$forExecution) {
                return new PregelExecutableTask(
                    id: $taskId,
                    name: $packet->node,
                    path: $taskPath,
                    triggers: $triggers,
                    metadata: $metadata,
                    interrupts: self::interruptsFor($pendingWrites, $taskId),
                );
            }

            $node = $proc->getNode();
            if ($node === null) {
                return null;
            }

            $task = new PregelExecutableTask(
                id: $taskId,
                name: $packet->node,
                input: $packet->args,
                proc: $node,
                writers: $proc->getWriters(),
                path: $taskPath,
                triggers: $triggers,
                retryPolicy: $proc->retryPolicy,
                cacheKey: self::buildCacheKey($proc->cachePolicy, $proc->lcGraphName, [$packet->args], $packet->node),
                timeout: $packet->timeout ?? $proc->timeout,
                metadata: $metadata,
                subgraphs: $proc->subgraphs,
                errorHandlerNode: $proc->errorHandlerNode,
                isErrorHandler: $proc->isErrorHandler,
            );
            $task->config = self::buildTaskConfig(
                task: $task,
                    config: $config,
                    taskId: $taskId,
                    nodeName: $packet->node,
                    taskCheckpointNamespace: $taskCheckpointNamespace,
                    parentNamespace: $parentNamespace,
                    checkpoint: $checkpoint,
                    pendingWrites: $pendingWrites,
                    processes: $processes,
                    channels: $channels,
                    taskPath: $taskPath,
                    triggers: $triggers,
                    currentTaskInput: $packet->args,
                    step: $step,
                    extra: $extra,
                    metadata: $metadata,
                    tags: $proc->tags,
            );

            return $task;
        }

        if ($taskPath->isPull()) {
            $name = (string) $taskPath->segments[1];
            $proc = $processes[$name] ?? null;
            if ($proc === null) {
                return null;
            }

            $nullVersion = CheckpointFunctions::getNullChannelVersion($checkpoint->channelVersions);
            if ($nullVersion === null) {
                return null;
            }

            $seen = $checkpoint->versionsSeen[$name] ?? [];

            // The first trigger that both has a value and has advanced.
            $trigger = null;
            foreach ($proc->triggers as $chan) {
                $chanInstance = $channels[$chan] ?? null;
                if (!$chanInstance instanceof BaseChannel || !$chanInstance->isAvailable()) {
                    continue;
                }
                $version = $checkpoint->channelVersions[$chan] ?? $nullVersion;
                $seenVersion = $seen[$chan] ?? $nullVersion;
                if (CheckpointFunctions::compareChannelVersions($version, $seenVersion) > 0) {
                    $trigger = $chan;
                    break;
                }
            }

            if ($trigger === null) {
                return null;
            }

            $val = self::procInput($proc, $channels, $forExecution);
            if ($val instanceof Missing) {
                return null;
            }

            $checkpointNamespace = self::childNamespace($parentNamespace, $name);

            // The task id, built ONCE and used for both the "already done"
            // probe and the task itself.
            //
            // These were two separate expressions that had drifted. The probe
            // used `$name` as the last path element while the real id used
            // `$trigger` — and in a StateGraph those differ, because a trigger is
            // `branch:to:<node>` and the bare node name is not. So the guard
            // asked whether a task id that could never be issued had succeeded:
            // dead code, silently. It only stayed harmless because nothing
            // re-scheduled a node in the same superstep; the moment something
            // did — a resume, or a channel consumed without re-versioning — the
            // node would have re-executed and applied its writes twice.
            //
            // Building the id once removes the possibility of the two drifting
            // again, which is a stronger guarantee than matching them by hand.
            $taskId = CheckpointFunctions::uuid5(
                (string) json_encode([
                    $checkpointNamespace,
                    (string) $step,
                    $name,
                    Constants::PULL,
                    [$trigger],
                ]),
                $checkpoint->id
            );

            // A task that already produced a non-error write this step is done;
            // re-preparing it would schedule the work twice. Checked AFTER the
            // trigger is resolved, because the id depends on it.
            if ($pendingWrites !== null && $pendingWrites !== []) {
                if ($extra->indexFor($pendingWrites)->hasCompletedWrite($taskId)) {
                    return null;
                }
            }
            $taskCheckpointNamespace = $checkpointNamespace . Constants::CHECKPOINT_NAMESPACE_END . $taskId;

            $metadata = array_merge([
                'langgraph_step' => $step,
                'langgraph_node' => $name,
                'langgraph_triggers' => [$trigger],
                'langgraph_path' => $taskPath->toArray(),
                'langgraph_checkpoint_ns' => $taskCheckpointNamespace,
                'checkpoint_ns' => $taskCheckpointNamespace,
            ], $proc->metadata);

            if (!$forExecution) {
                return new PregelExecutableTask(
                    id: $taskId,
                    name: $name,
                    path: $taskPath,
                    triggers: [$trigger],
                    metadata: $metadata,
                    interrupts: self::interruptsFor($pendingWrites, $taskId),
                );
            }

            $node = $proc->getNode();
            if ($node === null) {
                return null;
            }

            $task = new PregelExecutableTask(
                id: $taskId,
                name: $name,
                input: $val,
                proc: $node,
                writers: $proc->getWriters(),
                path: $taskPath,
                triggers: [$trigger],
                retryPolicy: $proc->retryPolicy,
                cacheKey: self::buildCacheKey($proc->cachePolicy, $proc->lcGraphName, [$val], $name),
                timeout: $proc->timeout,
                metadata: $metadata,
                subgraphs: $proc->subgraphs,
                errorHandlerNode: $proc->errorHandlerNode,
                isErrorHandler: $proc->isErrorHandler,
            );
            $task->config = self::buildTaskConfig(
                task: $task,
                    config: $config,
                    taskId: $taskId,
                    nodeName: $name,
                    taskCheckpointNamespace: $taskCheckpointNamespace,
                    parentNamespace: $parentNamespace,
                    checkpoint: $checkpoint,
                    pendingWrites: $pendingWrites,
                    processes: $processes,
                    channels: $channels,
                    taskPath: $taskPath,
                    triggers: [$trigger],
                    currentTaskInput: $val,
                    step: $step,
                    extra: $extra,
                    metadata: $metadata,
                    tags: $proc->tags,
            );

            return $task;
        }

        return null;
    }

    /**
     * Build the config a task runs with.
     *
     * Port of the `config` block inside `_prepareSingleTask`.
     *
     * The `configurable` entries are the whole private protocol between the
     * engine and a node:
     *
     *  - `CONFIG_KEY_READ` — read channel state, optionally folding this task's
     *    own writes over it;
     *  - `CONFIG_KEY_SEND` — schedule another node mid-superstep;
     *  - `CONFIG_KEY_SCRATCHPAD` — resume values, interrupt counters, and the
     *    task's own input, for a node calling `interrupt()`;
     *  - `CONFIG_KEY_CHECKPOINTER` / `CONFIG_KEY_CHECKPOINT_MAP` — so a subgraph
     *    node can find and extend its parent's checkpoint lineage.
     *
     * `checkpoint_id` is explicitly nulled: a task must write to the checkpoint
     * being built, not the one it read from.
     *
     * @param array<string, PregelNode>   $processes
     * @param array<string, BaseChannel>  $channels
     * @param list<array{0: string, 1: string, 2: mixed}>|null $pendingWrites
     * @param list<string>                 $triggers
     */
    private static function buildTaskConfig(
        PregelExecutableTask $task,
        RunnableConfig $config,
        string $taskId,
        string $nodeName,
        string $taskCheckpointNamespace,
        string $parentNamespace,
        Checkpoint $checkpoint,
        ?array $pendingWrites,
        array $processes,
        array $channels,
        TaskPath $taskPath,
        array $triggers,
        mixed $currentTaskInput,
        int $step,
        NextTaskExtraFields $extra,
        array $metadata,
        array $tags,
    ): RunnableConfig {
        $configurable = $config->configurable;

        $taskConfig = $config->with([
            'metadata' => array_merge($config->metadata, $metadata),
            'tags' => array_merge($config->tags, $tags),
            'run_name' => $nodeName,
        ]);

        $writesCollector = static function (array $items) use ($processes, $task): void {
            Algorithm::localWrite(
                static function (array $collected) use ($task): void {
                    foreach ($collected as $item) {
                        $task->writes[] = $item;
                    }
                },
                $processes,
                $items,
            );
        };

        $readFn = static function (string|array $select, bool $fresh = false) use (
            $checkpoint,
            $channels,
            $nodeName,
            $task,
            $taskPath
        ): mixed {
            return self::localRead(
                $checkpoint,
                $channels,
                new TaskWriteView($nodeName, $task, $task->triggers, $taskPath),
                $select,
                $fresh
            );
        };

        $index = $extra->indexFor($pendingWrites);

        $checkpointMap = (array) ($configurable[Constants::CONFIG_KEY_CHECKPOINT_MAP] ?? []);
        if ($parentNamespace !== '') {
            $checkpointMap[$parentNamespace] = $checkpoint->id;
        } else {
            $checkpointMap[''] = $checkpoint->id;
        }

        $taskConfig->configurable = array_merge($configurable, [
            Constants::CONFIG_KEY_TASK_ID => $taskId,
            Constants::CONFIG_KEY_SEND => $writesCollector,
            Constants::CONFIG_KEY_READ => $readFn,
            Constants::CONFIG_KEY_CHECKPOINTER => $extra->checkpointer ?? ($configurable[Constants::CONFIG_KEY_CHECKPOINTER] ?? null),
            Constants::CONFIG_KEY_CHECKPOINT_MAP => $checkpointMap,
            Constants::CONFIG_KEY_SCRATCHPAD => self::buildScratchpad(
                taskId: $taskId,
                currentTaskInput: $currentTaskInput,
                resumeMap: $configurable[Constants::CONFIG_KEY_RESUME_MAP] ?? $extra->resumeMap,
                namespaceHash: hash('xxh128', $taskCheckpointNamespace),
                index: $index,
            ),
            Constants::CONFIG_KEY_PREVIOUS_STATE => $checkpoint->channelValue(Constants::PREVIOUS),
            'checkpoint_id' => null,
            'checkpoint_ns' => $taskCheckpointNamespace,
        ]);
        return $taskConfig;
    }

    /**
     * The interrupts a task has raised, read back off the pending writes.
     *
     * Upstream surfaces these on `StateSnapshot.tasks[].interrupts` (its
     * `tasksWithWrites`, `pregel/debug.ts`); here the description-only task
     * carries them so `getState()` can report what a paused graph is asking.
     *
     * @param list<array{0: string, 1: string, 2: mixed}>|null $pendingWrites
     * @return list<array{id: string|null, value: mixed}>
     */
    public static function interruptsFor(?array $pendingWrites, string $taskId): array
    {
        $out = [];
        foreach ($pendingWrites ?? [] as $write) {
            if ((string) $write[0] === $taskId && $write[1] === Constants::INTERRUPT) {
                $out[] = $write[2] ?? null;
            }
        }

        return $out;
    }

    /**
     * Assemble the scratchpad a node's `interrupt()` reads.
     *
     * Port of `_scratchpad`. It holds the resume values queued for this task, a
     * counter so repeated interrupts in one node each consume a distinct value,
     * and the task's own input so a resumed task can pick up where it left off.
     */
    public static function buildScratchpad(
        string $taskId,
        mixed $currentTaskInput,
        ?array $resumeMap,
        string $namespaceHash,
        PendingWritesIndex $index,
    ): PregelScratchpad {
        // Each recorded write holds a LIST of resume values (one per interrupt answered so far),
        // and upstream flattens them one level: `resumeByTaskId.get(taskId).flat()`.
        $resume = [];
        foreach ($index->resumeFor($taskId) as $recorded) {
            if (\is_array($recorded) && array_is_list($recorded)) {
                foreach ($recorded as $value) {
                    $resume[] = $value;
                }
            } else {
                $resume[] = $recorded;
            }
        }

        if ($resumeMap !== null && array_key_exists($namespaceHash, $resumeMap)) {
            $resume[] = $resumeMap[$namespaceHash];
        }

        return new PregelScratchpad(
            resume: $resume,
            nullResume: $index->nullResume,
            currentTaskInput: $currentTaskInput,
        );
    }

    /**
     * Build the cache key for a task, if its node is cacheable.
     *
     * @param array{keyFunc?: callable, ttl?: int}|null $cachePolicy
     * @param list<mixed>                               $input
     * @return array{ns: list<string>, key: string, ttl: int|null}|null
     */
    private static function buildCacheKey(?array $cachePolicy, string $nodeName, array $input, string $node): ?array
    {
        if ($cachePolicy === null) {
            return null;
        }

        $keyFunc = $cachePolicy['keyFunc'] ?? null;
        // WHAT THIS DOES NOW: an unencodable input THROWS rather than collapsing.
        // `json_encode($input, JSON_THROW_ON_ERROR)` raises instead of returning
        // false, so a bad cache key propagates to the caller. Upstream matches:
        // `JSON.stringify` throws on input it cannot represent, so a bad key is
        // loud there too, and failing loudly is the faithful behaviour.
        //
        // HISTORY — none of the following describes the code above, which is
        // current. It is kept because the failure mode is not obvious from the
        // line that prevents it, and it is fenced because a reviewer read the
        // version of this comment that led with it and reported a BLOCKER for a
        // defect that was already fixed and mutation-verified:
        //
        //     a cache key that silently collapses is worse than no cache at all:
        //     two DIFFERENT inputs sharing one key means the second read returns
        //     the first's cached result. `json_encode()` returned `false` on
        //     failure — NAN/INF, malformed UTF-8, a resource, recursion — and
        //     `(string) false` was `""`, so every unencodable input hashed to
        //     the SAME key:
        //
        //         input 0: json_encode => false   (string) => ''
        //         input 1: json_encode => false   (string) => ''
        $key = $keyFunc !== null
            ? (string) $keyFunc($input)
            : json_encode($input, JSON_THROW_ON_ERROR);

        return [
            'ns' => [Constants::CACHE_NS_WRITES, $nodeName, $node],
            'key' => hash('xxh128', $key),
            'ttl' => $cachePolicy['ttl'] ?? null,
        ];
    }

    /**
     * Read the node's input from the channels it subscribes to.
     *
     * Port of `_procInput`. Two subscription shapes:
     *
     *  - a **map** — each key names a channel; a *trigger* channel that is
     *    empty means the node cannot run at all, while a non-trigger channel
     *    that is empty is simply omitted. That asymmetry is the point of
     *    distinguishing triggers: triggers are what woke the node, so an empty
     *    one means the wake was spurious.
     *  - a **list** — try each channel in order and take the first with a
     *    value. A node reading "any of these" cannot run if all are empty.
     *
     * Returns a {@see Missing} when the node has no input, which the caller
     * treats as "not scheduled".
     *
     * @param array<string, BaseChannel> $channels
     */
    public static function procInput(PregelNode $proc, array $channels, bool $forExecution): mixed
    {
        $val = null;

        if (!array_is_list($proc->channels)) {
            $val = [];
            /** @var array<string, string> $procChannels */
            $procChannels = $proc->channels;

            foreach ($procChannels as $key => $chan) {
                if (in_array($chan, $proc->triggers, true)) {
                    try {
                        // `catchErrors: false`: an empty *trigger* channel means
                        // the node cannot run, and that has to be
                        // distinguishable from a channel that holds null.
                        $val[$key] = self::readChannel($channels, $chan, false);
                    } catch (EmptyChannelError) {
                        return new Missing();
                    }
                    continue;
                }

                if (array_key_exists($chan, $channels)) {
                    try {
                        $val[$key] = self::readChannel($channels, $chan, false);
                    } catch (EmptyChannelError) {
                        // A non-trigger channel with no value is simply absent
                        // from the node's input.
                        continue;
                    }
                }
            }
        } else {
            $successfulRead = false;
            foreach ($proc->channels as $chan) {
                try {
                    $val = self::readChannel($channels, $chan, false);
                    $successfulRead = true;
                    break;
                } catch (EmptyChannelError) {
                    continue;
                }
            }
            if (!$successfulRead) {
                return new Missing();
            }
        }

        if ($forExecution && $proc->mapper !== null) {
            $val = ($proc->mapper)($val);
        }

        return $val;
    }

    /**
     * Read one channel, returning null instead of throwing when it is empty.
     *
     * Port of `readChannel` with `catchErrors = true`.
     *
     * @param array<string, BaseChannel> $channels
     */
    public static function readChannel(array $channels, string $channel, bool $catchErrors = true, bool $returnException = false): mixed
    {
        if (!isset($channels[$channel])) {
            throw new \InvalidArgumentException("No such channel: {$channel}");
        }

        try {
            return $channels[$channel]->get();
        } catch (EmptyChannelError $e) {
            if ($returnException) {
                return $e;
            }
            if ($catchErrors) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Read several channels at once.
     *
     * Port of `readChannels`. Empty channels are skipped rather than recorded as
     * null by default, so a partial state does not present absent keys as
     * explicit nulls.
     *
     * @param array<string, BaseChannel> $channels
     * @param string|list<string>        $select
     */
    public static function readChannels(array $channels, string|array $select, bool $skipEmpty = true): mixed
    {
        if (!is_array($select)) {
            return self::readChannel($channels, $select);
        }

        $values = [];
        foreach ($select as $key) {
            try {
                $values[$key] = self::readChannel($channels, $key, !$skipEmpty);
            } catch (EmptyChannelError) {
                continue;
            }
        }

        return $values;
    }

    /**
     * Compare two path segments, matching JS's `<` / `>` on mixed types.
     *
     * JS compares a number and a string by coercing both to strings. PHP's
     * `<=>` would order `10` before `9`, because it compares numbers when both
     * are numeric. Paths mix `PUSH` (string) with a send index (int), so
     * coercing to string is the behaviour that has to be reproduced.
     */
    private static function comparePathSegments(mixed $a, mixed $b): int
    {
        if (is_bool($a) || is_bool($b)) {
            return ((int) (bool) $a) <=> ((int) (bool) $b);
        }

        // Numeric when both are numbers, as JavaScript's `<` is. Upstream
        // compares the raw values with `aPath[i] < bPath[i]` (algo.ts:289), and
        // path segments are task indices — so upstream orders 9 before 10.
        //
        // A string comparison gets this backwards: "10" < "9" is true
        // lexicographically, so the tenth concurrent task would fold BEFORE the
        // ninth and the later write would win. That is not a crash and not a
        // flake — it is a graph that resumes from a state nobody intended, and
        // it only appears once a superstep has ten or more tasks.
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a <=> $b;
        }

        // Mixed number/string is the pathological case; JavaScript coerces the
        // number to a string for `<`, which is what strcmp does here.
        return strcmp((string) $a, (string) $b);
    }

    /** `parent|node`, or just `node` at the root. */
    private static function childNamespace(string $parentNamespace, string $nodeName): string
    {
        return $parentNamespace === ''
            ? $nodeName
            : $parentNamespace . Constants::CHECKPOINT_NAMESPACE_SEPARATOR . $nodeName;
    }

    /**
     * Snapshot the named channels' current values.
     *
     * @param array<string, BaseChannel> $channels
     * @param array<string, BaseChannel> $wanted
     * @return array<string, mixed>
     */
    private static function channelValuesFor(array $channels, array $wanted): array
    {
        $values = [];
        foreach ($wanted as $name => $channel) {
            try {
                $value = $channel->checkpoint();
                if ($value !== null) {
                    $values[$name] = $value;
                }
            } catch (EmptyChannelError) {
                continue;
            }
        }

        return $values;
    }
}
