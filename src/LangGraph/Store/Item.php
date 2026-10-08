<?php

declare(strict_types=1);

namespace LangGraph\Store;

/**
 * A stored item with its metadata.
 *
 * Port of `Item` from `store/base.ts`. The fields are mutable because the
 * in-memory store updates an existing item in place, exactly as upstream.
 */
class Item
{
    /**
     * @param array<string, mixed> $value     The stored data. Keys are filterable.
     * @param string               $key       Unique identifier within the namespace.
     * @param list<string>         $namespace Hierarchical path of the collection this item lives in.
     */
    public function __construct(
        public array $value,
        public string $key,
        public array $namespace,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
