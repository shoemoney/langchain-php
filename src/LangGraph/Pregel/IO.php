<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangGraph\Channels\BaseChannel;
use LangGraph\Errors\InvalidUpdateError;

/**
 * The translations between a superstep's internals and the values a caller sees.
 *
 * Port of `langgraph-core/src/pregel/io.ts`.
 *
 * Every one of these is a *projection*: something outside the engine (the
 * channel map, a list of pending writes) is turned into the shape a consumer
 * asked for. The translations are the boundary between Pregel's internal
 * vocabulary and the public one, and getting them wrong produces a graph that
 * runs correctly and reports nonsense.
 *
 *  - {@see self::mapInput()} turns a caller's input into channel writes.
 *  - {@see self::mapCommand()} turns a `Command` into channel writes, routing
 *    a `goto` to a branch channel and a `resume` to the resume channel.
 *  - {@see self::mapOutputValues()} decides whether a step produced *any*
 *    visible state change, and if so reads the output channels.
 *  - {@see self::mapOutputUpdates()} attributes a step's writes back to the
 *    nodes that produced them, for the `updates` stream.
 */
final class IO
{
    private function __construct()
    {
    }

    /**
     * Read one channel, optionally propagating or returning the empty error.
     *
     * Port of `readChannel`.
     *
     * @param array<string, BaseChannel> $channels
     */
    public static function readChannel(array $channels, string $chan, bool $catchErrors = true, bool $returnException = false): mixed
    {
        if (!isset($channels[$chan])) {
            throw new \InvalidArgumentException("No such channel: {$chan}");
        }

        try {
            return $channels[$chan]->get();
        } catch (\LangGraph\Errors\EmptyChannelError $e) {
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
     * Read several channels, skipping the empty ones by default.
     *
     * Port of `readChannels`.
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
        foreach ($select as $k) {
            try {
                $values[$k] = self::readChannel($channels, $k, !$skipEmpty);
            } catch (\LangGraph\Errors\EmptyChannelError) {
                continue;
            }
        }

        return $values;
    }

    /**
     * Turn a caller's input into channel writes.
     *
     * Port of `mapInput`. When the graph declares several input channels, the
     * input must be a map keyed by channel name and *only* those keys are
     * accepted — an unrecognised key is dropped rather than written, because
     * there is no channel to write it to and inventing one would silently widen
     * the graph's state.
     *
     * @param string|list<string> $inputChannels
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    public static function mapInput(string|array $inputChannels, mixed $chunk = null): \Generator
    {
        if ($chunk === null) {
            return;
        }

        if (is_array($inputChannels)) {
            if (is_array($chunk)) {
                foreach ($chunk as $k => $v) {
                    if (in_array((string) $k, $inputChannels, true)) {
                        yield [(string) $k, $v];
                    }
                }

                return;
            }

            throw new \InvalidArgumentException(
                'Input chunk must be an object when "inputChannels" is an array'
            );
        }

        yield [$inputChannels, $chunk];
    }

    /**
     * Turn a `Command` into channel writes.
     *
     * Port of `mapCommand`.
     *
     * The routing here is what makes `Command` work:
     *
     *  - a `goto` node name becomes a write to `branch:to:<node>`, the channel
     *    the destination node subscribes to. A `Send` goes to `TASKS` instead,
     *    which is the scheduler's queue.
     *  - a `resume` becomes a write to `RESUME`, which the scratchpad picks up.
     *    A map keyed by task-id hashes is fanned out per task, *appending* to
     *    the MOST RECENT resume that task already has, and not all of them.
     *    Upstream truncates the same way (`io.ts:108`, a `.slice(0, 1)`), so
     *    this is deliberate fidelity rather than an oversight — a task's queued
     *    resume values are replaced, not accumulated. It was previously
     *    documented here as the opposite, which is what invited a reviewer to
     *    read the code as a bug.
     *  - an `update` becomes writes to the named channels.
     *
     * All of it is attributed to the null task id: the command belongs to the
     * graph, not to whichever node happened to return it.
     *
     * @param list<array{0: string, 1: string, 2: mixed}> $pendingWrites
     * @return \Generator<int, array{0: string, 1: string, 2: mixed}>
     */
    public static function mapCommand(Command $cmd, array $pendingWrites = []): \Generator
    {
        if ($cmd->graph === Command::PARENT) {
            throw new InvalidUpdateError('There is no parent graph.');
        }

        foreach ($cmd->gotoList() as $send) {
            if ($send instanceof Send) {
                yield [Constants::NULL_TASK_ID, Constants::TASKS, $send];
            } elseif (is_string($send)) {
                yield [Constants::NULL_TASK_ID, 'branch:to:' . $send, '__start__'];
            } else {
                throw new \InvalidArgumentException(
                    'In Command.send, expected Send or string, got ' . get_debug_type($send)
                );
            }
        }

        if ($cmd->resume !== null && $cmd->resume !== false) {
            $resume = $cmd->resume;
            // Task-map detection: a NON-EMPTY map whose every key is a task
            // hash. Upstream's guard is `Object.keys(resume).length &&
            // Object.keys(resume).every(isXXH3)` (io.ts:100-102) — exactly
            // these two conditions. A comparison of `array_keys($resume)`
            // against itself sat here as well; it is true for every input and
            // guarded nothing, and a future editor "simplifying" the hash check
            // beside it would have broken task-map detection with nothing to
            // catch it.
            $isTaskMap = is_array($resume) && $resume !== []
                && self::allKeysAreHashes($resume);

            if ($isTaskMap) {
                foreach ($resume as $tid => $value) {
                    $existing = [];
                    foreach ($pendingWrites as $write) {
                        if ($write[0] === $tid && $write[1] === Constants::RESUME) {
                            $existing[] = $write[2];
                        }
                    }
                    $existing = array_slice($existing, 0, 1);
                    $existing[] = $value;
                    yield [(string) $tid, Constants::RESUME, $existing];
                }
            } else {
                yield [Constants::NULL_TASK_ID, Constants::RESUME, $resume];
            }
        }

        $update = $cmd->update;
        if ($update !== null && $update !== false && $update !== []) {
            if (!is_array($update)) {
                throw new \InvalidArgumentException(
                    'Expected cmd.update to be a dict mapping channel names to update values'
                );
            }

            foreach ($update as $k => $v) {
                yield [Constants::NULL_TASK_ID, (string) $k, $v];
            }
        }
    }

    /**
     * Whether a step produced visible state, and if so what the state is.
     *
     * Port of `mapOutputValues`. A step only produces a `values` chunk if
     * something actually wrote to an output channel — otherwise a ten-node
     * graph would emit one `values` event per step regardless, and a consumer
     * could not tell progress from noise.
     *
     * @param string|list<string>                $outputChannels
     * @param list<array{0: string, 1: mixed}>|true $pendingWrites
     * @param array<string, BaseChannel>        $channels
     * @return \Generator<int, mixed>
     */
    public static function mapOutputValues(
        string|array $outputChannels,
        array|bool $pendingWrites,
        array $channels,
    ): \Generator {
        if (is_array($outputChannels)) {
            $matched = $pendingWrites === true;
            if (!$matched) {
                foreach ($pendingWrites as $write) {
                    if (in_array($write[0], $outputChannels, true)) {
                        $matched = true;
                        break;
                    }
                }
            }
            if ($matched) {
                yield self::readChannels($channels, $outputChannels);
            }

            return;
        }

        $matched = $pendingWrites === true;
        if (!$matched) {
            foreach ($pendingWrites as $write) {
                if ($write[0] === $outputChannels) {
                    $matched = true;
                    break;
                }
            }
        }
        if ($matched) {
            yield self::readChannel($channels, $outputChannels);
        }
    }

    /**
     * Attribute a step's writes back to the nodes that made them.
     *
     * Port of `mapOutputUpdates`. This is what the `updates` stream reports, and
     * its shape is a caller-facing contract: `{nodeName: whatThatNodeWrote}`.
     *
     * Two subtleties, both deliberate:
     *
     *  - A node that wrote the same channel twice produces *two* entries, not
     *    one merged value. Merging would lose information a caller may need;
     *    the value becomes a list only because the API has to fit both cases in
     *    one shape.
     *  - Writes on `ERROR` and `INTERRUPT` are excluded. A failed task and a
     *    paused task produced no state, and reporting `['error' => ...]` as if
     *    it were an update would be a lie.
     *
     * @param string|list<string> $outputChannels
     * @param list<array{0: PregelExecutableTask, 1: list<array{0: string, 1: mixed}>}> $tasks
     * @return \Generator<int, array<string, mixed>>
     */
    public static function mapOutputUpdates(
        string|array $outputChannels,
        array $tasks,
        bool $cached = false,
    ): \Generator {
        $outputTasks = [];
        foreach ($tasks as $pair) {
            $task = $pair[0];
            $writes = $pair[1];

            $tags = $task instanceof PregelExecutableTask ? ($task->config?->tags ?? []) : [];
            if (in_array(Constants::TAG_HIDDEN, $tags, true)) {
                continue;
            }
            if ($writes === []) {
                continue;
            }
            if (($writes[0][0] ?? null) === Constants::ERROR) {
                continue;
            }
            if (($writes[0][0] ?? null) === Constants::INTERRUPT) {
                continue;
            }
            $outputTasks[] = $pair;
        }

        if ($outputTasks === []) {
            return;
        }

        $updated = [];

        $anyReturn = false;
        foreach ($outputTasks as [$task, $writes]) {
            foreach ($writes as $write) {
                if ($write[0] === Constants::RETURN) {
                    $anyReturn = true;
                    break;
                }
            }
        }

        if ($anyReturn) {
            foreach ($outputTasks as [$task, $writes]) {
                foreach ($writes as $write) {
                    if ($write[0] === Constants::RETURN) {
                        $updated[] = [$task->name, $write[1]];
                    }
                }
            }
        } elseif (!is_array($outputChannels)) {
            foreach ($outputTasks as [$task, $writes]) {
                foreach ($writes as $write) {
                    if ($write[0] === $outputChannels) {
                        $updated[] = [$task->name, $write[1]];
                    }
                }
            }
        } else {
            foreach ($outputTasks as [$task, $writes]) {
                $counts = [];
                foreach ($writes as $write) {
                    if (in_array($write[0], $outputChannels, true)) {
                        $counts[$write[0]] = ($counts[$write[0]] ?? 0) + 1;
                    }
                }

                $anyMultiple = false;
                foreach ($counts as $count) {
                    if ($count > 1) {
                        $anyMultiple = true;
                        break;
                    }
                }

                if ($anyMultiple) {
                    foreach ($writes as $write) {
                        if (in_array($write[0], $outputChannels, true)) {
                            $updated[] = [$task->name, [$write[0] => $write[1]]];
                        }
                    }
                } else {
                    $combined = [];
                    foreach ($writes as $write) {
                        if (in_array($write[0], $outputChannels, true)) {
                            $combined[$write[0]] = $write[1];
                        }
                    }
                    $updated[] = [$task->name, $combined];
                }
            }
        }

        $grouped = [];
        foreach ($updated as [$node, $value]) {
            $grouped[$node][] = $value;
        }

        $flattened = [];
        foreach ($grouped as $node => $values) {
            $flattened[$node] = count($values) === 1 ? $values[0] : $values;
        }

        if ($cached) {
            $flattened['__metadata__'] = ['cached' => true];
        }

        yield $flattened;
    }

    /**
     * Whether every key in an array looks like a namespace hash.
     *
     * Port of `isXXH3`. A 32-character hex string is the shape of the value
     * produced by `XXH3` over a checkpoint namespace, and is how a `resume`
     * map is told apart from a plain resume value — the same ambiguity a
     * channel name would create, resolved by shape.
     */
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
