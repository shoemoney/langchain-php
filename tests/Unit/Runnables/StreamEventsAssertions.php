<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Tracers\StreamEvent;
use PHPUnit\Framework\Assert;

/**
 * Shared helpers for the `streamEvents` / `streamLog` tests.
 *
 * The upstream tests compare whole event lists with `toEqual` and write `expect.any(String)` for run ids
 * and `expect.any(Object)` for metadata. {@see self::summarise()} is that wildcarding: it asserts the
 * wildcarded fields are present and well-formed, then reduces each event to the fields a test compares.
 */
final class StreamEventsAssertions
{
    private function __construct()
    {
    }

    /**
     * @param iterable<StreamEvent> $events
     *
     * @return list<StreamEvent>
     */
    public static function collect(iterable $events): array
    {
        $out = [];
        foreach ($events as $event) {
            Assert::assertInstanceOf(StreamEvent::class, $event);
            $out[] = $event;
        }

        return $out;
    }

    /**
     * Each event as `[event, name, tags, data]`, after checking `run_id` is a non-empty string and
     * `metadata` an array (upstream's `expect.any(String)` / `expect.any(Object)`).
     *
     * @param list<StreamEvent> $events
     *
     * @return list<array{0: string, 1: string, 2: list<string>, 3: mixed}>
     */
    public static function summarise(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            Assert::assertNotSame('', $event->runId);
            Assert::assertIsArray($event->metadata);
            $out[] = [$event->event, $event->name, $event->tags, $event->data];
        }

        return $out;
    }

    /**
     * @param list<StreamEvent> $events
     *
     * @return list<string> `event name` pairs, for order-only assertions.
     */
    public static function names(array $events): array
    {
        return array_map(static fn (StreamEvent $e): string => $e->event . ' ' . $e->name, $events);
    }

    /**
     * @param list<StreamEvent> $events
     */
    public static function first(array $events, string $eventName): StreamEvent
    {
        foreach ($events as $event) {
            if ($event->event === $eventName) {
                return $event;
            }
        }

        Assert::fail("No \"{$eventName}\" event in " . implode(', ', array_map(static fn (StreamEvent $e): string => $e->event, $events)));
    }

    public static function reverse(string $s): string
    {
        return strrev($s);
    }
}
