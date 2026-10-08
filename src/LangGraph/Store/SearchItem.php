<?php

declare(strict_types=1);

namespace LangGraph\Store;

/**
 * A search result: an {@see Item} plus an optional relevance score.
 *
 * Port of `SearchItem` from `store/base.ts`. The score is a cosine similarity
 * in `[-1, 1]` when the result came from a ranked search, otherwise null.
 */
final class SearchItem extends Item
{
    /**
     * @param array<string, mixed> $value
     * @param list<string>         $namespace
     */
    public function __construct(
        array $value,
        string $key,
        array $namespace,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        public ?float $score = null,
    ) {
        parent::__construct($value, $key, $namespace, $createdAt, $updatedAt);
    }

    /** Copy an item into a search result carrying the given score. */
    public static function fromItem(Item $item, ?float $score = null): self
    {
        return new self($item->value, $item->key, $item->namespace, $item->createdAt, $item->updatedAt, $score);
    }
}
