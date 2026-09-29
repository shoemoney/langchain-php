<?php

declare(strict_types=1);

namespace LangGraph\Channels;

/**
 * Registry helpers for a graph's channel map.
 *
 * Port of `emptyChannels` / `getOnlyChannels` from
 * `langgraph-core/src/channels/base.ts`.
 *
 * A graph's channels are held as a `name => channel` map. These helpers do two
 * things the engine relies on:
 *
 *  - **filter** the map down to actual channels, dropping sentinel bookkeeping
 *    keys (the TS original tags its result with a symbol; here filtering is
 *    structural, which survives serialization where a symbol would not);
 *  - **restore** every channel from a checkpoint, so a resumed run is
 *    indistinguishable from one that never stopped.
 */
final class ChannelRegistry
{
    private function __construct()
    {
    }

    /**
     * @param  array<string, BaseChannel> $channels
     * @return array<string, BaseChannel>
     */
    public static function getOnlyChannels(array $channels): array
    {
        $out = [];
        foreach ($channels as $name => $channel) {
            if (BaseChannel::isChannel($channel)) {
                $out[$name] = $channel;
            }
        }

        return $out;
    }

    /**
     * Build a fresh channel map restored from a checkpoint.
     *
     * A name absent from `$checkpointValues` is restored as an *empty* channel,
     * not as the prototype instance — each channel must be a distinct object so
     * that two names sharing a channel spec do not alias one another.
     *
     * @param array<string, BaseChannel> $channels
     * @param array<string, mixed>       $checkpointValues
     * @return array<string, BaseChannel>
     */
    public static function emptyChannels(array $channels, array $checkpointValues = []): array
    {
        $out = [];
        foreach (self::getOnlyChannels($channels) as $name => $channel) {
            $out[$name] = $channel->fromCheckpoint($checkpointValues[$name] ?? null);
        }

        return $out;
    }

    /**
     * The set of names whose channel currently has a value.
     *
     * @param array<string, BaseChannel> $channels
     * @return list<string>
     */
    public static function availableChannels(array $channels): array
    {
        $out = [];
        foreach (self::getOnlyChannels($channels) as $name => $channel) {
            if ($channel->isAvailable()) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Snapshot every channel for a checkpoint.
     *
     * An empty channel is *omitted* rather than stored as null, so the
     * checkpoint only carries live state. That keeps a checkpoint from growing
     * with dead keys over a long run, and lets a restored channel distinguish
     * "never set" from "explicitly cleared".
     *
     * @param  array<string, BaseChannel> $channels
     * @return array<string, mixed>
     */
    public static function checkpointValues(array $channels): array
    {
        $out = [];
        foreach (self::getOnlyChannels($channels) as $name => $channel) {
            if (!$channel->isAvailable()) {
                continue;
            }
            $value = $channel->checkpoint();
            if ($value !== null) {
                $out[$name] = $value;
            }
        }

        return $out;
    }
}
