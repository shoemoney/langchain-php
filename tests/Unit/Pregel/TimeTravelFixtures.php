<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\State\Annotation;
use LangGraph\State\AnnotationRoot;

/**
 * Helpers shared by the time-travel, update-state and subgraph tests.
 *
 * `State` is upstream's `Annotation.Root({ value: Annotation<string[]>({ reducer: concat }) })`.
 * `invoke()` returns the state here, not `{..., __interrupt__: [...]}`, so "the run is paused with
 * this question" is read back from `getState()` (see {@see self::interruptValues()}).
 */
trait TimeTravelFixtures
{
    private static function stateSchema(): AnnotationRoot
    {
        return Annotation::root([
            'value' => Annotation::withReducer(
                static fn (array $a, array $b): array => array_merge($a, $b),
                static fn (): array => [],
            ),
        ]);
    }

    private static function threadConfig(string $threadId = '1'): RunnableConfig
    {
        return new RunnableConfig(configurable: ['thread_id' => $threadId]);
    }

    /**
     * The config of a history entry or snapshot, as something `invoke()` accepts.
     *
     * @param array<string, mixed> $entry A `getStateHistory()` entry or a snapshot's `config`.
     */
    private static function configOf(array $entry): RunnableConfig
    {
        return new RunnableConfig(configurable: $entry['config']['configurable'] ?? $entry['configurable'] ?? $entry);
    }

    /**
     * The questions a paused thread is waiting on.
     *
     * @return list<mixed>
     */
    private static function interruptValues(object $graph, RunnableConfig $config): array
    {
        $values = [];
        foreach ($graph->getState($config)->tasks as $task) {
            foreach ($task->interrupts as $interrupt) {
                $values[] = $interrupt['value'] ?? null;
            }
        }

        return $values;
    }

    /**
     * Upstream's `checkpointSummary`: the last six characters of each id, for readable diffs.
     *
     * @param list<array<string, mixed>> $history
     * @return list<array{id: string, parentId: string|null, source: mixed, next: list<string>, values: mixed}>
     */
    private static function checkpointSummary(array $history): array
    {
        return array_map(static function (array $s): array {
            $cid = (string) ($s['config']['configurable']['checkpoint_id'] ?? '');
            $pid = $s['parentConfig']['configurable']['checkpoint_id'] ?? null;

            return [
                'id' => substr($cid, -6),
                'parentId' => $pid ? substr($pid, -6) : null,
                'source' => $s['metadata']['source'] ?? null,
                'next' => $s['next'],
                'values' => $s['values'],
            ];
        }, $history);
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return list<array{0: mixed, 1: list<string>}>
     */
    private static function sourceAndNext(array $history): array
    {
        return array_map(static fn (array $s): array => [$s['metadata']['source'] ?? null, $s['next']], $history);
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return list<array{0: mixed, 1: list<string>, 2: mixed}>
     */
    private static function sourceNextValues(array $history): array
    {
        return array_map(static fn (array $s): array => [$s['metadata']['source'] ?? null, $s['next'], $s['values']], $history);
    }

    /**
     * The first history entry whose `next` includes `$node`.
     *
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    private static function findNext(array $history, string $node): array
    {
        foreach ($history as $entry) {
            if (in_array($node, $entry['next'], true)) {
                return $entry;
            }
        }
        throw new \LogicException("no history entry has \"{$node}\" in next");
    }

    /**
     * The state of a subgraph addressed by its static namespace (upstream's `getSubgraphState`).
     *
     * @return array<string, mixed> The snapshot's `config` array.
     */
    private static function subgraphConfig(object $graph, RunnableConfig $config, string $subgraphName): array
    {
        return $graph->getState(
            new RunnableConfig(configurable: [
                'thread_id' => $config->configurable['thread_id'],
                'checkpoint_ns' => $subgraphName,
            ]),
            ['subgraphs' => true],
        )->config;
    }

    /**
     * A node that records its name, then returns `$returns`.
     *
     * @param list<string>         $called
     * @param array<string, mixed> $returns
     */
    private static function recording(array &$called, string $name, array $returns): \Closure
    {
        return static function () use (&$called, $name, $returns): array {
            $called[] = $name;

            return $returns;
        };
    }
}
