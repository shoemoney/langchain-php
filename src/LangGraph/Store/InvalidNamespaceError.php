<?php

declare(strict_types=1);

namespace LangGraph\Store;

/**
 * Thrown when a store is handed a namespace it refuses to write under.
 *
 * Port of `InvalidNamespaceError` from `@langchain/langgraph-checkpoint`'s
 * `store/base.ts`.
 */
class InvalidNamespaceError extends \InvalidArgumentException
{
}
