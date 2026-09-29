<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint;

/**
 * Why a checkpoint exists, and what it was made from.
 *
 * Port of `CheckpointMetadata` from `@langchain/langgraph-checkpoint`.
 *
 * The three defined keys are not decoration:
 *
 *  - `source` says *which* code path wrote this. A `fork` is a copy of another
 *    checkpoint and must not be mistaken for fresh progress; an `input` is the
 *    first checkpoint of a call.
 *  - `step` is the superstep number, `-1` for the first `input` checkpoint and
 *    `0` for the first `loop` one.
 *  - `parents` maps checkpoint namespace to parent checkpoint id, which is how a
 *    sub-graph records what it was forked from.
 *
 * Everything else a caller attaches is passed through untouched — the type is
 * open on purpose, and savers are required to store metadata as given.
 */
final class CheckpointMetadata
{
    private function __construct()
    {
    }

    /** A checkpoint created from an `invoke`/`stream`/`batch` input. */
    public const SOURCE_INPUT = 'input';

    /** A checkpoint created from inside the Pregel loop. */
    public const SOURCE_LOOP = 'loop';

    /** A checkpoint created by a manual state update. */
    public const SOURCE_UPDATE = 'update';

    /** A checkpoint created as a copy of another checkpoint. */
    public const SOURCE_FORK = 'fork';

    /**
     * The values `source` may take.
     *
     * @var list<string>
     */
    public const SOURCES = [
        self::SOURCE_INPUT,
        self::SOURCE_LOOP,
        self::SOURCE_UPDATE,
        self::SOURCE_FORK,
    ];

    /**
     * Whether a metadata map is well formed.
     *
     * `source` must be one of the four, and `step` must be an integer — a string
     * step is the sort of thing that sorts lexicographically later and turns a
     * resume into an infinite replay.
     *
     * @param array<string, mixed> $metadata
     */
    public static function isValid(array $metadata): bool
    {
        if (!isset($metadata['source']) || !is_string($metadata['source'])) {
            return false;
        }
        if (!in_array($metadata['source'], self::SOURCES, true)) {
            return false;
        }
        if (!isset($metadata['step']) || !is_int($metadata['step'])) {
            return false;
        }
        if (isset($metadata['parents']) && !is_array($metadata['parents'])) {
            return false;
        }

        return true;
    }

    /**
     * The defined keys of a metadata map, with their defaults applied.
     *
     * Used when a saver needs to read a defined key and cannot tolerate a
     * missing one; extra keys are never dropped, only read past.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array{source: string, step: int, parents: array<string, string>}
     */
    public static function definedKeys(array $metadata): array
    {
        $parents = [];
        foreach ((array) ($metadata['parents'] ?? []) as $namespace => $parentId) {
            $parents[(string) $namespace] = (string) $parentId;
        }

        return [
            'source' => (string) ($metadata['source'] ?? self::SOURCE_LOOP),
            'step' => (int) ($metadata['step'] ?? -1),
            'parents' => $parents,
        ];
    }
}
