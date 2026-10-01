<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointConstants;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\CheckpointTuple;
use LangGraph\Pregel\Checkpoint\CheckpointTuple as PregelCheckpointTuple;

/**
 * The shared checkpointer fixture.
 *
 * Port of the helpers in `checkpoint-validation/src/test_utils.ts`
 * (`initialCheckpointTuple`, `parentAndChildCheckpointTuplesWithWrites`,
 * `generateTuplePairs`, `putTuples`).
 *
 * One fixture, used by every saver under test, which is the only way the
 * "both savers behave identically" claim means anything: the same tuples go in
 * and the same tuples have to come back out, whichever storage is underneath.
 */
final class CheckpointerFixture
{
    /** @var list<array{tuple: CheckpointTuple, writes: list<array{writes: list<array{0: string, 1: mixed}>, taskId: string}>, newVersions: array<string, int|string>}>|null */
    private static ?array $generated = null;

    private function __construct()
    {
    }

    /**
     * A single checkpoint with opaque `versions_seen` data.
     *
     * @param  array<string, mixed> $channelValues
     * @return CheckpointTuple
     */
    public static function initialCheckpointTuple(
        string $threadId,
        string $checkpointNs,
        string $checkpointId,
        array $channelValues = [],
    ): CheckpointTuple {
        $channelVersions = [];
        foreach (array_keys($channelValues) as $channel) {
            $channelVersions[$channel] = 1;
        }

        return new CheckpointTuple(
            config: self::config($threadId, $checkpointNs, $checkpointId),
            checkpoint: new Checkpoint(
                v: CheckpointConstants::CHECKPOINT_VERSION,
                ts: gmdate('Y-m-d\TH:i:s.v\Z'),
                id: $checkpointId,
                channelValues: $channelValues,
                channelVersions: $channelVersions,
                // Deliberately opaque to a checkpointer: it must be stored and
                // returned untouched.
                versionsSeen: ['' => ['someChannel' => 1]],
            ),
            metadata: ['source' => 'input', 'step' => -1, 'parents' => []],
        );
    }

    /**
     * A parent checkpoint and the child that supersedes it.
     *
     * @param  array<string, mixed>                                 $initialChannelValues
     * @param  list<array{writes: list<array{0: string, 1: mixed}>, taskId: string}> $writesToParent
     * @param  list<array{writes: list<array{0: string, 1: mixed}>, taskId: string}> $writesToChild
     * @return array{parent: CheckpointTuple, child: CheckpointTuple}
     */
    public static function parentAndChildTuples(
        string $threadId,
        string $parentCheckpointId,
        string $childCheckpointId,
        string $checkpointNs,
        array $initialChannelValues = [],
        array $writesToParent = [],
        array $writesToChild = [],
    ): array {
        $parentChannelVersions = [];
        foreach (array_keys($initialChannelValues) as $channel) {
            $parentChannelVersions[$channel] = 1;
        }

        $childChannelValues = $initialChannelValues;
        $childWriteCountByChannel = [];
        foreach ($writesToChild as $batch) {
            foreach ($batch['writes'] as [$channel, $value]) {
                $childChannelValues[$channel] = [$channel, $value];
                $childWriteCountByChannel[$channel] = ($childWriteCountByChannel[$channel] ?? 0) + 1;
            }
        }

        $childChannelVersions = [];
        foreach ($parentChannelVersions as $channel => $version) {
            $childChannelVersions[$channel] = isset($childWriteCountByChannel[$channel])
                ? $version + $childWriteCountByChannel[$channel]
                : $version;
        }

        $parentPendingWrites = self::flattenWrites($writesToParent);
        $childPendingWrites = self::flattenWrites($writesToChild);

        return [
            'parent' => new CheckpointTuple(
                config: self::config($threadId, $checkpointNs, $parentCheckpointId),
                checkpoint: new Checkpoint(
                    v: CheckpointConstants::CHECKPOINT_VERSION,
                    ts: gmdate('Y-m-d\TH:i:s.v\Z'),
                    id: $parentCheckpointId,
                    channelValues: $initialChannelValues,
                    channelVersions: $parentChannelVersions,
                    versionsSeen: ['' => ['someChannel' => 1]],
                ),
                metadata: ['source' => 'input', 'step' => -1, 'parents' => []],
                pendingWrites: $parentPendingWrites,
            ),
            'child' => new CheckpointTuple(
                config: self::config($threadId, $checkpointNs, $childCheckpointId),
                checkpoint: new Checkpoint(
                    v: CheckpointConstants::CHECKPOINT_VERSION,
                    ts: gmdate('Y-m-d\TH:i:s.v\Z'),
                    id: $childCheckpointId,
                    channelValues: $childChannelValues,
                    channelVersions: $childChannelVersions,
                    versionsSeen: ['' => ['someChannel' => 1]],
                ),
                metadata: ['source' => 'loop', 'step' => 0, 'parents' => [$checkpointNs => $parentCheckpointId]],
                parentConfig: self::config($threadId, $checkpointNs, $parentCheckpointId),
                pendingWrites: $childPendingWrites,
            ),
        ];
    }

    /**
     * The fixture every list test runs against: two threads' worth of
     * parent/child pairs across the root and a child namespace.
     *
     * @return list<array{tuple: CheckpointTuple, writes: list<array{writes: list<array{0: string, 1: mixed}>, taskId: string}>, newVersions: array<string, int|string>}>
     */
    public static function generatedTuples(): array
    {
        if (self::$generated !== null) {
            return self::$generated;
        }

        $namespaces = ['', 'child'];
        $generated = [];

        for ($i = 0; $i < 2; $i++) {
            $threadId = CheckpointId::uuid6(3);
            foreach ($namespaces as $namespace) {
                $parentId = CheckpointId::uuid6(3);
                $childId = CheckpointId::uuid6(3);

                $writesToParent = [[
                    'writes' => [[CheckpointConstants::TASKS, ['add_fish']]],
                    'taskId' => 'pending_sends_task',
                ]];
                $writesToChild = [[
                    'writes' => [['animals', ['fish', 'dog']]],
                    'taskId' => 'add_fish',
                ]];

                ['parent' => $parent, 'child' => $child] = self::parentAndChildTuples(
                    threadId: $threadId,
                    parentCheckpointId: $parentId,
                    childCheckpointId: $childId,
                    checkpointNs: $namespace,
                    initialChannelValues: ['animals' => ['dog']],
                    writesToParent: $writesToParent,
                    writesToChild: $writesToChild,
                );

                $generated[] = [
                    'tuple' => $parent,
                    'writes' => $writesToParent,
                    'newVersions' => $parent->checkpoint->channelVersions,
                ];
                $generated[] = [
                    'tuple' => $child,
                    'writes' => $writesToChild,
                    'newVersions' => self::versionDiff(
                        $parent->checkpoint->channelVersions,
                        $child->checkpoint->channelVersions,
                    ),
                ];
            }
        }

        return self::$generated = $generated;
    }

    /**
     * Write a fixture into a saver, one checkpoint at a time.
     *
     * Each tuple is put with its *parent's* id in the config, which is how the
     * chain is built: the checkpoint being written inherits the checkpoint the
     * caller was last at.
     *
     * @param  list<array{tuple: CheckpointTuple, writes: list<array{writes: list<array{0: string, 1: mixed}>, taskId: string}>, newVersions: array<string, int|string>}> $generated
     * @return \Generator<PregelCheckpointTuple>
     */
    public static function putTuples(BaseCheckpointSaver $saver, array $generated): \Generator
    {
        foreach ($generated as $entry) {
            $configurable = $entry['tuple']->config['configurable'];
            $parentId = $entry['tuple']->parentConfig['configurable']['checkpoint_id'] ?? null;

            self::assertNotStored($saver, $entry['tuple']->config);

            $config = $saver->put(
                [
                    'thread_id' => $configurable['thread_id'],
                    'checkpoint_ns' => $configurable['checkpoint_ns'],
                    'checkpoint_id' => $parentId,
                ],
                $entry['tuple']->checkpoint,
                $entry['tuple']->metadata,
                $entry['newVersions'],
            );

            foreach ($entry['writes'] as $batch) {
                $saver->putWrites($config, $batch['writes'], $batch['taskId']);
            }

            $expected = $saver->getTuple($config);
            if ($expected === null) {
                throw new \RuntimeException('fixture postcondition violated: tuple just written is not readable');
            }

            yield $expected;
        }
    }

    /**
     * Tuples keyed by checkpoint id.
     *
     * @param  list<PregelCheckpointTuple> $tuples
     * @return array<string, PregelCheckpointTuple>
     */
    public static function toMap(array $tuples): array
    {
        $map = [];
        foreach ($tuples as $tuple) {
            $map[$tuple->checkpoint->id] = $tuple;
        }

        return $map;
    }

    /**
     * @return array{configurable: array{thread_id: string, checkpoint_ns: string, checkpoint_id: string}}
     */
    public static function config(string $threadId, string $checkpointNs, string $checkpointId): array
    {
        return [
            'configurable' => [
                'thread_id' => $threadId,
                'checkpoint_ns' => $checkpointNs,
                'checkpoint_id' => $checkpointId,
            ],
        ];
    }

    /**
     * @param  list<array{writes: list<array{0: string, 1: mixed}>, taskId: string}> $batches
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private static function flattenWrites(array $batches): array
    {
        $out = [];
        foreach ($batches as $batch) {
            foreach ($batch['writes'] as [$channel, $value]) {
                $out[] = [$batch['taskId'], $channel, $value];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, int|string> $before
     * @param  array<string, int|string> $after
     * @return array<string, int|string>
     */
    private static function versionDiff(array $before, array $after): array
    {
        $diff = [];
        foreach ($after as $channel => $version) {
            if (($before[$channel] ?? null) !== $version) {
                $diff[$channel] = $version;
            }
        }

        return $diff;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function assertNotStored(BaseCheckpointSaver $saver, array $config): void
    {
        if ($saver->getTuple($config) !== null) {
            throw new \RuntimeException('fixture precondition violated: tuple already stored');
        }
    }
}
