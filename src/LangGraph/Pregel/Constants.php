<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * Every reserved name the Pregel engine uses.
 *
 * Port of `langgraph-core/src/constants.ts`.
 *
 * These strings are load-bearing, not cosmetic. Three distinct groups:
 *
 *  1. **Reserved node names** (`START`, `END`) and **reserved channels**
 *     (`TASKS`, `INTERRUPT`, `RESUME`, ...). The engine's algorithms branch on
 *     them by string identity — e.g. `_applyWrites` refuses to route a write to
 *     `TASKS` as a real channel, and `applyWrites` folds `ERROR`/`INTERRUPT`
 *     writes into a single scalar rather than per-task maps.
 *  2. **`CONFIG_KEY_*` config keys.** The engine injects callables and objects
 *     into `configurable` under these names, and a node reads them back out.
 *     They travel through checkpoints, so renaming one breaks resume.
 *  3. **Namespace separators.** `|` and `:` encode checkpoint namespace depth
 *     in a single string, and a name containing either is rejected.
 *
 * Nothing here may be changed without breaking a persisted thread.
 */
final class Constants
{
    private function __construct()
    {
    }

    /** Special reserved node name denoting the start of a graph. */
    public const START = '__start__';

    /** Special reserved node name denoting the end of a graph. */
    public const END = '__end__';

    public const INPUT = '__input__';

    public const COPY = '__copy__';

    public const ERROR = '__error__';

    /**
     * Reserved write key recording which node failed, so a node-level error
     * handler sees the same provenance after a checkpoint resume.
     *
     * Value format in pending writes: `[taskId, ERROR_SOURCE_NODE, nodeName]`.
     */
    public const ERROR_SOURCE_NODE = '__error_source_node__';

    /**
     * Reserved write key marking a task whose writes came from a node error handler.
     *
     * Upstream runs a handler as its own task, so a handled failure is never cached under the failed
     * node's key. This engine runs the handler inline, so the node's write-back appends this marker for
     * {@see \LangGraph\Pregel\PregelLoop} to see. It never reaches a channel.
     */
    public const HANDLED = '__handled__';

    /** Reserved cache namespace for node writes. */
    public const CACHE_NS_WRITES = '__pregel_ns_writes';

    public const CONFIG_KEY_SEND = '__pregel_send';

    /** Config key holding the callable that runs a pushed task. */
    public const CONFIG_KEY_CALL = '__pregel_call';

    public const CONFIG_KEY_READ = '__pregel_read';

    public const CONFIG_KEY_CHECKPOINTER = '__pregel_checkpointer';

    public const CONFIG_KEY_RESUMING = '__pregel_resuming';

    public const CONFIG_KEY_TASK_ID = '__pregel_task_id';

    public const CONFIG_KEY_STREAM = '__pregel_stream';

    public const CONFIG_KEY_RESUME_VALUE = '__pregel_resume_value';

    public const CONFIG_KEY_RESUME_MAP = '__pregel_resume_map';

    public const CONFIG_KEY_SCRATCHPAD = '__pregel_scratchpad';

    /** State from the previous invocation of a graph for the given thread. */
    public const CONFIG_KEY_PREVIOUS_STATE = '__pregel_previous';

    public const CONFIG_KEY_DURABILITY = '__pregel_durability';

    /** The long-term {@see \LangGraph\Store\BaseStore} a task reads and writes. */
    public const CONFIG_KEY_STORE = '__pregel_store';

    /** A per-call {@see \LangGraph\Cache\BaseCache} that overrides the graph's own. */
    public const CONFIG_KEY_CACHE = '__pregel_cache';

    public const CONFIG_KEY_CHECKPOINT_ID = 'checkpoint_id';

    public const CONFIG_KEY_CHECKPOINT_NS = 'checkpoint_ns';

    public const CONFIG_KEY_NODE_FINISHED = '__pregel_node_finished';

    /** Config key holding a {@see \LangGraph\Errors\NodeError} for a handler. */
    public const CONFIG_KEY_NODE_ERROR = '__pregel_node_error';

    /** Part of the public API. */
    public const CONFIG_KEY_CHECKPOINT_MAP = 'checkpoint_map';

    public const CONFIG_KEY_REPLAY_STATE = '__pregel_replay_state';

    public const CONFIG_KEY_ABORT_SIGNALS = '__pregel_abort_signals';

    /** Special channel reserved for graph interrupts. */
    public const INTERRUPT = '__interrupt__';

    /** Special channel reserved for graph resume. */
    public const RESUME = '__resume__';

    /** Special channel for a task that exits without writing anything. */
    public const NO_WRITES = '__no_writes__';

    /** Special channel reserved for graph return. */
    public const RETURN = '__return__';

    /** Special channel reserved for graph previous state. */
    public const PREVIOUS = '__previous__';

    public const RUNTIME_PLACEHOLDER = '__pregel_runtime_placeholder__';

    public const RECURSION_LIMIT_DEFAULT = 25;

    public const TAG_HIDDEN = 'langsmith:hidden';

    public const TAG_NOSTREAM = 'langsmith:nostream';

    public const SELF = '__self__';

    public const TASKS = '__pregel_tasks';

    public const PUSH = '__pregel_push';

    public const PULL = '__pregel_pull';

    public const TASK_NAMESPACE = '6ba7b831-9dad-11d1-80b4-00c04fd430c8';

    /**
     * The task id used for writes that belong to the graph as a whole rather
     * than to any one task — input writes, `Command` updates and gotos.
     */
    public const NULL_TASK_ID = '00000000-0000-0000-0000-000000000000';

    /** Separates checkpoint namespace levels. */
    public const CHECKPOINT_NAMESPACE_SEPARATOR = '|';

    /** Marks the end of a checkpoint namespace segment. */
    public const CHECKPOINT_NAMESPACE_END = ':';

    /**
     * Channel and tag names the engine reserves for its own bookkeeping.
     *
     * A trigger in this set never wakes a node directly; a `CONFIG_KEY_*` entry
     * is not a channel at all, and `TAG_HIDDEN` is a trace label. {@see
     * Algorithm::applyWrites()} consults it to decide which triggered channels
     * to consume.
     *
     * @return list<string>
     */
    public static function reserved(): array
    {
        return [
            self::TAG_HIDDEN,
            self::INPUT,
            self::INTERRUPT,
            self::RESUME,
            self::ERROR,
            self::ERROR_SOURCE_NODE,
            self::HANDLED,
            self::NO_WRITES,
            self::CONFIG_KEY_SEND,
            self::CONFIG_KEY_READ,
            self::CONFIG_KEY_CHECKPOINTER,
            self::CONFIG_KEY_DURABILITY,
            self::CONFIG_KEY_STORE,
            self::CONFIG_KEY_CACHE,
            self::CONFIG_KEY_STREAM,
            self::CONFIG_KEY_RESUMING,
            self::CONFIG_KEY_TASK_ID,
            self::CONFIG_KEY_CALL,
            self::CONFIG_KEY_RESUME_VALUE,
            self::CONFIG_KEY_SCRATCHPAD,
            self::CONFIG_KEY_PREVIOUS_STATE,
            self::CONFIG_KEY_CHECKPOINT_MAP,
            self::CONFIG_KEY_CHECKPOINT_NS,
            self::CONFIG_KEY_CHECKPOINT_ID,
            self::CONFIG_KEY_REPLAY_STATE,
        ];
    }

    /** True when `$name` is reserved by the engine. */
    public static function isReserved(string $name): bool
    {
        return in_array($name, self::reserved(), true);
    }
}
