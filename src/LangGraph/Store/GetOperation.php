<?php

declare(strict_types=1);

namespace LangGraph\Store;

/**
 * Operation to retrieve an item by namespace and key.
 *
 * Port of `GetOperation` from `store/base.ts`.
 */
final class GetOperation implements Operation
{
    /**
     * @param list<string> $namespace Hierarchical path for the item, e.g. `["users", "profiles"]`.
     * @param string       $key       Unique identifier within the namespace.
     */
    public function __construct(
        public readonly array $namespace,
        public readonly string $key,
    ) {
    }
}
