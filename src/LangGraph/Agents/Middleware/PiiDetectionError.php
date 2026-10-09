<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

/**
 * Error thrown when PII is detected and the strategy is `block`.
 *
 * Port of `PIIDetectionError` from `langchain/src/agents/middleware/pii.ts`. A match is
 * `['text' => string, 'start' => int, 'end' => int]`; the offsets are byte offsets into the content.
 */
final class PiiDetectionError extends \Exception
{
    /**
     * @param list<array{text: string, start: int, end: int}> $matches
     */
    public function __construct(
        public readonly string $piiType,
        public readonly array $matches,
    ) {
        parent::__construct(\sprintf('PII detected: %s found %d occurrence(s)', $piiType, \count($matches)));
    }

    /** Upstream's `name`. */
    public function errorName(): string
    {
        return 'PIIDetectionError';
    }
}
