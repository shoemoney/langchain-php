<?php

declare(strict_types=1);

namespace LangGraph\Channels;

use LangGraph\Errors\EmptyChannelError;

/**
 * Base class for every channel in a Pregel graph.
 *
 * Port of `BaseChannel` from `langgraph-core/src/channels/base.ts`.
 *
 * A channel is the mechanism Pregel uses to decouple nodes from each other.
 * Nodes never call one another; they *write* to named channels, and the engine
 * decides which nodes to wake. This class is the entire contract between a node
 * and the engine, and the exact semantics of `update()`, `get()`, and
 * `isAvailable()` are what make a graph's behaviour correct — in particular:
 *
 *  - `update([])` is called every step for every channel, even unwritten ones.
 *    That is how a channel like {@see EphemeralValue} knows to clear itself.
 *  - `get()` MUST throw {@see EmptyChannelError} when empty, because
 *    {@see self::isAvailable()} is defined in terms of that throw.
 *
 * @template TValue     The value a reader sees.
 * @template TUpdate    What a writer may write (often the same, sometimes a delta).
 * @typeparam TCheckpoint What {@see self::checkpoint()} persists.
 */
abstract class BaseChannel
{
    /**
     * The channel's name as it appears in a serialized graph.
     *
     * Several code paths (delta-channel detection, equality, error messages)
     * dispatch on this string rather than on the class, because a restored
     * channel may come back from a checkpoint as a bare object.
     */
    public string $lcGraphName = 'BaseChannel';

    /** Marker used by {@see self::isChannel()} for duck-typed detection. */
    public bool $lgIsChannel = true;

    /**
     * Return a new, empty channel, optionally seeded from a checkpoint value.
     *
     * This is "restoration": the checkpoint is a snapshot of channel state, and
     * a restored channel must be indistinguishable from one that had evolved to
     * that state.
     *
     * @return static
     */
    abstract public function fromCheckpoint(mixed $checkpoint = null): self;

    /**
     * Apply a sequence of updates. Order within $values is arbitrary.
     *
     * Called by Pregel for **every** channel at the end of each step, with an
     * empty array for channels nothing wrote to.
     *
     * @param  list<mixed> $values
     * @return bool true if the channel's state changed
     * @throws \LangGraph\Errors\InvalidUpdateError if the sequence is invalid
     */
    abstract public function update(array $values): bool;

    /**
     * The current value.
     *
     * @throws EmptyChannelError if the channel has never been written to
     */
    abstract public function get(): mixed;

    /**
     * The value to persist, or null if the channel is empty.
     *
     * @throws EmptyChannelError if the channel is empty and cannot checkpoint
     */
    abstract public function checkpoint(): mixed;

    /**
     * Mark the current value consumed, so it is not offered again.
     *
     * No-op by default; a channel overrides this when its value is meant to be
     * read exactly once.
     *
     * @return bool true if state changed
     */
    public function consume(): bool
    {
        return false;
    }

    /**
     * Notify the channel the Pregel run is finishing.
     *
     * No-op by default; a channel overrides this to defer availability until the
     * end of a run (see `LastValueAfterFinish`).
     *
     * @return bool true if state changed
     */
    public function finish(): bool
    {
        return false;
    }

    /**
     * True when the channel can be read right now.
     *
     * The default implementation probes {@see self::get()} and catches the
     * empty error. Subclasses override it where they can answer more cheaply
     * than a throw/catch round-trip — on a hot superstep path that matters.
     */
    public function isAvailable(): bool
    {
        try {
            $this->get();

            return true;
        } catch (EmptyChannelError) {
            return false;
        }
    }

    /**
     * Semantic equality, used to decide whether two channels with the same key
     * are interchangeable. Identity by default.
     */
    public function equals(BaseChannel $other): bool
    {
        return $this === $other;
    }

    /**
     * Duck-typed channel detection, for values restored from a checkpoint that
     * may have lost their class.
     */
    public static function isChannel(mixed $obj): bool
    {
        return $obj instanceof BaseChannel || (is_object($obj) && ($obj->lgIsChannel ?? false) === true);
    }
}
