<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint;

use LangGraph\Pregel\Checkpoint\CheckpointFunctions;

/**
 * Ordering of channel versions.
 *
 * Port of `compareChannelVersions` / `maxChannelVersion` from
 * `@langchain/langgraph-checkpoint`'s `base.ts`.
 *
 * Versions are compared, never subtracted: the numeric scheme increments by one
 * per write, but a saver is free to override `getNextVersion()` and use
 * lexicographic strings (`"01.a" < "02.a" < "10.a"`). Mixing the two would make
 * a resume's scheduling decision wrong in a way that only shows up as a node
 * that silently never runs.
 */
final class ChannelVersions
{
    private function __construct()
    {
    }

    /**
     * The later of the two versions.
     *
     * Numeric versions compare numerically; anything else compares as a string.
     */
    public static function compare(int|string $a, int|string $b): int
    {
        return CheckpointFunctions::compareChannelVersions($a, $b);
    }

    /**
     * The latest of a set of versions.
     *
     * @param int|string ...$versions
     */
    public static function max(int|string ...$versions): int|string
    {
        if ($versions === []) {
            throw new \InvalidArgumentException('max() requires at least one version');
        }

        $max = $versions[0];
        foreach ($versions as $version) {
            $max = self::compare($max, $version) >= 0 ? $max : $version;
        }

        return $max;
    }

    /**
     * The latest of a channel's whole version map, or null for an empty map.
     *
     * Null means "nothing has happened yet", which the scheduler reads as "this
     * checkpoint is not a resumable baseline".
     *
     * @param array<string, int|string> $channelVersions
     */
    public static function maxOfMap(array $channelVersions): int|string|null
    {
        return CheckpointFunctions::maxChannelMapVersion($channelVersions);
    }
}
