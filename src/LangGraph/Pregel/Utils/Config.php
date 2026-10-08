<?php

declare(strict_types=1);

namespace LangGraph\Pregel\Utils;

use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\AsyncLocalStorage;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Debug;
use LangGraph\Pregel\PregelScratchpad;

/**
 * Config plumbing shared by the engine and by node code.
 *
 * Port of `langgraph-core/src/pregel/utils/config.ts`.
 *
 * ## How the TS `LangGraphRunnableConfig` maps onto {@see RunnableConfig}
 *
 * `RunnableConfig` is a closed PHP class, so the LangGraph-only keys upstream spreads across
 * the config object (`store`, `writer`, `interrupt`, `interruptBefore`, `interruptAfter`,
 * `checkpointDuring`, `durability`, `heartbeat`, `executionInfo`, `serverInfo`, `control`,
 * `outputKeys`, `streamMode`) live in {@see RunnableConfig::$options}. An array config is
 * accepted anywhere a `RunnableConfig` is, spelled either way (`run_name` / `runName`).
 *
 * ## Known non-exact behaviour
 *
 *  - Callbacks are plain handler lists in this port, so only the array/array merge of
 *    upstream's six-way `mergeCallbacks` exists: the lists are concatenated. There is no
 *    `CallbackManager` to merge tags/metadata/handlers of, and no dedupe-by-identity case.
 *  - A `RunnableConfig` object cannot distinguish an absent key from one set to its default,
 *    so `recursionLimit` from a later config object wins only when it differs from the
 *    default (25). A later config that deliberately sets exactly 25 over an earlier 100
 *    therefore does not win; spell it as an array (`['recursionLimit' => 25]`) to be exact.
 *  - {@see self::getConfig()} falls back to the config of the task that is currently running
 *    ({@see PregelScratchpad::currentConfig()}) when no ambient {@see AsyncLocalStorage}
 *    config is installed, because nothing in this port seeds that storage while a node runs.
 */
final class Config
{
    private const DEFAULT_RECURSION_LIMIT = 25;

    /** Keys that upstream's `ensureLangGraphConfig` accepts from a caller config. */
    private const CONFIG_KEYS = [
        'tags', 'metadata', 'callbacks', 'runName', 'maxConcurrency', 'recursionLimit',
        'configurable', 'runId', 'outputKeys', 'streamMode', 'store', 'writer', 'interrupt',
        'context', 'interruptBefore', 'interruptAfter', 'checkpointDuring', 'durability',
        'signal', 'heartbeat', 'executionInfo', 'serverInfo', 'control',
    ];

    /** The accepted keys that have no `RunnableConfig` property and so live in `options`. */
    private const OPTION_KEYS = [
        'outputKeys', 'streamMode', 'store', 'writer', 'interrupt', 'interruptBefore',
        'interruptAfter', 'checkpointDuring', 'durability', 'heartbeat', 'executionInfo',
        'serverInfo', 'control',
    ];

    /** `configurable` keys copied into `metadata` (upstream `PROPAGATE_TO_METADATA`). */
    public const PROPAGATE_TO_METADATA = [
        'thread_id', 'checkpoint_id', 'checkpoint_ns', 'task_id', 'run_id', 'assistant_id', 'graph_id',
    ];

    private function __construct()
    {
    }

    /**
     * Copy the allow-listed `configurable` keys into `metadata`, without overwriting any key
     * that metadata already carries.
     *
     * Port of `propagateConfigurableToMetadata`. Only the keys in
     * {@see self::PROPAGATE_TO_METADATA} move: `configurable` also holds the engine's private
     * `__pregel_*` plumbing and arbitrary user values, and none of that belongs in a trace.
     * An empty string moves (an empty `checkpoint_ns` means "the root graph" and traces need
     * to see it); a null does not, because null is how this port spells JS `undefined`.
     *
     * @param array<string, mixed>|null $configurable
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>|null
     */
    public static function propagateConfigurableToMetadata(?array $configurable, ?array $metadata = null): ?array
    {
        if ($configurable === null || $configurable === []) {
            return $metadata;
        }

        $result = $metadata ?? [];
        foreach (self::PROPAGATE_TO_METADATA as $key) {
            if (array_key_exists($key, $result)) {
                continue;
            }
            $value = $configurable[$key] ?? null;
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Drop the engine's `seq:step*` bookkeeping tags.
     *
     * Port of `filterToUserTags`. Returns the surviving tags, or null if none remain.
     * Delegates to {@see Debug::filterToUserTags()}, which carried this function before the
     * rest of `config.ts` was ported, so the two cannot drift.
     *
     * @param list<string>|null $tags
     * @return list<string>|null
     */
    public static function filterToUserTags(?array $tags): ?array
    {
        return Debug::filterToUserTags($tags);
    }

    /**
     * Fold any number of configs, plus the ambient one, into a complete config.
     *
     * Port of `ensureLangGraphConfig`.
     *
     * Precedence, lowest to highest: the defaults, the ambient config installed by
     * {@see AsyncLocalStorage::runWithConfig()}, then each argument in order. `tags`,
     * `metadata`, `configurable` and `callbacks` compose across configs (tags are a plain
     * concatenation, with duplicates kept; the maps merge with later keys winning); every
     * other key is replaced by the later config.
     *
     * The ambient `configurable` is IGNORED for a fresh top-level run. It may belong to a
     * different concurrent invocation on a shared compiled graph, and its scratchpad, task
     * input and user keys would leak into this one. A run is "fresh" when the last config
     * supplied carries a `thread_id` and neither any explicit config nor the ambient one is
     * nested inside a Pregel task (carries `__pregel_read`).
     *
     * The inputs are never mutated: the result owns copies of every array it carries.
     */
    public static function ensureLangGraphConfig(RunnableConfig|array|null ...$configs): RunnableConfig
    {
        $normalized = array_map(self::normalize(...), $configs);
        $ambient = self::ambient();

        $skipImplicitConfigurable = self::isRootLevelExplicitInvoke($normalized, $ambient);

        $empty = new RunnableConfig(
            tags: [],
            metadata: [],
            callbacks: [],
            recursionLimit: self::DEFAULT_RECURSION_LIMIT,
            configurable: [],
        );

        if ($ambient !== null) {
            self::overlay($empty, $ambient, $skipImplicitConfigurable);
        }
        foreach ($normalized as $config) {
            if ($config !== null) {
                self::overlay($empty, $config, false);
            }
        }

        $empty->metadata = self::propagateConfigurableToMetadata($empty->configurable, $empty->metadata) ?? [];

        return $empty;
    }

    /**
     * The long-term store the graph was compiled with, as seen by this config.
     *
     * Port of `getStore`. With no argument the ambient config is read. Throws when neither is
     * available, because "no store" and "no way to find out" are different answers and a node
     * that continued on the second would silently skip its writes.
     */
    public static function getStore(RunnableConfig|array|null $config = null): ?object
    {
        $runConfig = self::requireConfig($config, 'getStore');

        return $runConfig->options['store']
            ?? $runConfig->configurable[Constants::CONFIG_KEY_STORE]
            ?? null;
    }

    /**
     * The custom-stream writer, when `custom` stream mode is on; otherwise null.
     *
     * Port of `getWriter`.
     */
    public static function getWriter(RunnableConfig|array|null $config = null): ?callable
    {
        $runConfig = self::requireConfig($config, 'getWriter');

        $writer = $runConfig->options['writer'] ?? $runConfig->configurable['writer'] ?? null;

        return is_callable($writer) ? $writer : null;
    }

    /**
     * The config of the running graph, or null outside one.
     *
     * Port of `getConfig`. See the class note for the task-config fallback.
     */
    public static function getConfig(): ?RunnableConfig
    {
        return self::ambient() ?? PregelScratchpad::currentConfig();
    }

    /**
     * The input of the task that is currently executing.
     *
     * Port of `getCurrentTaskInput`. Read from the task's scratchpad, so it is only available
     * inside a Pregel task; anywhere else it throws rather than returning a stale value.
     */
    public static function getCurrentTaskInput(RunnableConfig|array|null $config = null): mixed
    {
        $runConfig = self::requireConfig($config, 'getCurrentTaskInput');

        $scratchpad = $runConfig->configurable[Constants::CONFIG_KEY_SCRATCHPAD] ?? null;
        if (!$scratchpad instanceof PregelScratchpad) {
            throw new \RuntimeException('BUG: internal scratchpad not initialized.');
        }

        return $scratchpad->currentTaskInput;
    }

    /**
     * A checkpoint namespace without task ids or loop counters.
     *
     * Port of `recastCheckpointNamespace`: purely numeric segments are dropped and every
     * remaining segment is cut at the first `:`. `parent|123|child:abc` becomes
     * `parent|child`, which is the static path of nodes that `getSubgraphs` knows about.
     */
    public static function recastCheckpointNamespace(string $namespace): string
    {
        $parts = [];
        foreach (explode(Constants::CHECKPOINT_NAMESPACE_SEPARATOR, $namespace) as $part) {
            if (preg_match('/^\d+$/', $part) === 1) {
                continue;
            }
            $parts[] = explode(Constants::CHECKPOINT_NAMESPACE_END, $part)[0];
        }

        return implode(Constants::CHECKPOINT_NAMESPACE_SEPARATOR, $parts);
    }

    /**
     * The namespace of the graph that contains this one.
     *
     * Port of `getParentCheckpointNamespace`: trailing numeric segments are skipped (they are
     * loop counters, not graph names), then the last segment is removed. Only the TRAILING
     * run of digits is skipped, so a numeric segment in the middle stays.
     */
    public static function getParentCheckpointNamespace(string $namespace): string
    {
        $parts = explode(Constants::CHECKPOINT_NAMESPACE_SEPARATOR, $namespace);
        while (count($parts) > 1 && preg_match('/^\d+$/', $parts[count($parts) - 1]) === 1) {
            array_pop($parts);
        }

        return implode(Constants::CHECKPOINT_NAMESPACE_SEPARATOR, array_slice($parts, 0, -1));
    }

    // ---- internals ---------------------------------------------------------------------

    /**
     * Resolve a config argument, falling back to the ambient config, or throw.
     */
    private static function requireConfig(RunnableConfig|array|null $config, string $caller): RunnableConfig
    {
        $runConfig = $config === null ? self::getConfig() : self::normalize($config);
        if ($runConfig === null) {
            throw new \RuntimeException(implode("\n", [
                'Config not retrievable. This is likely because you are running outside a graph node, '
                . 'or in an environment without ambient config support.',
                "If you're running `{$caller}` in such an environment, pass the `config` from the node function directly.",
            ]));
        }

        return $runConfig;
    }

    /** The ambient config installed by {@see AsyncLocalStorage::runWithConfig()}, normalised. */
    private static function ambient(): ?RunnableConfig
    {
        $ambient = AsyncLocalStorage::getRunnableConfig();

        return $ambient === null ? null : self::normalize($ambient);
    }

    /**
     * Turn a loose array (or a config object) into a {@see RunnableConfig}.
     *
     * Array keys may be camelCase or snake_case. LangGraph-only keys are moved into
     * `options`. Keys outside upstream's accepted list are dropped, exactly as upstream drops
     * them from a caller config.
     *
     * @param RunnableConfig|array<string, mixed>|object|null $config
     */
    private static function normalize(mixed $config): ?RunnableConfig
    {
        if ($config === null) {
            return null;
        }
        if ($config instanceof RunnableConfig) {
            return $config;
        }
        if (!is_array($config)) {
            return null;
        }

        $known = [];
        $options = [];
        foreach ($config as $key => $value) {
            if ($value === null) {
                continue;
            }
            $camel = lcfirst(str_replace('_', '', ucwords((string) $key, '_')));
            if (!in_array($camel, self::CONFIG_KEYS, true)) {
                continue;
            }
            if (in_array($camel, self::OPTION_KEYS, true)) {
                $options[$camel] = $value;
            } else {
                $known[$camel] = $value;
            }
        }

        return new RunnableConfig(
            tags: array_values((array) ($known['tags'] ?? [])),
            metadata: (array) ($known['metadata'] ?? []),
            runId: (array) ($known['runId'] ?? []),
            callbacks: array_values((array) ($known['callbacks'] ?? [])),
            maxConcurrency: isset($known['maxConcurrency']) ? (int) $known['maxConcurrency'] : null,
            recursionLimit: isset($known['recursionLimit']) ? (int) $known['recursionLimit'] : self::DEFAULT_RECURSION_LIMIT,
            signal: $known['signal'] ?? null,
            runName: isset($known['runName']) ? (string) $known['runName'] : null,
            configurable: (array) ($known['configurable'] ?? []),
            options: $options,
            context: $known['context'] ?? null,
        );
    }

    /**
     * Whether the caller is starting a fresh top-level run (see {@see self::ensureLangGraphConfig()}).
     *
     * Only the LAST supplied config counts as invoke-time options; earlier ones are
     * graph-bound defaults, and a child graph bound with a `thread_id` but invoked from a
     * parent task without a fresh config still needs the ambient nesting keys.
     *
     * @param list<RunnableConfig|null> $configs
     */
    private static function isRootLevelExplicitInvoke(array $configs, ?RunnableConfig $ambient): bool
    {
        $invokeConfig = null;
        for ($i = count($configs) - 1; $i >= 0; $i--) {
            if ($configs[$i] !== null) {
                $invokeConfig = $configs[$i];
                break;
            }
        }

        $hasInvokeTimeThreadId = ($invokeConfig?->configurable['thread_id'] ?? null) !== null;

        $hasExplicitNesting = false;
        foreach ($configs as $config) {
            if (($config?->configurable[Constants::CONFIG_KEY_READ] ?? null) !== null) {
                $hasExplicitNesting = true;
                break;
            }
        }

        $hasAmbientNesting = ($ambient?->configurable[Constants::CONFIG_KEY_READ] ?? null) !== null;

        return $hasInvokeTimeThreadId && !$hasExplicitNesting && !$hasAmbientNesting;
    }

    /**
     * Layer one config over the accumulating result.
     */
    private static function overlay(RunnableConfig $into, RunnableConfig $from, bool $skipConfigurable): void
    {
        if (!$skipConfigurable) {
            $into->configurable = array_merge($into->configurable, $from->configurable);
        }
        $into->metadata = array_merge($into->metadata, $from->metadata);
        $into->tags = array_merge($into->tags, $from->tags);
        $into->callbacks = array_merge($into->callbacks, $from->callbacks);

        if ($from->runId !== []) {
            $into->runId = $from->runId;
        }
        if ($from->maxConcurrency !== null) {
            $into->maxConcurrency = $from->maxConcurrency;
        }
        if ($from->recursionLimit !== self::DEFAULT_RECURSION_LIMIT) {
            $into->recursionLimit = $from->recursionLimit;
        }
        if ($from->signal !== null) {
            $into->signal = $from->signal;
        }
        if ($from->runName !== null) {
            $into->runName = $from->runName;
        }
        if ($from->context !== null) {
            $into->context = $from->context;
        }

        foreach ($from->options as $key => $value) {
            if (in_array($key, self::OPTION_KEYS, true) && $value !== null) {
                $into->options[$key] = $value;
            }
        }
    }
}
