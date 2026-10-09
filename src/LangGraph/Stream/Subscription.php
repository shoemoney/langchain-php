<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * Subscription matching and channel inference for the run-stream protocol.
 *
 * Port of `stream/subscription.ts`. A "matchable event" is any array with `method`, optional `seq` and
 * `params.namespace` / `params.data`; a subscription definition is
 * `['channels' => list<string>, 'namespaces'? => list<list<string>>, 'depth'? => int, 'since'? => int]`.
 */
final class Subscription
{
    /**
     * The base subscription channels (the templated `custom:<name>` form excluded).
     *
     * @var list<string>
     */
    public const SUPPORTED_CHANNELS = [
        'values', 'updates', 'messages', 'tools', 'lifecycle', 'input', 'checkpoints', 'tasks', 'custom',
    ];

    private function __construct()
    {
    }

    /**
     * Strip a namespace segment's dynamic suffix (everything from the first `:`).
     */
    public static function normalizeNamespaceSegment(string $segment): string
    {
        $idx = strpos($segment, ':');

        return $idx === false ? $segment : substr($segment, 0, $idx);
    }

    /**
     * Whether `$namespace` starts with `$prefix`. A candidate segment also matches after its dynamic suffix
     * is stripped, unless the prefix segment itself contains `:`.
     *
     * @param list<string> $namespace
     * @param list<string> $prefix
     */
    public static function isPrefixMatch(array $namespace, array $prefix): bool
    {
        if (\count($prefix) > \count($namespace)) {
            return false;
        }
        foreach ($prefix as $i => $segment) {
            $candidate = $namespace[$i];
            if ($candidate === $segment) {
                continue;
            }
            if (str_contains($segment, ':')) {
                return false;
            }
            if (self::normalizeNamespaceSegment($candidate) === $segment) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Whether `$value` names a subscription channel: a base channel or `custom:<name>`.
     */
    public static function isSupportedChannel(string $value): bool
    {
        return \in_array($value, self::SUPPORTED_CHANNELS, true) || str_starts_with($value, 'custom:');
    }

    /**
     * Map an event's method to its subscription channel; null for unrecognised methods.
     *
     * @param array<string, mixed> $event
     */
    public static function inferChannel(array $event): ?string
    {
        switch ($event['method'] ?? null) {
            case 'values':
                return 'values';
            case 'checkpoints':
                return 'checkpoints';
            case 'updates':
                return 'updates';
            case 'messages':
                return 'messages';
            case 'tools':
                return 'tools';
            case 'custom':
                $data = $event['params']['data'] ?? null;

                return \is_array($data) && isset($data['name']) ? 'custom:' . $data['name'] : 'custom';
            case 'lifecycle':
                return 'lifecycle';
            case 'input':
            case 'input.requested':
                return 'input';
            case 'tasks':
                return 'tasks';
            default:
                return null;
        }
    }

    /**
     * Whether an event should be delivered for a subscription definition. A numeric `since` cursor excludes
     * events at or before that `seq`.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $definition
     */
    public static function matchesSubscription(array $event, array $definition): bool
    {
        $since = $definition['since'] ?? null;
        if ((\is_int($since) || \is_float($since)) && ($event['seq'] ?? 0) <= $since) {
            return false;
        }

        $channel = self::inferChannel($event);
        if ($channel === null) {
            return false;
        }

        $channels = $definition['channels'] ?? [];
        $channelMatched = \in_array($channel, $channels, true)
            || (str_starts_with($channel, 'custom:') && \in_array('custom', $channels, true));
        if (!$channelMatched) {
            return false;
        }

        return self::namespaceMatches(
            $event['params']['namespace'] ?? [],
            $definition['namespaces'] ?? null,
            $definition['depth'] ?? null,
        );
    }

    /**
     * @param list<string> $eventNamespace
     * @param list<list<string>>|null $prefixes
     */
    private static function namespaceMatches(array $eventNamespace, ?array $prefixes, ?int $depth): bool
    {
        if ($prefixes === null || $prefixes === []) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            if (!self::isPrefixMatch($eventNamespace, $prefix)) {
                continue;
            }
            if ($depth === null || \count($eventNamespace) - \count($prefix) <= $depth) {
                return true;
            }
        }

        return false;
    }
}
