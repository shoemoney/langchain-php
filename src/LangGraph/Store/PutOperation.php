<?php

declare(strict_types=1);

namespace LangGraph\Store;

/**
 * Operation to store, update, or delete an item.
 *
 * Port of `PutOperation` from `store/base.ts`. A null value deletes the item.
 */
final class PutOperation implements Operation
{
    /**
     * @param list<string>              $namespace Hierarchical path for the item.
     * @param string                    $key       Unique identifier within the namespace.
     * @param array<string, mixed>|null $value     JSON-serializable data, or null to delete.
     * @param false|list<string>|null   $index     null uses the store's default fields, false disables
     *                                             indexing for this item, a list names the field paths to index.
     */
    public function __construct(
        public readonly array $namespace,
        public readonly string $key,
        public readonly ?array $value,
        public readonly false|array|null $index = null,
    ) {
    }
}
