<?php

declare(strict_types=1);

namespace LangGraph\Store;

/**
 * One prefix or suffix condition for listing namespaces.
 *
 * Port of `MatchCondition` and `NamespaceMatchType` from `store/base.ts`. A
 * `"*"` path element matches any single label.
 */
final class MatchCondition
{
    public const PREFIX = 'prefix';

    public const SUFFIX = 'suffix';

    /**
     * @param string       $matchType `prefix` or `suffix`.
     * @param list<string> $path      Labels to match; `"*"` is a wildcard.
     */
    public function __construct(
        public readonly string $matchType,
        public readonly array $path,
    ) {
    }
}
