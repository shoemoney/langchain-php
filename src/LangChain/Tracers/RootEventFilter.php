<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * Applies the include/exclude filters of `streamEvents()` to the events of the ROOT runnable.
 *
 * Port of `_RootEventFilter` from `@langchain/core/runnables/utils`. Sub-runs are filtered by the log
 * stream handler; the root run is never in its log, so its start/stream/end events are filtered here.
 * The "v1" schema is the only user.
 */
final class RootEventFilter
{
    /** @var list<string>|null */
    public ?array $includeNames;

    /** @var list<string>|null */
    public ?array $includeTypes;

    /** @var list<string>|null */
    public ?array $includeTags;

    /** @var list<string>|null */
    public ?array $excludeNames;

    /** @var list<string>|null */
    public ?array $excludeTypes;

    /** @var list<string>|null */
    public ?array $excludeTags;

    /**
     * @param array{includeNames?: list<string>, includeTypes?: list<string>, includeTags?: list<string>, excludeNames?: list<string>, excludeTypes?: list<string>, excludeTags?: list<string>} $fields
     */
    public function __construct(array $fields = [])
    {
        $this->includeNames = $fields['includeNames'] ?? null;
        $this->includeTypes = $fields['includeTypes'] ?? null;
        $this->includeTags = $fields['includeTags'] ?? null;
        $this->excludeNames = $fields['excludeNames'] ?? null;
        $this->excludeTypes = $fields['excludeTypes'] ?? null;
        $this->excludeTags = $fields['excludeTags'] ?? null;
    }

    public function includeEvent(StreamEvent $event, string $rootType): bool
    {
        $include = $this->includeNames === null && $this->includeTypes === null && $this->includeTags === null;
        $eventTags = $event->tags;

        if ($this->includeNames !== null) {
            $include = $include || \in_array($event->name, $this->includeNames, true);
        }
        if ($this->includeTypes !== null) {
            $include = $include || \in_array($rootType, $this->includeTypes, true);
        }
        if ($this->includeTags !== null) {
            $include = $include || array_intersect($eventTags, $this->includeTags) !== [];
        }

        if ($this->excludeNames !== null) {
            $include = $include && !\in_array($event->name, $this->excludeNames, true);
        }
        if ($this->excludeTypes !== null) {
            $include = $include && !\in_array($rootType, $this->excludeTypes, true);
        }
        if ($this->excludeTags !== null) {
            $include = $include && array_intersect($eventTags, $this->excludeTags) === [];
        }

        return $include;
    }
}
