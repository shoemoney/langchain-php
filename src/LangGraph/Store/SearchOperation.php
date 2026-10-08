<?php

declare(strict_types=1);

namespace LangGraph\Store;

/**
 * Operation to search for items within a namespace prefix.
 *
 * Port of `SearchOperation` from `store/base.ts`.
 */
final class SearchOperation implements Operation
{
    /**
     * @param list<string>              $namespacePrefix Only items under this prefix are searched; `[]` searches everything.
     * @param array<string, mixed>|null $filter          Exact matches or `$eq` `$ne` `$gt` `$gte` `$lt` `$lte` `$in` `$nin` comparisons.
     * @param int                       $limit           Maximum number of items to return.
     * @param int                       $offset          Number of items to skip, for pagination.
     * @param string|null               $query           Natural language query; ranks results by vector similarity.
     */
    public function __construct(
        public readonly array $namespacePrefix,
        public readonly ?array $filter = null,
        public readonly int $limit = 10,
        public readonly int $offset = 0,
        public readonly ?string $query = null,
    ) {
    }
}
