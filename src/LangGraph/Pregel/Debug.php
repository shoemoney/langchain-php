<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Channels\BaseChannel;
use LangGraph\Checkpoint\CheckpointConstants;
use LangGraph\Errors\EmptyChannelError;
use LangGraph\Pregel\Messages\TracedNode;

/**
 * Helpers that shape engine state for debug streams and console output.
 *
 * Port of the free functions in `langgraph-core/src/pregel/debug.ts`. They are
 * static methods because PSR-4 autoloads classes, not functions.
 *
 * The streaming side of this file (the `debug` mode's `task`, `task_result` and
 * `checkpoint` events) is emitted by {@see PregelLoop::emitDebug()}; this class
 * holds the pure mappers a caller can use on their own, and the `print*`
 * renderers behind `debug: true`.
 *
 * ## Known non-exact behaviour
 *
 * Upstream's `console.log` becomes `echo`. `findSubgraphPregel` (a recursive
 * search through wrapping runnables, `pregel/utils/subgraph.ts`) is reduced to
 * "is this a {@see Pregel}, possibly under a node-tracing wrapper".
 */
final class Debug
{
    /** ANSI colours the renderers use. */
    public const BLUE = ['start' => "\x1b[34m", 'end' => "\x1b[0m"];

    public const GREEN = ['start' => "\x1b[32m", 'end' => "\x1b[0m"];

    public const YELLOW = ['start' => "\x1b[33;1m", 'end' => "\x1b[0m"];

    /**
     * Wrap some text in a colour for printing to the console.
     *
     * @param array{start: string, end: string} $color
     */
    public static function wrap(array $color, string $text): string
    {
        return $color['start'] . $text . $color['end'];
    }

    /**
     * Read each channel as a `[name, value]` pair, skipping the empty ones.
     *
     * Port of `_readChannels`. An empty channel is "nothing written yet", not a
     * failure; every other error is a real one and propagates.
     *
     * @param array<string, BaseChannel> $channels
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    public static function readChannels(array $channels): \Generator
    {
        foreach ($channels as $name => $channel) {
            try {
                $value = $channel->get();
            } catch (EmptyChannelError) {
                continue;
            }

            yield [(string) $name, $value];
        }
    }

    /**
     * Narrow tags to the ones a user set, dropping `seq:step:N` bookkeeping.
     *
     * Port of `filterToUserTags` (`pregel/utils/config.ts`), here because the
     * debug mappers are its only caller in this port.
     *
     * @param list<string>|null $tags
     * @return list<string>|null
     */
    public static function filterToUserTags(?array $tags): ?array
    {
        if ($tags === null || $tags === []) {
            return null;
        }

        $filtered = array_values(array_filter(
            $tags,
            static fn (string $tag): bool => !str_starts_with($tag, 'seq:step'),
        ));

        return $filtered === [] ? null : $filtered;
    }

    /**
     * The user-meaningful metadata to forward on a task's stream payload.
     *
     * Drops the framework's own keys, which the task's fields and namespace
     * already carry, keeping things like `lc_agent_name` and caller-supplied
     * metadata. Filtered config tags are folded in under `tags`.
     *
     * @return array<string, mixed>|null Null when there is nothing to forward.
     */
    private static function buildTaskMetadata(?RunnableConfig $config): ?array
    {
        if ($config === null) {
            return null;
        }

        $metadata = [];
        foreach ($config->metadata as $key => $value) {
            if (!in_array($key, CheckpointConstants::EXCLUDED_METADATA_KEYS, true)) {
                $metadata[$key] = $value;
            }
        }

        $tags = self::filterToUserTags($config->tags);
        if ($tags !== null) {
            $metadata['tags'] = $tags;
        }

        return $metadata === [] ? null : $metadata;
    }

    /**
     * The `task` event payload for each visible task.
     *
     * Port of `mapDebugTasks`. A task tagged hidden is skipped entirely.
     *
     * @param iterable<PregelExecutableTask> $tasks
     * @return \Generator<int, array<string, mixed>>
     */
    public static function mapDebugTasks(iterable $tasks): \Generator
    {
        foreach ($tasks as $task) {
            if ($task->config !== null && in_array(Constants::TAG_HIDDEN, $task->config->tags, true)) {
                continue;
            }

            // Upstream matches `[writeId, n]` against a task's `[channel, value]`
            // writes, so this selects a write whose CHANNEL is the task id and whose
            // VALUE is the interrupt marker. That is what the source does; it is
            // ported as written rather than "corrected" into a different feature.
            $interrupts = [];
            foreach ($task->writes as $write) {
                if (($write[0] ?? null) === $task->id && ($write[1] ?? null) === Constants::INTERRUPT) {
                    $interrupts[] = $write[1];
                }
            }

            $payload = [
                'id' => $task->id,
                'name' => $task->name,
                'input' => $task->input,
                'triggers' => $task->triggers,
                'interrupts' => $interrupts,
            ];

            $metadata = self::buildTaskMetadata($task->config);
            if ($metadata !== null) {
                $payload['metadata'] = $metadata;
            }

            yield $payload;
        }
    }

    /**
     * Fold a task's writes into a `channel => value` map.
     *
     * A channel written twice becomes `{$writes: [first, second]}` so neither
     * value is lost to the later one.
     *
     * @param list<array{0: string, 1: mixed}> $writes
     * @return array<string, mixed>
     */
    private static function mapTaskResultWrites(array $writes): array
    {
        $result = [];

        foreach ($writes as [$channel, $value]) {
            $channel = (string) $channel;

            if (array_key_exists($channel, $result)) {
                $existing = $result[$channel];
                $channelWrites = is_array($existing) && isset($existing['$writes']) && is_array($existing['$writes'])
                    ? $existing['$writes']
                    : [$existing];

                $channelWrites[] = $value;
                $result[$channel] = ['$writes' => $channelWrites];
            } else {
                $result[$channel] = $value;
            }
        }

        return $result;
    }

    /**
     * The `task_result` event payload for each visible task.
     *
     * Port of `mapDebugTaskResults`.
     *
     * @param iterable<array{0: PregelExecutableTask, 1: list<array{0: string, 1: mixed}>}> $tasks
     * @param string|list<string>                                                           $streamChannels
     * @return \Generator<int, array<string, mixed>>
     */
    public static function mapDebugTaskResults(iterable $tasks, string|array $streamChannels): \Generator
    {
        foreach ($tasks as [$task, $writes]) {
            if ($task->config !== null && in_array(Constants::TAG_HIDDEN, $task->config->tags, true)) {
                continue;
            }

            $streamed = array_values(array_filter(
                $writes,
                static fn (array $write): bool => is_array($streamChannels)
                    ? in_array($write[0], $streamChannels, true)
                    : $write[0] === $streamChannels,
            ));

            $interrupts = [];
            foreach ($writes as $write) {
                if ($write[0] === Constants::INTERRUPT) {
                    $interrupts[] = $write[1];
                }
            }

            yield [
                'id' => $task->id,
                'name' => $task->name,
                'result' => self::mapTaskResultWrites($streamed),
                'interrupts' => $interrupts,
            ];
        }
    }

    /**
     * A config in the snake_case shape LangGraph Python streams.
     *
     * @return array<string, mixed>
     */
    private static function formatConfig(RunnableConfig $config): array
    {
        return [
            'callbacks' => $config->callbacks,
            'configurable' => $config->configurable,
            'max_concurrency' => $config->maxConcurrency,
            'metadata' => $config->metadata,
            'recursion_limit' => $config->recursionLimit,
            'run_id' => $config->runId,
            'run_name' => $config->runName,
            'tags' => $config->tags,
        ];
    }

    /**
     * Whether a runnable is, or wraps, a compiled graph.
     */
    private static function isSubgraph(mixed $candidate): bool
    {
        if ($candidate instanceof TracedNode) {
            $candidate = $candidate->inner;
        }

        return $candidate instanceof Pregel;
    }

    /**
     * The `checkpoint` event payload.
     *
     * Port of `mapDebugCheckpoint`. Subgraph tasks get a `state` entry holding
     * the config that addresses their own checkpoint namespace, so a consumer
     * can fetch the child's state without guessing it.
     *
     * @param array<string, BaseChannel>                       $channels
     * @param string|list<string>                              $streamChannels
     * @param array<string, mixed>                             $metadata
     * @param list<PregelExecutableTask>                       $tasks
     * @param list<array{0: string, 1: string, 2: mixed}>      $pendingWrites
     * @param string|list<string>                              $outputKeys
     * @return \Generator<int, array<string, mixed>>
     */
    public static function mapDebugCheckpoint(
        RunnableConfig $config,
        array $channels,
        string|array $streamChannels,
        array $metadata,
        array $tasks,
        array $pendingWrites,
        ?RunnableConfig $parentConfig,
        string|array $outputKeys,
    ): \Generator {
        $parentNs = $config->configurable['checkpoint_ns'] ?? null;
        $taskStates = [];

        foreach ($tasks as $task) {
            $candidates = $task->subgraphs !== [] ? $task->subgraphs : [$task->proc];
            $hasSubgraph = false;
            foreach ($candidates as $candidate) {
                if (self::isSubgraph($candidate)) {
                    $hasSubgraph = true;
                    break;
                }
            }
            if (!$hasSubgraph) {
                continue;
            }

            $taskNs = $task->name . ':' . $task->id;
            if ($parentNs !== null && $parentNs !== '') {
                $taskNs = $parentNs . '|' . $taskNs;
            }

            $taskStates[$task->id] = [
                'configurable' => [
                    'thread_id' => $config->configurable['thread_id'] ?? null,
                    'checkpoint_ns' => $taskNs,
                ],
            ];
        }

        yield [
            'config' => self::formatConfig($config),
            'values' => IO::readChannels($channels, $streamChannels),
            'metadata' => $metadata,
            'next' => array_map(static fn (PregelExecutableTask $t): string => $t->name, $tasks),
            'tasks' => self::tasksWithWrites($tasks, $pendingWrites, $taskStates, $outputKeys),
            'parentConfig' => $parentConfig !== null ? self::formatConfig($parentConfig) : null,
        ];
    }

    /**
     * Attach each task's error, interrupts, child state and result.
     *
     * Port of `tasksWithWrites`. Returns arrays rather than
     * {@see PregelTaskDescription} objects: upstream widens the description with
     * `error`, `state` and `result`, which the class does not have, and a key
     * that is present only when it applies is what separates "no result yet"
     * from a result of null.
     *
     * @param iterable<PregelTaskDescription|PregelExecutableTask>  $tasks
     * @param list<array{0: string, 1: string, 2: mixed}>           $pendingWrites `[taskId, channel, value]`
     * @param array<string, mixed>|null                             $states        Child state by task id.
     * @param string|list<string>                                   $outputKeys
     * @return list<array<string, mixed>>
     */
    public static function tasksWithWrites(
        iterable $tasks,
        array $pendingWrites,
        ?array $states,
        string|array $outputKeys,
    ): array {
        $out = [];

        foreach ($tasks as $task) {
            $error = null;
            $hasError = false;
            foreach ($pendingWrites as [$id, $channel, $value]) {
                if ($id === $task->id && $channel === Constants::ERROR) {
                    $error = $value;
                    $hasError = true;
                    break;
                }
            }
            // Upstream tests truthiness of the error payload, not its presence.
            $hasError = $hasError && (bool) $error;

            $interrupts = [];
            foreach ($pendingWrites as [$id, $channel, $value]) {
                if ($id === $task->id && $channel === Constants::INTERRUPT) {
                    $interrupts[] = $value;
                }
            }

            $result = null;
            if (!$hasError && $interrupts === [] && $pendingWrites !== []) {
                $result = self::resultFor($task->id, $pendingWrites, $outputKeys);
            }

            $path = $task->path?->toArray();

            if ($hasError) {
                $entry = [
                    'id' => $task->id,
                    'name' => $task->name,
                    'path' => $path,
                    'error' => $error,
                    'interrupts' => $interrupts,
                ];
            } else {
                $entry = [
                    'id' => $task->id,
                    'name' => $task->name,
                    'path' => $path,
                    'interrupts' => $interrupts,
                ];
                if (isset($states[$task->id])) {
                    $entry['state'] = $states[$task->id];
                }
            }

            if ($result !== null) {
                $entry['result'] = $result;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * A task's result: its explicit return, else its writes to the output keys.
     *
     * @param list<array{0: string, 1: string, 2: mixed}> $pendingWrites
     * @param string|list<string>                         $outputKeys
     */
    private static function resultFor(string $taskId, array $pendingWrites, string|array $outputKeys): mixed
    {
        foreach ($pendingWrites as [$id, $channel, $value]) {
            if ($id === $taskId && $channel === Constants::RETURN) {
                return $value;
            }
        }

        if (is_string($outputKeys)) {
            foreach ($pendingWrites as [$id, $channel, $value]) {
                if ($id === $taskId && $channel === $outputKeys) {
                    return $value;
                }
            }

            return null;
        }

        $results = [];
        foreach ($pendingWrites as [$id, $channel, $value]) {
            if ($id === $taskId && in_array($channel, $outputKeys, true)) {
                $results[] = [$channel, $value];
            }
        }

        return $results === [] ? null : self::mapTaskResultWrites($results);
    }

    /**
     * Print the channel values at the end of a step.
     *
     * Port of `printCheckpoint`.
     *
     * @param array<string, BaseChannel> $channels
     */
    public static function printCheckpoint(int $step, array $channels): void
    {
        $values = [];
        foreach (self::readChannels($channels) as [$name, $value]) {
            $values[$name] = $value;
        }

        echo implode('', [
            self::wrap(self::BLUE, '[langgraph/checkpoint]'),
            "Finishing step {$step}. Channel values:\n",
            "\n" . self::json($values),
        ]), "\n";
    }

    /**
     * Print the state at the end of a step.
     *
     * Port of `printStepCheckpoint`.
     *
     * @param array<string, BaseChannel> $channels
     * @param list<string>               $whitelist
     */
    public static function printStepCheckpoint(int $step, array $channels, array $whitelist): void
    {
        echo implode('', [
            self::wrap(self::BLUE, "[{$step}:checkpoint]"),
            "\x1b[1m State at the end of step {$step}:\x1b[0m\n",
            self::json(IO::readChannels($channels, $whitelist)),
        ]), "\n";
    }

    /**
     * Print the tasks a step is about to run.
     *
     * Port of `printStepTasks`.
     *
     * @param list<PregelExecutableTask> $nextTasks
     */
    public static function printStepTasks(int $step, array $nextTasks): void
    {
        $count = count($nextTasks);

        echo implode('', [
            self::wrap(self::BLUE, "[{$step}:tasks]"),
            "\x1b[1m Starting step {$step} with {$count} task" . ($count === 1 ? '' : 's') . ":\x1b[0m\n",
            implode("\n", array_map(
                static fn (PregelExecutableTask $task): string => '- ' . self::wrap(self::GREEN, $task->name)
                    . ' -> ' . self::json($task->input),
                $nextTasks,
            )),
        ]), "\n";
    }

    /**
     * Print what a step wrote, restricted to the whitelisted channels.
     *
     * Port of `printStepWrites`.
     *
     * @param list<array{0: string, 1: mixed}> $writes
     * @param list<string>                     $whitelist
     */
    public static function printStepWrites(int $step, array $writes, array $whitelist): void
    {
        $byChannel = [];
        foreach ($writes as [$channel, $value]) {
            if (in_array($channel, $whitelist, true)) {
                $byChannel[$channel][] = $value;
            }
        }

        $n = count($byChannel);
        $lines = [];
        foreach ($byChannel as $name => $values) {
            $lines[] = '- ' . self::wrap(self::YELLOW, (string) $name) . ' -> '
                . implode(', ', array_map(self::json(...), $values));
        }

        echo implode('', [
            self::wrap(self::BLUE, "[{$step}:writes]"),
            "\x1b[1m Finished step {$step} with writes to {$n} channel" . ($n !== 1 ? 's' : '') . ":\x1b[0m\n",
            implode("\n", $lines),
        ]), "\n";
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
