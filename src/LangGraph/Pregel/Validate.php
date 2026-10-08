<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangGraph\Channels\BaseChannel;

/**
 * Structural validation of a Pregel graph.
 *
 * Port of `validateGraph` and `validateKeys` from `langgraph-core/src/pregel/validate.ts`.
 *
 * A Pregel graph is three maps (nodes, channels, subscriptions) plus a few names that must
 * point into them. Nothing in PHP stops a caller wiring a node to a channel that does not
 * exist, and the engine's failure mode for that is silence: a node whose trigger channel is
 * missing is simply never scheduled. These checks turn the silent case into an error at the
 * boundary, before a run, where the cause is still on the stack.
 */
final class Validate
{
    private function __construct()
    {
    }

    /**
     * Check a graph's wiring, throwing {@see GraphValidationError} on the first fault.
     *
     * The order of the checks is upstream's, and it is observable: a graph with two faults
     * reports whichever comes first.
     *
     * @param array<string, PregelNode>|null  $nodes
     * @param array<string, BaseChannel>|null $channels
     * @param string|list<string>             $inputChannels
     * @param string|list<string>             $outputChannels
     * @param string|list<string>|null        $streamChannels
     * @param list<string>|string|null        $interruptAfterNodes  A node list, or `'*'` for all.
     * @param list<string>|string|null        $interruptBeforeNodes A node list, or `'*'` for all.
     */
    public static function validateGraph(
        ?array $nodes,
        ?array $channels,
        string|array $inputChannels,
        string|array $outputChannels,
        string|array|null $streamChannels = null,
        array|string|null $interruptAfterNodes = null,
        array|string|null $interruptBeforeNodes = null,
    ): void {
        if ($channels === null) {
            throw new GraphValidationError('Channels not provided');
        }
        $nodes ??= [];

        $subscribedChannels = [];

        foreach ($nodes as $name => $node) {
            $name = (string) $name;
            if ($name === Constants::INTERRUPT) {
                throw new GraphValidationError('"Node name ' . Constants::INTERRUPT . ' is reserved"');
            }
            // Upstream checks `node.constructor === PregelNode`: an exact class, not instanceof.
            if (is_object($node) && $node::class === PregelNode::class) {
                foreach ($node->triggers as $trigger) {
                    $subscribedChannels[$trigger] = true;
                }
            } else {
                throw new GraphValidationError(
                    'Invalid node type ' . self::jsTypeof($node) . ', expected PregelNode'
                );
            }
        }

        foreach (array_keys($subscribedChannels) as $chan) {
            if (!array_key_exists($chan, $channels)) {
                throw new GraphValidationError("Subscribed channel '{$chan}' not in channels");
            }
        }

        if (!is_array($inputChannels)) {
            if (!isset($subscribedChannels[$inputChannels])) {
                throw new GraphValidationError(
                    "Input channel {$inputChannels} is not subscribed to by any node"
                );
            }
        } else {
            $anySubscribed = false;
            foreach ($inputChannels as $channel) {
                if (isset($subscribedChannels[$channel])) {
                    $anySubscribed = true;
                    break;
                }
            }
            if (!$anySubscribed) {
                throw new GraphValidationError(
                    'None of the input channels ' . implode(',', $inputChannels)
                    . ' are subscribed to by any node'
                );
            }
        }

        $allOutputChannels = [];
        foreach ((array) $outputChannels as $chan) {
            $allOutputChannels[$chan] = true;
        }
        // Upstream: `streamChannels && !Array.isArray(...)` adds a scalar, an array adds each.
        // A falsy scalar (the empty string) adds nothing.
        if (is_array($streamChannels)) {
            foreach ($streamChannels as $chan) {
                $allOutputChannels[$chan] = true;
            }
        } elseif ($streamChannels !== null && $streamChannels !== '') {
            $allOutputChannels[$streamChannels] = true;
        }

        foreach (array_keys($allOutputChannels) as $chan) {
            if (!array_key_exists($chan, $channels)) {
                throw new GraphValidationError("Output channel '{$chan}' not in channels");
            }
        }

        foreach ([$interruptAfterNodes, $interruptBeforeNodes] as $interruptNodes) {
            if ($interruptNodes === null || $interruptNodes === '*') {
                continue;
            }
            foreach ((array) $interruptNodes as $node) {
                if (!array_key_exists($node, $nodes)) {
                    throw new GraphValidationError("Node {$node} not in nodes");
                }
            }
        }
    }

    /** What `typeof` would say, because the upstream message interpolates it. */
    private static function jsTypeof(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value), is_float($value) => 'number',
            is_bool($value) => 'boolean',
            $value === null => 'object',
            is_callable($value) && !is_array($value) => 'function',
            default => 'object',
        };
    }

    /**
     * Check that every requested key names a channel.
     *
     * Port of `validateKeys`. Used for the per-call `outputKeys` / `inputKeys` overrides,
     * where a typo would otherwise read as "that channel is empty".
     *
     * @param string|list<string>        $keys
     * @param array<string, BaseChannel> $channels
     */
    public static function validateKeys(string|array $keys, array $channels): void
    {
        foreach ((array) $keys as $key) {
            if (!array_key_exists($key, $channels)) {
                throw new \InvalidArgumentException("Key {$key} not found in channels");
            }
        }
    }
}
