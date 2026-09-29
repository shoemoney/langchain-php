<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * A packet asking the engine to run a node with a specific input.
 *
 * Port of `Send` from `langgraph-core/src/constants.ts`.
 *
 * `Send` is how a fan-out is expressed. A conditional edge returns a list of
 * `Send`s and the engine schedules one task per packet in the *next* superstep,
 * each with its own input — the map half of a map-reduce. A `Send`'s args need
 * not match the graph's state shape at all, which is what makes this different
 * from an edge: an edge passes the whole state, a `Send` passes whatever you
 * hand it.
 *
 * A `Send` is also how a node is re-invoked mid-run, so its `node` is validated
 * against the process map at preparation time and an unknown name is dropped
 * with a warning rather than crashing the run.
 */
final class Send
{
    public const LG_NAME = 'Send';

    /**
     * @param string $node  Name of the node to run.
     * @param mixed  $args  The input for that node. Passed through unchanged.
     * @param mixed  $timeout Per-task timeout overriding the node's own.
     */
    public function __construct(
        public readonly string $node,
        public readonly mixed $args = null,
        public readonly mixed $timeout = null,
    ) {
    }

    /**
     * The plain-object form a `Command` carries.
     *
     * The TS `Send` serialises with `lg_name`, and a serialised `Command` is
     * compared field-by-field against a JSON literal in the upstream conformance
     * tests. This exists so that comparison is expressible in PHP.
     *
     * @return array{lg_name: string, node: string, args: mixed, timeout: mixed}
     */
    public function toArray(): array
    {
        return [
            'lg_name' => self::LG_NAME,
            'node' => $this->node,
            'args' => $this->args,
            'timeout' => $this->timeout,
        ];
    }

    /**
     * Structural test for a `Send`.
     *
     * Port of `_isSendInterface`. Structural rather than `instanceof` because a
     * `Send` can arrive from a checkpoint or another runtime as a plain array,
     * and a `Command`'s `goto` legitimately holds that shape.
     */
    public static function isSendInterface(mixed $x): bool
    {
        if ($x instanceof self) {
            return true;
        }

        return is_array($x)
            && array_key_exists('node', $x)
            && is_string($x['node'])
            && array_key_exists('args', $x);
    }

    /** True only for a real `Send` instance or its `{lg_name: "Send"}` form. */
    public static function isSend(mixed $x): bool
    {
        if ($x instanceof self) {
            return true;
        }

        return is_array($x) && ($x['lg_name'] ?? null) === self::LG_NAME;
    }

    /** Normalise the structural form back into a `Send`. */
    public static function fromMixed(mixed $x): ?self
    {
        if ($x instanceof self) {
            return $x;
        }

        if (self::isSendInterface($x) && is_array($x)) {
            return new self($x['node'], $x['args'], $x['timeout'] ?? null);
        }

        return null;
    }
}
