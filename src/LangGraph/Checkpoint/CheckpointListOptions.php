<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint;

/**
 * How far back through a thread's history to look.
 *
 * Port of `CheckpointListOptions` from `@langchain/langgraph-checkpoint`.
 *
 * The three options answer one question each, and all three are *exclusive*
 * filters applied before the limit:
 *
 *  - `before` — start strictly older than this checkpoint. It is the pagination
 *    cursor, and it is exclusive so that paging through a history cannot repeat
 *    or skip a row.
 *  - `filter` — match metadata key for key. Applied to the decoded metadata, not
 *    to whatever the store happens to have indexed.
 *  - `limit` — stop after this many. A limit of zero or less yields nothing; it
 *    is not "unlimited".
 */
final class CheckpointListOptions
{
    /**
     * @param array<string, mixed>      $config  Whole config or inner `configurable` map.
     * @param array<string, mixed>|null $filter Metadata key/value pairs that must all match.
     */
    public function __construct(
        public readonly ?array $before = null,
        public readonly ?int $limit = null,
        public readonly ?array $filter = null,
    ) {
    }

    /**
     * Normalise the `list()` second argument.
     *
     * The engine calls `list($config)`; a caller paging through history passes
     * options. PHP has no overloading, so `list()` accepts either a bare limit or
     * a full options object and this is where the two become one shape.
     *
     * @param CheckpointListOptions|int|null $options
     */
    public static function of(CheckpointListOptions|int|null $options): self
    {
        if ($options instanceof self) {
            return $options;
        }

        return new self(limit: $options);
    }

    /**
     * The `checkpoint_id` of the cursor, or an empty string when there is none.
     *
     * @return string
     */
    public function beforeCheckpointId(): string
    {
        if ($this->before === null) {
            return '';
        }

        return CheckpointId::fromConfig($this->before);
    }

    /**
     * Whether a decoded metadata map passes the filter.
     *
     * An empty filter matches everything, which is why a caller can pass `{}` and
     * mean "no filtering" rather than "match nothing".
     *
     * @param array<string, mixed> $metadata
     */
    public function matches(array $metadata): bool
    {
        if ($this->filter === null) {
            return true;
        }

        foreach ($this->filter as $key => $value) {
            if (!array_key_exists($key, $metadata) || $metadata[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
